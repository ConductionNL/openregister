# flow-business-timers

## ADDED Requirements

### Requirement: A working calendar declares the hours of the day its clock runs (REQ-SHR-001)

A working calendar MAY declare `serviceHours`: for each working weekday, one
or more windows with a start and an end, stated in the calendar's own time
zone. A term whose unit is hours SHALL advance only inside those windows. A
calendar that declares no windows SHALL behave exactly as it does today. A
window whose end is not after its start, windows that overlap on one
weekday, and a window on a weekday the calendar does not work SHALL be
refused when the calendar is written, naming the weekday.

#### Scenario: four service hours from Friday afternoon land on Monday

- **GIVEN** a calendar working Monday to Friday from 09:00 to 17:00, and a 4-hour term armed on Friday at 16:00
- **WHEN** the fire moment is computed
- **THEN** it falls on the following Monday at 11:00

#### Scenario: a non-working day is skipped entirely

- **GIVEN** the same calendar and a 2-hour term armed on the Friday before a public holiday at 16:30
- **WHEN** the fire moment is computed
- **THEN** it falls on the next working day, not on the holiday

#### Scenario: a calendar without windows is unchanged

- **GIVEN** a calendar declaring no `serviceHours`
- **WHEN** terms are computed against it
- **THEN** the results are identical to those before this change

#### Scenario: an overlapping window is refused

- **GIVEN** a calendar declaring 09:00 to 13:00 and 12:00 to 17:00 on one weekday
- **WHEN** it is written
- **THEN** the write fails naming the weekday
- @e2e exclude {validator, covered by unit tests}

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
