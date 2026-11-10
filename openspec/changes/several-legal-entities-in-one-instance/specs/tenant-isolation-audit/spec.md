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
