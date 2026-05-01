# Tasks: workflow-operations

## 1. Workflow Execution History

### Task 1.1: Create WorkflowExecution entity and mapper
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-execution-history`
- **files**: `openregister/lib/Db/WorkflowExecution.php`, `openregister/lib/Db/WorkflowExecutionMapper.php`
- **acceptance_criteria**:
  - GIVEN the entity extends `OCP\AppFramework\Db\Entity` WHEN properties are defined THEN it MUST include: `uuid`, `hookId`, `eventType`, `objectUuid`, `schemaId`, `registerId`, `engine`, `workflowId`, `mode`, `status`, `durationMs`, `errors` (TEXT), `metadata` (TEXT), `payload` (TEXT), `executedAt` (datetime)
  - GIVEN the mapper extends `QBMapper` WHEN `findAll()` is called with filter parameters THEN it MUST support filtering by `objectUuid`, `schemaId`, `hookId`, `status`, `engine`, and `since` (timestamp)
  - GIVEN the mapper WHEN `findAll()` is called THEN it MUST support `limit` and `offset` for pagination and return results sorted by `executedAt` descending
  - GIVEN the mapper WHEN `countAll()` is called with the same filters THEN it MUST return the total count for pagination headers
  - GIVEN the mapper WHEN `deleteOlderThan(DateTime $cutoff)` is called THEN it MUST delete all records where `executedAt < $cutoff` and return the number of deleted rows
- [x] Implement
- [x] Test

### Task 1.2: Create database migration for workflow_executions table
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-execution-history`
- **files**: `openregister/lib/Migration/VersionXXXXDate_CreateWorkflowExecutions.php`
- **acceptance_criteria**:
  - GIVEN the migration runs WHEN `changeSchema()` executes THEN the `openregister_workflow_executions` table MUST be created with all required columns and indexes (idx_object_uuid, idx_schema_id, idx_hook_id, idx_status, idx_executed_at)
  - GIVEN the migration WHEN rolled back THEN the table MUST be droppable without affecting other tables
- [x] Implement
- [x] Test

### Task 1.3: Modify HookExecutor to persist execution history
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-execution-history` (hook execution is persisted, failed execution stores error details, async delivery is persisted)
- **files**: `openregister/lib/Service/HookExecutor.php`
- **acceptance_criteria**:
  - GIVEN `WorkflowExecutionMapper` is injected into HookExecutor WHEN `logHookExecution()` is called THEN it MUST create a `WorkflowExecution` entity via `createFromArray()` alongside the existing logger call
  - GIVEN a sync hook returns `approved` WHEN the execution is persisted THEN the `status` field MUST be `"approved"` and `errors` MUST be null
  - GIVEN a sync hook fails with a timeout WHEN the execution is persisted THEN `status` MUST be `"error"`, `errors` MUST contain the error message, and `payload` MUST contain the full CloudEvent payload
  - GIVEN an async hook delivery succeeds WHEN the execution is persisted THEN `mode` MUST be `"async"` and `status` MUST be `"delivered"`
  - GIVEN persistence of the execution entity fails WHEN an exception is thrown THEN HookExecutor MUST catch the exception and log a warning -- it MUST NOT fail the original hook execution
- [x] Implement
- [x] Test

### Task 1.4: Create WorkflowExecutionController
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-execution-history` (list executions with filters, get single detail, admin deletes)
- **files**: `openregister/lib/Controller/WorkflowExecutionController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated user WHEN `GET /api/workflow-executions/` is called with filter query parameters THEN the response MUST include `results` array, `total`, `limit`, and `offset`
  - GIVEN an authenticated user WHEN `GET /api/workflow-executions/{id}` is called THEN the response MUST include all execution fields
  - GIVEN an admin WHEN `DELETE /api/workflow-executions/{id}` is called THEN the record MUST be deleted and HTTP 200 returned
  - GIVEN a non-admin user WHEN `DELETE /api/workflow-executions/{id}` is called THEN the response MUST be HTTP 403
  - GIVEN routes.php is updated WHEN the app loads THEN routes for `GET /api/workflow-executions/`, `GET /api/workflow-executions/{id}`, and `DELETE /api/workflow-executions/{id}` MUST be registered before any wildcard routes
- [x] Implement
- [x] Test

## 2. Scheduled Workflow Triggers

### Task 2.1: Create ScheduledWorkflow entity and mapper
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers`
- **files**: `openregister/lib/Db/ScheduledWorkflow.php`, `openregister/lib/Db/ScheduledWorkflowMapper.php`
- **acceptance_criteria**:
  - GIVEN the entity extends `OCP\AppFramework\Db\Entity` WHEN properties are defined THEN it MUST include: `uuid`, `name`, `engine`, `workflowId`, `registerId`, `schemaId`, `intervalSec`, `enabled`, `payload` (TEXT/JSON), `lastRun` (datetime), `lastStatus`, `created`, `updated`
  - GIVEN the mapper WHEN `findAllEnabled()` is called THEN it MUST return only entities where `enabled = true`
  - GIVEN the mapper WHEN `findAll()` is called THEN it MUST return all scheduled workflows
