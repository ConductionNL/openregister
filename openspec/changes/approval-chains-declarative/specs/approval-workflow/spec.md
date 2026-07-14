## ADDED Requirements

### REQ-006: A schema MAY declare `x-openregister-approval-chains` provisioning an approval chain

A schema's `configuration` block MAY declare `x-openregister-approval-chains`, a
map of chain-key → chain spec. Each chain spec MUST name a `transition` matching
an action key in the same schema's `x-openregister-lifecycle.transitions`, and
MUST declare `approvers` (a non-empty list of `{role, min}`, optionally
`minAmount` per entry plus a top-level `amountField` for threshold-tier routing).
`ApprovalChainAnnotationInstaller` MUST upsert an `ApprovalChain` row (`name` =
chain key, `schemaId`, `steps` derived from `approvers`) on `SchemaCreatedEvent`/
`SchemaUpdatedEvent`, using the same row shape `POST /api/approval-chains`
produces. Schemas without this key are unaffected.

#### Scenario: Declaring a chain provisions it without manual CRUD
- **GIVEN** a schema declares `x-openregister-approval-chains` with one chain entry
- **WHEN** the schema is saved (create or update)
- **THEN** an `ApprovalChain` row MUST exist with `name` equal to the chain key and `schemaId` equal to the schema's id
- **AND** `GET /api/approval-chains` MUST list it exactly as if it had been created via `POST /api/approval-chains`

### REQ-007: A transition named by a declared chain MUST be blocked until its steps are all approved

When an object's lifecycle transition matches a chain's declared `transition`,
`ApprovalChainGateListener` MUST reject the `ObjectUpdatingEvent` (error code
`approval-chain-pending`) unless every `ApprovalStep` for (chain, object) is
`approved`. On the first attempt with no existing steps, the listener MUST
provision steps via `ApprovalService::initializeChain()` before rejecting.
A second attempt while steps are still in progress MUST NOT create duplicate
steps.

#### Scenario: First attempt provisions steps and is blocked
- **GIVEN** an object with no prior `ApprovalStep` rows attempts a gated transition
- **WHEN** the save is processed
- **THEN** `ApprovalStep` rows MUST be created for the object via `initializeChain()`
- **AND** the `ObjectUpdatingEvent` MUST be rejected with error code `approval-chain-pending`
- **AND** the object's lifecycle field MUST NOT change

#### Scenario: A second attempt while pending does not duplicate steps
- **GIVEN** an object already has in-progress `ApprovalStep` rows for the gated transition
- **WHEN** the transition is attempted again before all steps are approved
- **THEN** no additional `ApprovalStep` rows MUST be created
- **AND** the attempt MUST be rejected again

#### Scenario: A rejected cycle is cleared and reopened on the next attempt
- **GIVEN** an object's steps for a chain include one `rejected` step
- **WHEN** the gated transition is attempted again
- **THEN** the stale steps MUST be removed and a fresh set provisioned via `initializeChain()`
- **AND** the attempt MUST be rejected again (`approval-chain-pending`) pending the new cycle

### REQ-008: Threshold routing selects a single approver tier by amount

When a chain spec declares `amountField`, `ApprovalChainGateListener` MUST select
the single `approvers` entry with the highest `minAmount` that is
`<= object[amountField]` as the step(s) to provision, rather than the chain's full
static `steps`. When `amountField` is absent, provisioning falls back to the
chain's declared `steps` unchanged.

#### Scenario: Low-amount object routes to the lower tier
- **GIVEN** a chain spec with tiers `{role: finance-clerks, minAmount: 0}` and `{role: finance-directors, minAmount: 100000}`
- **WHEN** a gated transition is attempted on an object with `amount = 5000`
- **THEN** the provisioned step(s) MUST require role `finance-clerks` only

#### Scenario: High-amount object routes to the higher tier
- **GIVEN** the same chain spec
- **WHEN** a gated transition is attempted on an object with `amount = 250000`
- **THEN** the provisioned step(s) MUST require role `finance-directors` only

### REQ-009: Decisions MUST enforce separation of duties when declared

When a chain's schema declares a matching `x-openregister-approval-chains` entry,
`ApprovalService::approveStep()`/`rejectStep()` MUST reject a decision whose
deciding user id equals the step's `requesterId`, unless the entry explicitly sets
`separationOfDuties: false`. Chains with no matching declarative entry (the
pre-existing pure-CRUD-provisioned flow) MUST be unaffected.

#### Scenario: Requester cannot decide their own chain's step
- **GIVEN** a step whose `requesterId` is `alice`, provisioned under a chain whose schema declares `separationOfDuties: true` (the default)
- **AND** `alice` is also a member of the step's required role group
- **WHEN** `alice` calls `approveStep()`
- **THEN** the decision MUST be rejected
- **AND** the step MUST remain `pending`

#### Scenario: Pure-CRUD chains are unaffected
- **GIVEN** a step created via `POST /api/approval-chains` + manual object entry, with no matching schema declaration
- **WHEN** the step's own requester (if tracked at all) attempts to decide it
- **THEN** `resolveSeparationOfDuties()` MUST return `false` and the decision MUST proceed exactly as before this change

### REQ-010: A completed chain MUST auto-advance the gated transition when declared

When `ApprovalStepCompletedEvent` fires for a chain whose schema declares
`onApprove: advanceTransition` for that chain, `ApprovalChainAdvanceListener` MUST
invoke `TransitionEngine::transition()` for the completed chain's object and the
declared `transition` action, in the same request. Chains with no matching
declarative entry, or a declared `onApprove` other than `advanceTransition`, MUST
NOT trigger this call.

#### Scenario: Completion auto-advances the parent transition
- **GIVEN** a chain's final step is approved, completing it
- **AND** the chain's schema declares `onApprove: advanceTransition` for that chain
- **WHEN** `ApprovalStepCompletedEvent` is dispatched
- **THEN** `TransitionEngine::transition()` MUST be invoked for the chain's object and the declared `transition`
- **AND** the object's lifecycle field MUST reach the transition's declared `to` state

#### Scenario: A chain without the declaration does not auto-advance
- **GIVEN** a chain provisioned via pure CRUD with no matching schema declaration
- **WHEN** its `ApprovalStepCompletedEvent` fires
- **THEN** `TransitionEngine::transition()` MUST NOT be invoked
