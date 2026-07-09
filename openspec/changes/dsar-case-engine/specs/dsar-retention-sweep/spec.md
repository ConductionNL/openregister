## ADDED Requirements

### Requirement: Scheduled retention sweep enforcing declared windows
OpenRegister SHALL provide a scheduled `TimedJob` that enforces the windowed retention the
`dsar-case-subsystem` head declares on the case (`retentionWindow` / `retainUntil` stamps). On each
run the sweep SHALL identify data-subject-request cases whose `retainUntil` has passed, hard-delete
the case dossier, and scrub the case's evidence PII by reusing
`DataSubjectRequestService::erase(mode=pseudonymise)` — it MUST NOT hand-roll a scrub (ADR-011).
The job SHALL follow the existing `AvgRetentionJob` / `OCP\BackgroundJob\TimedJob` pattern with an
`IAppConfig` enabled toggle and a dry-run toggle, and every destructive action MUST be recorded in
the immutable audit trail.

#### Scenario: A dossier past its window is hard-deleted and its evidence scrubbed
- **WHEN** the sweep runs and a case's `retainUntil` has passed
- **THEN** the case dossier MUST be hard-deleted
- **AND** the case's evidence PII MUST be scrubbed via `DataSubjectRequestService::erase(mode=pseudonymise)`
- **AND** each action MUST be recorded in the immutable audit trail

#### Scenario: Cases still within their window are untouched
- **WHEN** the sweep runs and a case's `retainUntil` has not yet passed
- **THEN** the case MUST NOT be hard-deleted or scrubbed

#### Scenario: Dry-run reports without destroying
- **WHEN** the sweep runs with the dry-run toggle enabled
- **THEN** it MUST report the cases it would destroy without deleting or scrubbing any data

### Requirement: Retention sweep is legal-hold aware
The retention sweep MUST consult `RetentionService::hasActiveLegalHold` (and
`validateNotImmutable`) before deleting or scrubbing any case, and MUST skip any case under an
active legal hold, leaving it intact. This mirrors the legal-hold guarantee the erase path already
honours.

#### Scenario: A case under legal hold is skipped
- **WHEN** the sweep encounters a case past its `retainUntil` that is under an active legal hold
- **THEN** the case MUST be left intact (not deleted, not scrubbed)
- **AND** the skip MUST NOT advance any destruction on that case until the hold is released

@e2e An operator runs the retention sweep in dry-run and sees which expired cases would be purged; a case under legal hold is reported as skipped, and enabling the sweep hard-deletes only the non-held expired dossiers.
