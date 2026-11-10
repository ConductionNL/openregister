# enhanced-audit-trail

## ADDED Requirements

### Requirement: The audit trail is written to a configured file sink (REQ-ATS-001)

In addition to the database, audit entries SHALL be written to a
structured file in a configured location and a documented format. The file
SHALL be append-only from the application's side. A failure to write to
the sink SHALL itself be recorded as an audit entry and SHALL be reported
on the operations console.

#### Scenario: the security operations centre can read the trail

- **GIVEN** a configured file sink
- **WHEN** an auditable act happens
- **THEN** an entry appears in the database and a line in the file

#### Scenario: a broken sink is loud

- **GIVEN** a sink location that cannot be written
- **WHEN** an auditable act happens
- **THEN** the database entry is written, a failure entry is recorded, and the console reports it
- @e2e exclude {filesystem condition, covered by unit tests}

### Requirement: A write made with a token names the token, its owner and its consumer (REQ-ATS-002)

An audit entry for a write made with an API token SHALL name the token,
the principal who owns it and the consumer it belongs to, beside the
before and after values the entry already carries. Request and response
payloads SHALL NOT be stored.

#### Scenario: which integration changed this field

- **GIVEN** a field changed by a call made with a supplier's token
- **WHEN** the entry is read
- **THEN** it names the token, its owner, the consumer, and the value before and after

#### Scenario: no payload copy

- **GIVEN** the same call carrying a body
- **WHEN** the entry is read
- **THEN** no request or response body is present

### Requirement: Reported content keeps a copy that removal does not destroy (REQ-ATS-003)

When content is reported for review, the system SHALL copy it at the
moment the report is filed. The copy SHALL be readable only by the
reviewers, SHALL carry its own retention, and a later removal of the
content SHALL name the copy.

#### Scenario: deleting the content does not delete the evidence

- **GIVEN** reported content that is then removed
- **WHEN** a reviewer opens the report
- **THEN** the copy is readable and the removal record names it

#### Scenario: the copy is not generally readable

- **GIVEN** the same copy
- **WHEN** a user who is not a reviewer asks for it
- **THEN** it is refused
- @e2e exclude {access resolution, covered by unit tests}

### Requirement: A security-relevant setting change is announced (REQ-ATS-004)

A setting MAY be marked security relevant. When such a setting changes,
the administrators SHALL be notified, naming the setting, the actor and
the old and new values where neither is a secret. A secret value SHALL be
announced as changed without being quoted.

#### Scenario: the beheerteam hears about it

- **GIVEN** a setting marked security relevant
- **WHEN** it changes
- **THEN** the administrators are notified with the setting, the actor and both values

#### Scenario: a secret is announced without being shown

- **GIVEN** a security-relevant setting holding a credential
- **WHEN** it changes
- **THEN** the notification says it changed and quotes neither value
