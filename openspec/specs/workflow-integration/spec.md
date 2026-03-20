# Workflow Integration

---
status: implemented
---

## Purpose
Integrate BPMN-style workflow automation with register operations via n8n (primary) and other pluggable workflow engines (Windmill, future). Register events (create, update, delete, status change) MUST trigger configurable workflows for process automation, enrichment, validation, escalation, approval chains, and scheduled tasks. The integration MUST support zero-coding workflow configuration for functional administrators and provide full observability into workflow executions via logging, status tracking, and audit trails.

**Tender demand**: 38% of analyzed government tenders require workflow/process automation capabilities.

## Requirements

### Requirement: n8n SHALL be the primary workflow engine
n8n MUST serve as the default and primary workflow engine for OpenRegister. It SHALL run as a Nextcloud ExApp with API calls routed through the ExApp proxy at `/index.php/apps/app_api/proxy/n8n/`. The system MUST also support additional engines (Windmill) via the `WorkflowEngineInterface` adapter pattern, with engine selection configurable per-hook.

#### Scenario: n8n is auto-discovered when installed as ExApp
- **GIVEN** the n8n ExApp is enabled in Nextcloud
- **WHEN** an admin navigates to `GET /api/engines/available`
- **THEN** n8n MUST appear in the list with `engineType: "n8n"` and a pre-filled `suggestedBaseUrl`
- **AND** the admin MUST be able to register it with a single click using the suggested configuration
- **AND** the system MUST perform an initial health check on registration via `WorkflowEngineInterface::healthCheck()`

#### Scenario: n8n adapter routes through ExApp proxy
- **GIVEN** n8n is registered as a workflow engine with `baseUrl` pointing to the ExApp proxy path
- **WHEN** the `N8nAdapter` makes API calls (deploy, execute, list workflows)
- **THEN** all requests MUST route through `/index.php/apps/app_api/proxy/n8n/` with proper Nextcloud authentication headers
- **AND** workflow execution MUST POST to `{baseUrl}/webhook/{workflowId}` for webhook-triggered workflows
- **AND** workflow management MUST use `{baseUrl}/rest/workflows` for CRUD operations

#### Scenario: n8n MCP integration for AI-assisted workflow creation
- **GIVEN** the n8n MCP server is configured (via `npx n8n-mcp@latest`)
- **WHEN** an AI agent invokes `mcp__n8n__n8n_create_workflow` or `mcp__n8n__n8n_list_workflows`
- **THEN** the MCP server MUST interact with n8n's REST API to create, list, execute, and debug workflows
- **AND** created workflows MUST be deployable to OpenRegister via the `WorkflowEngineInterface::deployWorkflow()` method
- **AND** the MCP tools `n8n_test_workflow` and `n8n_executions` MUST provide execution debugging capabilities

#### Scenario: Multiple engines active simultaneously
- **GIVEN** both an n8n engine and a Windmill engine are registered in the `WorkflowEngineRegistry`
- **WHEN** a schema has hook 1 referencing `engine: "n8n"` and hook 2 referencing `engine: "windmill"`
- **THEN** `HookExecutor` MUST resolve the correct adapter for each hook via `engineRegistry->getEnginesByType()`
- **AND** hook 1 MUST be routed to the `N8nAdapter` and hook 2 to the `WindmillAdapter`
- **AND** engine selection MUST be per-hook, NOT per-schema

### Requirement: Register events MUST trigger workflow executions
All CRUD operations and configurable property changes on register objects MUST be publishable as events that trigger connected workflow definitions. Events are dispatched via Nextcloud's `IEventDispatcher` and caught by the `HookListener`, which delegates to `HookExecutor` for schema hook processing.

#### Scenario: Trigger workflow on object creation
- **GIVEN** a schema `meldingen` has a hook configured with `event: "creating"`, `engine: "n8n"`, `workflowId: "intake-melding"`, `mode: "sync"`
- **WHEN** a new melding object is created and `ObjectCreatingEvent` is dispatched
- **THEN** `HookExecutor::executeHooks()` MUST load enabled hooks matching event type `creating` from the schema
- **AND** the `N8nAdapter::executeWorkflow()` MUST POST the CloudEvent payload to `{baseUrl}/webhook/intake-melding`
- **AND** the payload MUST include `data.object` (full object data), `data.schema`, `data.register`, `data.action`, and `openregister.hookId`
- **AND** the workflow execution MUST be logged with hookId, eventType, objectUuid, engine, workflowId, and durationMs

