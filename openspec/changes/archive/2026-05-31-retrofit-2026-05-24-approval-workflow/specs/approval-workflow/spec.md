---
retrofit_extensions:
  - REQ-006
  - REQ-007
---
# Approval Workflow (delta)

## Requirements

### REQ-006: Frontend lists approval chains per schema

The system SHALL provide a Vue panel (`ApprovalChainPanel`) mounted in the schema-detail workflow tab that lists configured approval chains. On mount the panel issues `GET /api/approval-chains` and, when a `schemaId` prop is supplied, filters the response to chains whose `schemaId` matches. The panel displays each chain's `name`, `statusField`, and ordered steps (with each step's `order`, `role`, `statusOnApprove`, and `statusOnReject`), and exposes a "Create Chain" form that issues `POST /api/approval-chains` with the schema's ID attached and re-fetches on success.

#### Scenario: Panel lists chains for the current schema

- **GIVEN** the schema-detail workflow tab is open for a schema with ID `42`
- **AND** the user is authenticated
- **WHEN** `ApprovalChainPanel` mounts with `schemaId=42`
- **THEN** `GET /api/approval-chains` is issued
- **AND** the response is filtered client-side to entries where `chain.schemaId === 42`
- **AND** each remaining chain is rendered with its name, statusField, and step list

#### Scenario: Panel creates a chain bound to the current schema

- **GIVEN** the create form is open with a `name` filled in
- **WHEN** the user clicks "Save Chain"
- **THEN** `POST /api/approval-chains` is issued with the form body merged with `{ schemaId: <current schemaId> }`
- **AND** on success the form closes and `fetchChains` is re-invoked

#### Notes

- The panel filters by `schemaId` **client-side**, not via a server query parameter. With many chains this is wasteful; a server-side `?schemaId=` filter would be more efficient but is not currently implemented.
- Error handling is a `console.error` only — failed requests do not surface to the user. This is observed behavior, not desired behavior. A future change should introduce toast notifications via `@nextcloud/dialogs` (`showError`).

---

### REQ-007: Frontend renders approval-step progress with inline decide controls

The system SHALL provide a Vue component (`ApprovalStepList`) that lists approval steps for one object and renders inline approve/reject controls for steps the current user can decide. On mount, the component issues `GET /api/approval-steps?objectUuid={objectUuid}` and renders each returned step's order, role, status, and (when present) the deciding user. For each step with `status: pending`, an inline action row exposes a comment input plus "Approve" and "Reject" buttons that issue `POST /api/approval-steps/{id}/approve` and `/reject` respectively with the comment in the body. After any decide call the component re-fetches the step list.

#### Scenario: Component lists steps for an object

- **GIVEN** an object with UUID `abc-123` has two approval steps in two chains
- **WHEN** `ApprovalStepList` mounts with `objectUuid="abc-123"`
- **THEN** `GET /api/approval-steps?objectUuid=abc-123` is issued
- **AND** all returned steps are rendered in order with their `stepOrder`, `role`, status badge, and (if set) `decidedBy`

#### Scenario: User approves a pending step inline

- **GIVEN** a step with `status: pending` is rendered with a comment input
- **WHEN** the user types a comment and clicks "Approve"
- **THEN** `POST /api/approval-steps/{step.id}/approve` is issued with `{ comment: "<entered text>" }`
- **AND** on success `fetchSteps` is re-invoked so the now-decided step's badge updates and any newly-advanced step appears as `pending`

#### Scenario: User rejects a pending step inline

- **GIVEN** a step with `status: pending` is rendered
- **WHEN** the user clicks "Reject" (with or without a comment)
- **THEN** `POST /api/approval-steps/{step.id}/reject` is issued with `{ comment: "<entered text or empty string>" }`
- **AND** on success `fetchSteps` is re-invoked

#### Notes

- `canDecide()` in the component currently `return true` — i.e., the client renders approve/reject buttons for **every** pending step regardless of the viewing user's group membership. Authorisation is enforced server-side per REQ-005 (the server returns 403 for non-group members), so the buttons "work" only in the sense that unauthorised clicks fail. A future change should fetch the current user's groups and gate the buttons client-side to avoid showing actions the user cannot perform. Flagged as observed-but-suspicious.
- The component does not paginate. With many steps per object this is acceptable today, but if approval chains grow long (>50 steps) pagination should be added.
- Error handling is `console.error` only (same caveat as REQ-006).
