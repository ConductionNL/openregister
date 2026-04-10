---
status: implemented
---
# Schema Hooks


# Schema Hooks
## Purpose
Schema hooks enable per-schema configuration of workflow callbacks that fire on object lifecycle events, allowing external systems to validate, enrich, transform, or reject data before or after persistence. Hooks use CloudEvents 1.0 structured content mode for payloads, support synchronous (request-response) and asynchronous (fire-and-forget) delivery modes, and provide configurable failure behavior (reject, allow, flag, queue) so administrators can balance data integrity against availability. The hook system is engine-agnostic through the `WorkflowEngineInterface` abstraction, currently supporting n8n and Windmill adapters, and integrates deeply with Nextcloud's PSR-14 event dispatcher via `StoppableEventInterface` for pre-mutation rejection.

## Requirements

### Requirement: Hook Configuration on Schema
Schemas MUST support a `hooks` JSON property that defines an array of workflow hook objects, each bound to a specific lifecycle event. The `hooks` property is stored as a JSON column on the `oc_openregister_schemas` table and accessed via `Schema::getHooks()` / `Schema::setHooks()`.

#### Scenario: Schema stores hook configuration
- **GIVEN** a Schema entity with the `hooks` JSON property
- **WHEN** the `hooks` property is set to a JSON array of hook objects
- **THEN** each hook object MUST contain `event`, `engine`, `workflowId`, and `mode` as required fields
- **AND** each hook object MAY contain `id` (unique identifier within the schema), `order` (default 0), `timeout` (default 30 seconds), `onFailure` (default `"reject"`), `onTimeout` (default `"reject"`), `onEngineDown` (default `"allow"`), `filterCondition` (object with key-value pairs), and `enabled` (default `true`)

#### Scenario: Valid event values
- **GIVEN** a hook configuration being set on a schema
- **WHEN** the `event` field is set
- **THEN** it MUST be one of: `creating`, `updating`, `deleting`, `created`, `updated`, `deleted`, `locked`, `unlocked`, `reverted`
- **AND** `HookExecutor::resolveEventType()` MUST map event class instances to these string values (e.g., `ObjectCreatingEvent` maps to `creating`, `ObjectUpdatedEvent` maps to `updated`)

#### Scenario: Schema with multiple hooks on the same event
- **GIVEN** a schema with three hooks on the `creating` event with order 1, 2, and 3
- **WHEN** an object is created
- **THEN** `HookExecutor::loadHooks()` MUST filter hooks by event type and enabled status, sort by ascending `order` value, and execute all three hooks sequentially before the save

#### Scenario: Disabled hook is skipped
- **GIVEN** a hook with `enabled: false`
- **WHEN** the associated event fires
- **THEN** `HookExecutor::loadHooks()` MUST filter out the disabled hook and it MUST NOT execute

#### Scenario: Hook configuration persists across schema updates
- **GIVEN** a schema with 3 configured hooks
- **WHEN** the schema title or properties are updated without modifying the `hooks` field
- **THEN** the hooks configuration MUST remain intact in the database
- **AND** all hooks MUST continue to fire on subsequent object operations

### Requirement: Hook Lifecycle Events
The hook system MUST support both pre-mutation events (which can block or modify the operation) and post-mutation events (which notify after persistence is complete). Pre-mutation hooks fire BEFORE database writes; post-mutation hooks fire AFTER successful persistence.

#### Scenario: Pre-mutation hook fires before database write
- **GIVEN** a sync hook configured on the `creating` event
- **WHEN** a new object is created via `MagicMapper::insertObjectEntity()`
- **THEN** the `ObjectCreatingEvent` MUST be dispatched via `IEventDispatcher::dispatchTyped()` BEFORE the database INSERT
- **AND** `HookListener::handle()` MUST delegate to `HookExecutor::executeHooks()` with the event and resolved schema
- **AND** only if `isPropagationStopped()` returns `false` SHALL the database write proceed

#### Scenario: Post-mutation hook fires after successful persistence
- **GIVEN** an async hook configured on the `created` event
- **WHEN** an object is successfully inserted into the database
- **THEN** `MagicMapper::insertObjectEntity()` MUST dispatch an `ObjectCreatedEvent` AFTER the database INSERT completes
- **AND** `HookListener` MUST process the event and `HookExecutor` MUST fire the async hook as fire-and-forget
- **AND** failure of the post-mutation hook MUST NOT roll back the already-persisted object

#### Scenario: Update lifecycle dispatches both pre and post events
- **GIVEN** a schema with a sync hook on `updating` and an async hook on `updated`
- **WHEN** an object is updated via `MagicMapper::updateObjectEntity()`
- **THEN** `ObjectUpdatingEvent` MUST fire first with both `$newObject` and `$oldObject`
- **AND** if the updating hook approves, the database UPDATE proceeds
- **AND** after successful UPDATE, `ObjectUpdatedEvent` MUST fire and the async hook executes

#### Scenario: Delete lifecycle supports hook rejection
- **GIVEN** a sync hook on the `deleting` event with `onFailure: "reject"`
- **WHEN** an object deletion is attempted
- **THEN** `ObjectDeletingEvent` MUST be dispatched before the DELETE
- **AND** if the hook rejects, `isPropagationStopped()` returns `true` and `MagicMapper::deleteObjectEntity()` throws `HookStoppedException`
- **AND** the object MUST remain in the database

