---
retrofit: true
status: done
---
# Approval Workflow

## Purpose

@e2e exclude REST API CRUD — covered by Newman; no dedicated OR UI surface

Approval Workflow provides multi-step, role-gated approval chains for OpenRegister objects. Administrators configure named chains with ordered steps, each bound to a Nextcloud group (the "role"). When an object enters a chain, one `ApprovalStep` record per chain step is created — step 1 starts as `pending`, all others as `waiting`. Authorised users approve or reject the pending step; on approval the next waiting step is automatically advanced to `pending`. Each decision is persisted to the workflow execution history.

## Requirements

### REQ-006: A schema MAY declare `x-openregister-approval-chains` provisioning an approval chain

A schema's `configuration` block MAY declare `x-openregister-approval-chains`,
a map of chain-key → chain spec. Each chain spec MUST name a `transition`
matching an action key in the same schema's
`x-openregister-lifecycle.transitions`, and MUST declare `approvers` (a
non-empty list of `{role, min}`, optionally `minAmount` per entry plus a
top-level `amountField` for threshold-tier routing).

**The declared shape SHALL NOT change.** A schema that declared a chain
before this change SHALL declare it identically after, and SHALL require no
edit of any kind. What changes is only what the declaration provisions: on
`SchemaCreatedEvent` / `SchemaUpdatedEvent` the system SHALL upsert a **task
template** (named by the chain key, bound to the schema, with one ordered
position per `approvers` entry carrying that entry's role) instead of an
`ApprovalChain` row. Schemas without this key SHALL be unaffected.

`x-openregister-approval-chains` SHALL remain registered in the schema
annotation vocabulary. A configuration write drops any `x-openregister-*` key
absent from that whitelist, so removing the registration would make every
declared gate in the fleet silently inert — an open door rather than a
refusal.

Provisioning SHALL be idempotent: saving an unchanged schema repeatedly SHALL
converge on one template, and SHALL NOT create a second template, a second
position, or a new template version.

#### Scenario: The declared key survives a save round-trip
- **GIVEN** a schema whose `configuration` declares `x-openregister-approval-chains`
- **WHEN** the schema is saved and re-read
- **THEN** the configuration MUST still contain the `x-openregister-approval-chains` key, byte-identical to what was submitted
- @e2e exclude annotation-vocabulary persistence — covered by schema unit tests

#### Scenario: Declaring a chain provisions a task template without manual CRUD
- **GIVEN** a schema declares `x-openregister-approval-chains` with one chain entry naming two approver roles
- **WHEN** the schema is saved (create or update)
- **THEN** a task template MUST exist named by the chain key and bound to the schema
- **AND** it MUST carry two ordered positions whose candidate groups are the two declared roles, in declaration order
- @e2e exclude provisioning contract — covered by installer unit tests

#### Scenario: Re-saving an unchanged schema provisions nothing new
- **GIVEN** a schema whose declared chain has already been provisioned
- **WHEN** the schema is saved again with the same declaration
- **THEN** exactly one template MUST exist for that chain key
- **AND** no new template version MUST be created
- @e2e exclude idempotency — covered by installer unit tests

### REQ-007: A transition named by a declared chain MUST be blocked until its steps are all approved

When an object's lifecycle transition matches a chain's declared `transition`,
the system SHALL REFUSE the object write with the error code
`approval-chain-pending` unless the object's approval **sequence** for that
chain is complete with an approving outcome. The refusal SHALL be fail-closed:
a chain that is declared but cannot be provisioned SHALL refuse the transition
with `approval-chain-misconfigured`, and SHALL NOT let it through.

On the first attempt, with no sequence for (chain, object), the system SHALL
provision the sequence — enabling its first position and creating the rest —
before refusing. A second attempt while the sequence is still running SHALL
NOT provision a second sequence and SHALL NOT create a duplicate task at any
position.

A sequence terminated by a rejection SHALL be closed rather than deleted, and
the next attempt SHALL open a NEW sequence. The rejected sequence, its tasks,
its comments and its audit SHALL remain readable afterwards — today the
rejected cycle's step rows are DELETED
(`lib/Listener/ApprovalChainGateListener.php:232`), which destroys the record
of who refused and why at the moment somebody resubmits.

The error codes `approval-chain-pending` and `approval-chain-misconfigured`
SHALL be unchanged, because leaf apps and UIs match on them.

#### Scenario: First attempt provisions a sequence and is refused
- **GIVEN** an object with no approval sequence attempts a gated transition
- **WHEN** the save is processed
- **THEN** a sequence MUST be provisioned with its first position enabled and later positions not enabled
- **AND** the write MUST be refused with error code `approval-chain-pending`
- **AND** the object's lifecycle field MUST NOT change
- @e2e exclude gate refusal — covered by gate-listener unit tests

#### Scenario: A second attempt while running does not duplicate work
- **GIVEN** an object whose approval sequence is running
- **WHEN** the transition is attempted again before the sequence completes
- **THEN** no second sequence and no duplicate task MUST be created
- **AND** the attempt MUST be refused again with `approval-chain-pending`
- @e2e exclude idempotency of the gate — covered by gate-listener unit tests

#### Scenario: A rejected cycle is closed, preserved, and reopened on the next attempt
- **GIVEN** an object whose approval sequence was terminated by a rejection
- **WHEN** the gated transition is attempted again
- **THEN** a NEW sequence MUST be provisioned and the attempt MUST be refused with `approval-chain-pending`
- **AND** the rejected sequence, the rejecting decision, its comment and its actor MUST still be readable
- @e2e exclude resubmission history — covered by sequence-service unit tests

#### Scenario: A declared chain that cannot be provisioned fails closed
- **GIVEN** a schema declaring a chain whose approvers resolve to no usable role
- **WHEN** the gated transition is attempted
- **THEN** the write MUST be refused with error code `approval-chain-misconfigured`
- **AND** the transition MUST NOT be allowed through
- @e2e exclude fail-closed policy — covered by gate-listener unit tests

### REQ-008: Threshold routing selects a single approver tier by amount

When a chain spec declares `amountField`, provisioning SHALL select the single
`approvers` entry with the highest `minAmount` that is
`<= object[amountField]`, and SHALL provision the sequence from that entry
alone rather than from every declared tier. When `amountField` is absent,
provisioning SHALL use every declared entry in order, unchanged.

The tier SHALL be resolved once, at provisioning time, from the object data
being written, and SHALL be frozen onto the sequence. A later edit to the
amount SHALL NOT silently re-route a running sequence to a different approver
— the sequence records which tier it was opened under, and a changed amount is
a new attempt, not a live re-route.

#### Scenario: Low-amount object routes to the lower tier
- **GIVEN** a chain spec with tiers `{role: finance-clerks, minAmount: 0}` and `{role: finance-directors, minAmount: 100000}`
- **WHEN** a gated transition is attempted on an object with `amount = 5000`
- **THEN** the provisioned sequence MUST have exactly one position, with candidate group `finance-clerks`
- @e2e exclude tier selection — covered by threshold-routing unit tests

#### Scenario: High-amount object routes to the higher tier
- **GIVEN** the same chain spec
- **WHEN** a gated transition is attempted on an object with `amount = 250000`
- **THEN** the provisioned sequence MUST have exactly one position, with candidate group `finance-directors`
- @e2e exclude tier selection — covered by threshold-routing unit tests

#### Scenario: The resolved tier is frozen onto the sequence
- **GIVEN** a running sequence opened under the `finance-clerks` tier
- **WHEN** the object's amount is changed to a value that would resolve to `finance-directors`
- **THEN** the running sequence MUST keep its `finance-clerks` position
- **AND** the recorded tier MUST still name `finance-clerks`
- @e2e exclude frozen-tier rule — covered by sequence-service unit tests

### REQ-009: Decisions MUST enforce separation of duties when declared

When a chain's schema declares a matching `x-openregister-approval-chains`
entry, the system SHALL REFUSE a decision whose deciding identity is the
sequence's recorded requester, unless the entry explicitly sets
`separationOfDuties: false`. Absent the key, separation of duties SHALL
default to ON — an unstated policy on an approval is the safe one, not the
permissive one.

The check SHALL be evaluated against the identity that is actually deciding
AND against the identity being acted for. A delegated decision whose
`on_behalf_of` resolves to the requester SHALL be refused on the same
grounds; a self-decision routed through a delegate is the same self-decision.
Delegation does not exist in the retired engine, so this is the one place
where the migrated behaviour is deliberately stricter than what it replaces.

The refusal SHALL be distinguishable from an authorization failure: a
requester who is also a member of the candidate group is authorized and is
still refused, and the reason SHALL say which of the two applied.

A sequence with no recorded requester SHALL NOT be refused on these grounds.

#### Scenario: Requester cannot decide their own sequence
- **GIVEN** a sequence whose requester is `alice`, under a schema declaring the default `separationOfDuties`
- **AND** `alice` is a member of the position's candidate group
- **WHEN** `alice` attempts to complete the enabled task with an approving outcome
- **THEN** the decision MUST be refused with a reason naming separation of duties, not authorization
- **AND** the task MUST remain non-terminal
- @e2e exclude authorization rule — covered by sequence-authorization unit tests

#### Scenario: A delegate cannot decide on the requester's behalf
- **GIVEN** the same sequence, and a delegate acting with `on_behalf_of` set to `alice`
- **WHEN** the delegate attempts to complete the enabled task
- **THEN** the decision MUST be refused on separation-of-duties grounds
- @e2e exclude delegation loophole — covered by sequence-authorization unit tests

#### Scenario: An opted-out chain permits the requester to decide
- **GIVEN** a schema declaring `separationOfDuties: false` for the chain
- **WHEN** the requester, who is in the candidate group, completes the enabled task
- **THEN** the decision MUST be accepted
- @e2e exclude explicit opt-out — covered by sequence-authorization unit tests

### REQ-010: A completed chain MUST auto-advance the gated transition when declared

When an approval sequence completes with an approving outcome for a chain
whose schema declares `onApprove: advanceTransition`, the system SHALL apply
the declared `transition` to the sequence's anchor object in the same request
as the completing decision. A chain with no matching declaration, or a
declared `onApprove` other than `advanceTransition`, SHALL NOT advance
anything.

The auto-advance SHALL remain fail-soft in the same sense it is today
(`lib/Listener/ApprovalChainAdvanceListener.php:110-121`): a transition that
throws SHALL leave the sequence correctly completed and the object at its
pre-gate state, SHALL be logged with the action and the object, and SHALL NOT
re-open, re-run or invalidate the approval. A failure to advance SHALL NOT be
reported to the deciding user as a failure to approve — the approval
succeeded.

A subsequent manual attempt at the same transition SHALL be allowed through,
because the sequence is complete and the gate has nothing left to refuse.

#### Scenario: Completion auto-advances the parent transition
- **GIVEN** a sequence whose final position is approved, completing it
- **AND** the schema declares `onApprove: advanceTransition` for that chain
- **WHEN** the completion is processed
- **THEN** the declared transition MUST be applied to the anchor object in the same request
- **AND** the object's lifecycle field MUST reach the transition's declared target state
- @e2e exclude auto-advance wiring — covered by advance-listener unit tests

#### Scenario: A chain without the declaration does not auto-advance
- **GIVEN** a sequence for a chain with no `onApprove: advanceTransition` declaration
- **WHEN** it completes
- **THEN** no transition MUST be applied
- @e2e exclude negative case — covered by advance-listener unit tests

#### Scenario: A failing auto-advance does not undo the approval
- **GIVEN** a completing sequence whose declared transition is refused by a lifecycle guard
- **WHEN** the auto-advance is attempted
- **THEN** the sequence MUST remain completed with its approving outcome
- **AND** the failure MUST be logged naming the action and the object
- **AND** a later manual attempt at the same transition MUST pass the approval gate
- @e2e exclude fail-soft advance — covered by advance-listener unit tests

### REQ-011: Amount tiers can be cumulative

A chain declaration with `amountField` MAY set `tiers` to `cumulative`. The gate SHALL then provision every approver entry whose `minAmount` is at or below the object's amount, ordered by `minAmount` from low to high, as consecutive steps. An amount below the lowest tier SHALL need no approval and the transition SHALL go ahead. Without `tiers`, or with `tiers: highest`, routing SHALL stay as REQ-008 describes. Any other value SHALL make the chain misconfigured and the gated transition SHALL be refused.

#### Scenario: an order of 12,500 euro needs the team lead and the facility manager

- **GIVEN** a purchase order schema whose `approve` transition carries a chain with `amountField` `totalAmount`, `tiers` `cumulative` and tiers teamleider from 1 cent, facility_manager from 1,000,000 cents and procurement_manager from 5,000,000 cents
- **WHEN** a user approves an order of 1,250,000 cents
- **THEN** the transition is held with `approval-chain-pending` and the sequence has two steps: teamleider first, then facility_manager
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testCumulativeTiersRequireEveryTierAtOrBelowTheAmount proves it}

#### Scenario: an order below the lowest tier approves directly

- **GIVEN** the same chain
- **WHEN** a user approves an order of 0 cents
- **THEN** the transition goes ahead and no approval sequence is created
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testCumulativeTiersBelowTheLowestTierNeedNoApproval proves it}

#### Scenario: an unknown tiers mode is refused

- **GIVEN** a chain with `tiers` `every-other`
- **WHEN** a user attempts the gated transition
- **THEN** the transition is refused with `approval-chain-misconfigured`
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testAnUnknownTiersModeFailsClosed proves it}
