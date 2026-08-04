# Design: workflow-operations

## Architecture Overview

This change adds five operational layers on top of the existing workflow pipeline (HookExecutor + WorkflowEngineInterface + adapters):

```
                          Vue Frontend
                              |
       +----------------------+----------------------+
       |                      |                      |
  SchemaWorkflowTab    WorkflowExecPanel    ApprovalChainPanel
       |                      |                      |
       v                      v                      v
  WorkflowEngine        WorkflowExecution     Approval
  Controller            Controller            Controller
  (existing + testHook) (NEW)                 (NEW)
       |                      |                      |
       v                      v                      v
  HookExecutor <-----> WorkflowExecution     ApprovalChain
  (modified to          Entity/Mapper         ApprovalStep
   persist history)     (NEW)                 Entity/Mapper
       |                                     (NEW)
       v
  ScheduledWorkflowJob (TimedJob) <---> ScheduledWorkflow Entity
  (NEW)                                  (NEW)
```

### Component Relationships

1. **Workflow Execution History**: HookExecutor is modified to persist every execution to a `WorkflowExecution` entity (in addition to existing logging). A new controller exposes this data via REST API. The Vue panel reads it.

2. **Scheduled Workflows**: A new `ScheduledWorkflow` entity stores the schedule configuration (interval, engine, workflowId, register, schema). A `ScheduledWorkflowJob` (Nextcloud TimedJob) runs on the configured interval, resolves the engine adapter, and executes the workflow.

3. **Approval Chains**: An `ApprovalChain` entity defines the steps (ordered roles/groups required). An `ApprovalStep` entity tracks per-object progress through the chain. The controller provides approve/reject endpoints. Schema hooks on `updating` trigger chain advancement.

4. **Test Hook**: A new endpoint on `WorkflowEngineController` accepts hook configuration + sample data, executes the workflow via the adapter, and returns the result without database writes.

5. **Workflow Configuration UI**: A Vue tab on the schema detail page renders the `hooks` JSON property as a manageable list with add/edit/delete forms.

## API Design

### Workflow Execution History -- `/api/workflow-executions/`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/workflow-executions/` | List executions with filters | User |
| GET | `/api/workflow-executions/{id}` | Get single execution detail | User |
| DELETE | `/api/workflow-executions/{id}` | Delete an execution record | Admin |

**GET /api/workflow-executions/ -- Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `objectUuid` | string | Filter by object UUID |
| `schemaId` | int | Filter by schema ID |
| `hookId` | string | Filter by hook ID |
| `status` | string | Filter by result status (approved/rejected/modified/error) |
| `engine` | string | Filter by engine type |
| `since` | string | ISO 8601 date -- only executions after this timestamp |
| `limit` | int | Max results (default 50, max 500) |
| `offset` | int | Pagination offset |

**GET /api/workflow-executions/ -- Response (200):**
```json
{
  "results": [
    {
      "id": 1,
      "uuid": "exec-uuid-1",
      "hookId": "validate-kvk",
      "eventType": "creating",
      "objectUuid": "obj-uuid-123",
      "schemaId": 12,
      "registerId": 5,
      "engine": "n8n",
      "workflowId": "kvk-validator",
      "mode": "sync",
      "status": "approved",
      "durationMs": 45,
      "errors": null,
      "metadata": {},
      "executedAt": "2026-03-24T10:00:00Z"
    }
  ],
  "total": 142,
  "limit": 50,
  "offset": 0
}
```

### Scheduled Workflows -- `/api/scheduled-workflows/`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/scheduled-workflows/` | List scheduled workflows | User |
| POST | `/api/scheduled-workflows/` | Create a scheduled workflow | Admin |
| GET | `/api/scheduled-workflows/{id}` | Get scheduled workflow detail | User |
| PUT | `/api/scheduled-workflows/{id}` | Update scheduled workflow | Admin |
| DELETE | `/api/scheduled-workflows/{id}` | Remove scheduled workflow | Admin |

**POST /api/scheduled-workflows/ -- Request:**
```json
{
  "name": "Termijnbewaking vergunningen",
  "engine": "n8n",
  "workflowId": "termijn-bewaking",
  "registerId": 5,
  "schemaId": 12,
  "interval": 86400,
  "enabled": true,
  "payload": {
    "filter": { "status": "in_behandeling" }
  }
}
```

