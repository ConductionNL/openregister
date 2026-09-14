# tenant-isolation-audit

## ADDED Requirements

### Requirement: A log line names the tenant pseudonymously and never carries a secret (REQ-SLE-003)

Application and audit log lines SHALL carry the organisation UUID and a
pseudonymous actor reference rather than a person's name or e-mail. Token
values, passwords and credential values SHALL be redacted before the line
is written. When redaction cannot be established, the line SHALL be
dropped and a counter SHALL record the drop.

#### Scenario: a token never reaches the log

- **GIVEN** a request carrying a bearer token that fails
- **WHEN** the failure is logged
- **THEN** the line names the organisation and holds no part of the token

#### Scenario: an unredactable line is dropped and counted

- **GIVEN** a log payload the redactor cannot establish as clean
- **WHEN** it is written
- **THEN** no line is emitted and the dropped-line counter increases by one
- @e2e exclude {logging path, covered by unit tests}

### Requirement: Entering administration requires a fresh, expiring session (REQ-SLE-004)

Entering the administration surface of an organisation SHALL require a
fresh authentication of the signed-in identity. The elevated session SHALL
expire on its own after an administered period, after which administration
requires authenticating again. No second account SHALL be required.

#### Scenario: administration asks again

- **GIVEN** a signed-in administrator who has not authenticated for administration
- **WHEN** they open the administration surface
- **THEN** they are asked to authenticate before it renders

#### Scenario: the elevated session lapses

- **GIVEN** an elevated session older than the administered period
- **WHEN** an administration write is attempted
- **THEN** it is refused and a fresh authentication is required
- @e2e exclude {session expiry, covered by unit tests with a clock fixture}
