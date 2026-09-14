# rbac-scopes

## ADDED Requirements

### Requirement: A grant may name a role slot on the record rather than a principal (REQ-GSR-001)

A per-object grant MAY name a party role declared on the record instead of a
user or a group. The verbs SHALL be held by whoever occupies that role at the
moment access is evaluated, SHALL move with the occupant when the role is
reassigned, and SHALL be held by nobody while the role is vacant. No grant
SHALL be copied onto the occupant. The scopes read SHALL name the slot as the
source of such a grant.

#### Scenario: the right follows the behandelaar

- **GIVEN** a case with a grant of `update` to the role `behandelaar`, held by one user
- **WHEN** the role is reassigned to a colleague
- **THEN** the colleague may update the case
- **AND** the first user may no longer update it
- **AND** no grant was edited

#### Scenario: an empty slot grants nothing

- **GIVEN** the same case with the role vacant
- **WHEN** any user's access is evaluated
- **THEN** nobody holds the slot's verbs

#### Scenario: the answer names the slot

- **GIVEN** a user holding access only through the slot
- **WHEN** their scopes are read for the case
- **THEN** the source of the grant is the role slot

### Requirement: A party relationship may grant dated access to the other party's records (REQ-GSR-002)

A party relationship type MAY declare the verbs it grants on the records of
the party at the other end. The grant SHALL hold only while the relationship
record's period is current, SHALL be resolved at evaluation time rather than
written onto the records, and SHALL be reported by the scopes read with the
relationship as its source. A relationship that has ended or has not started
SHALL grant nothing.

#### Scenario: a gemachtigde reads what they are entitled to read

- **GIVEN** a relationship type `gemachtigde of` declaring `read`, and a current relationship between two parties
- **WHEN** the gemachtigde reads a record of the other party
- **THEN** the read is allowed
- **AND** the scopes read names the relationship as the source

#### Scenario: the access ends with the mandate

- **GIVEN** the same relationship, whose period has passed
- **WHEN** the same read is attempted
- **THEN** it is refused
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: nothing is written onto the records

- **GIVEN** a relationship granting access to forty records
- **WHEN** the relationship is created
- **THEN** no grant is written on any of the forty

### Requirement: Handing work to somebody is its own verb (REQ-GSR-003)

`assign` SHALL be part of the governed permission vocabulary and SHALL
appear in the published permission catalogue. Reassigning a record SHALL be
gated on `assign` for the record rather than on an administrator check.
`assign` SHALL be grantable without `update`, and holding `update` SHALL NOT
imply it.

#### Scenario: a coordinator who cannot edit can still hand work over

- **GIVEN** a user holding `read` and `assign` on a record and not `update`
- **WHEN** they reassign it to a colleague
- **THEN** the reassignment succeeds
- **AND** a write to the record's data is still refused

#### Scenario: an editor cannot reassign by virtue of editing

- **GIVEN** a user holding `read` and `update` and not `assign`
- **WHEN** they try to reassign the record
- **THEN** the reassignment is refused

#### Scenario: the verb is discoverable

- **GIVEN** the permission catalogue
- **WHEN** it is read
- **THEN** `assign` is listed with its description
- @e2e exclude {catalogue read, covered by unit tests}

### Requirement: Emergency self-granted access is declared, bounded and announced (REQ-GSR-004)

A schema MAY declare that emergency self-granted access is available, naming
the verbs it may grant and its maximum duration. A principal SHALL be able
to take such a grant on one record only by supplying a reason. The grant
SHALL carry exactly the declared verbs, SHALL expire at the declared
duration without any action, and SHALL NOT be renewable beyond an
administered limit. Taking it SHALL notify a declared recipient at that
moment. A schema that does not declare it SHALL refuse the attempt.

#### Scenario: somebody at the counter is helped, on the record

- **GIVEN** a schema declaring emergency access granting `read` for four hours
- **WHEN** a handler takes it on one record with a reason
- **THEN** they may read that record
- **AND** the declared recipient is notified at that moment

#### Scenario: it is not a route to administration

- **GIVEN** the same declaration
- **WHEN** the handler attempts to update the record under the grant
- **THEN** the write is refused

#### Scenario: it ends by itself

- **GIVEN** the same grant, four hours later
- **WHEN** the handler reads the record again
- **THEN** the read is refused
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: a schema that does not allow it refuses

- **GIVEN** a schema declaring no emergency access
- **WHEN** a principal attempts to take it
- **THEN** the attempt is refused naming the schema

### Requirement: A grant may be marked as not travelling to descendants (REQ-GSR-005)

A per-object grant MAY be marked as not inheritable. Such a grant SHALL hold
on the object it is written on and SHALL NOT be resolved for any descendant
through the declared hierarchy, while remaining distinct from a deny, which
removes a verb inside its scope. The scopes read and the access review SHALL
report the flag beside the provenance of every grant.

#### Scenario: access to the parent does not reach the children

- **GIVEN** a root object with a `read` grant marked not inheritable, and a child
- **WHEN** the grantee reads the child
- **THEN** the read is refused

#### Scenario: it is not a deny

- **GIVEN** the same grantee holding a separate inheritable `read` grant on the root through a group
- **WHEN** they read the child
- **THEN** the read is allowed through the inheritable grant

#### Scenario: the review can be finished

- **GIVEN** an object whose grants include inherited, direct and not-inheritable ones
- **WHEN** the access review is read
- **THEN** every grant names its source and whether it travels
- @e2e exclude {discovery endpoint, covered by Newman}