#### Scenario: Computed fields are evaluated before hooks
- **GIVEN** a schema with a save-time computed field `volledigeNaam` and a sync hook on `creating`
- **WHEN** an object is created
- **THEN** `ComputedFieldHandler::evaluateComputedFields()` MUST run in the SaveObject pipeline BEFORE `HookExecutor` processes the `creating` event
- **AND** the CloudEvent payload sent to the workflow MUST include the computed `volledigeNaam` value

### Requirement: CloudEvents Wire Format
All hook deliveries MUST use CloudEvents 1.0 structured content mode with JSON encoding. The `CloudEventFormatter::formatAsCloudEvent()` method MUST produce the canonical payload structure, and `HookExecutor::buildCloudEventPayload()` MUST add hook-specific extension attributes.

#### Scenario: Sync hook CloudEvent payload
- **GIVEN** a sync hook on the `creating` event for schema `organisation` in register `my-register`
- **WHEN** the hook fires for an object with UUID `abc-123`
- **THEN** the payload MUST be a valid CloudEvent with:
  - `specversion` = `"1.0"`
  - `type` = `"nl.openregister.object.creating"`
  - `source` = `"/apps/openregister/registers/{registerId}/schemas/{schemaId}"`
  - `id` = a unique UUID v4 generated via `Symfony\Component\Uid\Uuid::v4()`
  - `time` = ISO 8601 timestamp
  - `datacontenttype` = `"application/json"`
  - `subject` = `"object:abc-123"`
  - `data.object` = full object data (including computed field values)
  - `data.schema` = schema slug (or title if slug is null)
  - `data.register` = register ID
  - `data.action` = `"creating"`
  - `data.hookMode` = `"sync"`
  - `openregister.hookId` = hook identifier from configuration
  - `openregister.expectResponse` = `true`
  - `openregister.app` = `"openregister"`
  - `openregister.version` = app version string

#### Scenario: Async hook CloudEvent payload
- **GIVEN** an async hook on the `created` event
- **WHEN** the hook fires
- **THEN** `openregister.expectResponse` MUST be `false`
- **AND** `data.hookMode` MUST be `"async"`
- **AND** the delivery MUST be fire-and-forget (no response processing by `HookExecutor`)

#### Scenario: Retry hook CloudEvent payload
- **GIVEN** a hook is being retried via `HookRetryJob`
- **WHEN** the retry job builds its payload
- **THEN** `CloudEventFormatter::formatAsCloudEvent()` MUST produce a payload with `type` = `"nl.openregister.object.hook-retry"` and `source` = `"/apps/openregister/schemas/{schemaId}"`
- **AND** `data.action` MUST be `"retry"`

### Requirement: Sync Hook Response Format
Sync hooks MUST return a structured JSON response (parsed into a `WorkflowResult` value object) that determines save behavior. The `WorkflowResult` class supports four statuses: `approved`, `rejected`, `modified`, and `error`.

#### Scenario: Workflow approves object
- **GIVEN** a sync hook fires for object creation
- **WHEN** the workflow returns `{"status": "approved"}`
- **THEN** `WorkflowResult::isApproved()` returns `true`
- **AND** `HookExecutor::processWorkflowResult()` logs success and the save proceeds normally
- **AND** the next hook in order executes (if any)

#### Scenario: Workflow rejects object
- **GIVEN** a sync hook fires with `onFailure: "reject"`
- **WHEN** the workflow returns `{"status": "rejected", "errors": [{"field": "kvkNumber", "message": "Invalid KvK number", "code": "INVALID_KVK"}]}`
- **THEN** `WorkflowResult::isRejected()` returns `true`
- **AND** `HookExecutor::applyFailureMode()` calls `stopEvent()` which invokes `$event->stopPropagation()` and `$event->setErrors()`
- **AND** `MagicMapper` checks `isPropagationStopped()` and throws `HookStoppedException`
- **AND** the controller returns HTTP 422 with the validation errors array
- **AND** no object is persisted to the database

#### Scenario: Workflow modifies object
- **GIVEN** a sync hook fires for object creation
- **WHEN** the workflow returns `{"status": "modified", "data": {"enrichedAddress": "Keizersgracht 1, Amsterdam"}}`
- **THEN** `WorkflowResult::isModified()` returns `true` and `getData()` returns the modified data
- **AND** `HookExecutor::setModifiedDataOnEvent()` calls `$event->setModifiedData(data)` on the appropriate event class
- **AND** `MagicMapper` merges `$event->getModifiedData()` into the object via `array_merge($objectData, $modifiedData)` before persistence
- **AND** subsequent hooks in the chain receive the modified object data

#### Scenario: Workflow returns error status
- **GIVEN** a sync hook fires
- **WHEN** the workflow returns `{"status": "error", "errors": [{"message": "Internal workflow failure"}]}`
- **THEN** `WorkflowResult::isError()` returns `true`
- **AND** the `onFailure` mode from the hook configuration is applied (default: `"reject"`)

