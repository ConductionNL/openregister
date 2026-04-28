# Retrofit — calendar-integration (new cluster)

Describes observed behavior of 19 methods in the calendar cluster as 2 new REQs in a new `calendar-integration` capability. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/Calendar/RegisterCalendarProvider.php (3 methods: getCalendars, getCalendarEnabledSchemas, isValidUserPrincipal)
- lib/Calendar/RegisterCalendar.php (9 methods: getKey, getUri, getDisplayName, getDisplayColor, getPermissions, isDeleted, search, extractUserId, buildTimerangeFilters, findRegistersForSchema, matchesPattern)
- lib/Calendar/CalendarEventTransformer.php (7 methods: transform, determineAllDay, formatDateValue, buildDtend, interpolateTemplate)

## Approach
REQ-001 covers the ICalendarProvider/ICalendar contract implementation (CalDAV exposure layer). REQ-002 covers the iCalendar data transformation (VEVENT generation with all-day vs datetime handling, template interpolation).

Source: openspec/coverage-report.md generated 2026-04-28. Bucket 2b — calendar cluster.
