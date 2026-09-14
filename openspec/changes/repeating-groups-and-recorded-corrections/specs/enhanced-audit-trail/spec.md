# enhanced-audit-trail

## ADDED Requirements

### Requirement: A correction is its own act, with a required reason (REQ-RGC-003)

Correcting a core value after the fact SHALL require a correction right
and a reason, and SHALL be recorded as a correction rather than as an
ordinary update. The audit entry SHALL carry the reason and both values. A
principal without the correction right SHALL be refused, naming the right.

#### Scenario: a mis-registered case is corrected, not edited

- **GIVEN** a principal holding the correction right
- **WHEN** they correct a core value with a reason
- **THEN** the value changes and the audit entry is a correction carrying the reason and both values

#### Scenario: no reason, no correction

- **GIVEN** the same principal
- **WHEN** they correct a value with no reason
- **THEN** it is refused, naming the requirement

#### Scenario: an auditor can separate corrections from updates

- **GIVEN** a record with four updates and one correction
- **WHEN** the trail is filtered to corrections
- **THEN** one entry is returned

### Requirement: Consecutive edits merge only under an administered window (REQ-RGC-004)

The system SHALL default to recording every edit separately. An
administrator MAY set an aggregation window within which consecutive edits
by one actor on one object are recorded as a single entry, and that entry
SHALL name how many edits it covers.

#### Scenario: order is not merged away by default

- **GIVEN** no aggregation window
- **WHEN** one actor makes two edits a minute apart
- **THEN** two entries are recorded, in order

#### Scenario: a set window says what it merged

- **GIVEN** a window of five minutes
- **WHEN** one actor makes three edits inside it
- **THEN** one entry is recorded, naming three edits
- @e2e exclude {aggregation over time, covered by unit tests with a clock fixture}

### Requirement: A record's file metadata is corrected in one form (REQ-RGC-005)

Every file on an object, with its name and its description, SHALL be
editable and savable together in one act. One audit entry SHALL be written
per file actually changed, and a file left unchanged SHALL write none.

#### Scenario: tidying a dossier before it goes out

- **GIVEN** an object with six files, three of them badly named
- **WHEN** the three are renamed and saved together
- **THEN** all three are renamed and three audit entries are written

#### Scenario: nothing changed, nothing recorded

- **GIVEN** the same form saved with no edits
- **THEN** no audit entry is written