**POST /api/scheduled-workflows/ -- Response (201):**
```json
{
  "id": 1,
  "uuid": "sched-uuid-1",
  "name": "Termijnbewaking vergunningen",
  "engine": "n8n",
  "workflowId": "termijn-bewaking",
  "registerId": 5,
  "schemaId": 12,
  "interval": 86400,
  "enabled": true,
  "payload": { "filter": { "status": "in_behandeling" } },
  "lastRun": null,
  "nextRun": "2026-03-25T02:00:00Z",
  "lastStatus": null,
  "created": "2026-03-24T10:00:00Z",
  "updated": "2026-03-24T10:00:00Z"
}
```

### Approval Chains -- `/api/approval-chains/`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/approval-chains/` | List approval chains | User |
| POST | `/api/approval-chains/` | Create approval chain | Admin |
| GET | `/api/approval-chains/{id}` | Get chain with steps | User |
| PUT | `/api/approval-chains/{id}` | Update chain | Admin |
| DELETE | `/api/approval-chains/{id}` | Delete chain | Admin |
| GET | `/api/approval-chains/{id}/objects` | List objects in this chain with their progress | User |

### Approval Steps -- `/api/approval-steps/`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/approval-steps/` | List steps (filter by objectUuid, chainId, status) | User |
| POST | `/api/approval-steps/{id}/approve` | Approve a pending step | User (role check) |
| POST | `/api/approval-steps/{id}/reject` | Reject a pending step | User (role check) |

**POST /api/approval-chains/ -- Request:**
```json
{
  "name": "Vergunning goedkeuring",
  "schemaId": 12,
  "statusField": "status",
  "steps": [
    { "order": 1, "role": "teamleider", "statusOnApprove": "wacht_op_afdelingshoofd", "statusOnReject": "afgewezen" },
    { "order": 2, "role": "afdelingshoofd", "statusOnApprove": "goedgekeurd", "statusOnReject": "afgewezen" }
  ]
}
```

**POST /api/approval-steps/{id}/approve -- Request:**
```json
{
  "comment": "Akkoord, dossier is compleet."
}
```

**POST /api/approval-steps/{id}/approve -- Response (200):**
```json
{
  "id": 42,
  "chainId": 1,
  "objectUuid": "obj-uuid-123",
  "stepOrder": 1,
  "role": "teamleider",
  "status": "approved",
  "decidedBy": "admin",
  "comment": "Akkoord, dossier is compleet.",
  "decidedAt": "2026-03-24T11:00:00Z",
  "nextStep": {
    "id": 43,
    "stepOrder": 2,
    "role": "afdelingshoofd",
    "status": "pending"
  }
}
```

### Test Hook -- `/api/engines/{engineId}/test-hook`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| POST | `/api/engines/{engineId}/test-hook` | Execute a workflow with sample data (dry-run) | Admin |

**POST /api/engines/{engineId}/test-hook -- Request:**
```json
{
  "workflowId": "kvk-validator",
  "sampleData": {
    "kvkNumber": "12345678",
    "name": "Test Organisatie B.V."
  },
  "timeout": 10
}
```

**POST /api/engines/{engineId}/test-hook -- Response (200):**
```json
{
  "status": "modified",
  "data": {
    "kvkNumber": "12345678",
    "name": "Test Organisatie B.V.",
    "kvkVerified": true,
    "address": "Keizersgracht 1, Amsterdam"
  },
  "errors": [],
  "metadata": { "executionId": "n8n-exec-789", "durationMs": 234 },
  "dryRun": true
}
```

## Database

### Table: `openregister_workflow_executions`

```sql
CREATE TABLE openregister_workflow_executions (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid          VARCHAR(36)  NOT NULL,
    hook_id       VARCHAR(255) NOT NULL,
    event_type    VARCHAR(50)  NOT NULL,
    object_uuid   VARCHAR(36)  NOT NULL,
    schema_id     BIGINT       NULL,
    register_id   BIGINT       NULL,
    engine        VARCHAR(50)  NOT NULL,
    workflow_id   VARCHAR(255) NOT NULL,
    mode          VARCHAR(10)  NOT NULL DEFAULT 'sync',
    status        VARCHAR(20)  NOT NULL,
    duration_ms   INT          NOT NULL DEFAULT 0,
    errors        TEXT         NULL,
    metadata      TEXT         NULL,
    payload       TEXT         NULL,
    executed_at   DATETIME     NOT NULL,
    INDEX idx_object_uuid (object_uuid),
    INDEX idx_schema_id (schema_id),
    INDEX idx_hook_id (hook_id),
    INDEX idx_status (status),
    INDEX idx_executed_at (executed_at)
);
```

