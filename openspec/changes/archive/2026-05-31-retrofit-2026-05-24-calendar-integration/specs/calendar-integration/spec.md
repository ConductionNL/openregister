---
retrofit: true
---

# Calendar Integration

## ADDED Requirements

### REQ-003: The system MUST expose a REST link/unlink flow that ties CalDAV VEVENTs to OpenRegister objects

`CalendarEventsController` MUST offer per-object endpoints that create a new VEVENT in the authenticated user's primary VEVENT-capable calendar and that remove the OR linkage from an existing VEVENT. The created event MUST carry `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA` and `X-OPENREGISTER-OBJECT` custom properties plus an RFC 9253 `LINK;LINKREL="related"` pointing back to the OR object so the link survives outside the OR UI. When an OR object is destroyed, `CalendarEventService::unlinkEventsForObject()` MUST iterate every linked VEVENT and remove the OR linkage without deleting the VEVENT itself (events are owned by the user, not by OR).

This flow is distinct from REQ-001: REQ-001 surfaces objects read-only as a virtual CalDAV calendar; REQ-003 mutates the user's own calendar.

#### Scenario: Create endpoint requires a summary
- **GIVEN** an authenticated user calls `POST /apps/openregister/api/objects/{register}/{schema}/{id}/events` with body `{}`
- **WHEN** `CalendarEventsController::create()` runs
- **THEN** the response MUST be `400` with body `{"error": "Event summary is required"}`
- **AND** no calendar object MUST be created

#### Scenario: Create endpoint persists VEVENT with X-OPENREGISTER linking
- **GIVEN** an authenticated user calls the create endpoint with `summary`, `dtstart`, `dtend`
- **AND** the user has a VEVENT-capable calendar
- **WHEN** `CalendarEventService::createEvent()` runs
- **THEN** a new `.ics` file MUST be written to the user's calendar via `CalDavBackend::createCalendarObject()`
- **AND** the VEVENT body MUST contain `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT` lines
- **AND** the response MUST be `201` with the event in JSON-friendly form (`id`, `uid`, `calendarId`, `summary`, `dtstart`, `dtend`, `objectUuid`)

#### Scenario: Destroy endpoint requires the event to be currently linked to the object
- **GIVEN** the user calls `DELETE /apps/openregister/api/objects/{register}/{schema}/{id}/events/{eventId}` for an `eventId` that is not in the per-object event list
- **WHEN** `CalendarEventsController::destroy()` runs
- **THEN** the response MUST be `404` with body `{"error": "Event not found"}`

#### Scenario: Destroy endpoint removes X-OPENREGISTER linking but keeps the VEVENT
- **GIVEN** the user destroys a linked event
- **WHEN** `CalendarEventService::unlinkEvent()` runs (called from the controller)
- **THEN** the VEVENT MUST remain in the user's calendar
- **AND** `X-OPENREGISTER-REGISTER`, `X-OPENREGISTER-SCHEMA`, `X-OPENREGISTER-OBJECT` MUST be unset from the VEVENT
- **AND** any `LINK` property whose value contains `openregister` MUST be removed

#### Scenario: Object deletion sweeps all linked events
- **GIVEN** an OR object UUID has three CalDAV events linked via X-OPENREGISTER-OBJECT
- **WHEN** `CalendarEventService::unlinkEventsForObject($objectUuid)` runs
- **THEN** each event MUST be passed to `unlinkEvent()`
- **AND** a failure to unlink one event MUST be logged via `LoggerInterface::warning()` and MUST NOT abort the loop
- **AND** the VEVENTs MUST remain in the user's calendars

### REQ-004: The system MUST expose calendar links through the unified IntegrationProvider registry

`CalendarProvider` MUST implement `AbstractIntegrationProvider` so the `CnObjectSidebar` "Meetings" tab and dashboard widgets can render calendar-linked events without per-app glue. The provider MUST declare its identity (`getId()` returns `'calendar'`, `getLabel()` returns the localised "Meetings", `getIcon()` returns `'Calendar'`, `getGroup()` returns `'comms'`), MUST declare `getRequiredApp()` as `'calendar'`, MUST declare `getStorageStrategy()` as `'link-table'` (a registry-routing convention, not a literal description — see Notes), and MUST gate `isEnabled()` on `IAppManager::isInstalled('calendar')`. The provider MUST surface the linked events via `list()` and MUST accept inline unlinks via `delete()` using the composite `"calendarId/eventUri"` entity id.

#### Scenario: list() returns per-object events from CalendarEventService
- **GIVEN** an OR object UUID with two linked VEVENTs
- **WHEN** the registry calls `CalendarProvider::list($register, $schema, $objectUuid)`
- **THEN** `CalendarEventService::getEventsForObject($objectUuid)` MUST be invoked
- **AND** its return value MUST be returned unchanged

#### Scenario: list() degrades to empty array on CalDAV failure (AD-23)
- **GIVEN** the CalDAV backend throws (no user session, no VEVENT calendar, etc.)
- **WHEN** `CalendarProvider::list()` runs
- **THEN** the exception MUST be caught and `[]` MUST be returned
- **AND** the registry consumer MUST NOT receive the failure

#### Scenario: delete() rejects non-composite entityIds
- **GIVEN** the registry calls `CalendarProvider::delete($register, $schema, $objectId, 'bad-id-no-slash')`
- **WHEN** the method runs
- **THEN** `InvalidArgumentException` MUST be thrown with message `'Calendar entityId must be "calendarId/eventUri"'`