### Requirement: Hook Execution Order
When multiple hooks exist for the same event, they MUST execute in ascending `order` value. `HookExecutor::loadHooks()` MUST sort filtered hooks using `usort()` comparing `$hook['order'] ?? 0`. Hooks with equal order values MAY execute in any order relative to each other.

#### Scenario: Chained sync hooks execute in priority order
- **GIVEN** three sync hooks on `creating` with order 1, 2, 3
- **WHEN** an object is created
- **THEN** `HookExecutor::executeHooks()` MUST iterate the sorted array and execute hook 1 first
- **AND** only if hook 1 succeeds (approved or modified), hook 2 executes
- **AND** only if hook 2 succeeds, hook 3 executes
- **AND** if any hook rejects and its failure mode is `"reject"`, `isEventStopped()` returns `true` and remaining hooks are skipped via the `break` in the foreach loop

#### Scenario: Hook modifies data for next hook in chain
- **GIVEN** hook 1 (order=1) returns `{"status": "modified", "data": {"normalized": true}}`
- **AND** hook 2 (order=2) is configured on the same event
- **WHEN** hook 2 fires
- **THEN** `HookExecutor::buildCloudEventPayload()` reads the object data from `$object->getObject()` which includes the modified data from hook 1
- **AND** hook 2 receives the object data including `{"normalized": true}` in the CloudEvent payload

#### Scenario: Default order for hooks without explicit order
- **GIVEN** two hooks on `creating`, one with no `order` field and one with `order: 5`
- **WHEN** the hooks are loaded and sorted
- **THEN** the hook without an `order` field MUST default to `0` and execute BEFORE the hook with `order: 5`

#### Scenario: Mixed sync and async hooks on same event
- **GIVEN** a sync hook (order=1) and an async hook (order=2) on the `creating` event
- **WHEN** an object is created
- **THEN** the sync hook MUST execute first and its response MUST be processed
- **AND** if the sync hook stops propagation, the async hook MUST be skipped
- **AND** if the sync hook succeeds, the async hook fires as fire-and-forget via `executeAsyncHook()`

### Requirement: Failure Mode Behavior
Each failure mode MUST produce distinct behavior when a hook fails, times out, or cannot reach the engine. `HookExecutor::applyFailureMode()` implements a switch statement over four modes: `reject`, `allow`, `flag`, and `queue`. The `determineFailureMode()` method maps exception messages to the appropriate hook configuration key (`onFailure`, `onTimeout`, or `onEngineDown`).

#### Scenario: Mode "reject" blocks the operation
- **GIVEN** a sync hook with `onFailure: "reject"`
- **WHEN** the workflow returns a rejection, times out (if `onTimeout: "reject"`), or the engine is down (if `onEngineDown: "reject"`)
- **THEN** `applyFailureMode()` calls `stopEvent()` which invokes `$event->stopPropagation()` and `$event->setErrors()`
- **AND** the save is aborted and the API returns HTTP 422 with error details
- **AND** no object is persisted
- **AND** the failure is logged at ERROR level via `$this->logger->error()`

#### Scenario: Mode "allow" permits the operation despite failure
- **GIVEN** a sync hook with `onTimeout: "allow"`
- **WHEN** the workflow times out (exception message contains "timeout" or "timed out", detected by `determineFailureMode()`)
- **THEN** `applyFailureMode()` logs the timeout as a WARNING via `$this->logger->warning()`
- **AND** the save proceeds normally without any object modification
- **AND** subsequent hooks in the chain continue to execute

#### Scenario: Mode "flag" saves with validation metadata
- **GIVEN** a sync hook with `onFailure: "flag"`
- **WHEN** the workflow returns failure
- **THEN** `applyFailureMode()` calls `setValidationMetadata()` which sets `_validationStatus` to `"failed"` on the object data
- **AND** the validation errors are stored in the `_validationErrors` metadata field
- **AND** the save proceeds with the flagged object
- **AND** the failure is logged at WARNING level

#### Scenario: Mode "queue" defers for background retry
- **GIVEN** a sync hook with `onEngineDown: "queue"`
- **WHEN** the engine is unreachable (exception message contains "connection", "unreachable", or "refused", detected by `determineFailureMode()`)
- **THEN** `applyFailureMode()` calls `setValidationMetadata()` setting `_validationStatus` to `"pending"`
- **AND** `scheduleRetryJob()` adds a `HookRetryJob` to `IJobList` with the object ID, schema ID, and hook configuration
- **AND** the save proceeds with the pending-status object
- **AND** the queued state is logged at WARNING level

#### Scenario: Unknown failure mode defaults to reject
- **GIVEN** a hook with an invalid `onFailure` value (e.g., `"invalid"`)
- **WHEN** `applyFailureMode()` processes the failure
- **THEN** the `default` case in the switch MUST call `stopEvent()` to reject the operation
- **AND** an ERROR log MUST indicate the unknown failure mode with a fallback to reject

### Requirement: Filter Condition for Conditional Hook Execution
Hooks MAY define a `filterCondition` object containing key-value pairs that are evaluated against the object data. If the condition does not match, the hook MUST be skipped. `HookExecutor::evaluateFilterCondition()` implements simple dot-notation equality checks.

