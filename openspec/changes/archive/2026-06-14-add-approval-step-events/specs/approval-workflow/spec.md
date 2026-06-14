---
status: proposed
---

# Approval Workflow — typed event hooks (delta)

**OpenSpec change**: `add-approval-step-events` (in-progress)

**Cross-references**: [approval-workflow main spec](../../../../specs/approval-workflow/spec.md), [openregister event-driven-architecture spec](../../../../specs/event-driven-architecture/spec.md).

## Purpose of this delta

The `approval-workflow` capability today drives multi-step, role-gated approval chains and persists each decision to `workflow_executions`, but it dispatches no typed events on the chain's state transitions. Downstream apps (docudesk signing, decidesk multi-step decisions, any leaf app that wraps OR approvals for its own status model) cannot subscribe to the engine without hijacking generic `ObjectUpdatedEvent` or polling the execution table.

This delta adds four typed events — `ApprovalStepInitiatedEvent`, `ApprovalStepApprovedEvent`, `ApprovalStepRejectedEvent`, `ApprovalStepCompletedEvent` — dispatched at the engine's existing state transitions. All existing behaviour (state mutation, persistence, role verification) is unchanged.

---

## ADDED Requirements

### Requirement: The system MUST dispatch a typed event when an approval step transitions to `pending`

The system MUST dispatch `OCA\OpenRegister\Event\ApprovalStepInitiatedEvent` via the Nextcloud event dispatcher (`IEventDispatcher::dispatchTyped()`) at the moment an `ApprovalStep` enters the `pending` status. This event MUST fire:

- For step 1 of a chain at the end of `ApprovalService::initializeChain()`.
- For the next waiting step inside `ApprovalService::approveStep()`, immediately after that step's status is updated to `pending` and persisted.

The event MUST carry the parent `ApprovalChain`, the `ApprovalStep` that is now `pending`, and the object UUID being approved.

The event MUST NOT fire for steps that are created in the `waiting` state during `initializeChain()` — those steps trigger their own `Initiated` event when they later transition.

#### Scenario: First step of a chain fires Initiated on initialise
- **GIVEN** an `ApprovalChain` `c1` with two ordered steps and no prior `ApprovalStep` records for object `obj-A`
- **WHEN** `ApprovalService::initializeChain($c1, 'obj-A')` is called
- **THEN** exactly one `ApprovalStepInitiatedEvent` MUST be dispatched
- **AND** the event's `getStep()` MUST return the step whose `status` is `pending`
- **AND** the event's `getObjectUuid()` MUST return `'obj-A'`

#### Scenario: Next waiting step fires Initiated on prior approval
- **GIVEN** an `ApprovalChain` `c1` with two ordered steps where step 1 is `pending` and step 2 is `waiting` for object `obj-B`
- **WHEN** an authorised user approves step 1 via `ApprovalService::approveStep()`
- **THEN** an `ApprovalStepInitiatedEvent` MUST be dispatched whose `getStep()` returns the (now `pending`) step 2
- **AND** the event's `getObjectUuid()` MUST return `'obj-B'`

#### Scenario: Waiting steps created during initialise do NOT fire Initiated
- **GIVEN** an `ApprovalChain` `c1` with three ordered steps and no prior `ApprovalStep` records for object `obj-C`
- **WHEN** `ApprovalService::initializeChain($c1, 'obj-C')` is called
- **THEN** exactly ONE `ApprovalStepInitiatedEvent` MUST be dispatched
- **AND** the two steps created with `status = 'waiting'` MUST NOT each trigger their own `ApprovalStepInitiatedEvent`

### Requirement: The system MUST dispatch a typed event when an approval step is approved

The system MUST dispatch `OCA\OpenRegister\Event\ApprovalStepApprovedEvent` from `ApprovalService::approveStep()` after the step has been persisted with `status = 'approved'` and the workflow execution history has been written.

The event MUST carry the parent chain, the approved step, the deciding user ID, the resolved `statusOnApprove` (taken from the chain step definition or defaulted to `'approved'`), and the next step now in `pending` (or `null` if this was the final step).

The `Approved` event MUST be dispatched BEFORE the follow-up `Initiated` or `Completed` event so listeners can observe the dispatch order: `Approved → (Initiated | Completed)`.

#### Scenario: Non-final step approval fires Approved then Initiated
- **GIVEN** an `ApprovalChain` `c1` with two ordered steps, step 1 `pending` and step 2 `waiting` for object `obj-D`
- **WHEN** an authorised user approves step 1
- **THEN** exactly two events MUST be dispatched in this order:
  1. `ApprovalStepApprovedEvent` whose `getStep()` returns step 1 and `getNextStep()` returns step 2 and `isFinalStep()` returns `false`
  2. `ApprovalStepInitiatedEvent` whose `getStep()` returns step 2

