# instance-hardening

## ADDED Requirements

### Requirement: A published statement is accepted before use, per version (REQ-IHC-001)

An administrator SHALL be able to publish a statement carrying a version.
Before the application renders for a user who has not accepted the current
version, the statement SHALL be shown and acceptance SHALL be required.
The acceptance SHALL be recorded with the user, the version and the time.
Publishing a new version SHALL ask every user again.

#### Scenario: first use asks, and records the answer

- **GIVEN** a published statement at version 2 and a user who has accepted nothing
- **WHEN** the user opens the application
- **THEN** the statement is shown, and accepting it records the user, version 2 and the time

#### Scenario: a new version asks again

- **GIVEN** a user who accepted version 2
- **WHEN** version 3 is published and the user returns
- **THEN** the statement is shown again

### Requirement: Administration requires a fresh, expiring authentication (REQ-IHC-002)

Opening the administration surface SHALL require a fresh authentication of
the signed-in identity. The elevated session SHALL expire after an
administered period, after which an administration write SHALL be refused
and authentication SHALL be required again. No second account SHALL be
required, and elevation SHALL be written to the audit trail.

#### Scenario: administration asks again

- **GIVEN** a signed-in administrator with no elevated session
- **WHEN** they open the administration surface
- **THEN** they are asked to authenticate before it renders

#### Scenario: the elevated session lapses

- **GIVEN** an elevated session older than the administered period
- **WHEN** an administration write is attempted
- **THEN** it is refused and a fresh authentication is required
- @e2e exclude {expiry over time, covered by unit tests with a clock fixture}

### Requirement: A scope may require a second factor, and a surface may be bound to addresses (REQ-IHC-003)

A register or a schema MAY declare that reading it requires a verified
second factor; a principal without one SHALL be refused. The
administration surface and the API MAY each carry an address allowlist; a
request from an address not on it SHALL be refused, and the refusal SHALL
name neither the allowlist nor its entries.

#### Scenario: a confidential register refuses a single factor

- **GIVEN** a register declaring that a second factor is required
- **WHEN** a user without a verified second factor reads it
- **THEN** the read is refused, naming the requirement

#### Scenario: an address outside the allowlist is refused blankly

- **GIVEN** an administration allowlist holding one address range
- **WHEN** a request arrives from outside it
- **THEN** it is refused and the response names no address and no range
- @e2e exclude {network condition, covered by unit tests}

### Requirement: Content does not leave to an unverified address or fetch remote references unasked (REQ-IHC-004)

A notification addressed to an unverified address SHALL carry a pointer to
the message and no case content. Remote references in an inbound message,
images included, SHALL NOT be fetched until the reader allows them, per
message, and the choice SHALL be recorded. A published surface SHALL
declare whether search engines may index it, and the instance SHALL answer
accordingly.

#### Scenario: an unverified recipient learns only that there is something to read

- **GIVEN** a party whose address is not verified
- **WHEN** a notification about their case is sent
- **THEN** it carries a link and no case data

#### Scenario: a tracking pixel does not fire

- **GIVEN** an inbound message holding a remote image
- **WHEN** a handler opens it
- **THEN** the image is not fetched until the handler allows it, and the choice is recorded

#### Scenario: a sign-in page is not indexed

- **GIVEN** a published surface declaring that it may not be indexed
- **WHEN** a crawler reads the instance's robots answer
- **THEN** that surface is disallowed
- @e2e exclude {crawler behaviour, covered by unit tests}

### Requirement: Privilege changes are guarded, and an expression reads only what is allowlisted (REQ-IHC-005)

Removing the last principal holding administration of a register, a schema
or an organisation SHALL be refused, naming what would be left without
one. A grant that would give access to more than an administered share of
a register in one act SHALL be held for a second administrator to confirm,
and both the attempt and the outcome SHALL be recorded. An expression
SHALL resolve an environment variable only when it is on the administered
allowlist; any other variable SHALL resolve as absent and the attempt
SHALL be logged. A principal MAY be barred from further interaction, with
a reason, reversibly, and what they already wrote SHALL stay and stay
attributed.

#### Scenario: the last administrator stays

- **GIVEN** a register with one administrator
- **WHEN** that grant is removed
- **THEN** the removal is refused, naming the register

#### Scenario: a whole afdeling is a deliberate act

- **GIVEN** a share threshold of ten per cent and a grant covering forty
- **WHEN** an administrator makes the grant
- **THEN** it is held pending a second administrator's confirmation, and the attempt is recorded

#### Scenario: an unallowlisted variable is absent, not an error

- **GIVEN** an expression reading an environment variable that is not allowlisted
- **WHEN** it is evaluated
- **THEN** the value resolves as absent and the attempt is logged
- @e2e exclude {evaluator behaviour, covered by unit tests}

#### Scenario: a barred person keeps their history

- **GIVEN** a principal who has written three notes and is then barred
- **WHEN** the object is read
- **THEN** the three notes are present and attributed, and no further write by that principal is accepted
