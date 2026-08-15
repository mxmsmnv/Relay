# Relay Public API

Relay 1.0.0 provides permission-aware scheduling calls, a bounded worker, a PHP operational facade, optional REST and local CLI transports, read-only calendar subscriptions, scheduling quick presets, and preview-first job transfer.

Use this file as the canonical source for Relay calls. Verify the installed version and live configuration before use; documentation does not prove that a channel is enabled on a particular site.

## Compatibility And Stability

- ProcessWire 3.0.244+
- PHP 8.2+
- Module version `100` = release `1.0.0`
- REST API `v1`
- Transfer schema `relay.jobs`, version `1`

Stable site-facing methods are documented below. `operational*()` methods, `RelayStore`, `RelayRestApi`, admin `___execute*()` routes and private helpers are internal implementation surfaces even where PHP visibility is public for ProcessWire routing or facade delegation.

Relay 1.0.0 exposes no stable external hook event. Normal ProcessWire `Pages::save` hooks still run when a worker publishes or unpublishes a page.

## Obtain The Module

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('Relay')) {
    /** @var Relay $relay */
    $relay = $modules->get('Relay');
}
```

Inside a module, prefer the injected API:

```php
$relay = $this->wire()->modules->get('Relay');
```

The current requester must have `relay-manage`, be able to edit the page and be allowed to perform the requested publish/unpublish action. Passing another editorial identity requires `relay-run-as`; that identity must match configured roles and is checked again at execution.

## Schedule One Action

```php
$jobId = $relay->scheduleAction(
    $page,
    'publish',
    new \DateTimeImmutable('2026-09-01 09:00', new \DateTimeZone('Europe/Berlin')),
    null,
    'Autumn campaign'
);
```

```php
public function scheduleAction(
    Page $page,
    string $action,
    \DateTimeImmutable $when,
    ?User $runAs = null,
    string $note = ''
): int
```

Rules:

- `$action` is `publish` or `unpublish`;
- `$when` must carry a valid IANA timezone and resolve to a future instant inside `max_future_years`;
- `$note` is trimmed to 500 characters;
- a new pending page/action pair supersedes the existing pending pair;
- the return value is a positive database ID, or a negative session-only ID in imitation mode.

## Schedule A Publication Window

```php
$ids = $relay->schedulePublicationWindow(
    $page,
    new \DateTimeImmutable('2026-09-01 09:00', new \DateTimeZone('Europe/Berlin')),
    new \DateTimeImmutable('2026-09-08 18:00', new \DateTimeZone('Europe/Berlin')),
    null,
    'Seven-day campaign'
);
```

```php
public function schedulePublicationWindow(
    Page $page,
    \DateTimeImmutable $publishAt,
    \DateTimeImmutable $unpublishAt,
    ?User $runAs = null,
    string $note = ''
): array
```

Both values must use the same timezone and unpublish must be later than publish. Real creation is transactional. The result is:

```php
[
    'publish' => 123,
    'unpublish' => 124,
]
```

## Run Due Work

```php
$result = $relay->runDue(25, 'site-maintenance');
```

```php
public function runDue(?int $limit = null, ?string $executor = null): array
```

Result:

```php
[
    'claimed' => 3,
    'completed' => 3,
    'failed' => 0,
    'processed' => 3,
]
```

`runDue()` is a trusted worker method and does not provide a web authorization boundary by itself. Do not call it from anonymous templates or public requests. Use `bin/relay.php` for production cron. The operational facade additionally requires `relay-run` before delegating to it.

## Imitation Mode Semantics

When `imitation_mode=1`:

- direct scheduling still validates user, page, action, identity, timezone and horizon;
- jobs exist only in the current ProcessWire user session;
- IDs are negative and not durable;
- `runDue()` updates demo job state without changing pages;
- CLI has no editor session and processes no demo jobs;
- real jobs are not read or mutated;
- external notifications are suppressed.

Do not persist imitation IDs or treat imitation mode as an integration environment for durable consumers.

## Capabilities

```php
$capabilities = $relay->capabilities();
```

The result declares provider `Relay`, capability version `1.0.0`, REST API version, numeric module version, imitation state, channel readiness and named capabilities for job reads/writes, worker execution, notifications and Squad planning.

Channel booleans describe current module configuration and feature readiness. They are not permission grants for the current caller.

## Operational PHP Facade

The facade is disabled until `enable_agent_api=1`. Its actor must be a superuser or hold both `relay-api` and `relay-manage`. Running due work also requires `relay-run`.

```php
/** @var User $actor */
$api = $relay->api($actor);

