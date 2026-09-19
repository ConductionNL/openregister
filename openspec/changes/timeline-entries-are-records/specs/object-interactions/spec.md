# object-interactions

## ADDED Requirements

### Requirement: A timeline entry has a kind that declares its own fields and follow-up (REQ-TER-001)

A timeline entry MAY carry a declared kind. A kind SHALL declare its own
properties, validated the way object properties are validated, and MAY
declare that entries of that kind carry a follow-up state of open or done
with who closed it and when. An entry with no kind SHALL behave as a plain
note.

#### Scenario: a contact moment carries a channel and a direction

- **GIVEN** a kind `contactmoment` declaring `channel` and `direction`
- **WHEN** an entry of that kind is written with both values
- **THEN** reading the entry returns them, validated

#### Scenario: a callback is open until somebody closes it

- **GIVEN** a kind declaring a follow-up state
- **WHEN** an entry of that kind is created and later closed
- **THEN** the entry reads done, with the closer and the time

#### Scenario: an entry with no kind is unchanged

- **GIVEN** a note written with no kind
- **WHEN** it is read
- **THEN** it behaves exactly as before this change

### Requirement: An entry is pinned, and the inbound source is kept (REQ-TER-002)

An authorised user SHALL be able to pin an entry, which SHALL sort first
on the timeline and SHALL name who pinned it. For an entry created from an
inbound message, the raw source and its headers SHALL be stored beside the
rendered entry and SHALL be readable by anyone who may read the entry. The
detected language of an inbound message SHALL be recorded on the entry.

#### Scenario: four entries out of three hundred are found first

- **GIVEN** a timeline of three hundred entries with four pinned
- **WHEN** it is read
- **THEN** the four come first, each naming who pinned it

#### Scenario: a disputed arrival date is settled by the headers

- **GIVEN** an entry created from an inbound e-mail
- **WHEN** a reader who may read the entry asks for the source
- **THEN** the raw message with its headers is returned

#### Scenario: the arrival language is on the entry

- **GIVEN** an inbound message written in Polish
- **WHEN** the entry is read
- **THEN** the detected language is recorded on it
- @e2e exclude {detection library, covered by unit tests}

### Requirement: An administered reference pattern links a short code from any text (REQ-TER-003)

An administrator SHALL be able to declare a reference pattern and the
target it resolves to. Text on an object matching a declared pattern SHALL
render as a link to that target, and SHALL record a reference on both the
writing object and the referenced one. Removing the text SHALL remove the
reference.

#### Scenario: a case number written in a sentence becomes a link

- **GIVEN** a declared pattern for case numbers
- **WHEN** a note reads "zie Z-2026-0044"
- **THEN** the code renders as a link and both objects hold the reference

#### Scenario: an undeclared pattern changes nothing

- **GIVEN** an instance declaring no pattern
- **WHEN** a note holds a code-shaped string
- **THEN** it renders as plain text
- @e2e exclude {asserts the absence of instance-wide configuration, which a shared CI instance where other specs declare patterns cannot provide; covered by ReferenceServiceTest::testAnInstanceThatDeclaresNoPatternChangesNothing}

### Requirement: A note reaches related objects, and canned text is administered (REQ-TER-004)

An author SHALL be able to write one note onto several related objects at
once: one author, one text, an entry on each, each linked to the others.
Canned text blocks SHALL be administered, scoped to a register, a schema or
a group, and insertable into an entry with the same variable substitution
message templates use.

#### Scenario: one afstemmingsverslag on two cases

- **GIVEN** two related objects
- **WHEN** one note is written to both
- **THEN** each holds an entry with the same text, and each names the other

#### Scenario: a standard answer is inserted, not retyped

- **GIVEN** an administered canned text block scoped to a schema
- **WHEN** a handler inserts it into an entry on an object of that schema
- **THEN** the text appears with its variables substituted

### Requirement: A mention subscribes the mentioned principal (REQ-TER-005)

Naming a principal in an entry SHALL notify them and SHALL add them as a
watcher of the object through the watcher primitive. A principal who may
not read the object SHALL NOT be subscribed and SHALL NOT be notified.

#### Scenario: the jurist is pulled in and stays in

- **GIVEN** a handler naming a colleague in a note
- **WHEN** the note is saved
- **THEN** the colleague is notified and is a watcher of the object

#### Scenario: a mention cannot grant access

- **GIVEN** a principal who may not read the object
- **WHEN** they are named in an entry
- **THEN** they are neither notified nor subscribed
