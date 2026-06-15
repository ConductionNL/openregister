---
retrofit: true
status: implemented
---
# Approval Workflow

## Purpose

@e2e exclude REST API CRUD — covered by Newman; no dedicated OR UI surface

Approval Workflow provides multi-step, role-gated approval chains for OpenRegister objects. Administrators configure named chains with ordered steps, each bound to a Nextcloud group (the "role"). When an object enters a chain, one `ApprovalStep` record per chain step is created — step 1 starts as `pending`, all others as `waiting`. Authorised users approve or reject the pending step; on approval the next waiting step is automatically advanced to `pending`. Each decision is persisted to the workflow execution history.
## Requirements

### REQ-001: Approval chain CRUD

The system SHALL expose authenticated HTTP endpoints to create, read, update, and delete approval chain configurations. An approval chain entity has at minimum a `name` and an ordered array of `steps`, where each step defines a `role` (Nextcloud group ID) and an optional `order` integer.

#### Scenario: List all approval chains

- **GIVEN** an authenticated user
- **WHEN** `GET /api/approval-chains` is requested
- **THEN** the response is `200 OK` with a JSON array of all persisted approval chains

#### Scenario: Get a single approval chain

- **GIVEN** a chain with the given ID exists
- **WHEN** `GET /api/approval-chains/{id}` is requested
- **THEN** the response is `200 OK` with the chain's JSON representation
- **AND** if the chain does not exist the response is `404 Not Found`

#### Scenario: Create an approval chain

- **GIVEN** a valid request body with at minimum a `name` and `steps` array
- **WHEN** `POST /api/approval-chains` is requested
- **THEN** the response is `201 Created` with the created chain's JSON representation

#### Scenario: Update an approval chain

- **GIVEN** a chain with the given ID exists
- **WHEN** `PUT /api/approval-chains/{id}` is requested with an updated body
- **THEN** the response is `200 OK` with the updated chain
- **AND** if the chain does not exist the response is `404 Not Found`

#### Scenario: Delete an approval chain

- **GIVEN** a chain with the given ID exists
- **WHEN** `DELETE /api/approval-chains/{id}` is requested
- **THEN** the response is `200 OK` with the deleted chain's JSON representation
- **AND** if the chain does not exist the response is `404 Not Found`

---

### REQ-002: Track object progress through an approval chain

The system SHALL expose an endpoint that returns all objects currently in a given approval chain, along with per-object progress information: total step count and count of approved steps.

#### Scenario: List objects with approval progress

- **GIVEN** a chain with ID `{id}` that has objects in progress
- **WHEN** `GET /api/approval-chains/{id}/objects` is requested
- **THEN** the response is `200 OK` with a JSON array, one entry per unique `objectUuid`
- **AND** each entry contains `objectUuid`, `steps` (array of step representations), `approved` (count of steps with `status: approved`), and `total` (count of all steps for that object)
- **AND** if the chain does not exist the response is `404 Not Found`

---

### REQ-003: List and filter approval steps

The system SHALL expose an endpoint to list approval steps with optional filtering by `status`, `role`, `chainId`, and `objectUuid`. Any combination of filters may be applied; omitted filters are ignored.

#### Scenario: List pending steps for a role

- **GIVEN** a user who belongs to the `juridisch-adviseur` Nextcloud group
- **WHEN** `GET /api/approval-steps?status=pending&role=juridisch-adviseur` is requested
- **THEN** the response is `200 OK` with a JSON array containing only steps that match both filters

#### Scenario: List all steps for a specific object

- **GIVEN** an object UUID `{uuid}` that has steps in one or more chains
- **WHEN** `GET /api/approval-steps?objectUuid={uuid}` is requested
- **THEN** the response is `200 OK` with all steps for that object across all chains

---

### REQ-004: Initialize approval chain steps for an object

The system SHALL create one `ApprovalStep` record per chain-step definition when an object enters a chain. The first step (lowest `order`) is created with `status: pending`; all subsequent steps are created with `status: waiting`.

#### Scenario: Steps created on chain initialization

- **GIVEN** a chain with three steps (order 1, 2, 3)
- **WHEN** `initializeChain` is called for object UUID `{uuid}`
- **THEN** three `ApprovalStep` records are persisted
- **AND** the step with `stepOrder: 1` has `status: pending`
- **AND** steps with `stepOrder: 2` and `stepOrder: 3` have `status: waiting`
- **AND** all three steps reference the same `chainId` and `objectUuid`

---

### REQ-005: Approve or reject a pending step with role enforcement

The system SHALL allow an authenticated user to approve or reject a pending approval step, subject to the step's role constraint. Only users who are members of the step's Nextcloud group may decide the step. Deciding a step records the deciding user ID, an optional comment, and a `decidedAt` timestamp. On approval, the next `waiting` step for the same object is automatically advanced to `pending`. Each decision is persisted to the workflow execution history.

#### Scenario: Authorised user approves a pending step

- **GIVEN** a step with `status: pending` and `role: juridisch-adviseur`
- **AND** the requesting user is a member of the `juridisch-adviseur` group
- **WHEN** `POST /api/approval-steps/{id}/approve` is requested with an optional `comment`
- **THEN** the step's `status` is set to `approved`, `decidedBy` to the user's ID, and `decidedAt` to the current timestamp
- **AND** the response is `200 OK` containing the updated step and a `nextStep` key if a subsequent step was advanced to `pending`
- **AND** a workflow execution record is persisted with `status: approved`

#### Scenario: Authorised user rejects a pending step

- **GIVEN** a step with `status: pending` and `role: juridisch-adviseur`
- **AND** the requesting user is a member of the `juridisch-adviseur` group
- **WHEN** `POST /api/approval-steps/{id}/reject` is requested with an optional `comment`
- **THEN** the step's `status` is set to `rejected`, `decidedBy` to the user's ID, and `decidedAt` to the current timestamp
- **AND** the response is `200 OK` with the updated step; no next step is advanced on rejection
- **AND** a workflow execution record is persisted with `status: rejected`

#### Scenario: Unauthorised user attempts to decide a step

- **GIVEN** a step with `role: juridisch-adviseur`
- **AND** the requesting user is NOT a member of that group
- **WHEN** `POST /api/approval-steps/{id}/approve` or `/reject` is requested
- **THEN** the response is `403 Forbidden`

#### Scenario: Attempt to decide a non-pending step

- **GIVEN** a step with `status: approved` or `status: waiting`
- **WHEN** `POST /api/approval-steps/{id}/approve` or `/reject` is requested
- **THEN** the response is `400 Bad Request` with an error message

#### Notes

- Authentication is enforced by `IUserSession` — unauthenticated requests receive `401` before role checking.
- `statusOnApprove` / `statusOnReject` fields in the chain step definition allow overriding the resulting status (defaults: `approved` / `rejected`). These are stored in the chain's step definition array but currently not used to update any object state — they're returned in the service result but the controller does not act on them.
- Rejection does NOT advance the next step — the chain is effectively blocked until manual intervention.

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

