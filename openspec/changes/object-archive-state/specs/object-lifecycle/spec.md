# object-lifecycle

## ADDED Requirements

### Requirement: An object can be archived and restored (REQ-OAS-001)

A caller with `update` on an object whose schema declares
`x-openregister-archive` SHALL be able to archive it and to restore it.
Archiving SHALL write `@self.archived` with the archiving user, the time
and an optional reason, SHALL NOT change the object's own data, and SHALL
write one audit entry. Restoring SHALL clear the marker and write a second
audit entry. A schema that does not declare the annotation SHALL refuse
both with HTTP 422.

#### Scenario: archiving leaves the data alone

- **GIVEN** an object with four audit entries and two versions
- **WHEN** a user with `update` archives it with the reason "afgehandeld"
- **THEN** `@self.archived` names that user, the time and the reason
- **AND** the object's data and its two versions are unchanged
- **AND** it has five audit entries
- @e2e exclude {audit and version assertions, covered by unit tests}

#### Scenario: restoring brings it back into the working set

- **GIVEN** an archived object
- **WHEN** a user with `update` restores it
- **THEN** `@self.archived` is absent and the object appears in the default list again
- **AND** the audit trail holds both the archive and the restore entry

#### Scenario: a schema that does not offer archiving refuses

- **GIVEN** a schema without `x-openregister-archive`
- **WHEN** the archive endpoint is called on one of its objects
- **THEN** the response is HTTP 422 and nothing is written
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: a caller with read only cannot archive

- **GIVEN** a user who may read but not update the object
- **WHEN** they call the archive endpoint
- **THEN** the response is a refusal and `@self.archived` is absent
- @e2e exclude {RBAC guard, covered by unit tests}

### Requirement: An archived object refuses writes to its data (REQ-OAS-002)

A write to the data of an archived object SHALL be refused with a message
naming the archive, the archiving user and the time. Restoring SHALL be
accepted. The retention machinery SHALL keep working on an archived
object: setting a destruction date, placing a legal hold and destroying on
the date SHALL behave exactly as on an open object.

#### Scenario: an edit is refused and says why

- **GIVEN** an object archived by Anna on 12 September
- **WHEN** a user saves a change to one of its fields
- **THEN** the write is refused and the message names the archive, Anna and that date
- **AND** the object is unchanged

#### Scenario: a flow writing to an object archived under it is refused the same way

- **GIVEN** a running flow that writes to an object archived after the run started
- **WHEN** the write step executes
- **THEN** it is refused with the same message and the run records it
- @e2e exclude {flow interaction, covered by unit tests}

#### Scenario: retention still reaches an archived object

- **GIVEN** an archived object with an `archiefactiedatum` in the past
- **WHEN** the destruction job runs
- **THEN** the object is placed on the destruction list exactly as an open object would be
- @e2e exclude {retention job, covered by PHPUnit}

### Requirement: Archived objects leave the working views and search (REQ-OAS-003)

The object list, the object query, the aggregation endpoint and every
search provider SHALL exclude archived objects unless the caller asks for
them. `_archived=true` SHALL return only archived objects and
`_archived=any` SHALL return both. Reads by id, relation resolution and
`$ref` expansion SHALL be unaffected, so an archived object still answers
when another object points at it.

#### Scenario: the default list hides them

- **GIVEN** ten objects of which three are archived
- **WHEN** the object list is read without an `_archived` parameter
- **THEN** seven objects are returned

#### Scenario: the archived lens shows them

- **GIVEN** the same ten objects
- **WHEN** the list is read with `_archived=true`
- **THEN** the three archived objects are returned and the other seven are not

#### Scenario: a tile and its list agree

- **GIVEN** the same ten objects
- **WHEN** the aggregation endpoint counts them without an `_archived` parameter
- **THEN** the count is seven
- @e2e exclude {aggregation default, covered by unit tests}

#### Scenario: search does not find an archived object

- **GIVEN** an archived object whose title contains "Bouwvergunning"
- **WHEN** a user searches for that word
- **THEN** the archived object is not in the results
- @e2e exclude {search provider default, covered by unit tests}

#### Scenario: a reference into an archived object still resolves

- **GIVEN** an open object whose `contact` property points at an archived contact
- **WHEN** the open object is read with its references expanded
- **THEN** the archived contact's name is returned

### Requirement: An object can be frozen while staying visible and searchable (REQ-OAS-004)

An object MAY be frozen. A frozen object SHALL stay in working views and
in search results, SHALL refuse every write to its data, and SHALL be
unfrozen by an authorised principal. Freezing and unfreezing SHALL be
audit facts. A lifecycle state MAY declare that entering it freezes the
object.

#### Scenario: a zaak in bezwaar is findable and unchangeable

- **GIVEN** an object that is frozen
- **WHEN** a user lists and searches the register
- **THEN** the object appears, and a write to it is refused naming the frozen state

#### Scenario: closing a phase freezes its registration data

- **GIVEN** a lifecycle state declaring that entering it freezes the object
- **WHEN** the object enters that state
- **THEN** the object is frozen, with an audit entry naming the state

#### Scenario: frozen is not archived

- **GIVEN** one frozen object and one archived object
- **WHEN** the working list is read
- **THEN** the frozen one is present and the archived one is not

### Requirement: A property may be immutable once set (REQ-OAS-005)

A property MAY be declared immutable once set. An update that changes such
a property SHALL be refused, naming the property, whatever the object's
state and whoever the actor is. A property that has no value yet SHALL
still be settable.

#### Scenario: a vastgesteld besluit keeps its date

- **GIVEN** an immutable date property carrying a value
- **WHEN** an update sends a different value
- **THEN** the write is refused, naming the property

#### Scenario: an unset immutable property can still be set once

- **GIVEN** the same property with no value
- **WHEN** a value is written
- **THEN** it is accepted, and a later change is refused

### Requirement: A record closes to new entries, and an entry can be withdrawn or locked (REQ-OAS-006)

An object MAY be closed to new timeline entries and comments while staying
readable, with existing entries unchanged. An individual entry MAY be
withdrawn: it leaves the working timeline, stays in the record, and is
readable with its withdrawal, actor and reason by an authorised principal.
A note MAY be locked, after which edits to it are refused. Closing,
withdrawing and locking SHALL be audit facts.

#### Scenario: a dossier under beroep stops accepting correspondence

- **GIVEN** an object closed to new entries
- **WHEN** a new entry is written
- **THEN** it is refused, and the existing entries are unchanged

#### Scenario: a wrongly filed stuk goes without its arrival going

- **GIVEN** an entry that is withdrawn
- **WHEN** the working timeline is read
- **THEN** it is absent, and an authorised reader still sees it with the withdrawal and its actor

#### Scenario: a locked note is evidence

- **GIVEN** a locked note
- **WHEN** an edit is attempted
- **THEN** it is refused, naming the lock