#### Scenario: Hook skipped when filter condition does not match
- **GIVEN** a hook with `filterCondition: {"status": "submitted"}`
- **AND** an object being created with `{"status": "draft"}`
- **WHEN** `evaluateFilterCondition()` checks each condition key
- **THEN** `$objectData['status']` (`"draft"`) does NOT equal `"submitted"`
- **AND** the hook MUST be skipped with a debug log message

#### Scenario: Hook executes when all filter conditions match
- **GIVEN** a hook with `filterCondition: {"status": "submitted", "type": "vergunning"}`
- **AND** an object with `{"status": "submitted", "type": "vergunning"}`
- **WHEN** `evaluateFilterCondition()` checks all condition keys
- **THEN** all conditions match and the hook MUST execute

#### Scenario: Hook with no filter condition always executes
- **GIVEN** a hook with no `filterCondition` field (or `filterCondition: null`)
- **WHEN** `evaluateFilterCondition()` is called
- **THEN** it MUST return `true` and the hook MUST execute regardless of object data

#### Scenario: Hook with empty filter condition object always executes
- **GIVEN** a hook with `filterCondition: {}`
- **WHEN** `evaluateFilterCondition()` checks the condition
- **THEN** the empty array condition MUST return `true` and the hook MUST execute

### Requirement: Stoppable Events for Hook-Based Rejection
The `ObjectCreatingEvent`, `ObjectUpdatingEvent`, and `ObjectDeletingEvent` classes MUST implement PSR-14's `StoppableEventInterface`. Each event class MUST maintain `propagationStopped` (bool), `errors` (array), and `modifiedData` (array) state that hooks can set via the event's public methods.

#### Scenario: Event propagation stopped by hook rejection
- **GIVEN** a sync hook rejects an object creation
- **WHEN** `HookExecutor::stopEvent()` calls `$event->stopPropagation()` and `$event->setErrors(errors)`
- **THEN** `MagicMapper::insertObjectEntity()` checks `$creatingEvent->isPropagationStopped()` which returns `true`
- **AND** throws a `HookStoppedException` with the errors from `$event->getErrors()`
- **AND** the controller catches the exception and returns HTTP 422 with the errors array
- **AND** no object is persisted to the database

#### Scenario: Event propagation not stopped
- **GIVEN** all sync hooks approve the object
- **WHEN** `MagicMapper` checks `$creatingEvent->isPropagationStopped()`
- **THEN** it returns `false`
- **AND** the database write proceeds normally

#### Scenario: Modified data merged into object before persistence
- **GIVEN** a sync hook returns `{"status": "modified", "data": {"enriched": true}}`
- **WHEN** `HookExecutor::setModifiedDataOnEvent()` calls `$event->setModifiedData(data)`
- **AND** `MagicMapper` processes the event after dispatch
- **THEN** `$event->getModifiedData()` returns the hook's data
- **AND** `MagicMapper` calls `array_merge($objectData, $modifiedData)` and sets the result on the entity
- **AND** the enriched object is persisted to the database

#### Scenario: Multiple hooks accumulate modified data
- **GIVEN** hook 1 modifies `{"fieldA": "value1"}` and hook 2 modifies `{"fieldB": "value2"}`
- **WHEN** both hooks execute on the same `creating` event
- **THEN** `setModifiedData` is called for each hook individually
- **AND** the final persisted object MUST contain both `fieldA` and `fieldB` with their respective values

### Requirement: Engine-Agnostic Workflow Execution
Hook execution MUST be engine-agnostic via the `WorkflowEngineInterface` abstraction. `HookExecutor` resolves the engine adapter through `WorkflowEngineRegistry::getEnginesByType()` and `resolveAdapter()`, then calls `adapter->executeWorkflow()` with the CloudEvent payload and timeout.

#### Scenario: n8n engine adapter executes workflow
- **GIVEN** a hook with `engine: "n8n"` and `workflowId: "wf-validation-123"`
- **WHEN** `HookExecutor::executeSingleHook()` resolves the engine
- **THEN** `WorkflowEngineRegistry::getEnginesByType("n8n")` MUST return registered n8n engine entities
- **AND** `resolveAdapter()` MUST return an `N8nAdapter` instance
- **AND** `N8nAdapter::executeWorkflow()` MUST be called with the workflow ID, CloudEvent payload, and timeout
- **AND** the returned `WorkflowResult` MUST be processed by `processWorkflowResult()`

#### Scenario: Windmill engine adapter executes workflow
- **GIVEN** a hook with `engine: "windmill"` and `workflowId: "script-456"`
- **WHEN** `HookExecutor` resolves the engine
- **THEN** `WindmillAdapter` MUST be used and `executeWorkflow()` called with identical interface contract

#### Scenario: No engine found for type
- **GIVEN** a hook with `engine: "unknown_engine"`
- **WHEN** `WorkflowEngineRegistry::getEnginesByType("unknown_engine")` returns an empty array
- **THEN** `HookExecutor` MUST apply the `onEngineDown` failure mode (default `"allow"`)
- **AND** MUST log the failure with error `"No engine found for type 'unknown_engine'"`

#### Scenario: Engine health check before execution
- **GIVEN** a registered engine with `healthCheck()` method
- **WHEN** the engine becomes unreachable and `executeWorkflow()` throws a connection exception
- **THEN** `HookExecutor::determineFailureMode()` detects `"connection"` or `"refused"` in the exception message
- **AND** applies the `onEngineDown` failure mode from the hook configuration

