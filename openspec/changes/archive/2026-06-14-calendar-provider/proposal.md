# Calendar Provider

## Problem

OpenRegister stores structured data objects that frequently contain date and datetime fields -- deadlines, hearing dates, milestones, publication dates, appointment slots. These time-based data points are invisible to the Nextcloud Calendar app. Users must manually create calendar events to track deadlines, or switch between OpenRegister and their calendar to correlate dates with cases. There is no automatic visibility of object-driven dates in the user's calendar view.

The existing TaskService and the planned CalendarEventService (from the `nextcloud-entity-relations` change) address linking *manually created* CalDAV items to objects. But this is the opposite direction: we need OpenRegister to act as a *source* of calendar data, surfacing object dates as read-only events in the Nextcloud Calendar without requiring manual event creation.

## Context

- **Nextcloud Calendar Provider**: Nextcloud 23+ supports `ICalendarProvider` -- a lazy-loading mechanism that lets apps register virtual calendars. These calendars appear in the Calendar app and are queryable via `IManager::searchForPrincipal()`. Apps like Deck and Tasks already use this pattern.
- **OpenRegister Schemas**: Each schema defines typed properties. Properties with `format: date`, `format: date-time`, or `type: string` with date-like names (e.g., `deadline`, `einddatum`, `startDatum`) represent calendar-worthy dates.
- **Schema configuration**: Schemas already have a `configuration` JSON field that can hold calendar provider settings (which date fields to surface, event title template, color).
- **Consuming apps**: Procest (case deadlines), ZaakAfhandelApp (zaak termijnen), LarpingApp (event schedules), OpenCatalogi (publication dates) -- all would benefit from automatic calendar visibility.
- **RBAC**: OpenRegister has row-level and schema-level RBAC. Calendar events should only be visible to users who have read access to the underlying objects.

## Proposed Solution

Implement `OCP\Calendar\ICalendarProvider` in OpenRegister so that each schema with calendar-enabled date fields produces a virtual calendar. The calendar surfaces objects as read-only VEVENT items in the Nextcloud Calendar app.

Key design choices:
1. **One virtual calendar per schema** that has calendar configuration enabled (not per register, to avoid duplication when schemas are shared).
2. **Schema-level configuration** determines which date fields become DTSTART/DTEND, what the event title template is (e.g., `{title} - {zaaktype}`), and the calendar color.
3. **Read-only events**: Objects are the source of truth. Events are generated on-the-fly from object data -- no duplicate storage.
4. **RBAC-aware**: The provider respects OpenRegister's authorization model. Users only see events for objects they can read.
5. **Performance**: Uses the existing MagicMapper search infrastructure with date-range filtering to avoid loading all objects.

## Scope

### In scope
- `ICalendarProvider` implementation that registers virtual calendars for calendar-enabled schemas
- `ICalendar` implementation with search/query support for VEVENT generation
- Schema configuration fields for calendar mapping (date fields, title template, color, enabled flag)
- Date-range query optimization using MagicMapper
- RBAC enforcement on calendar queries
- Admin settings UI for configuring which schemas provide calendars
- Support for single-date events (DTSTART only, all-day) and range events (DTSTART + DTEND)
- Registration via `IRegistrationContext::registerCalendarProvider()`

### Out of scope
- Writing back to objects from the calendar (events are read-only projections)
- Recurring event patterns (each object = one event; recurrence is not a register concept)
- CalDAV sync (REPORT/PROPFIND) -- this uses the higher-level ICalendar search API only
- Integration with CalendarEventService from entity-relations (that links *real* CalDAV events to objects; this provides *virtual* events from object data)
- Free/busy lookups
