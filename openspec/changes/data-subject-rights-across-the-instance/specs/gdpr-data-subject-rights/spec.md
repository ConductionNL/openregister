# gdpr-data-subject-rights

## ADDED Requirements

### Requirement: An erasure is previewed with counts before it runs (REQ-DSR-001)

Before an erasure runs, the system SHALL report how many objects, files,
timeline entries and party records it would touch, split into what would
be erased, what would be pseudonymised, and what is protected by a legal
hold or a retention period. The preview SHALL write nothing. An item whose
hold cannot be resolved SHALL be counted as protected and named.

#### Scenario: the gemeente can answer the subject honestly

- **GIVEN** a data subject present on twelve objects, four of them under retention
- **WHEN** the erasure is previewed
- **THEN** eight are reported as erasable, four as protected, and nothing is written

#### Scenario: an unresolvable hold is protected, not erased

- **GIVEN** an object whose retention cannot be resolved
- **WHEN** the preview runs
- **THEN** it is counted as protected and named
- @e2e exclude {resolution failure, covered by unit tests}

### Requirement: An erasure runs from an approved preview through the recorded destruction (REQ-DSR-002)

An erasure SHALL run only from an approved preview, and everything it
destroys SHALL go through the recorded destruction of the delete window.
The destruction record SHALL name the data subject request that caused it.
The audit of the erasure SHALL survive the erasure.

#### Scenario: one destruction path, one record

- **GIVEN** an approved erasure preview
- **WHEN** it runs
- **THEN** each destroyed item has a destruction record naming the request

#### Scenario: an unapproved erasure does not run

- **GIVEN** a preview that has not been approved
- **WHEN** the erasure is started
- **THEN** it is refused and nothing is written

### Requirement: A data subject takes their own machine readable export (REQ-DSR-003)

A data subject, or a handler acting for them, SHALL be able to take
everything the instance holds about that subject in a machine readable
form. The export SHALL be produced as a background job, delivered as a
file carrying its own expiry, and recorded on the audit trail with the
requester and the subject.

#### Scenario: article 20 is answered without a database export

- **GIVEN** a data subject present in several registers
- **WHEN** their own export is requested
- **THEN** a machine readable file is produced holding what the instance holds about them

#### Scenario: the file does not live forever

- **GIVEN** a delivered subject export past its expiry
- **WHEN** the link is used
- **THEN** it is refused
- @e2e exclude {expiry over time, covered by unit tests with a clock fixture}
