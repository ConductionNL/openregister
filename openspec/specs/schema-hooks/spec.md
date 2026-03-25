# Schema Hooks Specification

---
status: implemented
---

## Purpose
Enables schema-level configuration of workflow hooks that fire on object lifecycle events. Hooks use CloudEvents 1.0 format and support synchronous (request-response) and asynchronous (fire-and-forget) delivery modes with configurable failure behavior.

## ADDED Requirements

### Requirement: Hook Configuration on Schema
Schemas MUST support a `hooks` JSON property that defines an array of workflow hooks, each bound to a specific lifecycle event.

#### Scenario: Schema stores hook configuration
- GIVEN a Schema entity
- WHEN the `hooks` property is set to a JSON array of hook objects
- THEN each hook object MUST contain `event`, `engine`, `workflowId`, and `mode` as required fields
- AND each hook object MAY contain `order` (default 0), `timeout` (default 30), `onFailure` (default "reject"), `onTimeout` (default "reject"), `onEngineDown` (default "allow"), `filterCondition`, and `enabled` (default true)

#### Scenario: Valid event values
- GIVEN a hook configuration
- WHEN the `event` field is set
- THEN it MUST be one of: `creating`, `updating`, `deleting`, `created`, `updated`, `deleted`, `locked`, `unlocked`, `reverted`

#### Scenario: Schema with multiple hooks on the same event
- GIVEN a schema with three hooks on the `creating` event with order 1, 2, and 3
- WHEN an object is created
- THEN all three hooks fire in order sequence before the save

#### Scenario: Disabled hook is skipped
- GIVEN a hook with `enabled: false`
- WHEN the associated event fires
- THEN the hook MUST NOT execute

### Requirement: CloudEvents Wire Format
All hook deliveries MUST use CloudEvents 1.0 structured content mode with JSON encoding.

#### Scenario: Sync hook CloudEvent payload
- GIVEN a sync hook on the `creating` event for schema "organisation" in register "my-register"
- WHEN the hook fires for an object with UUID "abc-123"
- THEN the payload MUST be a valid CloudEvent with:
  - `specversion` = `"1.0"`
  - `type` = `"nl.openregister.object.creating"`
  - `source` = `"/apps/openregister/registers/{registerId}/schemas/{schemaId}"`
  - `id` = a unique UUID for this event
  - `time` = ISO 8601 timestamp
  - `datacontenttype` = `"application/json"`
  - `subject` = `"object:abc-123"`
  - `data.object` = full object data
  - `data.schema` = schema slug
  - `data.register` = register slug
  - `data.action` = `"creating"`
  - `data.hookMode` = `"sync"`
  - `openregister.expectResponse` = `true`
  - `openregister.hookId` = hook identifier

#### Scenario: Async hook CloudEvent payload
- GIVEN an async hook on the `created` event
- WHEN the hook fires
- THEN `openregister.expectResponse` MUST be `false`
- AND `data.hookMode` MUST be `"async"`
- AND the delivery MUST be fire-and-forget (no response processing)

### Requirement: Sync Hook Response Format
Sync hooks MUST return a structured JSON response that determines save behavior.

#### Scenario: Workflow approves object
- GIVEN a sync hook fires for object creation
- WHEN the workflow returns `{"status": "approved"}`
- THEN the save proceeds normally
- AND the next hook in order executes (if any)

#### Scenario: Workflow rejects object
- GIVEN a sync hook fires with `onFailure: "reject"`
- WHEN the workflow returns `{"status": "rejected", "errors": [{"field": "kvkNumber", "message": "Invalid KvK number", "code": "INVALID_KVK"}]}`
- THEN the save is aborted
- AND the API returns HTTP 422 with the validation errors array
- AND no object is persisted to the database

#### Scenario: Workflow modifies object
- GIVEN a sync hook fires for object creation
- WHEN the workflow returns `{"status": "modified", "data": {"enrichedAddress": "Keizersgracht 1, Amsterdam"}}`
- THEN the modified data is merged into the object before save
- AND subsequent hooks in the chain receive the modified object data

### Requirement: Failure Mode Behavior
Each failure mode MUST produce distinct behavior when a hook fails, times out, or cannot reach the engine.

#### Scenario: Mode "reject"
- GIVEN a sync hook with `onFailure: "reject"`
- WHEN the workflow returns a rejection, times out (if `onTimeout: "reject"`), or the engine is down (if `onEngineDown: "reject"`)
- THEN the save is aborted
- AND the API returns HTTP 422 with error details
- AND no object is persisted

#### Scenario: Mode "allow"
- GIVEN a sync hook with `onTimeout: "allow"`
- WHEN the workflow times out
- THEN the save proceeds normally
- AND the timeout is logged as a warning

#### Scenario: Mode "flag"
- GIVEN a sync hook with `onFailure: "flag"`
- WHEN the workflow returns failure
- THEN the save proceeds
- AND the object metadata field `_validationStatus` is set to `"failed"`
- AND the validation errors are stored in the `_validationErrors` metadata field

#### Scenario: Mode "queue"
- GIVEN a sync hook with `onEngineDown: "queue"`
- WHEN the engine is unreachable
- THEN the save proceeds
- AND a Nextcloud background job is queued to re-run the hook when the engine recovers
- AND the object metadata field `_validationStatus` is set to `"pending"`

### Requirement: Hook Execution Order
When multiple hooks exist for the same event, they MUST execute in ascending `order` value. Hooks with equal order values MAY execute in any order relative to each other.

#### Scenario: Chained sync hooks
- GIVEN three sync hooks on `creating` with order 1, 2, 3
- WHEN an object is created
- THEN hook 1 executes first
- AND only if hook 1 succeeds (approved or modified), hook 2 executes
- AND only if hook 2 succeeds, hook 3 executes
- AND if any hook rejects and its failure mode is "reject", remaining hooks are skipped

