## Purpose delta

The integration-calendar leaf gains its inbound half: Calendar-side changes to linked or `X-OPENREGISTER-*`-tagged VEVENTs flow back into the `openregister_calendar_links` table, linking becomes idempotent with a reverse lookup, the legacy backfill job becomes an active registered one-shot, and the leaf can author calendar-native reminders (VALARM) on the events it creates. Consumer apps keep a single rule: never touch CalDAV, the leaf is complete in both directions.

## ADDED Requirements

### Requirement: Inbound calendar changes refresh the link table (REQ-CAL-INB-001)

The system SHALL register event listeners for `OCA\DAV\Events\CalendarObjectCreatedEvent`, `CalendarObjectUpdatedEvent`, `CalendarObjectMovedEvent`, and `CalendarObjectDeletedEvent`. For a VEVENT that carries `X-OPENREGISTER-*` properties or whose UID matches an existing link row: on create the system SHALL ensure a link row exists (idempotently, per REQ-CAL-INB-003); on update or move it SHALL refresh the cached `summary`, `dtstart`, `dtend`, `location`, `calendarId`, `calendarUri`, and `eventUri` fields of every matching link row; on deletion of the VEVENT it SHALL delete the matching link rows. Listener failures SHALL be logged and SHALL never abort the calendar operation itself. VEVENTs with neither tag nor link row SHALL be ignored at negligible cost.

#### Scenario: Meeting moved in Nextcloud Calendar updates the cached times

- **GIVEN** a link row for event UID `E1` cached with `dtstart` 10:00
- **WHEN** the user drags the event to 14:00 in the Calendar app and the `CalendarObjectUpdatedEvent` fires
- **THEN** the link row's `dtstart`/`dtend` SHALL reflect the new times
- **AND** a consumer reading `getLinkedEvents` SHALL see 14:00 without any rescan

`@e2e exclude backend DAV event listener; asserted via PHPUnit listener tests, no UI surface of its own`

#### Scenario: Event deleted in Nextcloud Calendar removes the link

- **GIVEN** a link row for event UID `E1`
- **WHEN** the VEVENT is deleted in the Calendar app
- **THEN** the link rows for `E1` SHALL be deleted
- **AND** the object's events read SHALL no longer include `E1`

`@e2e exclude backend DAV event listener; asserted via PHPUnit listener tests, no UI surface of its own`

#### Scenario: Tagged event created Calendar-side gains a link row

- **GIVEN** a VEVENT created in the Calendar app carrying `X-OPENREGISTER-REGISTER/SCHEMA/OBJECT` properties
- **WHEN** the `CalendarObjectCreatedEvent` fires
- **THEN** a link row SHALL exist for that object/event pair without any consumer-app action

`@e2e exclude backend DAV event listener; asserted via PHPUnit listener tests, no UI surface of its own`

---

### Requirement: Reverse event-to-objects lookup (REQ-CAL-INB-002)

The system SHALL provide `CalendarLinkMapper::findByEventUid(string $eventUid, ?string $calendarUri = null): array` and a service surface `CalendarLinkService::getObjectsForEvent(string $eventUid, ?string $calendarUri = null): array` returning the link rows (and thereby object UUIDs) attached to a VEVENT. The inbound listeners SHALL use this lookup; it SHALL be scoped by `calendarUri` when provided to disambiguate UID reuse across calendars.

#### Scenario: Listener resolves the objects behind an updated event

- **GIVEN** two objects linked to event UID `E1`
- **WHEN** the update listener processes `E1`
- **THEN** `getObjectsForEvent('E1')` SHALL return both link rows and both SHALL be refreshed

`@e2e exclude mapper/service API; covered by PHPUnit, no UI surface`

---

### Requirement: Linking is idempotent (REQ-CAL-INB-003)

