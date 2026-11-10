---
kind: code
depends_on: [flow-business-timers]
---

# Proposal: calendar-time-zone

## Summary

A working calendar names the time zone its days are counted in.
`WorkingCalendar` builds every rule date in UTC
(`lib/Service/Flow/Timer/WorkingCalendar.php:280,299`) and `SlaCalculator`
walks days on the instants it is given. A deadline armed at 23:30 in
Amsterdam is 21:30 UTC, so "is this a working day" can be asked about the
wrong date. This change puts one `timeZone` on the calendar and evaluates
day boundaries in it.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q8.19 | Is the buyer's own time zone accepted where a calendar or a term is configured | partial | S |

## Why

The register's note: "The answer is the platform's. Nextcloud takes a
per-user and per-instance time zone from the same IANA list PHP has. Whether
dossiq's term engine reads it or counts in UTC is not in the published
matrix. OTOBO's vendor image refuses `Europe/Amsterdam`; iTop's unattended
installer wrote `Europe/Paris` without asking." The best competitor,
verbatim from the `best` column: "Znuny 7.3 accepts Europe/Amsterdam; Odoo
resource.calendar.tz verified (`_round4/compare/pending-rows-batch6.md`)".

The register's `why`: "the zone a calendar counts in is a property of
WorkingCalendarService". `WorkingCalendar::fromArray()` is where the
property is validated, and the day walk in `SlaCalculator` is where it is
used.

## What changes

- The `working-calendar` schema gains `timeZone`, an IANA identifier,
  required. The seed sets `nl-national` to `Europe/Amsterdam`; the migration
  fills existing calendars from the instance's `default_timezone` and falls
  back to `UTC` with a logged warning.
- `WorkingCalendar::isWorkingDay()` and `nonWorkingDates()` evaluate the
  moment in the calendar's zone. `SlaCalculator` walks days at local
  midnight in that zone and returns instants.
- `describe()` and the timer ledger carry the zone, and every date they
  report is formatted in it beside the instant.
- Validation refuses an identifier `DateTimeZone` does not know.

## Consumers

- dossiq: write down which zone dossiq's terms count in and re-rate; with
  this change the answer is the calendar's. Specified in dossiq by the
  dossiq lane (register row Q8.19).
- Every business-timer app.

## ADRs

- ADR-022, ADR-098 decision 1: one clock, one zone per calendar.
- openregister ADR-008 (shared format validators): the zone validator lives
  once, in `lib/Formats/`.

## Impact

- Extends: `flow-business-timers` requirement "Business time is measured
  against ONE resolvable working calendar".
- Affected code: `lib/Settings/flow_timer_register.json`,
  `lib/Repair/SeedFlowTimerRegister.php`, `WorkingCalendar.php`,
  `SlaCalculator.php`, `FlowTimerService::describe()`, one migration.
- Size: S.
