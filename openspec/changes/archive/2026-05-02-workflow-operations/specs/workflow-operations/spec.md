# Workflow Operations -- Delta Spec

This is a delta spec for `openspec/specs/workflow-integration/spec.md`. It adds operational capabilities that are listed as "Not yet implemented" in the main spec.

## ADDED Requirements

### Requirement: Workflow Execution History

All hook executions MUST be persisted as `WorkflowExecution` entities in the database, providing a queryable execution history for monitoring, debugging, and audit purposes.

#### Scenario: Hook execution is persisted to history

- GIVEN a sync hook `validate-kvk` executes for object `obj-123`
- WHEN the workflow returns `status: "approved"` in 45ms
- THEN a `WorkflowExecution` entity MUST be created with `hookId: "validate-kvk"`, `eventType: "creating"`, `objectUuid: "obj-123"`, `engine: "n8n"`, `workflowId: "kvk-validator"`, `status: "approved"`, `durationMs: 45`, `executedAt: <current timestamp>`
- AND the existing logger-based logging MUST continue alongside the entity persistence

#### Scenario: Failed execution stores error details and payload

- GIVEN a sync hook fails due to a timeout
- WHEN the execution is persisted
- THEN the `WorkflowExecution` entity MUST include `status: "error"`, the `errors` field with a JSON array of error objects, and the `payload` field with the full CloudEvent payload that was sent
- AND the `metadata` field MUST include engine-specific error context

#### Scenario: Async hook delivery is persisted

- GIVEN an async hook `send-notification` fires
- WHEN the webhook delivery succeeds
- THEN a `WorkflowExecution` entity MUST be created with `mode: "async"`, `status: "delivered"`
- AND if delivery fails, `status` MUST be `"failed"` with error details

#### Scenario: List executions with filters

- GIVEN 100 workflow executions exist in the database
- WHEN an authenticated user sends `GET /api/workflow-executions/?objectUuid=obj-123&status=error&limit=10`
- THEN the response MUST include only executions matching all filter criteria
- AND the response MUST include `total` count, `limit`, and `offset` for pagination
- AND results MUST be sorted by `executedAt` descending (most recent first)

#### Scenario: Get single execution detail

- GIVEN a workflow execution with ID 42 exists
- WHEN an authenticated user sends `GET /api/workflow-executions/42`
- THEN the response MUST include all fields: hookId, eventType, objectUuid, schemaId, registerId, engine, workflowId, mode, status, durationMs, errors, metadata, payload, executedAt

#### Scenario: Admin deletes execution record

- GIVEN a workflow execution with ID 42 exists
- WHEN an admin sends `DELETE /api/workflow-executions/42`
- THEN the record MUST be removed from the database
- AND non-admin users MUST receive HTTP 403

### Requirement: Scheduled Workflow Triggers

The system MUST support scheduled workflows that run on a recurring basis, independent of object lifecycle events. Scheduled workflows use Nextcloud's TimedJob infrastructure.

#### Scenario: Create a scheduled workflow

- GIVEN an admin is authenticated and an n8n engine is registered
- WHEN they POST to `/api/scheduled-workflows/` with `name`, `engine`, `workflowId`, `registerId`, `schemaId`, `interval` (seconds), and `enabled: true`
- THEN a `ScheduledWorkflow` entity MUST be created
- AND the `ScheduledWorkflowJob` TimedJob MUST include this schedule in its next evaluation

#### Scenario: TimedJob evaluates scheduled workflows

- GIVEN a scheduled workflow `termijn-bewaking` with `interval: 86400` and `lastRun: 2026-03-23T02:00:00Z`
- WHEN the `ScheduledWorkflowJob` runs at `2026-03-24T02:01:00Z` (more than 86400 seconds later)
- THEN the job MUST resolve the engine adapter via `WorkflowEngineRegistry`
- AND build a payload with `register`, `schema`, `scheduledWorkflowId`, and the configured `payload` data
- AND execute the workflow via `adapter->executeWorkflow()`
- AND update `lastRun` to the current timestamp and `lastStatus` to the result status
- AND persist a `WorkflowExecution` entity with `eventType: "scheduled"`

#### Scenario: Scheduled workflow not yet due

- GIVEN a scheduled workflow with `interval: 86400` and `lastRun: 2026-03-24T01:00:00Z`
- WHEN the `ScheduledWorkflowJob` runs at `2026-03-24T02:00:00Z` (only 3600 seconds later)
- THEN the job MUST skip this schedule
- AND MUST NOT execute the workflow

#### Scenario: Disabled scheduled workflow is skipped

- GIVEN a scheduled workflow with `enabled: false`
- WHEN the `ScheduledWorkflowJob` evaluates schedules
- THEN it MUST skip this workflow entirely

#### Scenario: Scheduled workflow engine is unreachable