`CalendarLinkService::linkEvent` and `createAndLinkEvent` SHALL NOT create a second row for an identical `(objectUuid, eventUid, calendarUri)` triple: a repeated call SHALL return the existing row unchanged. A migration SHALL deduplicate existing rows (keeping the oldest) and add a unique index on the triple. The HTTP link route (`POST /api/objects/{register}/{schema}/{id}/events/link`) SHALL inherit this behaviour and SHALL respond successfully with the existing link on a repeat.

#### Scenario: Repeated link call returns the existing row

- **GIVEN** an existing link row for object `O1` and event `E1`
- **WHEN** `linkEvent` is called again for the same pair on the same calendar
- **THEN** no new row SHALL be inserted
- **AND** the returned entity SHALL be the existing row

`@e2e exclude service-level idempotency; covered by PHPUnit including the dedup migration`

#### Scenario: Duplicate rows from the past are collapsed by the migration

- **GIVEN** a pre-migration table containing two rows for the same `(objectUuid, eventUid, calendarUri)`
- **WHEN** the migration runs
- **THEN** exactly one row (the oldest) SHALL remain and the unique index SHALL be in place

`@e2e exclude one-time migration; covered by a migration PHPUnit test`

---

### Requirement: Backfill job is registered and enabled by default (REQ-CAL-INB-004)

`BackfillCalendarLinksJob` SHALL be registered as a background job so it is schedulable without manual `occ background-job:execute`, and the `backfill_calendar_links` app-config flag SHALL default to enabled, running the backfill once per instance after upgrade (the job's existing `findByObjectAndEvent` pre-check keeps re-runs idempotent). Setting the flag to `no` SHALL remain the kill switch and SHALL be honoured before any scan. The job SHALL keep its per-user scope (`callForSeenUsers`) and SHALL log inserted/skipped counts.

#### Scenario: Upgrade backfills legacy tagged events

- **GIVEN** an instance upgrading with X-OPENREGISTER-tagged VEVENTs that have no link rows
- **WHEN** the registered backfill job runs after upgrade
- **THEN** link rows SHALL exist for those events with `linkedBy = 'system:backfill'`
- **AND** a second run SHALL insert nothing (all skipped)

`@e2e exclude background job; covered by PHPUnit job tests and occ smoke run in dev`

#### Scenario: Kill switch is honoured

- **GIVEN** app-config `backfill_calendar_links` set to `no`
- **WHEN** the job runs
- **THEN** it SHALL log the skip and scan nothing

`@e2e exclude background job flag guard; covered by PHPUnit`

---

### Requirement: Created events can carry a reminder (REQ-CAL-INB-005)

`CalendarEventService::createEvent` SHALL accept an optional integer `reminderMinutesBefore` in its `$data` array and, when present and positive, SHALL emit a `VALARM` component (`ACTION:DISPLAY`, `TRIGGER:-PT{n}M`, `DESCRIPTION` = the event summary) inside the created VEVENT. The event array shape returned by the read paths SHALL expose the reminder as `reminderMinutesBefore` when a display alarm is present. Alarm delivery SHALL remain the calendar stack's responsibility; the leaf authors the alarm and SHALL NOT implement its own notification dispatch for it.

#### Scenario: Follow-up created with a reminder rings natively

- **GIVEN** a consumer app creates a follow-up event via the leaf with `reminderMinutesBefore: 30`
- **WHEN** the VEVENT is stored
- **THEN** it SHALL contain a `VALARM` with `TRIGGER:-PT30M`
- **AND** the user's calendar clients SHALL raise the reminder through their native alarm handling

`@e2e exclude VALARM authoring; asserted by PHPUnit on the serialized VEVENT — alarm firing is the calendar stack's, not ours`

#### Scenario: No reminder requested, no alarm written

- **GIVEN** `createEvent` is called without `reminderMinutesBefore`
- **WHEN** the VEVENT is stored
- **THEN** it SHALL contain no `VALARM` component

`@e2e exclude VALARM authoring; asserted by PHPUnit on the serialized VEVENT`
