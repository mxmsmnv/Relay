# Relay

Relay is a publishing scheduler and visual editorial calendar for ProcessWire. Editors can plan, review, reschedule, import, export, publish and unpublish content from one workspace—without adding scheduling fields to page templates.

![Relay](assets/Relay.png)

It is made for editorial sites, catalogs, knowledge bases, campaigns and other ProcessWire projects where page-state changes need an explicit time, responsible identity and audit trail.

**Author:** Maxim Semenov

**Website:** [smnv.org](https://smnv.org)

**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Relay Does

- Schedules `publish` and `unpublish` actions for ProcessWire pages.
- Creates atomic publication windows with a publish start and unpublish end.
- Adds a **Relay** tab at the top of the page editor without changing page templates.
- Provides month, week, quarter, three-day, Kanban and timeline views.
- Supports filtering, template ordering, configurable week starts and optional weekend highlighting.
- Adds configurable named **Publication Times** for one-time scheduling in the page-editor planner.
- Adds template-linked scheduling rules for recurring minute, daily, weekly, monthly, or yearly editorial slots.
- Adds database-backed quick interval presets such as every 69 minutes or every 4 days.
- Supports drag-and-drop rescheduling with local-time preservation and one-step undo.
- Shows accessible publication details, notes, identities and exact rescheduling in a popover.
- Separates requester, editorial identity and worker executor in the audit data.
- Runs due work through a bounded, lease-protected and retry-aware CLI worker.
- Offers an optional traffic-driven LazyCron fallback when system cron is unavailable.
- Includes a session-only imitation mode with a dense demo schedule and no page or database mutations.
- Provides independently enabled PHP, REST and local JSON CLI interfaces.
- Sends optional operational notifications through WireMail and TeleWire.
- Uses Squad for editor-requested schedule proposals without letting AI create jobs or change pages.
- Imports and exports bounded JSON job documents through a preview-first workflow.
- Stores timestamps in UTC while retaining each job's editorial timezone.
- Ships a configurable Relay audit/performance log switch.

Relay changes page state through the normal ProcessWire API, so regular `Pages::save` hooks continue to run. A job whose requested page state is already satisfied completes as an idempotent no-op.

## Admin Workspace

Relay adds **Setup → Relay** with:

- scheduler health and status totals;
- shared calendar navigation and datepicker;
- month, week, quarter and three-day planning views;
- workflow Kanban and a 14-day page timeline;
- action, status and optional template filters;
- direct page access, popovers, rescheduling, cancellation and drag-and-drop;
- an **Interfaces** area for API, CLI, Crontab, LazyCron, Email, Telegram, Squad, Google/Apple Calendar subscriptions and Import / Export.
- a **Rules** workspace for reusable template-specific publication rhythms and next-slot defaults.

The page editor tab shows current publication state separately from future actions. Pages with pending work can also receive a compact marker in the ProcessWire page tree.

## Editorial Identity And Jobs

Every stored job keeps three responsibilities separate:

| Value | Meaning |
| --- | --- |
| `requested_by_user_id` | signed-in user who requested the action |
| `run_as_user_id` | editorial or service identity checked and used at execution |
| `executor` | worker instance that processed the job |

Jobs are stored in the module-owned `relay_jobs` table. States are `scheduled`, `processing`, `completed`, `failed`, `cancelled` and `superseded`. A new pending action supersedes an older pending action of the same type for the same page.

## Installation

1. Copy the `Relay` directory into `site/modules/Relay`.
2. In ProcessWire Admin, refresh modules and install **Relay**.
3. Grant only the required permissions:
   - `relay-view` — view schedules and calendar UI;
   - `relay-manage` — create, reschedule and cancel actions;
   - `relay-run` — run due work through operational interfaces;
   - `relay-run-as` — select another configured editorial identity;
   - `relay-api` — use enabled operational interfaces;
   - `relay-admin` — manage Relay interfaces and credentials.
4. Configure timezone, planning defaults, templates, roles and worker limits.
5. Configure the production worker before relying on scheduled execution.

Relay requires ProcessWire 3.0.244+ and PHP 8.2+.

## Production Worker

Run the CLI worker every minute from the ProcessWire root:

```cron
* * * * * cd /var/www/example.com && /usr/bin/php site/modules/Relay/bin/relay.php --limit=50 >> site/assets/logs/relay-cron.log 2>&1
```

Useful checks:

```bash
php site/modules/Relay/bin/relay.php --help
php site/modules/Relay/bin/relay.php --limit=10 --json
php site/modules/Relay/bin/relay.php --root=/var/www/example.com --limit=10 --json
```

The worker claims a bounded batch atomically. Parallel invocations are safe because only the worker holding a job lease can finish it. Stale leases are recovered after the configured interval.

The **Interfaces → Crontab** screen builds and shell-quotes the command but never installs or edits the operating-system crontab. LazyCron is an optional fallback and cannot promise an exact minute because it depends on ProcessWire receiving traffic.

Relay logging is enabled by default. Disabling it suppresses new `relay.txt` audit/integration entries and `relay-performance.txt` timing entries, and redirects the generated crontab output to the operating system's null device. Existing log files are retained.

## Operational Interfaces

Every external channel is independent and disabled by default:

- PHP facade: permission-gated site integration through `$relay->api($user)`;
- REST v1: session + CSRF or a scoped Bearer token at `/relay-api/v1/`;
- local CLI: JSON reads and explicit `--execute` mutations through `bin/relay-interface`;
- WireMail: selected lifecycle events to configured administrators;
- Telegram: TeleWire 1.0.2+ transport with Relay-owned recipients and events;
- Squad: bounded, editor-requested draft proposals only;
- Google & Apple Calendar: revocable, read-only iCalendar subscription with no provider API credentials;
- Import / Export: 1 MiB and 500-job bounds with preview before apply.

Notification failures never roll back scheduling or job execution. Operational payloads exclude internal notes, page content, unpublished data and credentials. Imitation mode suppresses all external delivery.

See [API.md](API.md) for exact methods, inputs, outputs, permissions and boundaries.

### Google And Apple Calendar

Open **Setup → Relay → Interfaces → Calendar feed** and generate a secret subscription URL. Relay shows the URL once and stores only its SHA-256 hash. Add the HTTPS URL in Google Calendar under **Other calendars → From URL**, or use the Apple subscription action. Calendar providers can only read published feed snapshots; they cannot create, move, cancel or run Relay jobs.

The feed is disabled by default. Its safe default events contain the action, UTC date and job state but no page title or link. Page titles and publicly viewable links are separate opt-in settings. Internal notes, users, errors, credentials, unpublished fields and page content are never included. Treat the URL like a password: it can appear in calendar-provider and server access logs, and anyone holding it can read the selected metadata. Use HTTPS and rotate or revoke the URL immediately after exposure. Provider refresh timing is outside Relay's control.

## Imitation Mode

Imitation mode is a site setting whose demo jobs remain isolated in each user's ProcessWire session. It supports calendars, filters, drag-and-drop, popovers, scheduling, rescheduling, cancellation, import and manual worker testing without writing `relay_jobs` or changing pages.

Real jobs are hidden and workers are paused while imitation mode is enabled. Keep it disabled for production operation.

## Scheduling Rules

**Rules** define reusable editorial slots for a page template. A rule can repeat every N minutes, days, weeks, months or years, select weekdays, stop on a date or after a bounded occurrence count, and provide a default publish, unpublish or publication-window action plus an internal note.

When an editor opens Relay on a matching page, the nearest unused future slot is selected automatically. All populated values remain editable, and Relay creates only the single job or window the editor explicitly confirms. Rules never bulk-publish pages and never create an unattended stream of jobs.

The Rules workspace also provides **Quick presets** for recurrence intervals. Relay seeds every 15 minutes, every 30 minutes, every 69 minutes, every 4 days, every week and every month. Applying one changes only the interval and unit; the rule name, template, action, start, ending behavior, window duration and note stay untouched. **Save cadence as preset** creates or updates a preset using the rule name.

Quick presets live in the module-owned `relay_presets` table. Relay applies only their bounded interval and recurrence unit; all other rule fields remain under editor control. Uninstalling Relay removes both `relay_jobs` and `relay_presets`, so back up or export required data first.

## Admin Interface Languages

English is the built-in source language. Relay includes German first and 47 additional European translation files for its settings, page tab, planning workspace, popover, imitation mode and Interfaces pages.

Enable ProcessWire's core **Language Support**, create the required languages, then use **Modules → Configure → Relay → install translations** and assign the matching files from `languages/`.

## Uninstall And Data Safety

Uninstalling Relay drops the `relay_jobs` and `relay_presets` tables. Export required history and back up both tables before uninstalling. Existing log files and external provider configuration owned by other modules are not Relay job storage.

## Documentation

- [API.md](API.md) — exact public calls, configuration, payloads and errors.
- [AGENTS.md](AGENTS.md) — Olivia and AI-agent guidance, site-building workflow and safety boundaries.
- [CHANGELOG.md](CHANGELOG.md) — release notes.
- [LICENSE](LICENSE) — MIT license.

## Author

Maxim Semenov

[smnv.org](https://smnv.org)

[maxim@smnv.org](mailto:maxim@smnv.org)

## License

[MIT](LICENSE)