#### Scenario: delete() forwards to CalendarEventService::unlinkEvent
- **GIVEN** `entityId = "42/event-uid-123.ics"`
- **WHEN** `CalendarProvider::delete()` runs
- **THEN** `CalendarEventService::unlinkEvent(calendarId: '42', eventUri: 'event-uid-123.ics')` MUST be called exactly once

#### Scenario: health() reports installed/uninstalled
- **GIVEN** the NC `calendar` app is installed
- **WHEN** `CalendarProvider::health()` is called
- **THEN** the result MUST be `['status' => 'ok', 'authStatus' => 'configured', 'message' => null]`
- **AND** when the app is not installed the result MUST be `['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Calendar app is not installed']`

### REQ-005: The system MUST resolve VEVENT STATUS from object data via a configurable status mapping

`CalendarEventTransformer::resolveStatus()` MUST translate an OR object's status field into one of the iCalendar VEVENT `STATUS` values (`CONFIRMED`, `CANCELLED`, `TENTATIVE`) using a `statusMapping` lookup from the calendar configuration. When mapping or status field is absent, when the object's status value is null, or when the value is not in the mapping, the result MUST default to `CONFIRMED`. This requirement extends REQ-002 which is otherwise silent on the STATUS property.

#### Scenario: Default to CONFIRMED when statusField is unset
- **GIVEN** `calendarConfig` has no `statusField` or no `statusMapping`
- **WHEN** `resolveStatus()` runs
- **THEN** the return MUST be `'CONFIRMED'`

#### Scenario: Default to CONFIRMED when object data lacks the status field
- **GIVEN** `calendarConfig = ['statusField' => 'state', 'statusMapping' => ['open' => 'CONFIRMED']]`
- **AND** the object data has no `state` key (resolves to `null`)
- **WHEN** `resolveStatus()` runs
- **THEN** the return MUST be `'CONFIRMED'`

#### Scenario: Map known status to VEVENT STATUS value
- **GIVEN** `calendarConfig = ['statusField' => 'state', 'statusMapping' => ['cancelled' => 'CANCELLED', 'draft' => 'TENTATIVE']]`
- **AND** the object has `state: 'cancelled'`
- **WHEN** `resolveStatus()` runs
- **THEN** the return MUST be `'CANCELLED'`

#### Scenario: Unknown status falls back to CONFIRMED
- **GIVEN** `calendarConfig = ['statusField' => 'state', 'statusMapping' => ['draft' => 'TENTATIVE']]`
- **AND** the object has `state: 'reopened'` (not in the mapping)
- **WHEN** `resolveStatus()` runs
- **THEN** the return MUST be `'CONFIRMED'`

### REQ-006: The system MUST provide a schema-level admin UI to edit calendar provider configuration

The Schema editor MUST surface a "Calendar Provider" tab (`CalendarProviderTab.vue`) that reads and writes `schema.configuration.calendarProvider` — the same object shape REQ-001 (provider), REQ-002 (transformer) and REQ-005 (status) consume at runtime. The tab MUST hydrate its form from the schema on mount and on every schema prop change, MUST default missing keys to safe values (`enabled=false`, `color='#0082C9'`, `dtstart=null`, `dtend=null`, `titleTemplate=''`, `descriptionTemplate=''`, `locationField=null`, `allDay=null` for auto-detect), and MUST persist via `schemaStore.saveSchema()` so existing schema configuration is preserved.

#### Scenario: Tab hydrates from schema.configuration.calendarProvider on mount
- **GIVEN** a schema is loaded with `configuration.calendarProvider = { enabled: true, color: '#FF0000', dtstart: 'createdAt' }`
- **WHEN** `CalendarProviderTab.loadConfig(schema)` runs (via the immediate watcher)
- **THEN** `localConfig.enabled` MUST be `true`, `localConfig.color` MUST be `'#FF0000'`, `localConfig.dtstart` MUST be `'createdAt'`
- **AND** every other field MUST take its default

#### Scenario: Tab hydrates with safe defaults when configuration is missing
- **GIVEN** a schema with no `configuration.calendarProvider`
- **WHEN** `loadConfig()` runs
- **THEN** `localConfig` MUST equal `{ enabled: false, displayName: '', color: '#0082C9', dtstart: null, dtend: null, titleTemplate: '', descriptionTemplate: '', locationField: null, allDay: null }`

#### Scenario: Reloading the tab on schema change does not leak previous state
- **GIVEN** the tab is mounted with schema A (calendarProvider.enabled = true)
- **WHEN** the `schema` prop changes to schema B which has no `calendarProvider` block
- **THEN** the `schema` watcher MUST call `loadConfig(schemaB)`
- **AND** `localConfig.enabled` MUST be `false`

#### Notes
- `formatDateValue()` always emits a `Z` suffix on DATE-TIME values regardless of the source string's timezone (observed drift; REQ-002 is silent on tz handling — flagged for future REQ).
- `CalendarProvider::getStorageStrategy()` returns `'link-table'` as a registry-routing classification only. The actual storage is CalDAV custom properties on a VEVENT (see REQ-003 and the file's class-level docblock).
- `CalendarProvider::list()` swallows all `Throwable` per AD-23 (graceful degrade); the empty array is indistinguishable from "no events linked" at the UI layer (observed; REQ-004 documents this as the contract).
- REQ-006's tab is read-write for the configuration document only — it does not create or delete CalDAV calendars; that remains the responsibility of REQ-001's provider.
