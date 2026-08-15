# Changelog

## 1.0.0 — 2026-08-15

First public release of Relay.

- Expose the ProcessWire module version as a literal integer so the modules directory can index it statically.
- Install Relay-owned tables and permissions directly from a clean schema.
- Schedule publish and unpublish actions or atomic publication windows for ProcessWire pages.
- Plan work from the page editor and month, week, quarter, three-day, Kanban and timeline workspaces.
- Inspect complete publication details and reschedule with exact controls or drag-and-drop with one-step undo.
- Filter by action, status and template; configure template ordering, week starts and weekend highlighting.
- Use named Publication Times for one-time actions and template-linked Rules with reusable recurrence presets.
- Preserve requester, editorial identity and worker executor attribution with UTC storage and timezone snapshots.
- Execute bounded batches through a lease-protected CLI worker with retries, stale-lock recovery and optional LazyCron fallback.
- Test workflows in session-only imitation mode without changing pages, jobs or external systems.
- Enable PHP, REST and local JSON CLI operational interfaces independently.
- Preview JSON imports before applying them and export bounded scheduled or historical job documents.
- Send optional operational notifications through WireMail and TeleWire, or request bounded planning proposals from Squad.
- Publish a revocable, read-only iCalendar feed for Google Calendar, Apple Calendar and compatible clients.
- Provide configurable audit logging, permissions, secure credential handling and safe uninstall boundaries.
- Include 48 European translations, Olivia-oriented API and agent documentation, sponsorship metadata and an MIT license.
- Ship a compact ProcessWire composition root with cohesive runtime domains under `src/Traits`.