#### Scenario: Trigger workflow on post-creation event (async)
- **GIVEN** a schema `meldingen` has a hook configured with `event: "created"`, `engine: "n8n"`, `workflowId: "send-notification"`, `mode: "async"`
- **WHEN** the melding object is persisted and `ObjectCreatedEvent` is dispatched
- **THEN** the system MUST trigger the workflow in fire-and-forget mode
- **AND** `openregister.expectResponse` in the CloudEvent payload MUST be `false`
- **AND** the async execution result (delivered/failed) MUST be logged with `deliveryStatus`

#### Scenario: Trigger workflow on object update
- **GIVEN** a schema `vergunningen` has a hook configured with `event: "updating"`, `engine: "n8n"`, `workflowId: "validate-update"`
- **WHEN** a vergunning object is updated and `ObjectUpdatingEvent` is dispatched
- **THEN** `HookExecutor` MUST receive both the new object (via `getNewObject()`) and the old object (via `getOldObject()`)
- **AND** the CloudEvent payload MUST include the current object data for workflow processing
- **AND** if the workflow returns `status: "rejected"`, the update MUST be aborted and the object MUST remain unchanged

#### Scenario: Trigger workflow on object deletion
- **GIVEN** a schema `taken` has a hook configured with `event: "deleting"`, `engine: "n8n"`, `workflowId: "check-dependencies"`
- **WHEN** a taak object is deleted and `ObjectDeletingEvent` is dispatched
- **THEN** the workflow MUST receive the full object snapshot before deletion
- **AND** if the workflow returns `status: "rejected"`, the deletion MUST be aborted
- **AND** the rejection errors MUST be returned to the caller

### Requirement: Schema hooks MUST support configurable workflow triggers
Schemas MUST store workflow hook configurations in their `hooks` JSON property. Each hook binds a lifecycle event to a specific workflow in a specific engine, with configurable execution mode, ordering, timeout, and failure behavior.

#### Scenario: Configure hook via schema hooks property
- **GIVEN** a schema entity with the `hooks` JSON property
- **WHEN** an admin sets the hooks array to include `{"id": "validate-kvk", "event": "creating", "engine": "n8n", "workflowId": "kvk-validator", "mode": "sync", "order": 1, "timeout": 10, "onFailure": "reject", "onTimeout": "allow", "onEngineDown": "allow", "enabled": true}`
- **THEN** the hook MUST be stored as part of the schema entity
- **AND** the hook MUST fire when an `ObjectCreatingEvent` is dispatched for this schema
- **AND** hooks MUST be sorted by the `order` field (ascending) before execution

#### Scenario: Multiple hooks on same event execute in order
- **GIVEN** a schema with hooks at order 1 (validation), order 2 (enrichment), and order 3 (notification)
- **WHEN** the `creating` event fires
- **THEN** `HookExecutor::loadHooks()` MUST filter by event type and sort by order ascending
- **AND** the validation hook at order 1 MUST execute first
- **AND** if validation returns `status: "modified"`, the enriched data MUST be merged into the object via `event->setModifiedData()` before hook 2 executes
- **AND** if any hook stops propagation via `event->stopPropagation()`, subsequent hooks MUST be skipped

#### Scenario: Disabled hook is skipped
- **GIVEN** a hook configuration with `enabled: false`
- **WHEN** the associated event fires
- **THEN** `HookExecutor::loadHooks()` MUST filter it out
- **AND** the hook MUST NOT execute or be logged as executed

#### Scenario: Valid event type values
- **GIVEN** a hook configuration
- **WHEN** the `event` field is validated
- **THEN** it MUST be one of: `creating`, `updating`, `deleting`, `created`, `updated`, `deleted`
- **AND** pre-mutation events (`creating`, `updating`, `deleting`) support sync mode with response processing
- **AND** post-mutation events (`created`, `updated`, `deleted`) are typically used for async notifications

### Requirement: Workflows MUST use the Workflow Execution API
The system MUST provide a REST API for managing workflow engine registrations and executing workflows. The `WorkflowEngineController` exposes CRUD operations on engine configurations and health checks.

#### Scenario: Register a new workflow engine via API
- **GIVEN** an admin user is authenticated
- **WHEN** they POST to `/api/engines/` with `name`, `engineType` (n8n or windmill), `baseUrl`, `authType`, `authConfig`, `enabled`, and `defaultTimeout`
- **THEN** the engine MUST be stored via `WorkflowEngineRegistry::createEngine()`
- **AND** `authConfig` credentials MUST be encrypted at rest using Nextcloud's `ICrypto` service
- **AND** an initial health check MUST be performed via `WorkflowEngineRegistry::healthCheck()`
- **AND** the response MUST return HTTP 201 with the created engine configuration (credentials excluded)

#### Scenario: Execute a workflow programmatically
- **GIVEN** a registered n8n engine and a deployed workflow with ID `workflow-123`
- **WHEN** `WorkflowEngineInterface::executeWorkflow("workflow-123", $data, 30)` is called
- **THEN** the adapter MUST POST `$data` to the workflow's webhook URL
- **AND** wait for the response up to the timeout (30 seconds)
- **AND** return a `WorkflowResult` with status `approved`, `rejected`, `modified`, or `error`