#### Scenario: Approved event carries the resolved statusOnApprove
- **GIVEN** a chain step definition `{ order: 1, role: 'teamleider', statusOnApprove: 'wacht' }`
- **WHEN** an authorised user approves step 1
- **THEN** the dispatched `ApprovalStepApprovedEvent::getStatusOnApprove()` MUST return `'wacht'`

#### Scenario: Approved event carries the deciding user
- **GIVEN** user `alice` is in the role group of step 1 (in `pending`)
- **WHEN** `ApprovalService::approveStep(stepId, 'alice', 'looks good')` is called
- **THEN** the dispatched `ApprovalStepApprovedEvent::getUserId()` MUST return `'alice'`

### Requirement: The system MUST dispatch a typed event when an approval step is rejected

The system MUST dispatch `OCA\OpenRegister\Event\ApprovalStepRejectedEvent` from `ApprovalService::rejectStep()` after the step has been persisted with `status = 'rejected'` and the workflow execution history has been written.

The event MUST carry the parent chain, the rejected step, the deciding user ID, and the resolved `statusOnReject` (taken from the chain step definition or defaulted to `'rejected'`).

The system MUST NOT dispatch any `Initiated` or `Completed` event in response to a rejection — the chain is terminated.

#### Scenario: Rejection fires Rejected only
- **GIVEN** an `ApprovalChain` `c1` with two ordered steps, step 1 `pending` and step 2 `waiting` for object `obj-E`
- **WHEN** an authorised user rejects step 1
- **THEN** exactly one event MUST be dispatched: `ApprovalStepRejectedEvent`
- **AND** step 2 MUST remain in `waiting`
- **AND** no `ApprovalStepInitiatedEvent` MUST be dispatched for any step

#### Scenario: Rejected event carries the resolved statusOnReject
- **GIVEN** a chain step definition `{ order: 1, role: 'teamleider', statusOnReject: 'afgewezen' }`
- **WHEN** an authorised user rejects step 1
- **THEN** the dispatched `ApprovalStepRejectedEvent::getStatusOnReject()` MUST return `'afgewezen'`

### Requirement: The system MUST dispatch a typed event when an approval chain completes

The system MUST dispatch `OCA\OpenRegister\Event\ApprovalStepCompletedEvent` from `ApprovalService::approveStep()` when — and only when — the approved step has no next waiting step (i.e. it was the final step of the chain).

The event MUST carry the parent chain, the final (approved) step, the deciding user ID, and the resolved `statusOnApprove`. Downstream apps that only care about full-chain completion (e.g. docudesk advancing a signed document to its final archival state) MUST be able to subscribe to this event alone without also listening to `ApprovalStepApprovedEvent`.

The `Completed` event MUST be dispatched AFTER the `Approved` event for the same final step.

#### Scenario: Final step approval fires Approved then Completed
- **GIVEN** an `ApprovalChain` `c1` with one ordered step in `pending` for object `obj-F`
- **WHEN** an authorised user approves the step
- **THEN** exactly two events MUST be dispatched in this order:
  1. `ApprovalStepApprovedEvent` whose `isFinalStep()` returns `true` and `getNextStep()` returns `null`
  2. `ApprovalStepCompletedEvent` whose `getFinalStep()` returns the approved step and `getStatusOnApprove()` returns the chain-defined terminal status

#### Scenario: Non-final step approval does NOT fire Completed
- **GIVEN** an `ApprovalChain` `c1` with two ordered steps, step 1 `pending` and step 2 `waiting`
- **WHEN** an authorised user approves step 1
- **THEN** no `ApprovalStepCompletedEvent` MUST be dispatched
- **AND** `ApprovalStepInitiatedEvent` MUST be dispatched for step 2 instead

### Requirement: The system MUST preserve existing approval engine behaviour

Adding the four typed events MUST NOT change:

- The mutation of `ApprovalStep` records (`status`, `decidedBy`, `decidedAt`, `comment` are still set by `approveStep()` / `rejectStep()`).
- The advancement of the next waiting step on approval.
- The persistence of each decision to the `workflow_executions` table via `WorkflowExecutionMapper`.
- The role-membership check via `IGroupManager::isInGroup()`.
- The shape of the return arrays from `approveStep()` (`step`, `nextStep`, `statusOnApprove`, `chain`) and `rejectStep()` (`step`, `statusOnReject`, `chain`).

#### Scenario: workflow_executions row is still written on approve
- **GIVEN** an authorised user about to approve a `pending` step
- **WHEN** `ApprovalService::approveStep()` is called
- **THEN** a `workflow_executions` row with `engine = 'approval'`, `status = 'approved'` MUST still be persisted via `WorkflowExecutionMapper::createFromArray()`

#### Scenario: workflow_executions row is still written on reject
- **GIVEN** an authorised user about to reject a `pending` step
- **WHEN** `ApprovalService::rejectStep()` is called
- **THEN** a `workflow_executions` row with `engine = 'approval'`, `status = 'rejected'` MUST still be persisted via `WorkflowExecutionMapper::createFromArray()`
