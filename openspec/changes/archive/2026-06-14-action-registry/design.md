# Design: Action Registry

## Approach
Introduce the Action entity following the established OpenRegister entity pattern (Entity + Mapper + Controller + Service + Events + Migration). The implementation reuses the existing `HookExecutor`, `WorkflowEngineRegistry`, and `CloudEventFormatter` infrastructure, layering a new `ActionExecutor` and `ActionListener` on top. The Action entity follows the same conventions as Webhook (similar fields: events, filters, retry, mapping reference, statistics tracking) but is purpose-built for workflow automation rather than HTTP delivery.

### Architecture Decisions
1. **Action entity is separate from Webhook**: While similar in structure, actions execute workflows via engine adapters (n8n, Windmill) while webhooks deliver HTTP payloads. Merging them would conflate two distinct concerns.
2. **ActionListener coexists with HookListener**: Inline hooks (Schema::getHooks()) continue to work via HookListener. ActionListener handles Action entities. Inline hooks execute first for backward compatibility.
3. **ActionExecutor wraps HookExecutor patterns**: Rather than duplicating HookExecutor logic, ActionExecutor follows the same patterns (CloudEvent payload building, engine resolution, response processing, failure modes) but reads configuration from Action entities instead of inline hook JSON.
4. **Soft-delete with status lifecycle**: Actions use soft-delete (deleted timestamp) combined with a status field (draft/active/disabled/archived) for full lifecycle management.

## Files Affected

### New Files
- `lib/Db/Action.php` -- Entity class extending `OCP\AppFramework\Db\Entity`, implements `JsonSerializable`. Fields mirror Requirement 1 scenario 2. Uses `MultiTenancyTrait` for owner/application/organisation scoping.
- `lib/Db/ActionMapper.php` -- QBMapper for `oc_openregister_actions`. Methods: `findAll()`, `find()`, `findByUuid()`, `findBySlug()`, `findByEventType()`, `findMatchingActions()` (filters by event_type, schema, register, enabled, status, filter_condition). Uses `MultiTenancyTrait`.
- `lib/Db/ActionLog.php` -- Entity for `oc_openregister_action_logs`. Fields: id, action_id, action_uuid, event_type, object_uuid, schema_id, register_id, engine, workflow_id, status (success/failure/abandoned), duration_ms, request_payload (json), response_payload (json), error_message (text), attempt (integer), created (datetime).
- `lib/Db/ActionLogMapper.php` -- QBMapper for `oc_openregister_action_logs`. Methods: `findByActionId()`, `findByActionUuid()`, `getStatsByActionId()`.
- `lib/Controller/ActionsController.php` -- RESTful controller under `/api/actions`. CRUD: index, show, create, update, patch, destroy. Custom routes: `test` (dry-run), `logs` (execution history), `migrateFromHooks` (hook-to-action migration). Follows same patterns as `WebhooksController`.
- `lib/Service/ActionService.php` -- Business logic layer: `createAction()`, `updateAction()`, `deleteAction()`, `testAction()`, `migrateFromHooks()`, `updateStatistics()`.
- `lib/Service/ActionExecutor.php` -- Orchestrates action execution. Resolves matching actions from `ActionMapper::findMatchingActions()`, sorts by execution_order, builds CloudEvents payload via `CloudEventFormatter`, executes via `WorkflowEngineRegistry`, processes responses (approved/rejected/modified), applies failure modes, creates `ActionLog` entries, updates statistics.
- `lib/Listener/ActionListener.php` -- Implements `IEventListener`. Registered for ALL event types in `Application::registerEventListeners()`. On event dispatch: extracts event type and payload, queries ActionMapper for matching active+enabled actions, delegates to ActionExecutor.
- `lib/BackgroundJob/ActionScheduleJob.php` -- `TimedJob` (runs every 60 seconds). Queries ActionMapper for actions with non-null `schedule` field, evaluates cron expressions against current time, executes matching actions via ActionExecutor.
- `lib/BackgroundJob/ActionRetryJob.php` -- `QueuedJob` for retrying failed action executions. Reads action_id, payload, attempt from job arguments. Applies retry_policy backoff. Re-executes via ActionExecutor.
- `lib/Event/ActionCreatedEvent.php` -- Typed event dispatched after Action entity creation. Method: `getAction(): Action`.
- `lib/Event/ActionUpdatedEvent.php` -- Typed event dispatched after Action entity update.
- `lib/Event/ActionDeletedEvent.php` -- Typed event dispatched after Action entity deletion.
- `lib/Migration/Version1Date20260325000000.php` -- Database migration creating `oc_openregister_actions` and `oc_openregister_action_logs` tables with all columns from Requirement 1.

### Modified Files
- `appinfo/routes.php` -- Add `'Actions' => ['url' => 'api/actions']` to resources array. Add PATCH route, test route (`/api/actions/{id}/test`), logs route (`/api/actions/{id}/logs`), migrate route (`/api/actions/migrate-from-hooks/{schemaId}`).
- `lib/AppInfo/Application.php` -- Register `ActionListener` for all event types in `registerEventListeners()`. Register `ActionScheduleJob` in `registerBackgroundJobs()` (if method exists) or in boot().
- `lib/Service/HookExecutor.php` -- No changes required. ActionExecutor follows same patterns independently.
- `lib/Listener/HookListener.php` -- No changes required. Coexists with ActionListener.

