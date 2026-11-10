---
retrofit: true
---

# Calendar Integration

## Purpose

OpenRegister can surface register objects as Nextcloud calendar events by implementing the `ICalendarProvider` contract. Objects from enabled schemas are exposed as iCalendar events (VEVENT) via CalDAV, allowing any CalDAV client (Nextcloud Calendar app, iOS/Android) to subscribe to, view, and filter them. This capability integrates with the object-interactions spec's broader "objects as structured resources" theme but focuses specifically on the CalDAV provider layer.

## Requirements

### REQ-001: The system MUST expose register objects as CalDAV calendar events

`RegisterCalendarProvider` MUST implement `ICalendarProvider` and return a list of `RegisterCalendar` instances for schemas configured with `calendarEnabled: true`. Each `RegisterCalendar` MUST implement the Nextcloud `ICalendar` contract (getKey, getUri, getDisplayName, getDisplayColor, getPermissions, isDeleted, search) and MUST filter objects by the authenticated user's principal.

#### Scenario: Provider returns configured schemas as calendars
- **GIVEN** register `gemeente` has schemas `meldingen` (calendarEnabled: true) and `vergunningen` (calendarEnabled: false)
- **WHEN** `RegisterCalendarProvider::getCalendars()` is called for principal `/principals/users/behandelaar-1`
- **THEN** exactly one calendar MUST be returned (for `meldingen`)
- **AND** the calendar key MUST be in the format `openregister_{registerId}_{schemaId}`
- **AND** the user MUST be extracted from the principal URI via the last path segment

#### Scenario: Calendar search returns matching objects as VEVENT
- **GIVEN** register `gemeente` / schema `meldingen` has 3 objects with date fields matching a time range
- **WHEN** `RegisterCalendar::search()` is called with `start` and `end` calendar filters
- **THEN** objects whose date field falls within the range MUST be returned as iCalendar VEVENT strings
- **AND** each VEVENT MUST include `UID`, `DTSTART`, `DTEND`, `SUMMARY`, and `DESCRIPTION` components

### REQ-002: The system MUST transform register objects to iCalendar VEVENT format

`CalendarEventTransformer::transform()` MUST convert an OpenRegister object array into a complete iCalendar `VCALENDAR` string containing a single `VEVENT`. The transformer MUST determine whether the event is all-day (boolean date properties) or datetime-based, format date values to iCalendar `DATE` or `DATE-TIME` format accordingly, and compute `DTEND` from either an explicit end-date property or a 1-hour default duration.

#### Scenario: All-day event from boolean date schema property
- **GIVEN** object has property `datum` with value `"2026-05-15"` (no time component)
- **WHEN** `CalendarEventTransformer::determineAllDay()` is called
- **THEN** it MUST return `true`
- **AND** `formatDateValue()` MUST return `"20260515"` (DATE format)
- **AND** DTSTART MUST use VALUE=DATE parameter

#### Scenario: DateTime event with explicit end property
- **GIVEN** object has `startTime: "2026-05-15T09:00:00"` and `endTime: "2026-05-15T10:30:00"`
- **WHEN** `transform()` is called
- **THEN** DTSTART MUST be `"20260515T090000"` and DTEND MUST be `"20260515T103000"`

#### Scenario: DateTime event with no end property (default 1-hour duration)
- **GIVEN** object has `startTime: "2026-05-15T09:00:00"` and no end property
- **WHEN** `buildDtend()` is called
- **THEN** DTEND MUST be `"20260515T100000"` (1 hour after start)

#### Scenario: Template interpolation in SUMMARY
- **GIVEN** schema has `calendarSummaryTemplate: "Melding: {{omschrijving}}"`
- **AND** object has `omschrijving: "Geluidsoverlast"`
- **WHEN** `interpolateTemplate()` is called
- **THEN** the result MUST be `"Melding: Geluidsoverlast"`

#### Notes
- `RegisterCalendar::search()` builds Nextcloud QBMapper-style timerange filters using the schema's configured date property name. If no date property is configured, the calendar MUST return an empty array.
- The CalDAV integration is read-only: objects can be viewed via CalDAV but not created or modified through the calendar interface.
