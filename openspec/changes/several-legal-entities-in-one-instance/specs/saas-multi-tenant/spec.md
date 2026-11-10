# saas-multi-tenant

## ADDED Requirements

### Requirement: A register or schema may be shared master data across organisations (REQ-SLE-001)

An organisation MAY be declared the holder of a register or a schema, and
other organisations MAY be declared its consumers. A consumer SHALL read
the holder's objects through the same tenant-scoped query path as its own,
and SHALL be refused any write to them, with the refusal naming the
holder. The organisation UUID SHALL remain the only tenant key, and a
shared row SHALL NOT be copied into the consumer.

#### Scenario: two entities read one code list

- **GIVEN** organisation A holds a code list register and organisation B consumes it
- **WHEN** a user of B lists the code list
- **THEN** the rows A holds are returned

#### Scenario: a consumer cannot write the holder's row

- **GIVEN** the sharing above
- **WHEN** a user of B updates one of those rows
- **THEN** the write is refused and the message names organisation A

#### Scenario: an organisation that consumes nothing is unchanged

- **GIVEN** an organisation with no shared master declaration
- **WHEN** it lists its registers
- **THEN** it sees exactly what it saw before this change

### Requirement: An object graph moves between organisations under a per-type policy (REQ-SLE-002)

The system SHALL move an object and the graph beneath it from one
organisation to another. Each object type under the root SHALL carry a
policy of `move`, `copy` or `drop`. The operation SHALL be previewed
first, listing every object and the policy that will apply to it, and
SHALL only run on an approved preview. The completed move SHALL be written
to the audit trail of both organisations under one correlation.

#### Scenario: a case moves to the omgevingsdienst with its documents

- **GIVEN** a case with documents, notes and a shared party, and a policy of move, drop and copy in that order
- **WHEN** the move to the other organisation is previewed and approved
- **THEN** the documents move, the notes are dropped, the party is copied, and both audit trails hold the act

#### Scenario: an unapproved move does not run

- **GIVEN** a preview that has not been approved
- **WHEN** the move endpoint is called
- **THEN** it is refused and nothing is written
- @e2e exclude {guard behaviour, covered by unit tests}