#### Scenario: List all workflows from engine
- **GIVEN** an n8n engine is registered and contains 5 workflows
- **WHEN** `WorkflowEngineInterface::listWorkflows()` is called
- **THEN** it MUST return an array of workflow summaries with `id`, `name`, and `active` status
- **AND** if the engine is unreachable, it MUST return an empty array without throwing

#### Scenario: Delete a workflow engine
- **GIVEN** an engine is registered with ID 42
- **WHEN** an admin sends `DELETE /api/engines/42`
- **THEN** the engine MUST be removed from the registry via `WorkflowEngineMapper::delete()`
- **AND** any schema hooks referencing this engine type SHOULD receive a warning on next invocation

### Requirement: Workflow execution status MUST be tracked and logged
All workflow executions MUST be logged with structured context data for monitoring, debugging, and audit purposes. The `HookExecutor::logHookExecution()` method records every execution with timing, status, and error details.

#### Scenario: Successful sync workflow execution is logged
- **GIVEN** a sync hook `validate-kvk` executes successfully with `status: "approved"`
- **WHEN** the execution completes
- **THEN** `HookExecutor` MUST log at INFO level with message pattern `[HookExecutor] Hook 'validate-kvk' ok`
- **AND** the log context MUST include: `hookId`, `eventType`, `objectUuid`, `engine`, `workflowId`, `durationMs`, `responseStatus: "approved"`

#### Scenario: Failed workflow execution is logged with error details
- **GIVEN** a sync hook `validate-kvk` fails due to a network error
- **WHEN** the exception is caught
- **THEN** `HookExecutor` MUST log at ERROR level with the error message and payload
- **AND** the log context MUST include the full hook configuration, object UUID, and duration
- **AND** the failure mode (`onFailure`, `onTimeout`, or `onEngineDown`) MUST be applied

#### Scenario: Async workflow delivery status is tracked
- **GIVEN** an async hook `send-notification` fires
- **WHEN** the webhook delivery succeeds or fails
- **THEN** `HookExecutor::executeAsyncHook()` MUST log with `deliveryStatus: "delivered"` or `deliveryStatus: "failed"`
- **AND** async failures MUST NOT block or abort the object save operation

#### Scenario: Health check status is persisted on engine entity
- **GIVEN** an admin triggers `GET /api/engines/{id}/health`
- **WHEN** `WorkflowEngineRegistry::healthCheck()` executes
- **THEN** the adapter's `healthCheck()` result MUST be persisted on the `WorkflowEngine` entity via `setHealthStatus()` and `setLastHealthCheck()`
- **AND** the response MUST include `healthy` (boolean) and `responseTime` (milliseconds)

### Requirement: Workflows MUST support result callbacks that modify object data
When a sync workflow returns a `modified` result, the modified data MUST be merged back into the object before persistence. This enables workflow-driven data enrichment, normalization, and computed field population.

#### Scenario: Workflow enriches object with computed fields
- **GIVEN** a sync hook on `creating` event for schema `organisaties`
- **WHEN** the n8n workflow validates a KvK number and returns `{"status": "modified", "data": {"kvkVerified": true, "companyName": "Acme B.V.", "address": "Keizersgracht 1, Amsterdam"}}`
- **THEN** `HookExecutor::processWorkflowResult()` MUST detect `result->isModified()` and extract `result->getData()`
- **AND** `setModifiedDataOnEvent()` MUST call `event->setModifiedData(data)` to merge the enriched fields into the object
- **AND** subsequent hooks in the chain MUST receive the enriched object data
- **AND** the final persisted object MUST contain the workflow's modifications

#### Scenario: Workflow rejects object with validation errors
- **GIVEN** a sync hook with `onFailure: "reject"` on `creating` event
- **WHEN** the workflow returns `{"status": "rejected", "errors": [{"field": "bsn", "message": "BSN is invalid", "code": "INVALID_BSN"}]}`
- **THEN** `HookExecutor` MUST call `applyFailureMode("reject", ...)` which stops event propagation
- **AND** `stopEvent()` MUST call `event->stopPropagation()` and `event->setErrors()`
- **AND** the API MUST return HTTP 422 with the validation errors array
- **AND** no object MUST be persisted to the database

#### Scenario: Workflow approves object without modification
- **GIVEN** a sync hook on `updating` event
- **WHEN** the workflow returns `{"status": "approved"}` or a null response
- **THEN** `N8nAdapter::parseWorkflowResponse()` MUST return `WorkflowResult::approved()`
- **AND** the save MUST proceed normally
- **AND** the next hook in order MUST execute (if any)

