# calendar-provider

## ADDED Requirements

### Requirement: Objects with declared dates publish a subscribable feed (REQ-ODF-001)

The system SHALL publish a stable URL per calendar-enabled schema and per
saved view that answers `text/calendar` for the objects the named
principal may list. The feed SHALL be addressed by a revocable token that
names the principal, and the objects SHALL be resolved through the same
access path as the object list, including deny rules. A revoked or expired
token SHALL answer 404 rather than a reduced calendar. The feed SHALL be
generated on read, so an object whose date has moved, that has been
archived or that has been deleted SHALL be correct at the next refresh.

#### Scenario: a caseworker subscribes and sees only their own work

- **GIVEN** two users who may each list a different set of objects in one schema
- **WHEN** each subscribes with their own feed token
- **THEN** each calendar holds only the objects that user may list

#### Scenario: a moved deadline is correct at the next refresh

- **GIVEN** a subscribed feed holding a deadline on 1 October
- **WHEN** the object's deadline moves to 15 October and the client refreshes
- **THEN** the event is on 15 October and no event remains on 1 October

#### Scenario: a revoked token answers nothing

- **GIVEN** a feed token that has been revoked
- **WHEN** the client refreshes
- **THEN** the response is 404
- @e2e exclude {token lifecycle, covered by unit tests}

#### Scenario: an archived object leaves the feed

- **GIVEN** a subscribed feed holding an object's deadline
- **WHEN** the object is archived and the client refreshes
- **THEN** the event is absent
- @e2e exclude {covered by the archive e2e and unit tests}

### Requirement: A date property declares what kind of date it is (REQ-ODF-002)

A date property MAY declare the kind `deadline`, `appointment` or
`period`. Schema save SHALL refuse an unknown kind, naming the property. A
`deadline` SHALL publish as an all-day VEVENT on the date the term engine
resolves under the active working calendar, with the alarm offset the
schema declares. An `appointment` SHALL publish with its time and
duration. A `period` SHALL publish with a start and an end. A date
property with no declared kind SHALL NOT publish.

#### Scenario: a deadline publishes as an all-day event with a warning

- **GIVEN** a property `beslistermijn` declared as a deadline with an alarm offset of seven days
- **WHEN** the feed is read for an object whose `beslistermijn` is 20 October
- **THEN** the calendar holds an all-day event on 20 October with an alarm seven days earlier

#### Scenario: a deadline lands on the working day the engine uses

- **GIVEN** a term whose raw date falls on a closure day in the active working calendar
- **WHEN** the feed is read
- **THEN** the event is on the date the term engine resolves, not the raw date
- @e2e exclude {term engine integration, covered by unit tests with a calendar fixture}

#### Scenario: an undeclared date stays out of the agenda

- **GIVEN** a schema whose only date properties are `created` and `modified`
- **WHEN** the feed is read
- **THEN** the calendar is empty
- @e2e exclude {generator behaviour, covered by unit tests}

### Requirement: Attendee answers are recorded on the object (REQ-ODF-003)

For an appointment created from an object, the system SHALL record each
invitee's response against that object, naming the responder and the time
of the answer, so the record answers who is attending without reading the
organiser's calendar.

#### Scenario: a hearing's attendance is on the record

- **GIVEN** an appointment created from an object with three invitees
- **WHEN** two accept and one declines
- **THEN** reading the object returns the three responses with responder and time
- @e2e exclude {scheduling integration, covered by unit tests with a calendar fixture}
