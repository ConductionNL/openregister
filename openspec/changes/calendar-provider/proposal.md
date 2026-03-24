## Why

Register objects frequently contain important dates — case deadlines, lead follow-up dates, document review dates, appointment dates — but these are invisible in the Nextcloud Calendar. Users must check each app individually (Procest, Pipelinq, etc.) to discover upcoming deadlines. Implementing an `ICalendarProvider` bridges this gap by automatically surfacing object dates as calendar events, giving users a unified timeline view across all register data.

## What Changes

- Add a new `ICalendarProvider` implementation (`RegisterCalendarProvider`) that exposes date-bearing register objects as virtual calendars in Nextcloud Calendar
- Add a `DateFieldDetectionService` that scans schema definitions for date/datetime fields using both JSON Schema `format` and field name pattern matching (`*deadline*`, `*dueDate*`, `*datum*`, etc.)
- Add a `RegisterCalendar` implementing `ICalendar`, `ICalendarIsEnabled`, and `ICalendarIsShared` for each register/schema combination with detected date fields
- Generate iCalendar VEVENT data from object date fields with proper `SUMMARY`, `DTSTART`/`DTEND`, `DESCRIPTION`, `URL` (deep links), `UID`, `CATEGORIES`, and `VALARM`
- Implement `searchForPrincipal()` for efficient date-range queries from the calendar app
- Add admin settings for toggling calendar integration per register, selecting exposed date fields per schema, configuring default reminder time and display colors
- Cache schema date field mappings in APCu (TTL 300s) for performance
- Register the provider via `IRegistrationContext::registerCalendarProvider()` in `Application.php`

## Capabilities

### New Capabilities
- `calendar-provider`: Core calendar provider implementation — ICalendarProvider, ICalendar, date field detection, VEVENT generation, calendar search, admin configuration, and caching

### Modified Capabilities
<!-- No existing spec-level requirements change. The calendar provider reads data from existing registers/schemas/objects without modifying their behavior. -->

## Impact

- **Code**: New classes in `lib/Calendar/` (`RegisterCalendarProvider`, `RegisterCalendar`, `DateFieldDetectionService`), registration in `lib/AppInfo/Application.php`, new admin settings section
- **APIs**: No REST API changes — this integrates via Nextcloud's internal `ICalendarProvider` SPI, exposed through CalDAV
- **Dependencies**: Requires `OCP\Calendar\ICalendarProvider`, `ICalendar`, `ICalendarIsEnabled`, `ICalendarIsShared` from Nextcloud server (available since NC 23+). No external dependencies. Enhanced by `deep-link-registry` spec for event URLs
- **Performance**: Date-range queries on object tables require database indexes on date fields; APCu caching for schema field mappings; query results capped at 200 per calendar
- **Dependent apps**: Procest (case deadlines), Pipelinq (lead follow-ups), and any app storing date fields in OpenRegister objects will automatically benefit — no changes needed in consuming apps