### Requirement: Async Hook Execution (Fire-and-Forget)
Hooks with `mode: "async"` MUST be executed as fire-and-forget via `HookExecutor::executeAsyncHook()`. The adapter's `executeWorkflow()` is called, but the response is only used for logging purposes -- it does not affect the save operation.

#### Scenario: Async hook succeeds
- **GIVEN** an async hook on the `created` event
- **WHEN** `executeAsyncHook()` calls `adapter->executeWorkflow()` and it succeeds
- **THEN** a log entry MUST be created with `deliveryStatus: "delivered"`
- **AND** the save operation MUST NOT be affected (it already completed for post-mutation events)

#### Scenario: Async hook fails without blocking
- **GIVEN** an async hook on the `creating` event
- **WHEN** `executeAsyncHook()` catches an exception from `adapter->executeWorkflow()`
- **THEN** a log entry MUST be created with `deliveryStatus: "failed"` and the error message
- **AND** the save operation MUST proceed normally because async hooks do not stop propagation

#### Scenario: Async hook on post-mutation event
- **GIVEN** an async hook configured on the `updated` event with a notification workflow
- **WHEN** an object is successfully updated
- **THEN** the async hook fires after persistence and triggers the notification workflow
- **AND** if the notification workflow fails, the updated object remains unchanged in the database