### Existing Infrastructure Reused (No Changes)
- `lib/Service/WorkflowEngineRegistry.php` -- Engine resolution for n8n, Windmill adapters
- `lib/Service/Webhook/CloudEventFormatter.php` -- CloudEvents 1.0 payload building
- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- Engine adapter interface
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n execution
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill execution
- `lib/WorkflowEngine/WorkflowResult.php` -- Execution result processing
- `lib/Db/MultiTenancyTrait.php` -- Tenant scoping for ActionMapper

## Data Model

### oc_openregister_actions
| Column | Type | Nullable | Default | Index |
|--------|------|----------|---------|-------|
| id | integer | no | auto | PK |
| uuid | string(36) | no | generated | UNIQUE |
| name | string(255) | no | - | - |
| slug | string(255) | yes | - | UNIQUE |
| description | text | yes | null | - |
| version | string(20) | yes | '1.0.0' | - |
| status | string(20) | no | 'draft' | INDEX |
| event_type | text | no | - | INDEX |
| engine | string(50) | no | - | - |
| workflow_id | string(255) | no | - | - |
| mode | string(10) | no | 'sync' | - |
| execution_order | integer | no | 0 | - |
| timeout | integer | no | 30 | - |
| on_failure | string(20) | no | 'reject' | - |
| on_timeout | string(20) | no | 'reject' | - |
| on_engine_down | string(20) | no | 'allow' | - |
| filter_condition | text (json) | yes | null | - |
| configuration | text (json) | yes | null | - |
| mapping | integer | yes | null | - |
| schemas | text (json) | yes | null | - |
| registers | text (json) | yes | null | - |
| schedule | string(100) | yes | null | INDEX |
| max_retries | integer | no | 3 | - |
| retry_policy | string(20) | no | 'exponential' | - |
| enabled | boolean | no | true | INDEX |
| owner | string(64) | yes | null | - |
| application | string(64) | yes | null | - |
| organisation | string(64) | yes | null | - |
| last_executed_at | datetime | yes | null | - |
| execution_count | integer | no | 0 | - |
| success_count | integer | no | 0 | - |
| failure_count | integer | no | 0 | - |
| created | datetime | no | now | - |
| updated | datetime | no | now | - |
| deleted | datetime | yes | null | INDEX |

### oc_openregister_action_logs
| Column | Type | Nullable | Default | Index |
|--------|------|----------|---------|-------|
| id | integer | no | auto | PK |
| action_id | integer | no | - | INDEX |
| action_uuid | string(36) | no | - | INDEX |
| event_type | string(255) | no | - | - |
| object_uuid | string(36) | yes | null | INDEX |
| schema_id | integer | yes | null | - |
| register_id | integer | yes | null | - |
| engine | string(50) | no | - | - |
| workflow_id | string(255) | no | - | - |
| status | string(20) | no | - | INDEX |
| duration_ms | integer | yes | null | - |
| request_payload | text (json) | yes | null | - |
| response_payload | text (json) | yes | null | - |
| error_message | text | yes | null | - |
| attempt | integer | no | 1 | - |
| created | datetime | no | now | - |

## Key Design Patterns

### Action Matching Algorithm (ActionMapper::findMatchingActions)
```
1. Query WHERE enabled = true AND status = 'active' AND deleted IS NULL
2. Filter by event_type: exact match OR fnmatch() wildcard match (same as Webhook::matchesEvent())
3. Filter by schemas: empty schemas array = match all; otherwise object's schema UUID must be in the array
4. Filter by registers: empty registers array = match all; otherwise object's register UUID must be in the array
5. Apply filter_condition: use dot-notation key matching against event payload (same as WebhookService::matchesFilters())
6. Sort by execution_order ASC
7. Return matching Action entities
```

### Execution Flow (ActionListener -> ActionExecutor)
```
Event dispatched
  -> ActionListener::handle()
     -> Extract event type string (short class name)
     -> Extract payload (object data, register, schema from event)
     -> ActionMapper::findMatchingActions(eventType, schemaUuid, registerUuid)
     -> For each matching action (sorted by execution_order):
        -> ActionExecutor::execute(action, event, payload)
           -> Build CloudEvents payload via CloudEventFormatter
           -> Apply Mapping transformation if action.mapping is set
           -> Resolve engine adapter via WorkflowEngineRegistry
           -> Execute workflow via adapter
           -> Process WorkflowResult (approved/rejected/modified)
           -> Create ActionLog entry
           -> Update action statistics
           -> On failure: apply on_failure mode (reject/allow/flag/queue)
           -> On pre-mutation rejection: call event->stopPropagation()
        -> If propagation stopped, break loop
```

### Backward Compatibility
- HookListener continues to process inline hooks from Schema::getHooks()
- HookListener is registered BEFORE ActionListener in Application.php
- If HookListener stops propagation (inline hook rejects), ActionListener sees isPropagationStopped() and skips
- Both systems can coexist indefinitely; migration from hooks to actions is optional

## Risks and Mitigations
1. **Performance**: Multiple DB queries per event to find matching actions. Mitigation: index on (status, enabled, deleted, event_type), cache frequently-accessed actions in `RequestScopedCache`.
2. **Event listener ordering**: Nextcloud does not guarantee listener execution order. Mitigation: HookListener and ActionListener check isPropagationStopped() independently; inline hooks take precedence by convention.
3. **Schedule evaluation overhead**: Evaluating cron expressions every 60 seconds for all scheduled actions. Mitigation: cache last execution timestamp, use efficient cron parsing library (dragonmantank/cron-expression, already in Nextcloud core).