- [x] Implement
- [x] Test

### Task 2.2: Create database migration for scheduled_workflows table
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers`
- **files**: `openregister/lib/Migration/VersionXXXXDate_CreateScheduledWorkflows.php`
- **acceptance_criteria**:
  - GIVEN the migration runs WHEN `changeSchema()` executes THEN the `openregister_scheduled_workflows` table MUST be created with all required columns
- [x] Implement
- [x] Test

### Task 2.3: Create ScheduledWorkflowJob TimedJob
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers` (TimedJob evaluates, not yet due, disabled skipped, engine unreachable)
- **files**: `openregister/lib/BackgroundJob/ScheduledWorkflowJob.php`
- **acceptance_criteria**:
  - GIVEN the job extends `OCP\BackgroundJob\TimedJob` WHEN it runs THEN it MUST load all enabled `ScheduledWorkflow` entities
  - GIVEN a scheduled workflow whose `intervalSec` has elapsed since `lastRun` WHEN the job evaluates it THEN it MUST resolve the engine adapter via `WorkflowEngineRegistry`, build a payload with register/schema context, and call `adapter->executeWorkflow()`
  - GIVEN a scheduled workflow whose interval has NOT elapsed WHEN the job evaluates it THEN it MUST skip execution
  - GIVEN a disabled scheduled workflow WHEN the job evaluates it THEN it MUST skip it entirely
  - GIVEN a scheduled workflow targets an unreachable engine WHEN execution fails THEN the job MUST set `lastStatus` to `"error"`, log the failure, and continue processing remaining schedules
  - GIVEN each execution WHEN it completes THEN the job MUST update `lastRun` and `lastStatus` on the entity AND persist a `WorkflowExecution` with `eventType: "scheduled"`
- [x] Implement
- [x] Test

### Task 2.4: Create ScheduledWorkflowController
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers` (create, update, delete)
- **files**: `openregister/lib/Controller/ScheduledWorkflowController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `POST /api/scheduled-workflows/` is called with valid data THEN a `ScheduledWorkflow` entity MUST be created and returned with HTTP 201
  - GIVEN an authenticated user WHEN `GET /api/scheduled-workflows/` is called THEN all scheduled workflows MUST be returned
  - GIVEN an admin WHEN `PUT /api/scheduled-workflows/{id}` is called THEN the entity MUST be updated
  - GIVEN an admin WHEN `DELETE /api/scheduled-workflows/{id}` is called THEN the entity MUST be removed
  - GIVEN routes.php is updated THEN routes for scheduled workflow CRUD MUST be registered
- [x] Implement
- [x] Test

### Task 2.5: Register ScheduledWorkflowJob in Application.php
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers`
- **files**: `openregister/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN the DI container is built THEN `ScheduledWorkflowJob` MUST be registered as a TimedJob with a base interval of 60 seconds
  - GIVEN the job is registered WHEN Nextcloud cron runs THEN the job MUST be discoverable and executable
- [x] Implement
- [x] Test

## 3. Multi-Step Approval Chains