### Requirement: Workflows MUST support conditional execution based on object data
Hooks MUST support an optional `filterCondition` property that evaluates against the object's data to determine whether the hook should execute. This enables targeted workflow triggering without executing unnecessary workflows.

#### Scenario: Hook fires only when filter condition matches
- **GIVEN** a hook with `filterCondition: {"status": "in_behandeling"}` on event `updating`
- **WHEN** an object with `status: "in_behandeling"` is updated
- **THEN** `HookExecutor::evaluateFilterCondition()` MUST compare each key-value pair against `object->getObject()`
- **AND** the hook MUST execute because all conditions match

#### Scenario: Hook is skipped when filter condition does not match
- **GIVEN** a hook with `filterCondition: {"status": "in_behandeling"}` on event `updating`
- **WHEN** an object with `status: "nieuw"` is updated
- **THEN** `evaluateFilterCondition()` MUST return `false` because `actual !== expected`
- **AND** the hook MUST be skipped with a DEBUG log message: `Hook 'hookId' skipped: filterCondition not met`

#### Scenario: Hook with no filter condition always executes
- **GIVEN** a hook with `filterCondition: null` or an empty array
- **WHEN** any object matching the event type is processed
- **THEN** `evaluateFilterCondition()` MUST return `true`
- **AND** the hook MUST execute unconditionally

#### Scenario: Multiple filter conditions must all match
- **GIVEN** a hook with `filterCondition: {"status": "in_behandeling", "priority": "hoog"}`
- **WHEN** an object with `status: "in_behandeling"` but `priority: "normaal"` is updated
- **THEN** `evaluateFilterCondition()` MUST return `false` because the second condition does not match
- **AND** the hook MUST be skipped

### Requirement: The system MUST provide pre-built workflow templates for common operations
OpenRegister MUST ship with pre-configured n8n workflow templates in `lib/Settings/n8n_workflows.openregister.json` that cover common government process automation patterns. Templates MUST be deployable via the import pipeline or manually via the workflow engine API.

#### Scenario: Workflow templates define standard schemas
- **GIVEN** the `n8n_workflows.openregister.json` configuration file
- **WHEN** it is loaded by the system
- **THEN** it MUST define schemas for workflow-related entities: `workflow` (title, workflowId, description, active, tags), `trigger` (event-to-workflow bindings), `webhook` (external integrations), `schedule` (cron-based triggers), and `notification` (alert templates)
- **AND** each schema MUST include required fields and validation constraints

#### Scenario: Templates are deployable via import pipeline
- **GIVEN** a workflow template JSON with a `workflows` array
- **WHEN** the import pipeline processes the file (per `workflow-in-import` spec)
- **THEN** workflows MUST be deployed to the target engine via `WorkflowEngineInterface::deployWorkflow()`
- **AND** `attachTo` configurations MUST wire them as schema hooks
- **AND** `DeployedWorkflow` records MUST be created for version tracking with SHA-256 hash comparison

#### Scenario: Re-import detects unchanged templates
- **GIVEN** a workflow template was previously imported with a known `sourceHash`
- **WHEN** the same template file is re-imported
- **THEN** the import MUST compare SHA-256 hashes and skip re-deployment for unchanged workflows
- **AND** the import summary MUST show them as "unchanged"

### Requirement: Workflow error handling MUST support configurable failure modes
Each hook MUST support `onFailure`, `onTimeout`, and `onEngineDown` properties that determine behavior when the workflow fails, times out, or the engine is unreachable. The `HookExecutor::applyFailureMode()` implements four distinct modes.

#### Scenario: Failure mode "reject" aborts the operation
- **GIVEN** a hook with `onFailure: "reject"`
- **WHEN** the workflow returns `status: "error"` or `status: "rejected"`
- **THEN** `applyFailureMode("reject", ...)` MUST call `stopEvent()` which invokes `event->stopPropagation()` and `event->setErrors()`
- **AND** the object save MUST be aborted
- **AND** the error MUST be logged at ERROR level

#### Scenario: Failure mode "allow" permits the operation to continue
- **GIVEN** a hook with `onEngineDown: "allow"`
- **WHEN** the engine is unreachable (connection refused, timeout)
- **THEN** `determineFailureMode()` MUST detect connection/unreachable keywords and return `onEngineDown` mode
- **AND** `applyFailureMode("allow", ...)` MUST log a WARNING but NOT stop event propagation
- **AND** the object save MUST proceed normally

