# flow-business-timers

## ADDED Requirements

### Requirement: A working calendar counts its days in a named time zone

A working calendar SHALL carry a required `timeZone` holding an IANA
identifier, validated through `DateTimeZone`, and the system SHALL evaluate
whether a moment falls on a working day, and walk days when adding or
measuring a budget, in that zone. The seeded `nl-national` calendar SHALL
use `Europe/Amsterdam`.

#### Scenario: a late-evening anchor counts from the right date

- **GIVEN** a `1 businessDays` budget anchored at 2027-03-12T23:30:00+01:00, a Friday in Amsterdam, on `nl-national`
- **WHEN** the timer is armed
- **THEN** `fire_at` is Monday 2027-03-15T23:30:00+01:00 and not Sunday, which is what the UTC date 2027-03-12T22:30Z plus one walked day would give
- @e2e exclude {calendar arithmetic, covered by SlaCalculator unit tests}

#### Scenario: an unknown zone is refused

- **GIVEN** a calendar body with `timeZone: "Europe/Nowhere"`
- **WHEN** it is written
- **THEN** the write is refused with 422 naming the identifier
- @e2e exclude {validator, covered by TimeZoneFormat unit tests}

### Requirement: Existing calendars are given a zone on upgrade

The migration SHALL set `timeZone` on every existing working calendar from
the instance's `default_timezone`, falling back to `UTC` with a logged
warning that names the calendar.

#### Scenario: an instance in Amsterdam upgrades

- **GIVEN** `default_timezone` is `Europe/Amsterdam` and two calendars without a zone
- **WHEN** the migration runs
- **THEN** both calendars carry `Europe/Amsterdam` and nothing is logged as a warning
- @e2e exclude {migration, covered by a migration test}

### Requirement: Reported dates name their zone

`describe()` and the timer ledger SHALL carry the calendar's `timeZone` and
SHALL format every reported date in that zone beside the UTC instant.

#### Scenario: a handler sees the local date

- **GIVEN** a timer on `nl-national` with `fire_at` 2027-06-30T21:59:00Z
- **WHEN** it is described
- **THEN** the description shows `2027-06-30 23:59 Europe/Amsterdam` beside the instant
- @e2e exclude {describe output, covered by FlowTimerService unit tests}
