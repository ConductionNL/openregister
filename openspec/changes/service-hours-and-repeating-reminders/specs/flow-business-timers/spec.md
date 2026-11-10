# flow-business-timers

## ADDED Requirements

### Requirement: A working calendar declares the hours of the day its clock runs (REQ-SHR-001)

Which hours the clock runs is configuration, not code. A working calendar MAY
declare `serviceHours`: for each working weekday, one or more windows with a
start and an end, stated in the calendar's own time zone. An administrator
owns them, beside the working weekdays and the holiday rules on the same
calendar, and the fire moment of a term whose unit is hours SHALL follow the
windows that calendar declares rather than any hour written into the engine.
A calendar that declares no windows SHALL behave exactly as it does today. A
window whose end is not after its start, windows that overlap on one weekday,
and a window on a weekday the calendar does not work SHALL be refused when the
calendar is written, naming the weekday.

The elapsed reading of an hours term SHALL be measured in the same windows the
fire moment was computed in. A deadline counted inside the windows beside a
report counted across one unbroken block disagree by the length of the break,
and whoever reads the two cannot tell which is wrong.

> **The worked example is the configured window's, not a fixed hour.** This
> requirement said the Friday example landed at 11:00 and the implementation
> answered 12:00, and that disagreement stood as an open question for a day.
> Neither side was declared right: 11:00 and 12:00 are both correct answers to
> the same term against different opening times, which is the point of the
> requirement being about configuration. The example below names its window and
> follows from it, and `ServiceHoursAreAdministeredTest` asserts exactly this
> arithmetic, so the two cannot drift apart again without one turning red.
> Eleven o'clock is the answer against a counter that opens at 08:00, or to a
> three-hour term against this one.

#### Scenario: four service hours from Friday afternoon land on Monday

- **GIVEN** a calendar configured to work Monday to Friday, open 09:00 to 17:00 on each of them
- **AND** a 4-hour term armed on Friday at 16:00
- **WHEN** the fire moment is computed
- **THEN** it falls on the following Monday at 12:00, because the Friday gives one open hour and three remain from Monday's opening
- @e2e tests/e2e/ci/service-hours-admin.spec.ts (`@e2e flow-business-timers::four-service-hours-from-friday-afternoon-land-on-monday`), which stores the windows through the admin form and reads them back; the arithmetic itself is `ServiceHoursAreAdministeredTest::testFourServiceHoursFromFridayAfternoonAreDueMondayAtNoon`

#### Scenario: a closed midday is closed

- **GIVEN** a calendar open 09:00 to 12:30 and 13:30 to 17:00, and a 4-hour term armed on Monday at 11:00
- **WHEN** the fire moment is computed
- **THEN** it falls on the Monday at 16:00, the hour of the break having been skipped
- **AND** the calendar's hours in a working day read 7, derived from the windows rather than declared beside them
- @e2e tests/e2e/ci/service-hours-admin.spec.ts (`@e2e flow-business-timers::a-closed-midday-is-closed`), which types the split day into the form; the arithmetic is `ServiceHoursAreAdministeredTest::testTheLunchBreakIsNotCounted`

#### Scenario: a non-working day is skipped entirely

- **GIVEN** the same calendar and a 2-hour term armed on the Friday before a public holiday at 16:30
- **WHEN** the fire moment is computed
- **THEN** it falls on the next working day, not on the holiday
- @e2e exclude {the holiday is the calendar's, already covered in a browser by `tests/e2e/ci/working-calendar-admin.spec.ts`; the skip is `ServiceHoursAreAdministeredTest::testAClosedDayIsSkippedEntirely`}

#### Scenario: a calendar without windows is unchanged

- **GIVEN** a calendar declaring no `serviceHours`
- **WHEN** terms are computed against it
- **THEN** the results are identical to those before this change
- **AND** the shipped calendars declare none, so no upgrade moves a deadline that is already running
- @e2e exclude {the assertion is that nothing changed, which has no screen; `ServiceHoursAreAdministeredTest::testACalendarWithoutWindowsCountsHoursAsItAlwaysDid` and the untouched `ElapsedBusinessHoursTest` are the coverage}

#### Scenario: an unconfigured holiday list means no holidays

- **GIVEN** an organisation that closes on no fixed day of the year
- **WHEN** it saves a calendar with an empty holiday list
- **THEN** the calendar is accepted and keeps no non-working dates
- **AND** no error asks it to name a holiday it does not keep
- @e2e tests/e2e/ci/service-hours-admin.spec.ts (`@e2e flow-business-timers::an-unconfigured-holiday-list-means-no-holidays`)

#### Scenario: an overlapping window is refused

- **GIVEN** a calendar declaring 09:00 to 13:00 and 12:00 to 17:00 on one weekday
- **WHEN** it is written
- **THEN** the write fails naming the weekday
- @e2e tests/e2e/ci/service-hours-admin.spec.ts (`@e2e flow-business-timers::an-overlapping-window-is-refused`), which also writes as an ordinary user and requires the refusal

### Requirement: More than one set of service hours is resolved and named (REQ-SHR-002)

Service hours SHALL be resolved through the existing calendar resolution
order of record type, unit and instance, so that two parts of one
organisation may keep different hours by naming different calendars. The
diagnostic of a computed term SHALL name the calendar that decided it and
the windows that were applied.

#### Scenario: the counter and the back office count differently

- **GIVEN** a schema naming a calendar open 09:00 to 12:30 and another schema naming a calendar open 09:00 to 17:00
- **WHEN** an identical 6-hour term is computed on each
- **THEN** the two fire moments differ
- **AND** each diagnostic names its own calendar

#### Scenario: the answer explains itself

- **GIVEN** any computed hours term
- **WHEN** its diagnostic is read
- **THEN** it names the resolved calendar and the windows applied
- @e2e exclude {diagnostic read, covered by unit tests}