$capabilities = $api->capabilities();
$counts = $api->counts();
$jobs = $api->jobs([
    'from' => '2026-08-01 00:00:00',
    'to' => '2026-12-01 00:00:00',
    'page_id' => 456,
    'status' => 'scheduled',
    'action' => 'publish',
    'limit' => 100,
]);
$job = $api->job(123);

$created = $api->schedule([
    'page_id' => 456,
    'action' => 'publish',
    'scheduled_at' => '2026-09-01 09:00',
    'timezone' => 'Europe/Berlin',
    'run_as_user_id' => 41,
    'note' => 'Campaign',
]);

$window = $api->schedule([
    'page_id' => 456,
    'action' => 'window',
    'scheduled_at' => '2026-09-01 09:00',
    'scheduled_until' => '2026-09-08 18:00',
    'timezone' => 'Europe/Berlin',
]);

$updated = $api->reschedule(123, [
    'scheduled_at' => '2026-09-02 09:00',
    'timezone' => 'Europe/Berlin',
]);
$cancelled = $api->cancel(123);
$result = $api->runDue(25);
```

Facade methods:

```php
canRead(): bool
canWrite(): bool
canAdmin(): bool
capabilities(): array
counts(): array
jobs(array $filters = []): array
job(int $id): array
schedule(array $data): array
reschedule(int $id, array $data): array
cancel(int $id): array
runDue(?int $limit = null): array
```

Lists are bounded to 500 jobs. Filters accept `from`, `to`, `page_id`, `status`, `action` and `limit`. Status is one of `scheduled`, `processing`, `completed`, `failed`, `cancelled` or `superseded`.

Job DTOs contain operational IDs, page metadata, action/status, UTC and local schedule values, timezone, note, last error, attempts, requester, editorial identity, executor and timestamps. They are staff-only data; do not expose them to untrusted frontend users.

## REST API v1

Enable both `enable_agent_api` and `enable_rest_api` to register:

```text
/relay-api/v1/{resource}/
```

Resources:

| Method | Resource | Purpose |
| --- | --- | --- |
| `GET` | `session` | login/readiness and session CSRF token |
| `GET` | `capabilities` | channel and capability declaration |
| `GET` | `counts` | job status totals |
| `GET` | `jobs` | bounded filtered jobs |
| `GET` | `job?id=123` | one job |
| `POST` | `schedule` | action or publication window |
| `POST` | `reschedule` | move a scheduled job |
| `POST` | `cancel` | cancel a scheduled job |
| `POST` | `run` | run a bounded due batch |

Authentication and boundaries:

- session reads use the current ProcessWire login;
- session mutations require the `relay-rest` CSRF token returned by `GET session/`, in JSON or its named `X-...` header;
- Bearer requests use `Authorization: Bearer …` and act as the configured eligible user;
- Relay stores only the Bearer token's SHA-256 hash and displays a rotated token once;
- JSON mutation bodies require `Content-Type: application/json` and are limited to 64 KiB;
- reads and writes have separate rate limits;
- responses are `private, no-store` and Relay emits no permissive CORS header.

Success envelope:

```json
{"ok":true,"api_version":"v1","result":{}}
```

Error envelope:

```json
{"ok":false,"api_version":"v1","error":"Redacted message"}
```

## Local Interface CLI

`bin/relay-interface` is disabled until `enable_interface_cli=1`. It boots the local ProcessWire installation, acts as its superuser and returns JSON.

Commands:

```text
capabilities
counts
jobs
job
schedule
reschedule
cancel
run
```

Every mutation requires `--execute`. Schedule and reschedule accept a bounded JSON object through `--stdin`. This is a host-local administrative interface, not the production scheduler.

## Import And Export

```php
public function exportJobs(User $actor, string $scope = 'scheduled'): array
public function importJobs(User $actor, string $json, bool $execute = false): array
```

The actor must be a superuser or hold both `relay-admin` and `relay-manage`.

`exportJobs()` accepts `scheduled` or `all`, returns at most 500 records and includes schema/version, source mode, page ID/path/title, action, status, UTC schedule, timezone, editorial identity and note. It excludes credentials, page content, delivery data, worker lock tokens and stored errors.

`importJobs()` is preview-first:

```php
$preview = $relay->importJobs($actor, $json);        // no write
$applied = $relay->importJobs($actor, $json, true);  // explicit apply
```

It repeats validation on apply. Bounds are 1 MiB and 500 rows. Only future `scheduled` actions are imported; history rows are reported as skipped. It validates page/path resolution, allowed templates, editability, identities, actions, timezone, horizon and duplicate page/action pairs. Applying a real import follows the standard superseding rule.

## Integration Readiness Methods

These methods are network-free/read-only unless noted:

```php
public function mailIntegrationStatus(): array
public function telegramIntegrationStatus(): array
public function telegramNotificationEvents(): array
public function squadIntegrationStatus(): array
public function squadModelOptions(): array
public function canAdmin(?User $user = null): bool
```

`telegramIntegrationStatus()` verifies TeleWire installation/API compatibility, readiness, recipient count, credential source and events without returning credentials.

`squadIntegrationStatus()` verifies Squad installation, active-provider readiness, configured provider/model, context fields and timeout without returning provider keys. `squadModelOptions()` reads active provider/model choices through Squad's discovery methods.

The page-editor Squad action is not an automatic scheduler. After an explicit editor click, Relay sends only configured bounded scalar fields, selected action, timezone and draft dates. The validated proposal fills the form; scheduling still requires a separate editor action.

## Notification Events

Email and Telegram may select:

```text
published
scheduled
rescheduled
cancelled
completed
failed
```

`published` is emitted only when a successful publish action actually changes an unpublished page to published. Operational notification payloads include event, job ID, action, page title, local schedule time, status and authenticated admin link. They exclude notes, page content and credentials. Delivery failure never rolls back the job transition.

## Scheduling Rules

Scheduling rules are managed through **Setup → Relay → Rules** by a `relay-admin` actor. They provide template-linked recurring editorial slots to the page planner but are not a public scheduling API and do not create jobs on their own.

The serialized `scheduling_rules` module configuration is internal. Site code and agents must not construct or modify its JSON directly; use the admin workspace. A matching rule supplies the next unused slot, action, optional publication-window duration and note, after which the editor reviews and explicitly creates one normal Relay job or window.

Quick presets are managed on the same page and stored in the internal `relay_presets` table. Each one provides a bounded interval and recurrence unit; applying it changes only those two editable cadence fields. `RelayStore::presets()`, `savePreset()` and `deletePreset()` are internal APIs; site code must not call them or write the table directly.

## Read-only Calendar Subscription

An administrator manages the optional feed through **Setup → Relay → Interfaces → Calendar feed**. The public route is a bearer URL shaped as `/relay-calendar/{secret}.ics` and returns `text/calendar` using the iCalendar 2.0 format. It is an internal transport surface, not a stable PHP method and not a CalDAV or provider API integration.

The feed is disabled by default. Generation creates 256 random bits, reveals the URL once and persists only its SHA-256 hash. Pause retains the credential; rotation invalidates the previous URL; revocation disables the route and removes the hash. The route never accepts writes and never grants a ProcessWire identity or permission.

Exports are bounded to configured past/future ranges and include scheduled, processing and completed publication actions. Titles and publicly viewable page links are independent opt-ins. Internal notes, users, execution errors, credentials, unpublished fields and page content are always excluded. Imitation mode returns a valid empty calendar. Keep the URL secret, require HTTPS and assume it may be retained in provider or web-server access logs. Calendar-client refresh frequency is controlled by the client, not Relay.

## Configuration Keys

Read configuration through ProcessWire's module configuration APIs. Do not assume a documented default is the current live value.

| Group | Keys | Defaults / boundary |
| --- | --- | --- |
| Planning | `timezone`, `default_view`, `week_starts_on`, `highlight_weekends`, `default_time`, `time_presets`, `scheduling_rules`, `max_future_years` | UTC, month, Monday, weekends on, 09:00, four named presets, empty rule library, 5 years |
| Calendar UI | `enable_template_controls`, `show_page_tree_markers`, `enable_drag_drop` | enabled |
| Scope | `allowed_templates`, `actor_roles` | all editable non-admin templates; `editor` role |
| Worker | `max_batch`, `max_attempts`, `stale_minutes` | 50, 3, 15 |
| Scheduling | `lazy_cron_fallback`, `cron_interval_minutes`, `cron_php_binary`, `cron_log_path` | fallback off; 1 minute; detected PHP; `site/assets/logs/relay-cron.log` |
| Safety | `enable_logging`, `imitation_mode` | logging on; imitation off |
| APIs | `enable_agent_api`, `enable_rest_api`, `enable_interface_cli` | all off |
| REST credential | `rest_bearer_token_hash`, `rest_bearer_user_id`, `rest_bearer_token_created_at` | empty/unassigned; rotate through admin UI |
| Calendar feed | `calendar_feed_enabled`, `calendar_feed_token_hash`, `calendar_feed_token_created_at`, `calendar_feed_include_titles`, `calendar_feed_include_links`, `calendar_feed_past_days`, `calendar_feed_future_days` | off; no credential; titles/links hidden; 30 days past and 365 days future |
| Email | `mail_notifications_enabled`, `mail_module`, `mail_recipients`, `mail_from_email`, `mail_from_name`, `mail_notification_events` | off; failures only |
| Telegram | `telegram_notifications_enabled`, `telegram_bot_token`, `telegram_chat_ids`, `telegram_notification_events`, `telegram_timeout_seconds` | off; published + failed; 10 seconds |
| Squad | `enable_squad_assistance`, `squad_provider_model`, `squad_context_fields`, `squad_system_prompt`, `squad_timeout_seconds` | off; title only; 18 seconds |

Runtime Telegram overrides, when explicitly configured by the site, take precedence:

```text
relayTelegramBotToken / RELAY_TELEGRAM_BOT_TOKEN
relayTelegramChatIds / RELAY_TELEGRAM_CHAT_IDS
```

Never print or log credential values.

## Errors And Edge Cases

Documented calls may throw:

- `WirePermissionException` for missing Relay or page permissions;
- `WireException` for invalid state, page, identity, date, horizon, import or worker conditions;
- `InvalidArgumentException` for unsupported actions, filters, scopes or malformed input;
- `Wire404Exception` for unavailable jobs through operational calls.

Deleted, trashed, inaccessible or permission-incompatible pages fail closed. Disabled/deleted editorial identities fail closed. Failed jobs retry up to `max_attempts`; a stale `processing` lease is recovered after `stale_minutes`.

## Internal APIs To Avoid

Do not call or depend on:

- `RelayStore` or raw `relay_jobs` SQL;
- raw `relay_presets` SQL or internal preset storage methods;
- `operational*()` methods directly instead of `api()`;
- admin `___execute*()` endpoints;
- Relay session keys or transfer-preview state;
- private imitation, rendering, identity, logging or notification helpers;
- REST transport classes from templates;
- generated admin URLs as stable public routes.

No API is deprecated in Relay 1.0.0.
