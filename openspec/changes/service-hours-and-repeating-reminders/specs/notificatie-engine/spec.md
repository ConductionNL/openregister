# notificatie-engine

## ADDED Requirements

### Requirement: A reminder is anchored to a named date property, with an offset (REQ-SHR-003)

A reminder rule SHALL declare the date property it counts from and an offset
before or after it, expressed in the same `{value, unit}` shape the timers
use, including business units resolved against the working calendar. Any
date property the schema declares SHALL be usable as the anchor. A rule
naming a property the schema does not declare, or a property that is not a
date, SHALL be refused at schema save with HTTP 422 naming the property. A
change to the anchor property SHALL re-arm the reminder from the new date.

#### Scenario: a reminder counts from the hearing date

- **GIVEN** a reminder anchored to `hearingDate` with an offset of 3 business days before
- **WHEN** an object is saved with a hearing date
- **THEN** the reminder is due three business days before it

#### Scenario: moving the date moves the reminder

- **GIVEN** the same object with the reminder armed
- **WHEN** the hearing date is moved two weeks later
- **THEN** the reminder is re-armed from the new date

#### Scenario: an anchor that is not a date is refused at save

- **GIVEN** a reminder anchored to a string property
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: A reminder repeats a bounded number of times (REQ-SHR-004)

A reminder MAY declare a repeat interval and a maximum number of repeats.
After each dispatch the reminder SHALL re-arm at the interval until the
maximum is reached, and SHALL then stop. A repeat interval without a maximum
SHALL be refused at schema save, because a reminder that cannot end is a
mailing list nobody can leave. Each repeat SHALL be recorded in the
notification history with its sequence number.

#### Scenario: a chase runs three times and stops

- **GIVEN** a reminder with a repeat interval of 7 days and a maximum of 3
- **WHEN** the condition stays unmet for a month
- **THEN** three reminders are dispatched, seven days apart
- **AND** no fourth is dispatched

#### Scenario: an unbounded repeat is refused at save

- **GIVEN** a reminder declaring a repeat interval and no maximum
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: A reminder stops itself when the thing it chases is done (REQ-SHR-005)

A reminder MAY declare a stop condition over the object's own data, in the
same filter grammar the scheduled trigger uses. The condition SHALL be
evaluated immediately before each dispatch. When it holds, the reminder
SHALL NOT dispatch, SHALL stop re-arming, and SHALL record that it stopped
with the condition that ended it.

#### Scenario: the document arrives and the chasing stops

- **GIVEN** a reminder chasing a missing attachment, with a stop condition on that property being present
- **WHEN** the attachment is uploaded before the next dispatch
- **THEN** no further reminder is dispatched
- **AND** the reminder records that it stopped, naming the condition

#### Scenario: an unmet condition leaves the reminder running

- **GIVEN** the same reminder with the attachment still missing
- **WHEN** the next dispatch is due
- **THEN** the reminder is dispatched
- @e2e exclude {scheduled sweep, covered by unit tests with a clock fixture}

#### Scenario: a closed object stops its reminders

- **GIVEN** a reminder whose stop condition names the closed state
- **WHEN** the object reaches that state
- **THEN** the reminder stops and records the state that ended it
