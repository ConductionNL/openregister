# Retrofit — calendar-integration (extend)

Describes observed behavior of 15 methods across 6 files in the calendar cluster as 4 new REQs extending the existing `calendar-integration` capability. Code already exists — this change retroactively specifies it. Triaged drops from the batch (12 methods) are recorded in the batch JSON, not re-specified here.

## Affected code units
- lib/Calendar/CalendarEventTransformer.php::resolveStatus
- lib/Controller/CalendarEventsController.php::create
- lib/Controller/CalendarEventsController.php::destroy
- lib/Service/CalendarEventService.php::unlinkEventsForObject
- lib/Service/Integration/Providers/CalendarProvider.php (getId, getLabel, getIcon, getGroup, getRequiredApp, getStorageStrategy, isEnabled, list, delete, health) — 10 methods, 1 file (getStorageStrategy counted once)
- src/views/schema/CalendarProviderTab.vue::loadConfig

## Approach
The existing capability covers two layers: REQ-001 the CalDAV provider (`RegisterCalendarProvider`) and REQ-002 the iCalendar transformer (`CalendarEventTransformer::transform()`). The kept methods in this cluster sit in four additional behavioural slices that are not yet specified:

- **REQ-003** — REST link/unlink flow for users who want to attach a normal CalDAV VEVENT (in their own calendar) to an OR object. `CalendarEventsController::create` and `::destroy` plus the `CalendarEventService::unlinkEventsForObject` cleanup helper define a path that is independent of `RegisterCalendarProvider` (which is a read-only CalDAV exposure layer per REQ-001's notes).
- **REQ-004** — `CalendarProvider` exposes that link/unlink flow through the unified `IntegrationProvider` registry so the `CnObjectSidebar` "Meetings" tab and dashboard widgets can render uniformly across integrations.
- **REQ-005** — Resolving the VEVENT `STATUS` from object data via a configurable status mapping (the only transformer slice not covered by REQ-002, which is silent on status).
- **REQ-006** — Schema-level admin UI for editing the calendar provider configuration that REQ-001/REQ-002/REQ-005 all read from.

## Notes / observed drifts
- `CalendarEventTransformer::formatDateValue()` always appends `Z` (UTC) to the DATE-TIME output regardless of the source string's timezone. REQ-002 does not call this out; flagged here as observed.
- `CalendarProvider::list()` swallows all `Throwable` and returns `[]`. This matches AD-23 ("graceful degrade") as documented in the file but is worth surfacing in REQ-004 because a silent empty list is indistinguishable from "no events linked" at the UI layer.
- `CalendarProvider::getStorageStrategy()` returns `'link-table'` even though events are physically stored as CalDAV custom properties — the file's own docblock acknowledges this is a registry-routing convention, not a literal description.
- `CalendarEventService::createEvent()` and `linkEvent()` are triaged-DROP in the batch but are described indirectly by REQ-003 (controller→service contract); their detailed behaviour belongs to a separate retrofit if/when needed.

Source: openspec/coverage-report.md — Bucket 2a (extend) for `calendar-integration`. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
