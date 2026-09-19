# Tasks: calendar-time-zone

## 1. Data

- [ ] 1.1 `timeZone` (required, `format: time-zone`) on the `working-calendar` schema; seed `nl-national` and `example-organisation` with `Europe/Amsterdam`.
- [ ] 1.2 `lib/Formats/TimeZoneFormat.php` validating through `DateTimeZone` (ADR-008).
- [ ] 1.3 Migration filling existing calendars from `default_timezone`, fallback `UTC` with a warning.

## 2. Arithmetic and reporting

- [ ] 2.1 `WorkingCalendar::fromArray()` reads and validates the zone; `isWorkingDay()` and `nonWorkingDates()` evaluate in it.
- [ ] 2.2 `SlaCalculator` walks days at local midnight in the calendar's zone and returns UTC instants.
- [ ] 2.3 `describe()` and the ledger carry the zone and the formatted local date.

## 3. Tests

- [ ] 3.1 Unit tests: the 23:30 Friday case, a DST boundary inside a walk, an unknown zone refused, the migration.
