# Calendar Provider

## Standards

- **GEMMA Agendacomponent** -- Dutch government standard for agenda/calendar integration
- **iCalendar (RFC 5545)** -- VEVENT format for calendar event representation
- **OCP\Calendar\ICalendarProvider** -- Nextcloud 23+ lazy-loading calendar provider interface
- **OCP\Calendar\ICalendar** -- Nextcloud virtual calendar interface

## Overview

The Calendar Provider creates virtual calendars from OpenRegister schema objects that have date fields. When a schema has `calendarProvider.enabled = true` in its configuration, its objects with date properties are surfaced as read-only VEVENT items in the Nextcloud Calendar app. This enables users to see case deadlines, publication dates, hearing schedules, and other time-based data directly in their calendar without manual event creation.

## Key Capabilities

### ICalendarProvider Implementation

`RegisterCalendarProvider` implements `OCP\Calendar\ICalendarProvider` to register virtual calendars for calendar-enabled schemas. The provider is lazy-loaded -- `getCalendars()` is only called when the Calendar app (or any app querying `IManager`) actually needs calendar data.

**Status**: The class exists at `lib/Calendar/RegisterCalendarProvider.php` and passes PHP lint. However, it is **not yet registered** via `$context->registerCalendarProvider()` in `Application.php`, so no OpenRegister calendars currently appear in the Calendar app.

### RegisterCalendar (Virtual Calendar)

`RegisterCalendar` (`lib/Calendar/RegisterCalendar.php`) implements `OCP\Calendar\ICalendar` and represents a single virtual calendar for a schema. It translates object data into VEVENT-compatible search results.

### CalendarEventTransformer

`CalendarEventTransformer` (`lib/Calendar/CalendarEventTransformer.php`) converts OpenRegister objects into calendar event arrays. Features include:

- **Template interpolation** -- Event titles and descriptions use configurable templates with field placeholders
- **All-day detection** -- Automatically detects whether date fields represent all-day events (date-only) or timed events (datetime)
- **Date field mapping** -- Configurable start/end date field mapping from schema properties

### Schema Configuration

Schemas store calendar provider configuration in their `configuration` JSON field under the `calendarProvider` key. The `getCalendarProviderConfig()` method on the Schema entity extracts the block; an empty/false `enabled` value returns `null`, signalling the schema is not surfaced as a calendar.

#### Configuration fields

| Field | Required | Description |
|---|---|---|
| `enabled` | yes | Set to `true` to register a virtual calendar for this schema. |
| `dtstart` | yes | Name of the schema property holding the event start datetime (e.g. `startsAt`, `publishedAt`, `dueDate`). |
| `dtend` | no | Property holding the explicit end datetime. When omitted, the transformer auto-computes a default end (1h after start for date-time, 1 day after start for date). |
| `titleTemplate` | yes | Mustache-style template for the VEVENT SUMMARY. Tokens `{{ propertyName }}` are replaced with the corresponding object data field. **Note: must use double-brace syntax** — single-brace `{title}` was a previous bug that silently mis-rendered as `}`; the renderer now requires `{{title}}` and consistent with the notification dispatcher's interpolation grammar. |
| `descriptionTemplate` | no | Same Mustache grammar as `titleTemplate`, used for the VEVENT DESCRIPTION. |
| `color` | no | CSS hex colour used by the Calendar app for this calendar. |
| `allDay` | no | Force all-day mode. When omitted, auto-detected from the property type — `format: date` produces all-day events, `format: date-time` produces timed events. |

#### Common configuration patterns

**Zaak deadlines** (one event per case, due date as start):
```json
{
  "calendarProvider": {
    "enabled": true,
    "dtstart": "deadline",
    "titleTemplate": "Deadline: {{ title }}",
    "color": "#e74c3c"
  }
}
```

**Publication dates** (one event when something goes live):
```json
{
  "calendarProvider": {
    "enabled": true,
    "dtstart": "publishedAt",
    "titleTemplate": "Published: {{ title }}",
    "descriptionTemplate": "{{ summary }}"
  }
}
```

**Event schedules** (meetings with start + end):
```json
{
  "calendarProvider": {
    "enabled": true,
    "dtstart": "startsAt",
    "dtend": "endsAt",
    "titleTemplate": "{{ title }}",
    "descriptionTemplate": "Location: {{ location }}",
    "color": "#0082c9"
  }
}
```

#### Timerange filtering

When a calendar client (Nextcloud Calendar, CalDAV, etc.) requests events for a date window, `RegisterCalendar::search()` translates the timerange into canonical operator filters on `dtstart`:

```php
['dtstartField' => ['gte' => $startISO, 'lte' => $endISO]]
```

These flow through the magic-table search pipeline using the same operator grammar as `x-openregister-aggregations` (`gte`/`lte`/`gt`/`lt`/`in`/`ne`). Older versions used a non-canonical `field>=` array-key shape that the search layer didn't recognise, silently returning empty results — fixed in the same change as the Mustache-template alignment above.

### Frontend Configuration

`CalendarProviderTab.vue` provides a tab in the Schema detail view for configuring calendar provider settings per schema.

## Architecture

```
ICalendarProvider (Nextcloud Calendar Manager)
    -> RegisterCalendarProvider.getCalendars()
        -> SchemaMapper: find schemas with calendarProvider.enabled
        -> For each schema: create RegisterCalendar (ICalendar)
            -> RegisterCalendar.search()
                -> MagicMapper: load objects
                -> CalendarEventTransformer: convert to VEVENT data
```

## Registration Gap

The design spec (`openspec/changes/calendar-provider/design.md`) specifies registration via `$context->registerCalendarProvider(RegisterCalendarProvider::class)` in `Application.php`. This line is currently missing, which means the Calendar app does not discover OpenRegister calendars. The classes are fully implemented and tested but inactive.

## Files

| File | Purpose |
|------|---------|
| `lib/Calendar/RegisterCalendarProvider.php` | ICalendarProvider implementation |
| `lib/Calendar/RegisterCalendar.php` | Virtual ICalendar per schema |
| `lib/Calendar/CalendarEventTransformer.php` | Object-to-VEVENT conversion |
| `lib/Db/Schema.php` | `getCalendarProviderConfig()` method |
| `src/views/schema/CalendarProviderTab.vue` | Frontend configuration tab |
| `tests/Unit/Calendar/RegisterCalendarProviderTest.php` | Unit tests |
