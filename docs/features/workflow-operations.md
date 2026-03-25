# Workflow Operations

## Standards

- GEMMA Procesautomatiseringscomponent
- CloudEvents (CNCF)

## Overview

Workflow Operations extends OpenRegister with workflow execution history tracking, scheduled workflows with cron-based evaluation, and multi-step role-based approval chains. This feature provides the backend infrastructure and Vue UI for managing automated workflows triggered by object lifecycle events (hooks), monitoring their execution, and enforcing approval gates.

## Key Capabilities

### WorkflowExecution Logging

Tracks every hook/workflow execution with status, timing, input/output payloads, and error details. The `WorkflowExecutionController` provides a read-only API for querying execution history. Entities are stored via `WorkflowExecutionMapper` in the database.

### ScheduledWorkflowJob (60-second cron evaluation)

The `ScheduledWorkflowJob` background job evaluates scheduled workflows every 60 seconds. Scheduled workflows are configured via `ScheduledWorkflowController` and persisted through `ScheduledWorkflowMapper`. Each scheduled workflow defines a cron expression, target hook, and payload template.

### ApprovalService (chain init, approve/reject with IGroupManager)

The `ApprovalService` manages multi-step approval chains where each step requires approval from members of a specific Nextcloud group (via `IGroupManager`). The `ApprovalController` exposes endpoints for initiating chains, approving or rejecting steps, and querying chain status. Entities: `ApprovalChain` and `ApprovalChainMapper`.

### ExecutionHistoryCleanupJob (90-day retention)

The `ExecutionHistoryCleanupJob` background job purges workflow execution records older than 90 days to prevent unbounded database growth.

### testHook Dry-Run Endpoint

The webhooks controller includes a test endpoint (`POST /api/webhooks/{id}/test`) that performs a dry-run execution of a webhook/hook configuration, returning the result without persisting side effects.

## Route Status

**Important:** The workflow execution, scheduled workflow, and approval chain controllers exist in `lib/Controller/` but their routes are **not yet registered** in `appinfo/routes.php`. The controllers are implemented and ready but currently inaccessible via API. The webhook routes (12 routes under `/api/webhooks`) and workflow engine routes (7 routes under `/api/engines`) are registered and functional.

Existing registered workflow-related routes:

| Verb   | URL                                    | Controller            |
|--------|----------------------------------------|-----------------------|
| GET    | /api/webhooks                          | webhooks#index        |
| GET    | /api/webhooks/{id}                     | webhooks#show         |
| POST   | /api/webhooks                          | webhooks#create       |
| PUT    | /api/webhooks/{id}                     | webhooks#update       |
| DELETE | /api/webhooks/{id}                     | webhooks#destroy      |
| POST   | /api/webhooks/{id}/test                | webhooks#test         |
| GET    | /api/webhooks/events                   | webhooks#events       |
| GET    | /api/webhooks/{id}/logs                | webhooks#logs         |
| GET    | /api/webhooks/{id}/logs/stats          | webhooks#logStats     |
| GET    | /api/webhooks/logs                     | webhooks#allLogs      |
| POST   | /api/webhooks/logs/{logId}/retry       | webhooks#retry        |
| GET    | /api/engines/available                 | workflowEngine#available |
| GET    | /api/engines                           | workflowEngine#index  |
| POST   | /api/engines                           | workflowEngine#create |
| GET    | /api/engines/{id}                      | workflowEngine#show   |
| PUT    | /api/engines/{id}                      | workflowEngine#update |
| DELETE | /api/engines/{id}                      | workflowEngine#destroy |
| POST   | /api/engines/{id}/health               | workflowEngine#health |

## Vue Components (9)

| Component                  | Path                                              |
|---------------------------|---------------------------------------------------|
| SchemaWorkflowTab         | src/views/schemas/SchemaWorkflowTab.vue           |
| HookList                  | src/components/workflow/HookList.vue              |
| HookForm                  | src/components/workflow/HookForm.vue              |
| TestHookDialog            | src/components/workflow/TestHookDialog.vue         |
| ApprovalChainPanel        | src/components/workflow/ApprovalChainPanel.vue     |
| ApprovalStepList          | src/components/workflow/ApprovalStepList.vue       |
| ScheduledWorkflowPanel    | src/components/workflow/ScheduledWorkflowPanel.vue |
| WorkflowExecutionDetail   | src/components/workflow/WorkflowExecutionDetail.vue|
| WorkflowExecutionPanel    | src/components/workflow/WorkflowExecutionPanel.vue |

## Backend Components

| Component                     | Path                                              |
|------------------------------|---------------------------------------------------|
| WorkflowExecution entity     | lib/Db/WorkflowExecution.php                      |
| WorkflowExecutionMapper      | lib/Db/WorkflowExecutionMapper.php                |
| WorkflowExecutionController  | lib/Controller/WorkflowExecutionController.php    |
| ScheduledWorkflow entity     | lib/Db/ScheduledWorkflow.php                      |
| ScheduledWorkflowMapper      | lib/Db/ScheduledWorkflowMapper.php                |
| ScheduledWorkflowController  | lib/Controller/ScheduledWorkflowController.php    |
| ScheduledWorkflowJob         | lib/BackgroundJob/ScheduledWorkflowJob.php        |
| ApprovalChain entity         | lib/Db/ApprovalChain.php                          |
| ApprovalChainMapper          | lib/Db/ApprovalChainMapper.php                    |
| ApprovalController           | lib/Controller/ApprovalController.php             |
| ApprovalService              | lib/Service/ApprovalService.php                   |
| HookExecutor                 | lib/Service/HookExecutor.php                      |
| ExecutionHistoryCleanupJob   | lib/BackgroundJob/ExecutionHistoryCleanupJob.php  |
