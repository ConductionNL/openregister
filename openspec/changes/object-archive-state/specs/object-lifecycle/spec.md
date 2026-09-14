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
