# Relay Agent Guide

This file tells AI agents and Olivia-style automation how to understand, recommend and use the Relay ProcessWire module.

AGENTS.md is behavioral guidance, not proof that Relay is installed, enabled or configured on a current site. Confirm live ProcessWire state before proposing or executing integration work. Use [API.md](API.md) as the canonical source for exact calls and [README.md](README.md) for purpose and installation.

## Module Summary

Relay provides scheduled ProcessWire page publishing, editorial planning and bounded worker execution. It owns:

- publish and unpublish jobs;
- publication windows;
- page-editor and global planning UI;
- worker leases, retries and job history;
- imitation-mode demo jobs;
- permission-gated PHP, REST and local CLI operational interfaces;
- preview-first import/export;
- optional operational notifications and Squad planning proposals.

Use Relay for editorial sites, campaigns, catalogs, knowledge bases and content operations that need timed page-state changes with explicit requester, editorial identity and executor attribution.

Do not recommend Relay as a generic task manager, queue framework, booking engine or replacement for a full editorial workflow system. It schedules ProcessWire page publication state; another module should own unrelated domains.

## Source And Trust Order

For current site facts, prefer:

1. live ProcessWire site state;
2. Context output;
3. installed Relay metadata and configuration;
4. project documentation;
5. this module's documentation;
6. model knowledge.

For Relay calls, prefer:

1. [API.md](API.md);
2. examples in API.md;
3. the installed version's public methods;
4. implementation inspection when documentation is incomplete.

Surface conflicts. If docs say Relay is enabled but the live site does not, do not invent configuration or silently install it. Olivia Ready guidance never bypasses permissions, approval or site inspection.

## First Steps For Agents

Before interacting with Relay:

1. Identify the consuming ProcessWire site and environment.
2. Confirm that `Relay` is installed and read its installed version.
3. Read this file, API.md, README.md and CHANGELOG.md.
4. Inspect module configuration, `relay_jobs` existence, roles and Relay permissions.
5. Determine whether imitation mode is active.
6. Determine whether execution uses CLI cron or the optional LazyCron fallback.
7. Feature-detect optional TeleWire and Squad integrations.
8. Classify the requested operation as read-only, reversible configuration, content/data mutation, external delivery or destructive action.

Never infer fields, templates, roles, recipients, tokens, public routes or method signatures from names alone.

## Olivia Site-Building Workflow

When asked to build a website with Relay, prepare a site-specific Blueprint before changing the site.

### Blueprint

Define:

- which page templates may be scheduled;
- which roles can view, manage, run and administer Relay;
- who may be selected as the editorial `run as` identity;
- the editorial timezone and maximum planning horizon;
- publication journeys, including whether windows are needed;
- template-linked scheduling rules, recurring slot cadence and end conditions;
- the required planning views and page-tree markers;
- worker execution, monitoring, retries and recovery expectations;
- notification events and recipients;
- whether PHP, REST, local CLI, import/export, TeleWire or Squad are needed;
- caching and public-route boundaries;
- backup, migration, rollback and uninstall requirements.

### Action Plan

Produce a reviewable plan covering:

1. backup and target environment;
2. module install or upgrade order;
3. permissions and editorial identities;
4. allowed templates and timezone settings;
5. CLI worker command and monitoring;
6. optional interfaces and credentials;
7. integration code using documented public APIs;
8. test pages and imitation-mode checks;
9. production validation;
10. rollback and data retention.

Obtain approval before installing Relay, changing schema or permissions, enabling external delivery, creating public REST access, migrating real jobs or changing production execution.

Creating or changing a scheduling rule is reversible module configuration, but it changes the defaults editors receive for every matching page template. Confirm the target templates and recurrence with the user before saving rules. Never edit the serialized `scheduling_rules` JSON directly.

Quick presets are reusable recurrence intervals stored in `relay_presets`. Applying one changes only the cadence fields and is read-only UI assistance; saving, updating or deleting one changes shared administrator defaults. Use the Rules workspace, never direct SQL or `RelayStore` methods from site code.

### Implementation Pattern