### Task 3.1: Create ApprovalChain entity and mapper
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains`
- **files**: `openregister/lib/Db/ApprovalChain.php`, `openregister/lib/Db/ApprovalChainMapper.php`
- **acceptance_criteria**:
  - GIVEN the entity extends `OCP\AppFramework\Db\Entity` WHEN properties are defined THEN it MUST include: `uuid`, `name`, `schemaId`, `statusField`, `steps` (TEXT/JSON), `enabled`, `created`, `updated`
  - GIVEN the mapper WHEN `findBySchema(int $schemaId)` is called THEN it MUST return chains configured for that schema
  - GIVEN the `steps` property WHEN serialized THEN each step MUST have `order`, `role`, `statusOnApprove`, and `statusOnReject`
- [x] Implement
- [x] Test

### Task 3.2: Create ApprovalStep entity and mapper
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains`
- **files**: `openregister/lib/Db/ApprovalStep.php`, `openregister/lib/Db/ApprovalStepMapper.php`
- **acceptance_criteria**:
  - GIVEN the entity extends `OCP\AppFramework\Db\Entity` WHEN properties are defined THEN it MUST include: `uuid`, `chainId`, `objectUuid`, `stepOrder`, `role`, `status` (pending/waiting/approved/rejected), `decidedBy`, `comment`, `decidedAt`, `created`
  - GIVEN the mapper WHEN `findByChainAndObject(int $chainId, string $objectUuid)` is called THEN it MUST return all steps for that chain and object combination, sorted by `stepOrder` ascending
  - GIVEN the mapper WHEN `findPendingByRole(string $role)` is called THEN it MUST return all steps with `status: "pending"` matching the given role
  - GIVEN the mapper WHEN `findByObjectUuid(string $objectUuid)` is called THEN it MUST return all approval steps for that object across all chains
- [x] Implement
- [x] Test

### Task 3.3: Create database migration for approval tables
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains`
- **files**: `openregister/lib/Migration/VersionXXXXDate_CreateApprovalTables.php`
- **acceptance_criteria**:
  - GIVEN the migration runs THEN `openregister_approval_chains` and `openregister_approval_steps` tables MUST be created with all columns and indexes
  - GIVEN `openregister_approval_steps` WHEN the table is created THEN it MUST have a foreign key on `chain_id` referencing `openregister_approval_chains(id)` with `ON DELETE CASCADE`
- [x] Implement
- [x] Test

### Task 3.4: Create ApprovalService
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains` (object enters chain, approve step, reject step, final step)
- **files**: `openregister/lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN `initializeChain(ApprovalChain $chain, string $objectUuid)` is called WHEN a new object is created for a schema with an approval chain THEN `ApprovalStep` entities MUST be created for each step: step 1 as `pending`, all others as `waiting`
  - GIVEN `approveStep(int $stepId, string $userId, string $comment)` is called WHEN the user is authorised THEN the step MUST be set to `approved`, the next step in the chain MUST be set to `pending`, and the object's status field MUST be updated via ObjectService
  - GIVEN `rejectStep(int $stepId, string $userId, string $comment)` is called WHEN the user is authorised THEN the step MUST be set to `rejected`, subsequent steps MUST remain as `waiting`, and the object's status field MUST be set to the step's `statusOnReject`
  - GIVEN role checking WHEN `approveStep()` or `rejectStep()` is called THEN the service MUST verify the user is a member of the step's `role` group via `IGroupManager` and throw an exception if not
  - GIVEN the final step in a chain is approved WHEN no more steps remain THEN the object's status MUST be set to the final step's `statusOnApprove`
- [x] Implement
- [x] Test

### Task 3.5: Create ApprovalController
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains` (API endpoints, unauthorised user, list objects, query pending)
- **files**: `openregister/lib/Controller/ApprovalController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `POST /api/approval-chains/` is called THEN an `ApprovalChain` MUST be created and returned with HTTP 201
  - GIVEN an authenticated user WHEN `GET /api/approval-chains/` is called THEN all chains MUST be returned
  - GIVEN an authenticated user WHEN `GET /api/approval-chains/{id}` is called THEN the chain with its step definitions MUST be returned
  - GIVEN an admin WHEN `PUT /api/approval-chains/{id}` and `DELETE /api/approval-chains/{id}` are called THEN the chain MUST be updated/deleted
  - GIVEN an authenticated user WHEN `GET /api/approval-chains/{id}/objects` is called THEN all objects in the chain with their approval progress MUST be returned
  - GIVEN an authorised user WHEN `POST /api/approval-steps/{id}/approve` is called THEN the step MUST be approved via ApprovalService
  - GIVEN an authorised user WHEN `POST /api/approval-steps/{id}/reject` is called THEN the step MUST be rejected via ApprovalService
  - GIVEN an unauthorised user WHEN approve/reject is called THEN HTTP 403 MUST be returned
  - GIVEN an authenticated user WHEN `GET /api/approval-steps/?status=pending&role=teamleider` is called THEN matching pending steps MUST be returned
  - GIVEN routes.php is updated THEN routes for approval chain CRUD, approval step approve/reject, and step listing MUST be registered
- [x] Implement
- [x] Test

## 4. Test Hook / Dry-Run

### Task 4.1: Add testHook endpoint to WorkflowEngineController
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-test-hook--dry-run-execution`
- **files**: `openregister/lib/Controller/WorkflowEngineController.php`, `openregister/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `POST /api/engines/{engineId}/test-hook` is called with `workflowId`, `sampleData`, and optional `timeout` THEN the controller MUST resolve the adapter via `WorkflowEngineRegistry`, call `executeWorkflow()`, and return the `WorkflowResult` with `dryRun: true`
  - GIVEN the workflow execution succeeds WHEN the response is returned THEN it MUST include `status`, `data`, `errors`, `metadata`, and `dryRun: true`
  - GIVEN the workflow execution fails WHEN the adapter throws or returns error THEN the response MUST include the error details with appropriate HTTP status (422 for workflow errors, 502 for connectivity errors)
  - GIVEN any test-hook call WHEN it completes THEN NO database writes MUST occur (no WorkflowExecution entity, no object creation)
  - GIVEN the route is registered WHEN a non-admin calls the endpoint THEN HTTP 403 MUST be returned
- [x] Implement
- [x] Test

## 5. Workflow Configuration UI

### Task 5.1: Create SchemaWorkflowTab Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-configuration-ui` (Workflows tab, hook list)
- **files**: `openregister/src/views/schemas/SchemaWorkflowTab.vue`
- **acceptance_criteria**:
  - GIVEN the schema detail page WHEN it renders THEN a "Workflows" tab MUST be visible
  - GIVEN the Workflows tab is active WHEN it loads THEN it MUST display a list of hooks from the schema's `hooks` property using the HookList component
  - GIVEN the tab WHEN "Add hook" is clicked THEN it MUST open the HookForm component in create mode
  - GIVEN the tab WHEN hook execution history section is expanded THEN it MUST display recent WorkflowExecution records filtered by schemaId
