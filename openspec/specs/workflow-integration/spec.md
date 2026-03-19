# workflow-integration Specification

---
status: implemented
---

## Purpose
Integrate BPMN-style workflow automation with register operations via n8n and other workflow engines. Register events (create, update, delete, status change) MUST trigger configurable workflows for process automation, escalation, approval chains, and scheduled tasks. The integration MUST support zero-coding workflow configuration for functional administrators.

**Tender demand**: 38% of analyzed government tenders require workflow/process automation capabilities.

## ADDED Requirements

### Requirement: Register events MUST trigger workflow executions
All CRUD operations and configurable property changes on register objects MUST be publishable as events that trigger connected workflow definitions.

#### Scenario: Trigger workflow on object creation
- GIVEN a workflow definition `intake-melding` is connected to schema `meldingen` on event `object.created`
- WHEN a new melding object is created
- THEN the system MUST trigger the `intake-melding` workflow
- AND pass the full object data as workflow input
- AND the workflow execution MUST be logged with reference to the triggering object

#### Scenario: Trigger workflow on status change
- GIVEN a workflow `escalatie-check` is connected to schema `vergunningen` on event `object.updated` with condition `changed.status == "in_behandeling"`
- WHEN a vergunning's status is updated to `in_behandeling`
- THEN the workflow MUST be triggered
- AND the workflow input MUST include both the previous and new status values

#### Scenario: Trigger workflow on deadline
- GIVEN a workflow `termijn-bewaking` is scheduled to run daily
- AND it queries for vergunningen where `deadline` < today AND `status` != `afgehandeld`
- WHEN the daily schedule fires
- THEN the workflow MUST identify overdue vergunningen
- AND take configured actions (notification, escalation, reassignment)

### Requirement: Workflows MUST be able to modify register objects
Workflow actions MUST support creating, updating, and deleting register objects via the OpenRegister API.

#### Scenario: Workflow updates object status
- GIVEN a running workflow for melding `melding-1`
- WHEN the workflow executes an "Update Object" action setting `status` to `in_behandeling`
- THEN the system MUST update the object via the internal API
- AND the update MUST trigger the normal audit trail entry
- AND the audit entry user MUST indicate the workflow as the actor

#### Scenario: Workflow creates related objects
- GIVEN a workflow triggered by a new vergunning
- WHEN the workflow creates a `taak` object assigned to `behandelaar-1`
- THEN the taak MUST be created in the register with a reference to the vergunning
- AND the taak creation MUST appear in the audit trail

### Requirement: The system MUST provide a workflow configuration UI
Administrators MUST be able to configure event-workflow connections without coding.

#### Scenario: Configure event trigger via UI
- GIVEN the admin navigates to schema `meldingen` settings
- WHEN they open the "Workflows" tab
- THEN the UI MUST display a list of connected workflows
- AND an "Add trigger" button MUST allow selecting:
  - Event type (created, updated, deleted)
  - Optional condition (property change filter)
  - Target workflow (selected from available workflow definitions)

#### Scenario: Test workflow trigger
- GIVEN a configured workflow trigger
- WHEN the admin clicks "Test trigger"
- THEN the system MUST execute the workflow with sample data
- AND display the execution result (success/failure) in the UI

### Requirement: Workflow executions MUST be monitored and debuggable
All workflow executions MUST be logged and viewable for troubleshooting.

#### Scenario: View workflow execution history
- GIVEN schema `meldingen` with 50 workflow executions in the past week
- WHEN the admin navigates to the workflow execution log
- THEN the log MUST display each execution with:
  - Timestamp, trigger event, workflow name
  - Status (success, failed, running)
  - Duration and triggering object reference

#### Scenario: Inspect failed workflow execution
- GIVEN a failed workflow execution for melding `melding-45`
- WHEN the admin clicks the execution entry
- THEN the system MUST display the error message and the step that failed
- AND provide a link to the full execution details in the workflow engine

### Requirement: Workflows MUST support approval chains
The system MUST support multi-step approval workflows where objects require sign-off from one or more users before proceeding.

#### Scenario: Two-step approval workflow
- GIVEN a workflow requiring approval from `teamleider` then `afdelingshoofd`
- WHEN `teamleider` approves
- THEN the object MUST move to status `wacht_op_afdelingshoofd`
- AND `afdelingshoofd` MUST receive a notification
- AND when `afdelingshoofd` approves, the object MUST move to `goedgekeurd`