- GIVEN a scheduled workflow targets an engine that is currently down
- WHEN the job attempts execution
- THEN it MUST set `lastStatus` to `"error"`
- AND log the failure
- AND persist a `WorkflowExecution` with `status: "error"` and the error details
- AND MUST NOT crash the TimedJob (other schedules must still run)

#### Scenario: Update scheduled workflow

- GIVEN a scheduled workflow with ID 1 exists
- WHEN an admin sends `PUT /api/scheduled-workflows/1` with `interval: 3600`
- THEN the interval MUST be updated
- AND the next evaluation MUST use the new interval

#### Scenario: Delete scheduled workflow

- GIVEN a scheduled workflow with ID 1 exists
- WHEN an admin sends `DELETE /api/scheduled-workflows/1`
- THEN the entity MUST be removed from the database
- AND the job MUST no longer evaluate this schedule

### Requirement: Multi-Step Approval Chains

The system MUST support configurable multi-step approval workflows where objects require sign-off from one or more roles before proceeding. Approval chains are first-class entities that integrate with Nextcloud's group system.

#### Scenario: Create an approval chain

- GIVEN an admin is authenticated
- WHEN they POST to `/api/approval-chains/` with `name: "Vergunning goedkeuring"`, `schemaId: 12`, `statusField: "status"`, and `steps: [{ "order": 1, "role": "teamleider", "statusOnApprove": "wacht_op_afdelingshoofd", "statusOnReject": "afgewezen" }, { "order": 2, "role": "afdelingshoofd", "statusOnApprove": "goedgekeurd", "statusOnReject": "afgewezen" }]`
- THEN an `ApprovalChain` entity MUST be created with the steps stored as JSON
- AND the steps MUST be validated (unique order values, non-empty roles, valid status values)

#### Scenario: Object enters approval chain on creation

- GIVEN an approval chain exists for schema `vergunningen` with 2 steps
- WHEN a new vergunning object is created
- THEN the system MUST create `ApprovalStep` entities for the object: step 1 with `status: "pending"`, step 2 with `status: "waiting"`
- AND the object's `statusField` (e.g., `status`) MUST be set to the initial pending value

#### Scenario: Approve a pending step

- GIVEN object `obj-123` has approval step 1 with `status: "pending"` and `role: "teamleider"`
- WHEN a user who is a member of the `teamleider` Nextcloud group sends `POST /api/approval-steps/{stepId}/approve` with `comment: "Akkoord"`
- THEN step 1 MUST be updated to `status: "approved"`, `decidedBy: <username>`, `comment: "Akkoord"`, `decidedAt: <now>`
- AND step 2 MUST be updated from `status: "waiting"` to `status: "pending"`
- AND the object's status field MUST be set to `statusOnApprove` from step 1 (e.g., `"wacht_op_afdelingshoofd"`)
- AND a `WorkflowExecution` MUST be persisted with `eventType: "approval"`, `status: "approved"`

#### Scenario: Reject a pending step

- GIVEN object `obj-123` has approval step 1 with `status: "pending"` and `role: "teamleider"`
- WHEN a user in the `teamleider` group sends `POST /api/approval-steps/{stepId}/reject` with `comment: "Onvoldoende onderbouwing"`
- THEN step 1 MUST be updated to `status: "rejected"`, `decidedBy: <username>`, `comment`
- AND all subsequent steps MUST remain in `status: "waiting"` (they are NOT activated)
- AND the object's status field MUST be set to `statusOnReject` from step 1 (e.g., `"afgewezen"`)

#### Scenario: Unauthorised user cannot approve

- GIVEN approval step 1 requires `role: "teamleider"`
- WHEN a user who is NOT a member of the `teamleider` group sends `POST /api/approval-steps/{stepId}/approve`
- THEN the response MUST be HTTP 403 with error message "You are not authorised for this approval step"

#### Scenario: Final step approval completes the chain

- GIVEN a 2-step chain where step 1 is `approved` and step 2 is `pending`
- WHEN the afdelingshoofd approves step 2
- THEN the object's status MUST be set to the final `statusOnApprove` (e.g., `"goedgekeurd"`)
- AND no further steps exist -- the chain is complete

#### Scenario: List objects in approval chain with progress

- GIVEN 10 objects are in approval chain ID 1
- WHEN an authenticated user sends `GET /api/approval-chains/1/objects`
- THEN the response MUST include each object's UUID, current step, step status, and overall chain progress (e.g., "1 of 2 steps approved")

#### Scenario: Query pending approvals for current user

- GIVEN the current user is a member of groups `teamleider` and `admin`
- WHEN they send `GET /api/approval-steps/?status=pending&role=teamleider`
- THEN the response MUST include all pending steps where `role` matches one of the user's groups
- AND each result MUST include the object UUID, chain name, and step order

