# Tasks: calendar-time-zone

## 1. Data

- [ ] 1.1 `timeZone` (required, `format: time-zone`) on the `working-calendar` schema; seed `nl-national` and `example-organisation` with `Europe/Amsterdam`.
  > 14 Nov, partly: `working-calendar-admin` already ships the property as `timezone`, optional, default `UTC`. It now carries `format: time-zone` (schema 1.3.1, register 1.4.1), and `example-organisation` is seeded with `Europe/Amsterdam` (`nl-national` already was). Left for the owner: making it REQUIRED contradicts the merged UTC default, and needs 1.3 first; the key stays `timezone`, the name the code reads.
- [x] 1.2 `lib/Formats/TimeZoneFormat.php` validating through `DateTimeZone` (ADR-008).
  > 14 Nov: accepts exactly `DateTimeZone::listIdentifiers()`, the rule `WorkingCalendarDefinition` applies when it reads a calendar, so an offset or an abbreviation is refused at save as it is at read. Registered as `time-zone` in `FormatFactory::customStringFormats()`. `TimeZoneFormatTest` (3).
- [ ] 1.3 Migration filling existing calendars from `default_timezone`, fallback `UTC` with a warning.

## 2. Arithmetic and reporting

- [x] 2.1 `WorkingCalendar::fromArray()` reads and validates the zone; `isWorkingDay()` and `nonWorkingDates()` evaluate in it.
- [x] 2.2 `SlaCalculator` walks days at local midnight in the calendar's zone and returns UTC instants.
- [ ] 2.3 `describe()` and the ledger carry the zone and the formatted local date.
  > 14 Nov, `describe()` done: `FlowTimerService::describe()` adds `timeZone`, `fireAtLocal` and `unrolledAtLocal` (`2027-06-30 23:59 Europe/Amsterdam`) beside the UTC instants. `FlowTimerDescribeTimeZoneTest` (2, the spec scenario included). The ledger (timer events) is not touched yet.

## 3. Tests

- [ ] 3.1 Unit tests: the 23:30 Friday case, a DST boundary inside a walk, an unknown zone refused, the migration.
