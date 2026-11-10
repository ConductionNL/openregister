# referential-integrity

## ADDED Requirements

### Requirement: The relations of a record answer who else is affected (REQ-RTE-001)

The system SHALL answer, for a root object, the objects and the parties
reachable along the relation types the caller names, within the administered
depth. Each entry SHALL carry the path that reached it, the relation type of
each edge and its direction. The answer SHALL be evaluated for the calling
principal, SHALL never include a record the caller may not read, and SHALL
report truncation by depth or by cap exactly as the graph read does.

#### Scenario: who else hears about this decision

- **GIVEN** a case referencing two objects, each referencing a party, within the administered depth
- **WHEN** the affected set is read for the relation types that carry an interest
- **THEN** both objects and both parties are returned, each with the path that reached it

#### Scenario: a record the caller may not read is absent

- **GIVEN** a reachable object the caller has no read access to
- **WHEN** the affected set is read
- **THEN** that object is not in the answer
- @e2e exclude {access evaluation, covered by unit tests}

#### Scenario: a truncated answer says so

- **GIVEN** a graph deeper than the administered maximum
- **WHEN** the affected set is read
- **THEN** the answer is bounded and marked truncated
- @e2e exclude {traversal bound, covered by unit tests}

### Requirement: A branch of the walk is pruned, and the prune is reported (REQ-RTE-002)

The affected-set read SHALL accept relation types to exclude, and SHALL NOT
traverse past an excluded edge. The answer SHALL list what was excluded: the
relation type, and the node the walk stopped at. An excluded branch SHALL
NOT be silently omitted.

#### Scenario: the archive branch is not notified

- **GIVEN** a root whose relations include an archival reference reaching forty objects
- **WHEN** the affected set is read excluding that relation type
- **THEN** none of the forty is in the answer
- **AND** the exclusion is reported naming the relation type and the node it stopped at

#### Scenario: nothing excluded behaves as the unpruned walk

- **GIVEN** the same root and no exclusions
- **WHEN** the affected set is read
- **THEN** the answer equals the unpruned walk and reports no exclusions

### Requirement: Two parties hold a typed, reciprocal relationship for a period (REQ-RTE-003)

A relationship between two parties SHALL be its own record, naming both
parties, a relationship type, and the period it runs. A relationship type
SHALL declare the party kind permitted at each end, a label and a reciprocal
label, drawn from the same vocabulary a record relation uses. A relationship
whose ends do not match the declared kinds SHALL be refused with HTTP 422
naming the end, and a party SHALL NOT hold a relationship to itself. Reading
a party SHALL return its relationships with the label for the direction it
is read from.

#### Scenario: a guardian and a ward are one fact read two ways

- **GIVEN** a relationship type `guardian of` with reciprocal label `in guardianship of` and two person parties
- **WHEN** the relationship is created and each party is read
- **THEN** the first reads `guardian of` the second and the second reads `in guardianship of` the first

#### Scenario: an organisation cannot be somebody's guardian

- **GIVEN** a relationship type declaring person at both ends
- **WHEN** a relationship is created with an organisation at one end
- **THEN** it is refused with HTTP 422 naming that end
- @e2e exclude {validator, covered by unit tests}

#### Scenario: an ended relationship stops being current

- **GIVEN** a relationship whose period has passed
- **WHEN** the party's current relationships are read
- **THEN** it is not among them and is still readable as history
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}
