# workflow-integration Specification

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
