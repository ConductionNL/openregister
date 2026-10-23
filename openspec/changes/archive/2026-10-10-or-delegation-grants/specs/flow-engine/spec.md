## ADDED Requirements

### Requirement: A trigger naming another user MUST be refused without a grant

A schedule trigger whose `runAs` names a user other than the flow's author MUST
fail to save unless the author holds a live delegation grant for that user.

Naming yourself remains free. `or-delegated-identity` already requires the field
to be present and resolvable; this adds that the author must be entitled to it.

#### Scenario: A trigger naming an ungranted user is refused at save

- **GIVEN** an author holding no grant for another user
- **WHEN** they save a flow whose schedule trigger names that user
- **THEN** the save is refused, naming both parties
- **AND** the flow is not stored

#### Scenario: A trigger naming the author is unaffected

- **WHEN** an author saves a schedule trigger naming themselves
- **THEN** the save succeeds without consulting the grant store

### Requirement: A run MUST be able to park awaiting consent

A run that requires a grant nobody has yet given MUST enter `awaiting_consent`
rather than failing outright, so that answering the request resumes it.

This state MUST be distinct from a wait on a signal or a timer: the thing that
resumes it is a grant record changing state, and an operator must be able to tell
the two apart.

On resume the identity and the grant MUST both be re-resolved, per
`delegated-identity`.

#### Scenario: A run needing consent parks rather than failing

- **WHEN** a run reaches a step requiring an ungranted identity, and a consent
  request can be raised
- **THEN** the run enters `awaiting_consent`
- **AND** it names the pending grant

#### Scenario: Granting consent resumes the parked run

- **GIVEN** a run parked on a pending grant
- **WHEN** the grant is granted
- **THEN** the run becomes resumable
- **AND** on resume it re-resolves both the identity and the grant