#### Scenario: Failure mode "flag" marks the object with validation metadata
- **GIVEN** a hook with `onFailure: "flag"`
- **WHEN** the workflow fails
- **THEN** `applyFailureMode("flag", ...)` MUST call `setValidationMetadata()` to set `_validationStatus: "failed"` and `_validationErrors` on the object data
- **AND** the object MUST still be saved (propagation is NOT stopped)
- **AND** downstream consumers MAY read `_validationStatus` to display warnings

#### Scenario: Failure mode "queue" schedules a retry job
- **GIVEN** a hook with `onEngineDown: "queue"`
- **WHEN** the engine is unreachable
- **THEN** `applyFailureMode("queue", ...)` MUST set `_validationStatus: "pending"` on the object
- **AND** `scheduleRetryJob()` MUST add a `HookRetryJob` to Nextcloud's `IJobList` with the objectId, schemaId, and hook configuration
- **AND** the object MUST be saved with pending status

#### Scenario: Timeout detection uses keyword matching
- **GIVEN** a hook with `onTimeout: "allow"`
- **WHEN** the workflow execution throws an exception containing "timeout" or "timed out"
- **THEN** `determineFailureMode()` MUST match these keywords and return the `onTimeout` mode
- **AND** `applyFailureMode("allow", ...)` MUST log a warning and permit the save to proceed

### Requirement: Failed workflow executions MUST support automatic retry with backoff
The `HookRetryJob` background job MUST retry failed hook executions when the failure mode is `queue`. It MUST support a maximum retry count and re-queue itself for subsequent attempts.

#### Scenario: Retry succeeds on second attempt
- **GIVEN** a `HookRetryJob` is queued with `objectId: 42`, `schemaId: 5`, hook config, and `attempt: 1`
- **WHEN** the job runs and the engine is now reachable
- **THEN** the job MUST rebuild a CloudEvent payload with `eventType: "nl.openregister.object.hook-retry"`
- **AND** execute the workflow via the resolved adapter
- **AND** if the result is `approved` or `modified`, it MUST update the object's `_validationStatus` to `"passed"` and remove `_validationErrors`
- **AND** if `modified`, the workflow's data MUST be merged into the object via `array_merge()`

#### Scenario: Retry fails and re-queues with incremented attempt
- **GIVEN** a retry job at `attempt: 2` with `MAX_RETRIES: 5`
- **WHEN** the engine is still unreachable
- **THEN** the job MUST log a warning and add a new `HookRetryJob` with `attempt: 3`
- **AND** the object MUST retain its `_validationStatus: "pending"` state

#### Scenario: Maximum retries reached
- **GIVEN** a retry job at `attempt: 5` (equal to `MAX_RETRIES`)
- **WHEN** the engine is still unreachable
- **THEN** the job MUST log an ERROR: `Max retries reached for hook 'hookId' on object objectId`
- **AND** MUST NOT re-queue another retry job
- **AND** the object MUST retain its current `_validationStatus` (likely "pending")

### Requirement: Workflow executions MUST create an audit trail
All hook executions and their outcomes MUST be traceable for compliance, debugging, and operational monitoring. The audit trail combines structured logging from `HookExecutor` with workflow result metadata.

#### Scenario: Successful hook execution creates audit entry
- **GIVEN** a sync hook `validate-org` fires for object `org-123`
- **WHEN** the workflow returns `approved` in 45ms
- **THEN** the log entry MUST contain: `hookId: "validate-org"`, `eventType: "creating"`, `objectUuid: "org-123"`, `engine: "n8n"`, `workflowId: "org-validator"`, `durationMs: 45`, `responseStatus: "approved"`

#### Scenario: Rejected hook execution includes error details
- **GIVEN** a sync hook rejects an object
- **WHEN** the workflow returns `rejected` with errors
- **THEN** the log entry MUST contain the error message and the `responseStatus: "rejected"`
- **AND** the payload MUST be included in the log context for debugging

#### Scenario: Workflow actor is recorded in object audit trail
- **GIVEN** a workflow modifies an object via the OpenRegister API (n8n HTTP node calling the API)
- **WHEN** the workflow uses service account credentials for the API call
- **THEN** the audit trail entry for the object update MUST indicate the workflow/service account as the actor
- **AND** the modification MUST be distinguishable from manual user edits

### Requirement: Workflows MUST support multi-step approval chains
The system MUST support multi-step approval workflows where objects require sign-off from one or more users before proceeding. Approval chains are implemented as n8n workflows that update object status and send notifications at each step.

#### Scenario: Two-step approval workflow
- **GIVEN** an n8n workflow `two-step-approval` is deployed and wired to the `vergunningen` schema on `creating` event
- **WHEN** a new vergunning is created
- **THEN** the workflow MUST set the object status to `wacht_op_teamleider`
- **AND** send a notification to the assigned `teamleider` via Nextcloud notifications or email
- **AND** when `teamleider` approves (by updating the object status), the workflow MUST advance to `wacht_op_afdelingshoofd`