### Table: `openregister_scheduled_workflows`

```sql
CREATE TABLE openregister_scheduled_workflows (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid          VARCHAR(36)  NOT NULL,
    name          VARCHAR(255) NOT NULL,
    engine        VARCHAR(50)  NOT NULL,
    workflow_id   VARCHAR(255) NOT NULL,
    register_id   BIGINT       NULL,
    schema_id     BIGINT       NULL,
    interval_sec  INT          NOT NULL DEFAULT 86400,
    enabled       TINYINT(1)   DEFAULT 1,
    payload       TEXT         NULL,
    last_run      DATETIME     NULL,
    last_status   VARCHAR(20)  NULL,
    created       DATETIME     NOT NULL,
    updated       DATETIME     NOT NULL
);
```

### Table: `openregister_approval_chains`

```sql
CREATE TABLE openregister_approval_chains (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid          VARCHAR(36)  NOT NULL,
    name          VARCHAR(255) NOT NULL,
    schema_id     BIGINT       NOT NULL,
    status_field  VARCHAR(255) NOT NULL DEFAULT 'status',
    steps         TEXT         NOT NULL,
    enabled       TINYINT(1)   DEFAULT 1,
    created       DATETIME     NOT NULL,
    updated       DATETIME     NOT NULL
);
```

### Table: `openregister_approval_steps`

```sql
CREATE TABLE openregister_approval_steps (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid          VARCHAR(36)  NOT NULL,
    chain_id      BIGINT       NOT NULL,
    object_uuid   VARCHAR(36)  NOT NULL,
    step_order    INT          NOT NULL,
    role          VARCHAR(255) NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    decided_by    VARCHAR(255) NULL,
    comment       TEXT         NULL,
    decided_at    DATETIME     NULL,
    created       DATETIME     NOT NULL,
    INDEX idx_chain_object (chain_id, object_uuid),
    INDEX idx_status (status),
    INDEX idx_role (role),
    FOREIGN KEY (chain_id) REFERENCES openregister_approval_chains(id) ON DELETE CASCADE
);
```

## Nextcloud Integration

### HookExecutor Modification

The existing `HookExecutor::logHookExecution()` method currently only logs to the Nextcloud logger. It will be extended to also persist a `WorkflowExecution` entity via the mapper. This is a minor change: inject `WorkflowExecutionMapper` and call `createFromArray()` alongside the existing `$this->logger->info()/error()` calls.

```php
// In HookExecutor::logHookExecution()
$execution = $this->executionMapper->createFromArray([
    'hookId'     => $hookId,
    'eventType'  => $eventType,
    'objectUuid' => $objectUuid,
    'schemaId'   => $object->getSchema(),
    'registerId' => $object->getRegister(),
    'engine'     => $engineName,
    'workflowId' => $workflowId,
    'mode'       => ($hook['mode'] ?? 'sync'),
    'status'     => $responseStatus ?? ($success ? 'approved' : 'error'),
    'durationMs' => $durationMs,
    'errors'     => $error ? json_encode([['message' => $error]]) : null,
    'metadata'   => json_encode($context),
    'payload'    => $payload ? json_encode($payload) : null,
    'executedAt' => new \DateTime(),
]);
```

### Scheduled Workflow TimedJob

`ScheduledWorkflowJob` extends `OCP\BackgroundJob\TimedJob`. On each run:

1. Load all enabled `ScheduledWorkflow` entities from the mapper
2. For each, check if `interval_sec` has elapsed since `last_run`
3. If due: resolve the engine adapter via `WorkflowEngineRegistry`, build a payload with register/schema context, execute via `adapter->executeWorkflow()`
4. Update `last_run` and `last_status` on the entity
5. Log execution to `WorkflowExecution`

The job is registered once in `Application.php` via `$context->registerService()` with a base interval of 60 seconds (the job itself checks per-schedule intervals internally).

### Approval Chain Integration

Approval chains integrate with the existing hook system:

1. When an `ApprovalChain` is created for a schema, the system auto-generates a hook on the `creating` event that initialises `ApprovalStep` records for the new object
2. The `ApprovalController::approve()` and `reject()` methods update the `ApprovalStep` status, then update the object's status field via `ObjectService::saveObject()`
3. Status field updates trigger existing `ObjectUpdatingEvent` hooks, enabling further automation (notifications via n8n)

### Role Checking

Approval step role checks use Nextcloud's `IGroupManager` to verify the current user belongs to the required group. The `role` field in approval chain steps maps to Nextcloud group IDs.