- [x] Implement
- [x] Test

### Task 5.2: Create HookList Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-configuration-ui`
- **files**: `openregister/src/components/workflow/HookList.vue`
- **acceptance_criteria**:
  - GIVEN a schema with 3 configured hooks WHEN the component renders THEN each hook MUST display: event type, engine, workflowId, mode, order, enabled status
  - GIVEN a hook row WHEN the edit icon is clicked THEN HookForm MUST open pre-populated with the hook's values
  - GIVEN a hook row WHEN the delete icon is clicked and confirmed THEN the hook MUST be removed from the schema's hooks array and the schema MUST be saved
  - GIVEN a hook row WHEN the "Test" button is clicked THEN TestHookDialog MUST open for that hook
- [x] Implement
- [x] Test

### Task 5.3: Create HookForm Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-configuration-ui` (add hook, edit hook)
- **files**: `openregister/src/components/workflow/HookForm.vue`
- **acceptance_criteria**:
  - GIVEN the form is in create mode WHEN it renders THEN it MUST show fields for: event type (dropdown), engine (dropdown from `GET /api/engines/`), workflowId (dropdown populated from engine's workflow list), mode (sync/async), order (number), timeout (number, default 30), onFailure/onTimeout/onEngineDown (dropdowns: reject/allow/flag/queue), filterCondition (JSON editor or simple key-value pairs), enabled (toggle)
  - GIVEN the engine dropdown WHEN an engine is selected THEN the workflowId dropdown MUST be populated by calling the engine's `listWorkflows` method (via a new API endpoint or the existing adapter)
  - GIVEN the form is in edit mode WHEN it renders THEN all fields MUST be pre-populated with the existing hook values
  - GIVEN the form is submitted WHEN validation passes THEN the hook MUST be added/updated in the schema's `hooks` array and the schema MUST be saved via the API
- [x] Implement
- [x] Test

### Task 5.4: Create TestHookDialog Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-configuration-ui` (test hook button)
- **files**: `openregister/src/components/workflow/TestHookDialog.vue`
- **acceptance_criteria**:
  - GIVEN the dialog opens WHEN it renders THEN it MUST show a JSON editor with sample data derived from the schema's properties (generate default values from property types)
  - GIVEN the admin edits the sample data and clicks "Run test" WHEN the request is sent THEN it MUST call `POST /api/engines/{engineId}/test-hook` with the workflowId and sampleData
  - GIVEN the test completes WHEN the response is received THEN the dialog MUST display the WorkflowResult: status (color-coded), modified data (if any), errors (if any), execution metadata
  - GIVEN the dialog WHEN it displays results THEN it MUST clearly indicate "Dry run -- no data was persisted"
- [x] Implement
- [x] Test

### Task 5.5: Create WorkflowExecutionPanel Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-workflow-configuration-ui` (execution history view)
- **files**: `openregister/src/components/workflow/WorkflowExecutionPanel.vue`, `openregister/src/components/workflow/WorkflowExecutionDetail.vue`
- **acceptance_criteria**:
  - GIVEN the panel receives a schemaId prop WHEN it mounts THEN it MUST fetch executions from `GET /api/workflow-executions/?schemaId={id}&limit=20`
  - GIVEN execution results are loaded WHEN they render THEN each row MUST show: timestamp, hookId, objectUuid (as link), status (color-coded badge), durationMs
  - GIVEN a row is clicked WHEN the detail view opens THEN it MUST show all fields including errors, metadata, and payload
  - GIVEN the panel WHEN pagination controls are used THEN it MUST fetch the next page of results
- [x] Implement
- [x] Test

### Task 5.6: Create ApprovalChainPanel and ApprovalStepList Vue components
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-multi-step-approval-chains` (UI for chain management)
- **files**: `openregister/src/components/workflow/ApprovalChainPanel.vue`, `openregister/src/components/workflow/ApprovalStepList.vue`
- **acceptance_criteria**:
  - GIVEN the ApprovalChainPanel WHEN it renders for a schema THEN it MUST list existing approval chains for that schema
  - GIVEN an admin WHEN they click "Create chain" THEN a form MUST allow defining chain name, status field, and ordered steps (role + statusOnApprove + statusOnReject)
  - GIVEN the ApprovalStepList WHEN it receives an objectUuid prop THEN it MUST display the approval progress for that object across all chains
  - GIVEN a pending step WHEN the current user has the required role THEN an "Approve" and "Reject" button MUST be visible
  - GIVEN the user clicks "Approve" or "Reject" THEN a comment input MUST be shown and the action MUST call the corresponding API endpoint
- [x] Implement
- [x] Test

### Task 5.7: Create ScheduledWorkflowPanel Vue component
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-scheduled-workflow-triggers` (UI for schedule management)
- **files**: `openregister/src/components/workflow/ScheduledWorkflowPanel.vue`
- **acceptance_criteria**:
  - GIVEN the panel WHEN it renders THEN it MUST list all scheduled workflows from `GET /api/scheduled-workflows/`
  - GIVEN each row WHEN it renders THEN it MUST show: name, engine, workflowId, interval (human-readable), enabled status, lastRun, lastStatus
  - GIVEN an admin WHEN they click "Add schedule" THEN a form MUST allow setting name, engine, workflowId, register, schema, interval, payload, and enabled
  - GIVEN an existing schedule WHEN the admin edits it THEN the form MUST be pre-populated
  - GIVEN the enable/disable toggle WHEN toggled THEN the schedule MUST be updated via `PUT /api/scheduled-workflows/{id}`
- [x] Implement
- [x] Test

## 6. Execution History Cleanup

### Task 6.1: Create ExecutionHistoryCleanupJob
- **spec_ref**: `specs/workflow-operations/spec.md#requirement-execution-history-retention`
- **files**: `openregister/lib/BackgroundJob/ExecutionHistoryCleanupJob.php`, `openregister/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the job extends `OCP\BackgroundJob\TimedJob` WHEN it runs THEN it MUST read `workflow_execution_retention_days` from `IAppConfig` (default 90)
  - GIVEN the retention period WHEN the job executes THEN it MUST call `WorkflowExecutionMapper::deleteOlderThan()` with a cutoff date calculated as `now - retention_days`
  - GIVEN records are deleted WHEN the job completes THEN it MUST log the count of deleted records at INFO level
  - GIVEN no records need deletion WHEN the job runs THEN it MUST complete without error
  - GIVEN Application.php WHEN the app boots THEN the cleanup job MUST be registered with a daily interval (86400 seconds)
- [x] Implement
- [x] Test

## Verification

- [x] All tasks checked off
- [ ] `composer check:strict` passes in openregister
- [ ] All database migrations run without errors on both PostgreSQL and MariaDB
- [x] Workflow execution history is persisted and queryable via API
- [ ] Scheduled workflows execute on their configured intervals
- [x] Approval chains enforce role-based access via Nextcloud groups
- [x] Test hook endpoint returns results without database side effects
- [ ] Vue components render correctly and interact with the API
- [x] Execution history cleanup job prunes old records correctly
- [x] Code review against spec requirements
