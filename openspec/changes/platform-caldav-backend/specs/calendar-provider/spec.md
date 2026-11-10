# calendar-provider

## ADDED Requirements

### Requirement: Declared object dates appear as platform calendars (REQ-PCB-001)

The system SHALL expose, per principal, a calendar for each
calendar-enabled schema and each saved view, through the platform calendar
provider, built from the same event generator as the subscribable feed.
The calendars SHALL carry the declared date kinds, their alarm offsets and
the working-day date the term engine resolves. A schema declaring no date
kinds SHALL keep its current virtual calendar behaviour.

#### Scenario: a beslistermijn is in the Calendar app

- **GIVEN** a schema declaring a deadline property with an alarm offset
- **WHEN** a caseworker opens the Calendar app
- **THEN** the deadline appears as an all-day event with that alarm

#### Scenario: the feed and the calendar agree

- **GIVEN** the same object and the same principal
- **WHEN** the subscribable feed and the platform calendar are both read
- **THEN** they hold the same events on the same dates

#### Scenario: a saved view is a calendar

- **GIVEN** a saved view with a filter
- **WHEN** the principal's calendars are listed
- **THEN** a calendar for that view is present, holding only its objects

### Requirement: The platform calendar is read-only and resolved per principal (REQ-PCB-002)

The calendars SHALL be read-only until the recomputation change has
landed: a write from a CalDAV client SHALL be refused rather than accepted
and overwritten. The objects SHALL be resolved through the object access
path for the requesting principal, including a deny, with no second access
rule.

#### Scenario: an edit in a client is refused, not lost

- **GIVEN** a generated calendar in a CalDAV client
- **WHEN** the client moves an event
- **THEN** the write is refused

#### Scenario: two caseworkers see their own work

- **GIVEN** two principals who may list different objects of one schema
- **WHEN** each lists their calendars
- **THEN** each calendar holds only the objects that principal may list