#### Scenario: Approval rejection with reason
- **GIVEN** an object in status `wacht_op_afdelingshoofd`
- **WHEN** `afdelingshoofd` rejects by updating the object with `status: "afgewezen"` and `rejectReason: "Onvoldoende onderbouwing"`
- **THEN** the update event MUST trigger a notification workflow that informs the original submitter
- **AND** the rejection reason MUST be stored on the object for audit purposes

#### Scenario: Approval chain with parallel approvers
- **GIVEN** a workflow requiring approval from both `juridisch` AND `financieel` before final approval
- **WHEN** both approvers have approved
- **THEN** the workflow MUST advance the object to the next status only when all required approvals are received
- **AND** each individual approval MUST be recorded in the object's audit trail

### Requirement: Workflows MUST support scheduled execution via Nextcloud background jobs
The system MUST support scheduled workflows that run on a recurring basis, independent of object lifecycle events. Scheduled workflows use Nextcloud's `TimedJob` infrastructure for cron-based execution.

#### Scenario: Daily deadline monitoring workflow
- **GIVEN** a scheduled n8n workflow `termijn-bewaking` that runs daily
- **WHEN** the Nextcloud cron triggers the associated `TimedJob`
- **THEN** the workflow MUST query for objects where `deadline < today AND status != "afgehandeld"`
- **AND** for each overdue object, take configured actions (notification, escalation, status update)

#### Scenario: Weekly report generation
- **GIVEN** a scheduled workflow `weekly-report` configured with interval `604800` seconds (7 days)
- **WHEN** the cron interval elapses
- **THEN** the workflow MUST aggregate data from the register and generate a report
- **AND** the report MUST be stored as a file in Nextcloud or sent via notification

#### Scenario: Scheduled workflow uses register context
- **GIVEN** a scheduled workflow that needs to query objects from register `zaken` with schema `vergunningen`
- **WHEN** the workflow executes
- **THEN** it MUST have access to the OpenRegister API to query objects with filters
- **AND** the workflow MUST authenticate using the configured engine credentials

### Requirement: Workflows MUST receive register context as variables
Workflow payloads MUST include contextual information about the register, schema, and triggering event so that workflows can make context-aware decisions without additional API calls.

#### Scenario: CloudEvent payload includes full context
- **GIVEN** a hook fires for object `obj-123` in register `zaken` (registerId: 5), schema `vergunningen` (schemaId: 12)
- **WHEN** `HookExecutor::buildCloudEventPayload()` constructs the payload
- **THEN** the payload MUST conform to CloudEvents 1.0 with:
  - `specversion: "1.0"`
  - `type: "nl.openregister.object.creating"`
  - `source: "/apps/openregister/registers/5/schemas/12"`
  - `subject: "object:obj-123"`
  - `data.object`: full object data from `object->getObject()`
  - `data.schema`: schema slug or title
  - `data.register`: register ID
  - `data.action`: event type string (creating, updating, etc.)
  - `data.hookMode`: "sync" or "async"
  - `openregister.hookId`: hook identifier
  - `openregister.expectResponse`: true for sync, false for async

#### Scenario: Updating event includes old and new state context
- **GIVEN** a hook on `updating` event
- **WHEN** the payload is constructed
- **THEN** `data.object` MUST contain the current (new) object data
- **AND** the workflow MAY compare with previous state by querying the audit trail API

#### Scenario: Retry payload uses special event type
- **GIVEN** a `HookRetryJob` rebuilds a CloudEvent payload
- **WHEN** the retry executes
- **THEN** the `eventType` MUST be `"nl.openregister.object.hook-retry"` to distinguish retries from original events
- **AND** `data.action` MUST be `"retry"`

### Requirement: Workflows MUST support testing and dry-run execution
Administrators MUST be able to test workflow triggers with sample data before activating them in production, to verify correct behavior and prevent data corruption.

#### Scenario: Test workflow trigger via n8n MCP
- **GIVEN** an n8n workflow `validate-org` is deployed
- **WHEN** an admin or AI agent invokes `mcp__n8n__n8n_test_workflow` with sample object data
- **THEN** the workflow MUST execute with the test data
- **AND** the execution result MUST be returned without modifying any register data
- **AND** execution details MUST be viewable via `mcp__n8n__n8n_executions`

#### Scenario: Test workflow via engine adapter
- **GIVEN** a registered engine and a deployed workflow
- **WHEN** `WorkflowEngineInterface::executeWorkflow()` is called with test data and a mock object
- **THEN** the workflow MUST execute and return a `WorkflowResult`
- **AND** the caller MUST NOT persist the result to the database (dry-run is caller-controlled)