### Requirement: Test Hook / Dry-Run Execution

Administrators MUST be able to test workflow execution with sample data without persisting any changes, to verify correct behavior before activating hooks in production.

#### Scenario: Test hook via engine endpoint

- GIVEN an n8n engine is registered with ID 1 and a workflow `kvk-validator` is deployed
- WHEN an admin sends `POST /api/engines/1/test-hook` with `workflowId: "kvk-validator"`, `sampleData: { "kvkNumber": "12345678" }`, `timeout: 10`
- THEN the system MUST resolve the adapter and call `executeWorkflow("kvk-validator", sampleData, 10)`
- AND the response MUST include the full `WorkflowResult` (status, data, errors, metadata)
- AND the response MUST include `dryRun: true`
- AND NO database writes MUST occur (no object creation, no execution history entry)

#### Scenario: Test hook with invalid workflow ID

- GIVEN an engine is registered but the workflowId does not exist
- WHEN the test-hook endpoint is called
- THEN the response MUST be HTTP 422 with an error message from the adapter
- AND no database writes MUST occur

#### Scenario: Test hook with engine down

- GIVEN an engine is registered but currently unreachable
- WHEN the test-hook endpoint is called
- THEN the response MUST be HTTP 502 with `status: "error"` and a connectivity error message

### Requirement: Workflow Configuration UI

Administrators MUST be able to configure schema hooks via a graphical interface in the schema settings, without writing JSON manually.

#### Scenario: Schema settings shows Workflows tab

- GIVEN an admin navigates to schema `meldingen` settings page
- WHEN the page loads
- THEN a "Workflows" tab MUST be visible alongside other schema settings tabs
- AND the tab MUST display a list of configured hooks from the schema's `hooks` JSON property

#### Scenario: Add a new hook via UI

- GIVEN the admin clicks "Add hook" in the Workflows tab
- WHEN the hook form is displayed
- THEN it MUST provide form fields for: event type (dropdown: creating/updating/deleting/created/updated/deleted), engine (dropdown populated from `GET /api/engines/`), workflowId (dropdown populated from `adapter.listWorkflows()` for selected engine), mode (sync/async), order (number), timeout (number, default 30), onFailure (dropdown: reject/allow/flag/queue), onTimeout (dropdown: reject/allow/flag/queue), onEngineDown (dropdown: reject/allow/flag/queue), filterCondition (JSON editor), enabled (toggle)
- AND on save, the form MUST update the schema's `hooks` array via the schema API

#### Scenario: Edit an existing hook

- GIVEN a hook `validate-kvk` exists in the schema's hooks array
- WHEN the admin clicks the edit icon
- THEN the hook form MUST be pre-populated with the hook's current values
- AND on save, the hook MUST be updated in-place in the `hooks` array

#### Scenario: Delete a hook

- GIVEN a hook `validate-kvk` exists in the schema's hooks array
- WHEN the admin clicks the delete icon and confirms
- THEN the hook MUST be removed from the `hooks` array
- AND the schema MUST be saved via the schema API

#### Scenario: View execution history for a hook

- GIVEN the Workflows tab is open for schema `meldingen`
- WHEN the admin clicks on a hook or expands an execution history section
- THEN the UI MUST display recent `WorkflowExecution` records filtered by `schemaId` and `hookId`
- AND each entry MUST show: timestamp, objectUuid, status (color-coded), duration, and a link to the full detail

#### Scenario: Test hook button in UI

- GIVEN a hook is configured with engine and workflowId
- WHEN the admin clicks "Test" on the hook row
- THEN a dialog MUST open with a JSON editor pre-populated with sample data derived from the schema's properties
- AND the admin MAY edit the sample data
- AND on submit, the UI MUST call `POST /api/engines/{engineId}/test-hook` and display the result
- AND the dialog MUST clearly indicate this is a dry-run (no data is persisted)

### Requirement: Execution History Retention

Workflow execution history records MUST be automatically pruned to prevent unbounded table growth.

#### Scenario: Background job prunes old records

- GIVEN a configurable retention period (default 90 days, stored in IAppConfig as `workflow_execution_retention_days`)
- WHEN the `ExecutionHistoryCleanupJob` TimedJob runs (once daily)
- THEN it MUST delete all `WorkflowExecution` records where `executedAt` is older than the retention period
- AND it MUST log the number of deleted records

#### Scenario: Retention period is configurable

- GIVEN an admin sets `workflow_execution_retention_days` to 30 via Nextcloud settings
- WHEN the cleanup job runs
- THEN it MUST use the configured 30-day period instead of the default 90

#### Scenario: No records to prune

- GIVEN all execution records are within the retention period
- WHEN the cleanup job runs
- THEN it MUST complete without error
- AND MUST NOT delete any records
