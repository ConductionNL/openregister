# Tasks: calendar-time-zone

## 1. Data

- [x] 1.1 `timeZone` (required, `format: time-zone`) on the `working-calendar` schema; seed `nl-national` and `example-organisation` with `Europe/Amsterdam`.
  > 14 Nov, partly: `working-calendar-admin` already ships the property as `timezone`, optional, default `UTC`. It now carries `format: time-zone` (schema 1.3.1, register 1.4.1), and `example-organisation` is seeded with `Europe/Amsterdam` (`nl-national` already was). Left for the owner: making it REQUIRED contradicts the merged UTC default, and needs 1.3 first; the key stays `timezone`, the name the code reads.
  > 15 Nov, done: with 1.3 giving every stored calendar a zone, `timezone` is now in the schema's `required` list (schema 1.4.0, register 1.5.0); the key stays `timezone`. Schemas validate before defaults are filled in, so the admin form now has a Time zone field and sends the zone on every save (a new calendar starts in the administrator's own zone, a stored one keeps its own). The mock-register calendars carry `Europe/Amsterdam`. `time-zone` joins the property validator's format list, which refused the shipped schema since 1.2.
- [x] 1.2 `lib/Formats/TimeZoneFormat.php` validating through `DateTimeZone` (ADR-008).
  > 14 Nov: accepts exactly `DateTimeZone::listIdentifiers()`, the rule `WorkingCalendarDefinition` applies when it reads a calendar, so an offset or an abbreviation is refused at save as it is at read. Registered as `time-zone` in `FormatFactory::customStringFormats()`. `TimeZoneFormatTest` (3).
- [x] 1.3 Migration filling existing calendars from `default_timezone`, fallback `UTC` with a warning.
  > 14 Nov: `FillCalendarTimeZones`, a post-migration repair step after `SeedFlowTimerRegister`, writes the instance's `default_timezone` onto every `working-calendar` whose `timezone` is missing or blank; an unset or non-IANA `default_timezone` gives `UTC` with a warning on the output and in the log. A calendar that names a zone is never written; one that cannot be written is logged and the rest still get theirs. `FillCalendarTimeZonesTest` (8, the Amsterdam upgrade scenario included).

## 2. Arithmetic and reporting

- [x] 2.1 `WorkingCalendar::fromArray()` reads and validates the zone; `isWorkingDay()` and `nonWorkingDates()` evaluate in it.
- [x] 2.2 `SlaCalculator` walks days at local midnight in the calendar's zone and returns UTC instants.
- [x] 2.3 `describe()` and the ledger carry the zone and the formatted local date.
  > 14 Nov, `describe()` done: `FlowTimerService::describe()` adds `timeZone`, `fireAtLocal` and `unrolledAtLocal` (`2027-06-30 23:59 Europe/Amsterdam`) beside the UTC instants. `FlowTimerDescribeTimeZoneTest` (2, the spec scenario included). Ledger done the same day: every `openregister_flow_timer_events` row stores `time_zone` (migration `Version1Date20261114180000`), the calendar's zone when the row was written, and its JSON adds `priorFireAtLocal`, `newFireAtLocal` and `unrolledAtLocal`; a calendar that cannot be resolved still gets its row, without a zone. `FlowTimerLedgerTimeZoneTest` (3).

## 3. Tests

- [x] 3.1 Unit tests: the 23:30 Friday case, a DST boundary inside a walk, an unknown zone refused, the migration.
  > 14 Nov: `SlaCalculatorTimeZoneTest` (the 23:30 Friday anchor lands Monday 23:30 Amsterdam; a business-day walk across the end of summer time keeps 10:00 local; an instant that is Monday in Amsterdam and Sunday in UTC lands differently on a UTC copy of the calendar), the unknown zone in `WorkingCalendarZoneTest::testAZoneThatDoesNotResolveIsRefused` and `TimeZoneFormatTest`, the migration in `FillCalendarTimeZonesTest`.