#### Scenario: Verify hook configuration before activation
- **GIVEN** a new hook configuration for schema `organisaties`
- **WHEN** the admin wants to verify the hook works
- **THEN** they MUST be able to list available workflows via `WorkflowEngineInterface::listWorkflows()`
- **AND** verify the target workflow exists and is active
- **AND** check engine health via `WorkflowEngineInterface::healthCheck()`

### Requirement: The system MUST provide a workflow configuration UI
Administrators MUST be able to configure event-workflow connections without coding. The UI MUST allow managing hooks on schemas, viewing engine status, and monitoring workflow executions.

#### Scenario: Configure event trigger via schema settings UI
- **GIVEN** the admin navigates to schema `meldingen` settings
- **WHEN** they open the "Workflows" tab
- **THEN** the UI MUST display a list of connected hooks from the schema's `hooks` property
- **AND** an "Add hook" form MUST allow selecting: event type (creating/updating/deleting/created/updated/deleted), engine (from registered engines), workflowId (from engine's workflow list), mode (sync/async), order, timeout, onFailure/onTimeout/onEngineDown modes, and optional filterCondition

#### Scenario: View workflow engine health in UI
- **GIVEN** the admin navigates to the workflow engines settings page
- **WHEN** engines are listed via `GET /api/engines/`
- **THEN** each engine MUST display its name, type, enabled status, health status, and last health check timestamp
- **AND** a "Check health" button MUST trigger `GET /api/engines/{id}/health` and update the display

#### Scenario: Test hook trigger from UI
- **GIVEN** a configured hook on schema `meldingen`
- **WHEN** the admin clicks "Test hook"
- **THEN** the system MUST execute the workflow with sample data derived from the schema's properties
- **AND** display the `WorkflowResult` (status, data, errors, metadata) in the UI
- **AND** the test MUST NOT modify any register data

## Current Implementation Status

**Substantially implemented** via the schema hooks + workflow engine abstraction infrastructure:

**Implemented (core event-workflow pipeline):**
- `lib/Service/HookExecutor.php` -- Orchestrates schema hook execution for object lifecycle events (creating, created, updating, updated, deleting, deleted). Supports hook ordering, filter conditions, sync/async modes, and configurable failure modes (reject/allow/flag/queue).
- `lib/Listener/HookListener.php` -- PSR-14 listener that dispatches events to HookExecutor
- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- Engine-agnostic interface with methods: `deployWorkflow()`, `updateWorkflow()`, `getWorkflow()`, `deleteWorkflow()`, `activateWorkflow()`, `deactivateWorkflow()`, `executeWorkflow()`, `getWebhookUrl()`, `listWorkflows()`, `healthCheck()`
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n adapter implementing the interface, routes through ExApp proxy, supports bearer/basic auth, parses n8n responses into WorkflowResult
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill adapter implementing the interface
- `lib/WorkflowEngine/WorkflowResult.php` -- Structured result value object with statuses: `STATUS_APPROVED`, `STATUS_REJECTED`, `STATUS_MODIFIED`, `STATUS_ERROR`; implements `JsonSerializable`
- `lib/Db/Schema.php` -- Schema `hooks` JSON property for configuring event-workflow connections
- `lib/Service/WorkflowEngineRegistry.php` -- Registry for managing engines, resolving adapters, encrypting credentials via ICrypto, auto-discovering ExApps via IAppManager
- `lib/Controller/WorkflowEngineController.php` -- REST API for CRUD on engine configurations, health checks, and auto-discovery (`/api/engines/`, `/api/engines/{id}/health`, `/api/engines/available`)
- `lib/BackgroundJob/HookRetryJob.php` -- QueuedJob for retrying failed hooks with max 5 attempts, updates `_validationStatus` on success
- `lib/Service/Webhook/CloudEventFormatter.php` -- CloudEvents 1.0 payload formatter
- `lib/Db/WorkflowEngine.php` + `WorkflowEngineMapper.php` -- Engine configuration entity with authType, authConfig (encrypted), healthStatus, lastHealthCheck
- `lib/Db/DeployedWorkflow.php` + `DeployedWorkflowMapper.php` -- Deployed workflow tracking with SHA-256 hash, version, attachedSchema, attachedEvent
- `lib/Settings/n8n_workflows.openregister.json` -- Pre-configured n8n workflow templates (workflow, trigger, webhook, schedule, notification schemas)
- `lib/Controller/Settings/N8nSettingsController.php` -- n8n connection configuration, testing, and project initialization
- `n8n-mcp/` -- n8n MCP integration for AI-assisted workflow creation and debugging

**Implemented (workflow modifies objects):**
- HookExecutor processes `modified` results via `setModifiedDataOnEvent()`, merging enriched data back into objects before save
- Workflows can call OpenRegister API to create/update/delete objects (n8n HTTP nodes)
- Filter conditions supported via `evaluateFilterCondition()` with simple key-value matching

**Not yet implemented:**
- Workflow configuration UI in schema settings (no "Workflows" tab with "Add hook" form)
- Visual workflow execution history/monitoring dashboard in the OpenRegister UI
- Scheduled/cron-based workflow triggers via TimedJob (can be configured directly in n8n but no OpenRegister-specific scheduling integration)
- Multi-step approval chain workflows (can be built in n8n but no OpenRegister-specific approval state machine)
- Approval/rejection UI with notification integration
- "Test hook" button in the configuration UI

## Standards & References
- CloudEvents 1.0 Specification -- wire format for all hook payloads (`specversion: "1.0"`, structured content mode)
- BPMN 2.0 (Business Process Model and Notation) -- conceptual model for workflow automation
- n8n REST API (https://docs.n8n.io/api/) -- workflow CRUD, webhook triggers, execution history
- n8n MCP (https://www.npmjs.com/package/n8n-mcp) -- AI agent integration for workflow management
- Windmill REST API (https://app.windmill.dev/openapi.html) -- alternative engine support
- Nextcloud ExApp API proxy (`IAppApiService`) -- secure routing to containerized engines
- Nextcloud notification system (`OCP\Notification`) -- user notifications for approval workflows
- Nextcloud background jobs (`OCP\BackgroundJob\QueuedJob`, `TimedJob`) -- retry jobs and scheduled workflows
- Dutch government process automation requirements (VNG ZGW process standards)
- Adapter pattern (Gang of Four) -- engine abstraction strategy
- PSR-14 Event Dispatcher -- event listener architecture

## Cross-References
- **workflow-engine-abstraction** -- Defines the `WorkflowEngineInterface`, adapter pattern, engine registry, and `WorkflowResult` value object that this spec builds upon
- **workflow-in-import** -- Defines how workflow definitions are deployed via the import pipeline, including `DeployedWorkflow` versioning and `attachTo` hook wiring
- **schema-hooks** -- Defines the hook configuration format on schemas, CloudEvents wire format, sync/async delivery modes, and failure mode behaviors
- **event-driven-architecture** -- Defines the typed PHP events (`ObjectCreatingEvent`, etc.), `StoppableEventInterface` for pre-mutation rejection, and `IEventDispatcher` integration that triggers hooks

## Specificity Assessment
- **Specific enough to implement?** Yes for the backend pipeline -- the `HookExecutor`, adapters, registry, and retry system are well-defined and implemented. UI requirements need component-level detail.
- **Missing/ambiguous:**
  - No specification for the workflow configuration UI component structure (Vue components, store integration)
  - No specification for approval chain state machine (valid states/transitions, delegation rules)
  - No specification for scheduled workflow registration (how TimedJob instances map to n8n schedules)
  - No specification for notification templates for approval requests/rejections
  - No specification for complex filterCondition expressions (currently limited to simple key-value equality)
- **Open questions:**
  - Should approval chains be first-class OpenRegister entities or purely n8n workflow configurations?
  - How should workflow execution history be stored (OpenRegister database? n8n execution log? Both?)
  - Should the workflow configuration UI be in OpenRegister or delegated to the engine's native UI (n8n editor)?
  - Should filterCondition support nested property access (dot-notation), comparison operators, or full expression language?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `HookExecutor` orchestrates workflow execution on object lifecycle events. `HookListener` dispatches events to the executor via `IEventDispatcher`. `WorkflowEngineInterface` with `N8nAdapter` and `WindmillAdapter` provide engine-agnostic execution. `WorkflowResult` handles structured responses (approved/rejected/modified/error). `WorkflowEngineRegistry` manages adapter resolution with `ICrypto` credential encryption and `IAppManager` engine auto-discovery. `WorkflowEngineController` exposes REST API. `HookRetryJob` retries failed hooks via `QueuedJob`. Pre-configured n8n workflow templates in `n8n_workflows.openregister.json`. `DeployedWorkflow` entity tracks imported workflows with version hashing.
- **Nextcloud Core Integration**: Background jobs use `QueuedJob` for hook retry and `TimedJob` for scheduled workflows. Event-driven via `IEventDispatcher::dispatchTyped()`. Workflow engine services registered in DI container via `IBootstrap::register()`. n8n ExApp integration routes through `IAppApiService` proxy. Credential encryption uses `ICrypto`. Engine auto-discovery uses `IAppManager`. CloudEvents payloads formatted by `CloudEventFormatter`.
- **Recommendation**: Mark as implemented. The core event-workflow pipeline is fully functional. UI features (workflow configuration tab, execution history dashboard, approval chain support, test hook button) are planned enhancements that do not block core functionality.