#### Scenario: Hook modifies data for next hook in chain
- GIVEN hook 1 (order=1) returns `{"status": "modified", "data": {"normalized": true}}`
- AND hook 2 (order=2) is configured on the same event
- WHEN hook 2 fires
- THEN hook 2 receives the object data including `{"normalized": true}`

### Requirement: Stoppable Events
The `ObjectCreatingEvent`, `ObjectUpdatingEvent`, and `ObjectDeletingEvent` classes MUST implement PSR-14's `StoppableEventInterface`.

#### Scenario: Event propagation stopped by hook rejection
- GIVEN a sync hook rejects an object creation
- WHEN the HookExecutor calls `stopPropagation()` on the event
- THEN the mapper (MagicMapper for magic-table storage, ObjectEntityMapper for blob storage) checks `isPropagationStopped()` after dispatching the event
- AND throws a `HookStoppedException` containing the validation errors
- AND the controller catches the exception and returns HTTP 422 with the errors array
- AND no object is persisted to the database

#### Scenario: Event propagation not stopped
- GIVEN all sync hooks approve the object
- WHEN the mapper checks `isPropagationStopped()`
- THEN it returns `false`
- AND the database write proceeds normally

#### Scenario: Hook returns modified data
- GIVEN a sync hook returns `{"status": "modified", "data": {...}}`
- WHEN the mapper processes the event after dispatch
- THEN the modified data from `getModifiedData()` is merged into the object before save
- AND the enriched object is persisted to the database

### Requirement: Hook Logging
All hook executions MUST be logged for debugging and audit purposes.

#### Scenario: Successful sync hook logged
- GIVEN a sync hook executes successfully
- THEN a log entry is created with: hook ID, event type, object UUID, engine name, workflow ID, response status, execution duration in milliseconds

#### Scenario: Failed sync hook logged
- GIVEN a sync hook fails (rejection, timeout, or engine down)
- THEN a log entry is created with the above fields PLUS: error details, failure mode applied, full request payload, full response body (if any)

#### Scenario: Async hook logged
- GIVEN an async hook fires
- THEN a log entry is created with: hook ID, event type, object UUID, engine name, workflow ID, delivery status (sent/failed)

### Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/Db/Schema.php` -- Schema entity supports `hooks` JSON property storing hook configuration arrays
- `lib/Service/HookExecutor.php` -- Main hook execution service:
  - Processes sync/async hooks with CloudEvents 1.0 payload format
  - Supports ordered execution (ascending `order` value)
  - Handles `WorkflowResult` responses (approved, rejected, modified, error)
  - Applies failure mode behavior (reject, allow, flag, queue)
  - Integrates with `WorkflowEngineRegistry` to resolve engine adapters per hook
- `lib/Listener/HookListener.php` -- PSR-14 event listener that delegates to HookExecutor on object lifecycle events
- `lib/Event/ObjectCreatingEvent.php`, `ObjectUpdatingEvent.php`, `ObjectDeletingEvent.php` -- All implement `StoppableEventInterface` for hook-based rejection
- `lib/Exception/HookStoppedException.php` -- Exception with validation errors for rejected saves (returns HTTP 422)
- `lib/Service/Webhook/CloudEventFormatter.php` -- CloudEvents 1.0 structured content mode formatting
- `lib/BackgroundJob/HookRetryJob.php` -- Background job for "queue" failure mode (retry when engine recovers)
- `lib/Db/MagicMapper.php` -- Checks `isPropagationStopped()` after event dispatch

**Valid event values supported:** `creating`, `updating`, `deleting`, `created`, `updated`, `deleted` (plus `locked`, `unlocked`, `reverted` per spec)

**What is NOT yet implemented:**
- `filterCondition` on hooks (conditional hook execution based on object data)
- Comprehensive hook execution logging with duration metrics (basic logging exists)

### Standards & References
- CloudEvents 1.0 Specification (https://cloudevents.io/) -- structured content mode with JSON encoding
- PSR-14 Event Dispatcher (https://www.php-fig.org/psr/psr-14/) -- `StoppableEventInterface` for sync hooks
- HTTP 422 Unprocessable Entity (RFC 4918) -- for hook rejections

### Specificity Assessment
- **Specific enough to implement?** Yes -- this spec is very detailed and the implementation closely matches the scenarios.
- **Missing/ambiguous:**
  - The `filterCondition` field is mentioned but not specified (what expression language? Same as RBAC conditions?)
  - No specification for hook execution timeout behavior per-engine vs per-hook
  - No specification for hook execution metrics/monitoring dashboard
- **Open questions:**
  - Should hook execution logs be stored in the database or only in Nextcloud's log file?
  - How should the `reverted` event interact with content versioning?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `HookExecutor` processes sync/async hooks with CloudEvents 1.0 payloads. `HookListener` is a PSR-14 event listener. Stoppable events (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`) implement `StoppableEventInterface`. `HookRetryJob` handles "queue" failure mode. `CloudEventFormatter` formats payloads.
- **Nextcloud Core Integration**: Uses `IEventDispatcher` for dispatching typed events extending the `OCP\EventDispatcher\Event` base class. `HookListener` registered via `IBootstrap::register()`. Background retry jobs use Nextcloud's `QueuedJob` (via `HookRetryJob`). The stoppable event pattern follows PSR-14 which aligns with Nextcloud's event dispatcher implementation.
- **Recommendation**: Mark as implemented. The hook system deeply integrates with NC's event dispatcher. Consider adding `filterCondition` support and comprehensive execution logging with duration metrics as future enhancements.