```php
$user = $this->userSession->getUser();
if (!$this->groupManager->isInGroup($user->getUID(), $step->getRole())) {
    return new JSONResponse(['error' => 'You are not authorised for this approval step'], 403);
}
```

### DI Registration

All new services are auto-wired by Nextcloud's DI container. The `ScheduledWorkflowJob` TimedJob is registered in `Application::register()`:

```php
$context->registerService(ScheduledWorkflowJob::class, function ($c) {
    return new ScheduledWorkflowJob(
        $c->get(ITimeFactory::class),
        $c->get(ScheduledWorkflowMapper::class),
        $c->get(WorkflowEngineRegistry::class),
        $c->get(WorkflowExecutionMapper::class),
        $c->get(LoggerInterface::class)
    );
});
```

## File Structure

```
openregister/lib/
  Controller/
    WorkflowExecutionController.php     # NEW -- execution history CRUD
    ScheduledWorkflowController.php     # NEW -- scheduled workflow CRUD
    ApprovalController.php              # NEW -- chain CRUD + approve/reject
    WorkflowEngineController.php        # MODIFIED -- add testHook()
  Db/
    WorkflowExecution.php               # NEW -- Entity
    WorkflowExecutionMapper.php         # NEW -- QBMapper
    ScheduledWorkflow.php               # NEW -- Entity
    ScheduledWorkflowMapper.php         # NEW -- QBMapper
    ApprovalChain.php                   # NEW -- Entity
    ApprovalChainMapper.php             # NEW -- QBMapper
    ApprovalStep.php                    # NEW -- Entity
    ApprovalStepMapper.php              # NEW -- QBMapper
  Service/
    HookExecutor.php                    # MODIFIED -- persist execution history
    ApprovalService.php                 # NEW -- approval chain logic
  BackgroundJob/
    ScheduledWorkflowJob.php            # NEW -- TimedJob
  Migration/
    VersionXXXXDate_CreateWorkflowExecutions.php   # NEW
    VersionXXXXDate_CreateScheduledWorkflows.php    # NEW
    VersionXXXXDate_CreateApprovalTables.php        # NEW

openregister/src/
  views/schemas/
    SchemaWorkflowTab.vue               # NEW -- hook management tab
  components/workflow/
    HookForm.vue                        # NEW -- add/edit hook form
    HookList.vue                        # NEW -- list of configured hooks
    WorkflowExecutionPanel.vue          # NEW -- execution history table
    WorkflowExecutionDetail.vue         # NEW -- single execution detail
    ScheduledWorkflowPanel.vue          # NEW -- scheduled workflow management
    ApprovalChainPanel.vue              # NEW -- approval chain config
    ApprovalStepList.vue                # NEW -- per-object approval progress
    TestHookDialog.vue                  # NEW -- dry-run test modal
```

## Security Considerations

- **Execution history access**: All authenticated users can read execution history (filtered to their accessible registers). Only admins can delete records.
- **Approval role enforcement**: Approval steps verify the current user is a member of the required Nextcloud group via `IGroupManager`. Unauthorised users receive HTTP 403.
- **Scheduled workflow credentials**: Scheduled workflows use the same engine credentials (encrypted via ICrypto) as hook-triggered workflows. No additional credential storage needed.
- **Test hook isolation**: The test-hook endpoint is admin-only and explicitly does NOT persist any data. The response clearly marks `dryRun: true`.
- **Execution payload storage**: Failed execution payloads are stored for debugging. Payloads may contain sensitive object data -- the execution history API respects the same access controls as the object API.
- **Rate limiting**: The execution history table can grow large. A background job should prune records older than a configurable retention period (default 90 days).

## Trade-offs

| Alternative | Why not |
|---|---|
| Store execution history only in Nextcloud log | Logs are not queryable from the UI. Admins need structured, filterable execution history. |
| Use n8n's own execution history | Engine-specific, not accessible from OpenRegister UI. Does not cover Windmill or future engines. |
| Implement approval chains purely in n8n | No OpenRegister-side state tracking. Cannot enforce role-based approval via Nextcloud groups. Cannot query approval status per object. |
| Use Nextcloud's built-in workflow engine (OCA\WorkflowEngine) | Nextcloud's workflow engine handles file/tag operations, not structured data lifecycle events. Not suitable for object-level hooks. |
| Single mega-migration for all tables | Separate migrations are easier to manage and roll back independently. |
| Store approval steps in the object's JSON data | Would pollute domain data with workflow metadata. Separate entity enables clean queries and role enforcement. |
