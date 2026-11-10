---
retrofit: true
status: implemented
---
# Approval Workflow

## Purpose

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