### Requirement: Hook Retry via Background Job
When a hook fails with `onEngineDown: "queue"`, `HookExecutor::scheduleRetryJob()` MUST add a `HookRetryJob` (extending Nextcloud's `QueuedJob`) to `IJobList`. The retry job re-executes the hook with exponential backoff up to `MAX_RETRIES` (5 attempts).

#### Scenario: Failed hook is queued for retry
- **GIVEN** a sync hook with `onEngineDown: "queue"` fails because n8n is unreachable
- **WHEN** `scheduleRetryJob()` is called
- **THEN** `$this->jobList->add(HookRetryJob::class, ...)` MUST be called with arguments containing `objectId`, `schemaId`, and the full `hook` configuration array
- **AND** the object's `_validationStatus` MUST be set to `"pending"`

#### Scenario: Successful retry updates object validation status
- **GIVEN** `HookRetryJob::run()` retries a hook on attempt 3 and the workflow returns `approved`
- **WHEN** the retry succeeds
- **THEN** the object's `_validationStatus` MUST be set to `"passed"`
- **AND** `_validationErrors` MUST be removed from the object data via `unset($objectData['_validationErrors'])`
- **AND** `MagicMapper::update()` MUST persist the updated object

#### Scenario: Retry with modified data merges into object
- **GIVEN** a hook retry returns `{"status": "modified", "data": {"verified": true}}`
- **WHEN** `HookRetryJob` processes the result
- **THEN** the modified data MUST be merged via `array_merge($objectData, $result->getData())`
- **AND** `_validationStatus` MUST be set to `"passed"`
- **AND** the updated object MUST be persisted

#### Scenario: Max retries exceeded
- **GIVEN** a hook retry has reached attempt 5 (equal to `MAX_RETRIES`)
- **WHEN** `HookRetryJob::run()` catches another exception
- **THEN** it MUST log an ERROR message indicating max retries reached
- **AND** MUST NOT re-queue another `HookRetryJob`
- **AND** the object remains with `_validationStatus: "pending"` for admin inspection

#### Scenario: Incremental retry re-queues with attempt counter
- **GIVEN** `HookRetryJob` fails on attempt 2 (below `MAX_RETRIES`)
- **WHEN** the exception is caught
- **THEN** a new `HookRetryJob` MUST be added to `IJobList` with `attempt: 3`
- **AND** the job arguments MUST preserve the original `objectId`, `schemaId`, and `hook` configuration

### Requirement: Hook Logging
All hook executions MUST be logged via `HookExecutor::logHookExecution()` for debugging and audit purposes. The method tracks execution duration using `hrtime(true)` and logs structured context data.

#### Scenario: Successful sync hook logged
- **GIVEN** a sync hook executes successfully with status `approved`
- **THEN** `$this->logger->info()` MUST be called with a message including the hook ID, event type, object UUID, and duration in milliseconds
- **AND** the log context MUST include: `hookId`, `eventType`, `objectUuid`, `engine`, `workflowId`, `durationMs`, and `responseStatus`

#### Scenario: Failed sync hook logged with full context
- **GIVEN** a sync hook fails (rejection, timeout, or engine down)
- **THEN** `$this->logger->error()` MUST be called with the above fields PLUS: `error` (error message string)
- **AND** if a `payload` was provided, it MUST be included in the context for debugging

#### Scenario: Async hook delivery logged
- **GIVEN** an async hook fires
- **THEN** a log entry MUST be created with `deliveryStatus` set to either `"delivered"` or `"failed"`
- **AND** the log MUST include the hook ID, event type, object UUID, engine, workflow ID, and duration

#### Scenario: Filter condition skip logged at debug level
- **GIVEN** a hook's `filterCondition` does not match the object data
- **WHEN** `evaluateFilterCondition()` returns `false`
- **THEN** `$this->logger->debug()` MUST log the skip with the hook ID and object UUID

### Requirement: HookListener Registration and Event Delegation
`HookListener` MUST be registered as a PSR-14 event listener for all six object lifecycle events in `Application::registerEventListeners()`. It MUST resolve the schema from the object, check for hook configurations, and delegate to `HookExecutor::executeHooks()`.

#### Scenario: HookListener registered for all lifecycle events
- **GIVEN** the OpenRegister app boots via `Application::register()`
- **WHEN** `registerEventListeners()` is called
- **THEN** `HookListener::class` MUST be registered for: `ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`, `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
- **AND** all registrations MUST use `$context->registerEventListener()` for Nextcloud's lazy-loading mechanism

#### Scenario: HookListener resolves schema and delegates
- **GIVEN** an `ObjectCreatingEvent` is dispatched for an object with schema ID `5`
- **WHEN** `HookListener::handle()` is invoked
- **THEN** it MUST extract the object via `getObjectFromEvent()`
- **AND** load the schema via `SchemaMapper::find(5)`
- **AND** check `$schema->getHooks()` for configured hooks
- **AND** if hooks exist, call `$this->hookExecutor->executeHooks($event, $schema)`

#### Scenario: Schema without hooks short-circuits
- **GIVEN** an object's schema has `hooks: null` or `hooks: []`
- **WHEN** `HookListener::handle()` checks the hooks
- **THEN** it MUST return early without calling `HookExecutor`
- **AND** no performance overhead is introduced for schemas without hooks

#### Scenario: Schema lookup failure is handled gracefully
- **GIVEN** an object references schema ID `999` which does not exist
- **WHEN** `SchemaMapper::find(999)` throws an exception
- **THEN** `HookListener` MUST catch the exception, log it at debug level, and return without executing hooks
- **AND** the object operation MUST proceed normally

### Requirement: Hook Timeout Configuration
Each hook MUST support a configurable `timeout` value (in seconds, default 30) that is passed to the engine adapter's `executeWorkflow()` call. When the workflow exceeds the timeout, the `onTimeout` failure mode is applied.

#### Scenario: Hook with custom timeout
- **GIVEN** a hook with `timeout: 60` and `onTimeout: "allow"`
- **WHEN** `HookExecutor::executeSingleHook()` calls `adapter->executeWorkflow()`
- **THEN** the timeout parameter MUST be `60` seconds
- **AND** if the workflow exceeds 60 seconds, the `"allow"` failure mode applies

#### Scenario: Default timeout applied when not specified
- **GIVEN** a hook with no `timeout` field
- **WHEN** `executeSingleHook()` reads `$hook['timeout'] ?? 30`
- **THEN** the default timeout of 30 seconds MUST be used

#### Scenario: Timeout exception triggers onTimeout mode
- **GIVEN** a hook with `onTimeout: "reject"` and `timeout: 10`
- **WHEN** the workflow times out and throws an exception containing "timeout" or "timed out"
- **THEN** `determineFailureMode()` MUST return the value of `$hook['onTimeout']` (`"reject"`)
- **AND** `applyFailureMode("reject", ...)` MUST stop the event propagation

### Requirement: n8n Workflow Integration for Hooks
Schema hooks MUST seamlessly integrate with n8n workflows deployed via `N8nAdapter`. The `WorkflowEngineInterface` contract ensures hooks can deploy, activate, execute, and monitor n8n workflows through a unified API.

#### Scenario: n8n validation workflow as a creating hook
- **GIVEN** a schema `vergunningen` with a hook: `{ "event": "creating", "engine": "n8n", "workflowId": "wf-validate-bsn", "mode": "sync", "onFailure": "reject" }`
- **WHEN** a new vergunning is created with BSN `"123456789"`
- **THEN** `N8nAdapter::executeWorkflow("wf-validate-bsn", payload, 30)` MUST be called with the CloudEvent payload containing the BSN
- **AND** the n8n workflow validates the BSN and returns `{"status": "approved"}` or `{"status": "rejected", "errors": [...]}`

#### Scenario: n8n enrichment workflow as a creating hook
- **GIVEN** a hook with `mode: "sync"` on `creating` that enriches addresses via a geocoding workflow
- **WHEN** the workflow returns `{"status": "modified", "data": {"lat": 52.37, "lng": 4.89}}`
- **THEN** the geographic coordinates MUST be merged into the object data before save

#### Scenario: n8n notification workflow as an async created hook
- **GIVEN** a hook with `mode: "async"` on `created` that sends email notifications via n8n
- **WHEN** an object is successfully created
- **THEN** the n8n workflow fires asynchronously to send the notification
- **AND** notification delivery failure does NOT affect the saved object

#### Scenario: n8n engine unavailable triggers retry
- **GIVEN** a hook with `onEngineDown: "queue"` and n8n is temporarily down
- **WHEN** `N8nAdapter::executeWorkflow()` throws a connection refused exception
- **THEN** `HookRetryJob` is scheduled to retry when n8n recovers
- **AND** Nextcloud's cron system picks up the `QueuedJob` on the next run

### Requirement: HookStoppedException Carries Validation Errors
The `HookStoppedException` class MUST extend `Exception` and carry an `errors` array that is surfaced in the HTTP 422 response. The controller layer MUST catch this exception and format the errors for the API consumer.

#### Scenario: Controller handles HookStoppedException
- **GIVEN** `MagicMapper::insertObjectEntity()` throws a `HookStoppedException` with errors `[{"field": "bsn", "message": "Invalid BSN", "code": "INVALID_BSN"}]`
- **WHEN** the `ObjectsController` catches the exception
- **THEN** it MUST return an HTTP 422 response with the errors array from `$exception->getErrors()`
- **AND** the response body MUST be structured so the frontend can display field-level validation messages

#### Scenario: HookStoppedException with default message
- **GIVEN** a hook rejection with no custom error message
- **WHEN** `HookStoppedException` is constructed with default parameters
- **THEN** the message MUST be `"Operation blocked by schema hook"`
- **AND** the errors array MUST be empty (or populated with the fallback error from `stopEvent()`)

#### Scenario: Deletion blocked by hook returns 422
- **GIVEN** a `deleting` hook rejects deletion because the object has active references
- **WHEN** `MagicMapper::deleteObjectEntity()` throws `HookStoppedException`
- **THEN** the HTTP response MUST be 422 (not 403 or 409)
- **AND** the error message MUST explain why deletion was blocked

### Requirement: Bulk Operation Event Suppression
When `MagicMapper::insertObjectEntity()` or `deleteObjectEntity()` is called with `dispatchEvents: false` (used during bulk imports), no lifecycle events MUST be dispatched and therefore no hooks MUST execute. This prevents overwhelming external workflow engines during large data migrations.

#### Scenario: Bulk import skips hooks
- **GIVEN** an admin imports 10,000 objects via the import API
- **WHEN** `MagicMapper::insertObjectEntity()` is called with `dispatchEvents: false`
- **THEN** no `ObjectCreatingEvent` or `ObjectCreatedEvent` MUST be dispatched
- **AND** no hooks execute, no workflow engines are called
- **AND** objects are persisted directly to the database

#### Scenario: Individual operations always trigger hooks
- **GIVEN** a user creates a single object via the API
- **WHEN** `MagicMapper::insert()` calls `insertObjectEntity()` with `dispatchEvents: true` (default)
- **THEN** all registered listeners MUST receive events and hooks MUST execute normally

#### Scenario: Bulk delete skips hooks
- **GIVEN** an admin deletes all objects in a register
- **WHEN** `MagicMapper::deleteObjectEntity()` is called with `dispatchEvents: false`
- **THEN** no `ObjectDeletingEvent` or `ObjectDeletedEvent` MUST be dispatched
- **AND** hook-based deletion guards are bypassed

## Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/Db/Schema.php` -- Schema entity supports `hooks` JSON property (type `json`) storing hook configuration arrays, accessed via `getHooks()` / `setHooks()`
- `lib/Service/HookExecutor.php` -- Main hook execution service:
  - `executeHooks()` orchestrates the full pipeline: resolve event type, load hooks, iterate and execute
  - `loadHooks()` filters by event type and enabled status, sorts by ascending order
  - `executeSingleHook()` handles filter condition evaluation, CloudEvent payload building, engine resolution, sync/async dispatch
  - `processWorkflowResult()` handles approved/rejected/modified/error statuses
  - `applyFailureMode()` implements reject/allow/flag/queue behavior
  - `evaluateFilterCondition()` supports simple key-value equality matching on object data
  - `determineFailureMode()` maps exception messages to onTimeout/onEngineDown/onFailure
  - `logHookExecution()` provides structured logging with duration tracking via `hrtime(true)`
- `lib/Listener/HookListener.php` -- PSR-14 event listener that resolves schema from object and delegates to HookExecutor; registered for all 6 object lifecycle events in `Application::registerEventListeners()`
- `lib/Event/ObjectCreatingEvent.php`, `ObjectUpdatingEvent.php`, `ObjectDeletingEvent.php` -- All implement `StoppableEventInterface` with `stopPropagation()`, `isPropagationStopped()`, `setErrors()`, `getErrors()`, `setModifiedData()`, `getModifiedData()`
- `lib/Exception/HookStoppedException.php` -- Exception with `$errors` array for rejected saves (controller returns HTTP 422)
- `lib/Service/Webhook/CloudEventFormatter.php` -- CloudEvents 1.0 structured content mode formatting with UUID v4 IDs via `Symfony\Component\Uid\Uuid`
- `lib/BackgroundJob/HookRetryJob.php` -- `QueuedJob` for `"queue"` failure mode; retries up to `MAX_RETRIES` (5) with re-queuing and incremental attempt counter; updates `_validationStatus` to `"passed"` on success
- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- Engine-agnostic interface with `executeWorkflow()`, `deployWorkflow()`, `healthCheck()`, etc.
- `lib/WorkflowEngine/N8nAdapter.php` -- n8n implementation of WorkflowEngineInterface
- `lib/WorkflowEngine/WindmillAdapter.php` -- Windmill implementation of WorkflowEngineInterface
- `lib/WorkflowEngine/WorkflowResult.php` -- Value object with statuses: approved, rejected, modified, error; factory methods and type-safe accessors
- `lib/Service/WorkflowEngineRegistry.php` -- Registry for resolving engine adapters by type
- `lib/Db/MagicMapper.php` -- Dispatches pre/post mutation events, checks `isPropagationStopped()`, merges `getModifiedData()`, throws `HookStoppedException`; supports `dispatchEvents` parameter for bulk suppression

**Valid event values supported:** `creating`, `updating`, `deleting`, `created`, `updated`, `deleted` (plus `locked`, `unlocked`, `reverted` per spec -- event classes exist but HookExecutor does not yet map them)

**What is NOT yet implemented:**
- `locked`, `unlocked`, `reverted` event mapping in `HookExecutor::resolveEventType()` (event classes exist but are not handled)
- Advanced `filterCondition` expressions beyond simple key-value equality (no dot-notation nested paths, no comparison operators, no regex matching)
- Hook execution metrics dashboard in the UI (structured logging exists but no visualization)
- Hook dry-run / test mode (no way to test a hook without creating a real object)
- Hook versioning (no history of hook configuration changes on the schema)

## Standards & References
- **CloudEvents 1.0 Specification** (https://cloudevents.io/) -- structured content mode with JSON encoding for hook payloads
- **PSR-14 Event Dispatcher** (https://www.php-fig.org/psr/psr-14/) -- `StoppableEventInterface` for sync hook rejection via `isPropagationStopped()`
- **HTTP 422 Unprocessable Entity** (RFC 4918) -- response code for hook rejections via `HookStoppedException`
- **Nextcloud IEventDispatcher** (`OCP\EventDispatcher\IEventDispatcher`) -- typed event dispatch for lifecycle events
- **Nextcloud IEventListener** (`OCP\EventDispatcher\IEventListener`) -- `HookListener` interface implementation
- **Nextcloud IBootstrap** -- `IRegistrationContext::registerEventListener()` for lazy listener registration in `Application.php`
- **Nextcloud QueuedJob** (`OCP\BackgroundJob\QueuedJob`) -- `HookRetryJob` base class for background retry processing
- **Nextcloud IJobList** (`OCP\BackgroundJob\IJobList`) -- job scheduling for `"queue"` failure mode

## Cross-References
- **event-driven-architecture** -- Schema hooks are a consumer of the event-driven architecture; `HookListener` is one of 11+ event listeners registered in `Application.php`. The event-driven spec defines the full event class hierarchy and dispatch flow that hooks depend on.
- **computed-fields** -- Computed fields are evaluated BEFORE hooks fire, ensuring hook workflows receive fully-computed object data. Hooks MAY override computed values via the `"modified"` response status.
- **workflow-integration** -- The workflow-integration spec defines the broader n8n/Windmill integration infrastructure (`WorkflowEngineInterface`, `N8nAdapter`, `WorkflowEngineRegistry`) that schema hooks use as execution backends.

## Specificity Assessment
- **Specific enough to implement?** Yes -- this spec is very detailed and the implementation closely matches all scenarios. Every class, method, and behavior described has a corresponding implementation.
- **Missing/ambiguous:**
  - The `filterCondition` field supports only simple key-value equality; no specification for nested path access, comparison operators, or expression-based conditions (same question as RBAC conditions)
  - No specification for hook execution timeout behavior per-engine vs per-hook (currently per-hook only)
  - No specification for hook execution metrics/monitoring dashboard or dry-run testing
  - No specification for how `locked`/`unlocked`/`reverted` events integrate with `HookExecutor::resolveEventType()`
- **Open questions:**
  - Should hook execution logs be stored in the database (queryable) or only in Nextcloud's log file (current approach)?
  - How should the `reverted` event interact with content versioning -- should hooks be able to reject a revert?
  - Should `filterCondition` support the same expression language as RBAC conditions for consistency?

## Nextcloud Integration Analysis

- **Status**: Implemented
- **Existing Implementation**: `HookExecutor` processes sync/async hooks with CloudEvents 1.0 payloads. `HookListener` is a PSR-14 event listener registered for all 6 object lifecycle events via `IRegistrationContext::registerEventListener()` with lazy loading. Stoppable events (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`) implement `StoppableEventInterface`. `HookRetryJob` extends `QueuedJob` for background retry with `IJobList`. `CloudEventFormatter` formats payloads with UUID v4 via `Symfony\Component\Uid\Uuid`. `WorkflowEngineRegistry` resolves engine adapters (`N8nAdapter`, `WindmillAdapter`) from the DI container.
- **Nextcloud Core Integration**: Uses `IEventDispatcher::dispatchTyped()` for typed event dispatch. `HookListener` registered via `IBootstrap::register()` in `Application::registerEventListeners()`. Background retry jobs use Nextcloud's `QueuedJob` (via `HookRetryJob`). The stoppable event pattern follows PSR-14 which aligns with Nextcloud's event dispatcher. Engine adapters use `IClientService` for HTTP communication. All services are registered in the DI container via constructor injection.
- **Recommendation**: The hook system is production-ready and deeply integrated with Nextcloud's core infrastructure. Future enhancements: (1) Add `locked`/`unlocked`/`reverted` event mapping to `HookExecutor::resolveEventType()`. (2) Implement richer `filterCondition` evaluation with dot-notation paths and comparison operators. (3) Add hook execution log storage in the database for queryable metrics dashboard. (4) Consider hook dry-run mode for testing without side effects.