#### Scenario: Approval rejection
- GIVEN an object awaiting approval from `afdelingshoofd`
- WHEN `afdelingshoofd` rejects
- THEN the object MUST move to status `afgewezen`
- AND the original submitter MUST receive a notification with the rejection reason

### Current Implementation Status

**Substantially implemented** via the schema hooks + workflow engine abstraction infrastructure:

**Implemented (core event-workflow pipeline):**
- `lib/Service/HookExecutor.php` -- Executes workflows on object lifecycle events (creating, created, updating, updated, deleting, deleted)
- `lib/Listener/HookListener.php` -- PSR-14 listener dispatching events to HookExecutor
- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- Engine-agnostic interface for triggering workflows
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n adapter for workflow execution via webhook triggers
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill adapter
- `lib/WorkflowEngine/WorkflowResult.php` -- Structured result (approved/rejected/modified/error)
- `lib/Db/Schema.php` -- Schema `hooks` property for configuring event-workflow connections
- `lib/Controller/WorkflowEngineController.php` -- API for managing workflow engine registrations
- `lib/BackgroundJob/HookRetryJob.php` -- Retry failed hook executions
- `lib/Settings/n8n_workflows.openregister.json` -- Pre-configured n8n workflow templates

**Implemented (workflow can modify objects):**
- HookExecutor processes `modified` results, merging enriched data back into objects before save
- Workflows can call OpenRegister API to create/update/delete objects (n8n HTTP nodes)

**Not implemented:**
- Workflow configuration UI in schema settings (no "Workflows" tab with "Add trigger" button)
- Visual workflow execution history/monitoring dashboard in the OpenRegister UI
- Conditional triggers based on property changes (e.g., `changed.status == "in_behandeling"`)
- Scheduled/cron-based workflow triggers (e.g., daily deadline checks)
- Multi-step approval chain workflows (can be built in n8n but no OpenRegister-specific support)
- Approval/rejection UI with notification integration
- "Test trigger" button in the configuration UI

### Standards & References
- BPMN 2.0 (Business Process Model and Notation) -- conceptual model for workflow automation
- CloudEvents 1.0 -- event payload format used for workflow triggers
- n8n workflow automation platform (https://n8n.io/)
- Dutch government process automation requirements (VNG ZGW process standards)
- Nextcloud notification system (`OCP\Notification`)

### Specificity Assessment
- **Specific enough to implement?** Partially -- the backend event-workflow pipeline is well-defined and implemented, but the UI and approval chain requirements need more detail.
- **Missing/ambiguous:**
  - No specification for the workflow configuration UI component structure
  - No specification for how "condition" filters are expressed (same as RBAC conditions? Custom DSL?)
  - No specification for how scheduled workflows interact with Nextcloud's cron system
  - No specification for approval chain state machine (what states/transitions are valid?)
  - No specification for notification templates for approval requests/rejections
- **Open questions:**
  - Should approval chains be first-class OpenRegister entities or purely n8n workflow configurations?
  - How should workflow execution history be stored (OpenRegister database? n8n execution log? Both?)
  - Should the workflow configuration UI be in OpenRegister or delegated to the engine's native UI?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `HookExecutor` executes workflows on object lifecycle events. `HookListener` dispatches events to the executor. `WorkflowEngineInterface` with `N8nAdapter` and `WindmillAdapter` provide engine-agnostic execution. `WorkflowResult` handles structured responses. `WorkflowEngineController` exposes REST API for engine management. Pre-configured n8n workflow templates in `n8n_workflows.openregister.json`.
- **Nextcloud Core Integration**: Background jobs use `TimedJob` and `QueuedJob` for async workflow execution and retry (`HookRetryJob`). Event-driven via `IEventDispatcher`. Workflow engine services registered in the DI container via `IBootstrap::register()`. n8n ExApp integration routes through Nextcloud's `IAppApiService` proxy.
- **Recommendation**: Mark as implemented. The core event-workflow pipeline is functional. UI features (workflow configuration tab, execution history dashboard, approval chain support) are not yet implemented but are not NC-integration blockers.