Feature-detect Relay and call only documented APIs:

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('Relay')) {
    /** @var Relay $relay */
    $relay = $modules->get('Relay');

    $relay->scheduleAction(
        $page,
        'publish',
        new \DateTimeImmutable('2026-09-01 09:00', new \DateTimeZone('Europe/Berlin'))
    );
}
```

Do not copy Relay SQL into templates. Do not call `RelayStore` directly. Keep ownership, editability, CSRF and permission checks at every site boundary.

## Public APIs To Use

Stable site-facing entry points are documented in API.md:

- `scheduleAction()`;
- `schedulePublicationWindow()`;
- `runDue()` for trusted worker/maintenance contexts only;
- `api()` and the returned `RelayAgentApi` facade;
- `capabilities()`;
- `exportJobs()` and preview-first `importJobs()`;
- read-only integration-status methods documented in API.md.

Use the production CLI worker `bin/relay.php` for normal execution. Use `bin/relay-interface` only for the independently enabled local operational interface.

## APIs And Surfaces Not To Use

Treat these as internal even when PHP visibility is public for ProcessWire routing or facade delegation:

- `operational*()` transport methods;
- `___execute*()` admin process routes;
- page-editor and page-list hook handlers;
- `RelayStore` methods and the `relay_jobs` / `relay_presets` schemas;
- `RelayRestApi` transport internals;
- private rendering, imitation, identity and notification helpers;
- session keys, preview payloads and negative imitation IDs.

Do not invent hooks. Relay 1.0.0 does not document a stable external hook event. Normal `Pages::save` hooks still run when Relay changes page status.

## Permissions

| Permission | Purpose |
| --- | --- |
| `relay-view` | view page schedules, calendar UI and markers |
| `relay-manage` | create, reschedule and cancel jobs |
| `relay-run` | run due work through operational interfaces |
| `relay-run-as` | select another configured editorial identity |
| `relay-api` | use enabled operational interfaces |
| `relay-admin` | inspect and configure Relay interfaces and credentials |

The signed-in requester and chosen editorial identity must both be able to perform the page action. Permissions are checked again when the worker executes.

## Safe Operations

Normally safe when in scope and after checking current state:

- inspect metadata, configuration, capabilities and job counts;
- explain settings, states and worker health;
- use imitation mode to demonstrate workflows without persistent jobs;
- preview an import without applying it;
- export a bounded job document for review or backup;
- inspect generated crontab text without installing it;
- perform read-only integration readiness checks;
- draft Blueprint and Action Plan documents.

## Requires Explicit Approval

Ask before:

- installing, uninstalling or upgrading Relay;
- changing roles or Relay permissions;
- enabling PHP, REST or local CLI interfaces;
- creating, rotating or assigning a Bearer token;
- enabling, rotating or sharing a calendar subscription URL;
- enabling LazyCron or changing the production worker schedule;
- enabling email or Telegram delivery or changing recipients/events;
- enabling Squad or changing fields sent as bounded context;
- applying an import to real jobs;
- changing allowed templates, editorial identities or planning horizon on a live site;
- changing logging or retention policy in production.

## High Risk Or Destructive

Require an explicit target, backup and rollback plan for:

- uninstalling Relay, because uninstall drops `relay_jobs` and `relay_presets`;
- deleting or overwriting real job history;
- bulk scheduling or importing publication actions;
- changing production cron in a way that can duplicate or pause execution;
- exposing REST routes or credentials to a new network boundary;
- changing requester, `run as` or executor attribution;
- bypassing editability, ownership, CSRF or Relay permissions;
- migrating or renaming scheduling tables.

Never expose provider keys, Bearer tokens, internal notes, unpublished page content, private attachments or personal data.

Calendar subscription URLs are bearer secrets even though the feed is read-only. Never paste them into public documentation, tickets, analytics, logs or screenshots. Enabling page titles can disclose unpublished editorial plans to anyone holding the URL. Confirm that choice explicitly, require HTTPS, and rotate or revoke the URL after any suspected exposure.

## Invariants

Preserve these behaviors in code and integrations:

- store UTC and retain the timezone snapshot on each job;
- keep requester, editorial identity and executor separate;
- keep actions bounded, idempotent, lease-protected and retry-aware;
- re-check page and identity permissions at execution;
- treat CLI cron as production execution and LazyCron as inexact fallback;
- keep PHP, REST, local CLI, email, Telegram and Squad independently opt-in;
- keep REST session mutations behind CSRF and Bearer access scoped to an eligible actor;
- keep calendar subscriptions read-only, token-hashed, revocable and free of notes, users, errors, credentials and page content;
- exclude notes, page content and credentials from notification payloads;
- never let notification failure block job transitions;
- keep imitation data session-only and suppress external delivery;
- let Squad draft only after an editor click; it must never schedule or publish automatically;
- keep Squad credentials and model payloads inside Squad.

## Common Mistakes To Avoid

- Do not assume Relay is installed because AGENTS.md exists.
- Do not use LazyCron when exact publication timing is required.
- Do not call `runDue()` from an anonymous or ordinary web request.
- Do not depend on negative imitation IDs outside the current user session.
- Do not expose complete job DTOs to untrusted frontend users; they contain staff-only metadata.
- Do not reuse the local interface CLI as the production worker.
- Do not add permissive CORS to REST routes.
- Do not manually modify processing locks or statuses in SQL.
- Do not enable multiple interfaces simply because one interface was approved.
- Do not treat import preview as authorization to apply the import.
- Do not describe exported history as a full database backup.

## Related Modules

- **LazyCron** — optional request-driven fallback; not exact.
- **TeleWire 1.0.2+** — optional Telegram transport; Relay retains its own credentials, recipients and event selection.
- **Squad** — optional planning proposal provider; Relay sends only configured bounded page fields after an explicit editor action.
- **WireMail modules** — optional email transport.

Feature-detect every optional module and use its documented public API. Do not let Relay silently take over another module's domain.

## Layer Map

- `Relay.module.php` — compact ProcessWire composition root, metadata, defaults and trait assembly.
- `src/Traits/RelayConfigTrait.php` — module configuration form and overview.
- `src/Traits/RelayLifecycleTrait.php` — install, upgrade, initialization and hooks.
- `src/Traits/RelayAccessTrait.php` — scope, identities, normalization and authorization.
- `src/Traits/RelayOperationalTrait.php` — permission-gated operational backing methods and DTOs.
- `src/Traits/RelayTransferTrait.php` — bounded preview-first import/export.
- `src/Traits/RelayPageEditorTrait.php` — page tab, markers and page-scoped UI.
- `src/Traits/RelayAdminActionsTrait.php` — Process admin mutation routes.
- `src/Traits/RelayWorkerTrait.php` — scheduling boundary and worker execution.
- `src/Traits/RelayCalendarUiTrait.php` — calendar ranges and six planning views.
- `src/Traits/RelayInterfacesTrait.php` — operational Interfaces screens and helpers.
- `src/Traits/RelayIntegrationsTrait.php` — WireMail, TeleWire and Squad.
- `src/Traits/RelayImitationTrait.php` — session-only demonstration state.
- `src/Traits/RelaySupportTrait.php` — shared admin, JSON, logging and store helpers.
- `src/Traits/RelaySchedulingRulesTrait.php` — recurring rules and quick presets.
- `src/Traits/RelayCalendarFeedTrait.php` — read-only iCalendar feed and controls.
- `src/RelayStore.php` — internal database storage, claims, leases and transitions.
- `src/RelayClock.php` — timezone conversion and DST validation.
- `src/RelayAgentApi.php` — permission-gated operational facade.
- `src/RelayRestApi.php` — REST transport, authentication, rate limits and envelopes.
- `bin/relay.php` — production CLI worker.
- `bin/relay-interface` — optional local JSON interface.
- `assets/relay.css` and `assets/relay.js` — planning and Interfaces UI.
- `assets/relay-config.css` and `assets/relay-config.js` — module settings UI.
- `languages/*.csv` — bundled ProcessWire admin translations.

Keep the Process module as a small composition root. Add behavior to the trait that owns its domain; create a new trait only for a cohesive boundary that can move without duplicating state, weakening API clarity or changing Relay's ProcessWire translation textdomain.

## Verification

Run checks proportionate to risk. For runtime changes use the full suite:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/RelayClockTest.php
php tests/RelayModuleInfoTest.php
php tests/RelayStoreFilterTest.php
node --test tests/RelayUiTest.mjs
php tests/RelayTranslationTest.php
php tests/ProcessWireIntegration.php --run --root=/path/to/processwire
```

Also validate the affected admin pages, permission boundaries, imitation mode, worker error path and optional integration failure path. Record deviations, remaining risk and rollback steps.

## Release And Repository Rules

- Keep the module reusable; never add LQRS-specific fields, templates or paths.
- Preserve unrelated work and inspect the final diff.
- Update version, CHANGELOG, README, API and tests when behavior changes.
- Never commit secrets, databases, uploads, environment configuration, credentials or development-only site files.
- Synchronize released runtime files into known consuming sites without copying `.git/`, AGENTS.md, tests or release tooling into a public document root.
