# flow-business-timers Specification

## Purpose
How the flow engine's working calendar and SLA calculator measure time in working hours: when the working day opens, and how much working time elapsed between two moments, so a deadline or a dwell time counts the hours an organisation actually worked rather than wall-clock hours.

## Requirements

### Requirement: The calendar knows when the working day opens, and the calculator can measure elapsed working time

A working calendar SHALL carry an OPTIONAL `dayStartsAt`, the time of day the
organisation opens, written `HH:MM` in 24-hour form and defaulting to `09:00`.
A malformed value SHALL be refused by name rather than coerced. The working
day SHALL close `hoursPerWorkingDay` after it opens, and SHALL NOT extend
past the end of its own calendar day.

The calculator SHALL offer `elapsedBusinessHours(from, to, calendar)`: the
part of the interval that falls inside a working day's window, in hours,
negative when `to` precedes `from`.

No lifecycle, deadline, timer or escalation rule SHALL read `dayStartsAt`. A
calendar that declares none SHALL behave exactly as it does today.

#### Scenario: A weekend is not working time

- **GIVEN** a Monday-to-Friday calendar of eight hours opening at 09:00
- **WHEN** elapsed working hours are measured from Friday 16:00 to Monday 09:00
- **THEN** the answer SHALL be 1
- **AND** the same interval measured in hours SHALL still be 65

#### Scenario: Time outside the window is not counted

- **GIVEN** the same calendar
- **WHEN** elapsed working hours are measured from midnight to 09:00 on a working day
- **THEN** the answer SHALL be 0

#### Scenario: A malformed opening time is refused

- **GIVEN** a calendar declaring `dayStartsAt` as `9am`
- **WHEN** it is built
- **THEN** the build SHALL be refused with a message naming `dayStartsAt`

### Requirement: A working calendar says which zone its days are counted in

A working calendar SHALL carry an OPTIONAL `timezone`, an IANA zone name,
defaulting to `UTC`. A value that is not an IANA zone name SHALL be refused
with a message naming the field, and SHALL NOT be coerced.

The default SHALL NOT be the process's own default zone: the same calendar
must answer the same days on every instance.

The zone SHALL be the organisation's, and no rule SHALL read a viewer's
display zone in its place.

#### Scenario: The seeded Dutch calendar counts Dutch days

- **GIVEN** the `nl-national` calendar
- **WHEN** it is built
- **THEN** its zone SHALL be `Europe/Amsterdam`

#### Scenario: A calendar with no zone counts UTC days

- **GIVEN** a calendar declaring no zone, on a server set to another zone
- **WHEN** it is built
- **THEN** its zone SHALL be `UTC`

#### Scenario: A zone that does not resolve is refused

- **GIVEN** a calendar declaring `CET+1`
- **WHEN** it is built
- **THEN** the build SHALL be refused with a message naming `timezone`
