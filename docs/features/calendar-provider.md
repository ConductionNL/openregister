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

Schemas store calendar provider configuration in their `configuration` JSON field. The `getCalendarProviderConfig()` method on the Schema entity extracts and validates this configuration, including:

- `enabled` -- Whether the schema produces a virtual calendar
- `titleTemplate` -- Template for event titles with `{field}` placeholders
- `descriptionTemplate` -- Template for event descriptions
- `startDateField` -- Schema property to use as event start date
- `endDateField` -- Schema property to use as event end date

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
