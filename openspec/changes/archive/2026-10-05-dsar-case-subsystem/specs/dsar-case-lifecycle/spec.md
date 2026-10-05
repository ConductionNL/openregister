## ADDED Requirements

### Requirement: Configurable N-state case status graph
OpenRegister SHALL express the data-subject-request case status graph as declarative
`x-openregister-lifecycle` config on the `dataSubjectRequest` schema, not as a hard-coded PHP
state machine. The graph SHALL preserve the existing initial state (`received`) and final states
(`fulfilled`, `refused`, `closed`) and SHALL extend the intermediate states with the
case-management transitions: `assign` (handler assignment), `collectEvidence`, `draftDenial`,
`finaliseDenial`, `redact`, `bundle`, and `retain`. The state set and its transitions SHALL be
register configuration so a jurisdiction or tenant can add or rename states without a code change.
Every transition MUST be audited through the existing immutable hash-chained audit trail
(`AuditTrailMapper`); no parallel transition log MUST be introduced (ADR-022, ADR-031).

#### Scenario: Case advances through declared transitions
- **WHEN** a case in an intermediate state is advanced by a declared transition (e.g. `assign` then `collectEvidence`)
- **THEN** the case `status` MUST change to the transition's target state
- **AND** the transition MUST be recorded in the object's immutable audit trail

#### Scenario: Initial and final semantics are preserved
- **WHEN** the extended lifecycle is imported
- **THEN** the initial state MUST remain `received` and the final states MUST remain `fulfilled`, `refused`, and `closed`

#### Scenario: State set is config, not hard-coded
- **WHEN** an operator adds or renames a case state in the register configuration
- **THEN** the new state graph MUST take effect without any PHP code change

@e2e A steward opens a received case, assigns a handler, and advances it through collectEvidence, and the status + audit trail reflect each transition.

### Requirement: Mandatory regulator-reference gate before denial finalise
The lifecycle SHALL guard the `finaliseDenial` transition so that a case MAY be moved into a
finalised-denial outcome ONLY when a `regulatorReference` is present on the case. When
`regulatorReference` is empty, the `finaliseDenial` transition MUST be refused. The `draftDenial`
transition (recording a proposed `denialGround`) MUST NOT require the regulator reference — the
gate applies only at finalise. The guard MAY be expressed as a lifecycle `requires` reference to a
short PHP guard (delivered by `dsar-case-engine`, ADR-031 §3) or, where the lifecycle engine can
express a required-field precondition natively, as declarative config.

#### Scenario: Finalise denial is blocked without a regulator reference
- **WHEN** a handler attempts `finaliseDenial` on a case whose `regulatorReference` is empty
- **THEN** the transition MUST be refused and the case MUST NOT enter the finalised-denial outcome

#### Scenario: Finalise denial succeeds once the reference is recorded
- **WHEN** a handler records a `regulatorReference` and then attempts `finaliseDenial`
- **THEN** the transition MUST succeed and the case MUST reach the `refused` final state with the ground and reference persisted

#### Scenario: Drafting a denial does not require the reference
- **WHEN** a handler runs `draftDenial` recording a `denialGround` but no `regulatorReference`
- **THEN** the transition MUST succeed (the reference is only required at finalise)

@e2e A steward drafts a denial without a regulator reference (allowed), attempts finalise (blocked), records the reference, then finalises (allowed) — the case ends refused.
