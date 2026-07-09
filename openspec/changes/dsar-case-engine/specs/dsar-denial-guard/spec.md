## ADDED Requirements

### Requirement: Denial-finalise lifecycle guard enforcing a mandatory regulator reference
OpenRegister SHALL provide the lifecycle-transition guard class that the `dsar-case-subsystem`
head's `finaliseDenial` transition references via its `requires` binding (ADR-031 §3). The guard
MUST refuse the `finaliseDenial` transition when the case's `regulatorReference` is empty, and MUST
permit it only when a `regulatorReference` is present. The guard MUST NOT be invoked for
`draftDenial`: drafting a denial (recording a `denialGround`) MUST remain possible without a
regulator reference. The guard MUST fail closed — if it cannot determine that the precondition is
satisfied, it MUST refuse the transition rather than allow it.

#### Scenario: Finalise denial is blocked without a regulator reference
- **WHEN** a handler attempts `finaliseDenial` on a case whose `regulatorReference` is empty
- **THEN** the guard MUST refuse the transition
- **AND** the case MUST NOT enter the finalised-denial outcome

#### Scenario: Finalise denial succeeds once the reference is recorded
- **WHEN** a handler records a `regulatorReference` and then attempts `finaliseDenial`
- **THEN** the guard MUST permit the transition
- **AND** the case MUST reach the `refused` final state with the `denialGround` and `regulatorReference` persisted

#### Scenario: Drafting a denial is not gated
- **WHEN** a handler runs `draftDenial` recording a `denialGround` but no `regulatorReference`
- **THEN** the guard MUST NOT block the draft (the gate applies only at finalise)

@e2e A steward drafts a denial with no regulator reference (allowed), attempts finalise (blocked by the guard), records the reference, then finalises (allowed) — the case ends refused with ground and reference persisted.
