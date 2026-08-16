# Tasks: schema-hooks

## 1. Stoppable Events

### Task 1.1: Add StoppableEventInterface to ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-stoppable-events`
- **files**: `openregister/lib/Event/ObjectCreatingEvent.php`, `openregister/lib/Event/ObjectUpdatingEvent.php`, `openregister/lib/Event/ObjectDeletingEvent.php`
- **acceptance_criteria**:
  - GIVEN ObjectCreatingEvent WHEN inspected THEN it implements `Psr\EventDispatcher\StoppableEventInterface`
  - GIVEN an event instance WHEN `stopPropagation()` is called THEN `isPropagationStopped()` returns `true`
  - GIVEN an event instance WHEN `setErrors()` is called with validation errors THEN `getErrors()` returns them
  - GIVEN an event instance WHEN `setModifiedData()` is called THEN `getModifiedData()` returns the merged data
  - GIVEN ObjectUpdatingEvent and ObjectDeletingEvent WHEN inspected THEN they have the same StoppableEventInterface implementation
- [x] Implement
- [x] Test

## 2. Schema Entity Extension

### Task 2.1: Add hooks JSON field to Schema entity
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema`
- **files**: `openregister/lib/Db/Schema.php`, `openregister/lib/Migration/` (new migration)
- **acceptance_criteria**:
  - GIVEN a Schema entity WHEN `getHooks()` is called THEN it returns an array (decoded from JSON)
  - GIVEN a Schema entity WHEN `setHooks([...])` is called THEN it stores the array as JSON
  - GIVEN the database migration runs WHEN the `oc_openregister_schemas` table is inspected THEN it has a `hooks` column of type JSON/TEXT
  - GIVEN a Schema with no hooks WHEN `getHooks()` is called THEN it returns an empty array
- [x] Implement
- [x] Test

## 3. HookExecutor Service

### Task 3.1: Create HookExecutor service with hook loading and ordering
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-hook-execution-order`, `specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema`
- **files**: `openregister/lib/Service/HookExecutor.php`
- **acceptance_criteria**:
  - GIVEN a Schema with hooks WHEN `executeHooks($event, $schema)` is called THEN hooks for the matching event type are loaded
  - GIVEN hooks with order 3, 1, 2 WHEN loaded THEN they execute in order 1, 2, 3
  - GIVEN a hook with `enabled: false` WHEN hooks are loaded THEN it is skipped
  - GIVEN a hook with a `filterCondition` WHEN the condition does not match the object data THEN the hook is skipped
- [x] Implement
- [x] Test

### Task 3.2: Implement sync webhook delivery with CloudEvents payload
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-cloudevents-wire-format`, `specs/schema-hooks/spec.md#requirement-sync-hook-response-format`
- **files**: `openregister/lib/Service/HookExecutor.php`
- **acceptance_criteria**:
  - GIVEN a sync hook WHEN it fires THEN the HTTP POST body is a valid CloudEvent 1.0 with `specversion`, `type`, `source`, `id`, `time`, `datacontenttype`, `subject`, `data`, and `openregister` extension fields
  - GIVEN a sync hook WHEN `data.hookMode` is inspected THEN it is `"sync"` and `openregister.expectResponse` is `true`
  - GIVEN an async hook WHEN it fires THEN `data.hookMode` is `"async"` and `openregister.expectResponse` is `false`
  - GIVEN a sync hook WHEN the response is `{"status": "approved"}` THEN execution continues to the next hook
  - GIVEN a sync hook WHEN the response is `{"status": "modified", "data": {...}}` THEN the data is merged into the object and subsequent hooks see the modified data
  - GIVEN a sync hook with timeout 30 WHEN the workflow does not respond within 30 seconds THEN the `onTimeout` failure mode is applied
- [x] Implement
- [x] Test

### Task 3.3: Implement failure mode handling (reject, allow, flag, queue)
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-failure-mode-behavior`
- **files**: `openregister/lib/Service/HookExecutor.php`
- **acceptance_criteria**:
  - GIVEN failure mode "reject" WHEN a hook fails THEN `event->stopPropagation()` is called, errors are set on the event, and remaining hooks are skipped
  - GIVEN failure mode "allow" WHEN a hook times out THEN a warning is logged and execution continues
  - GIVEN failure mode "flag" WHEN a hook fails THEN `_validationStatus` is set to `"failed"` and `_validationErrors` is populated on the object metadata
  - GIVEN failure mode "queue" WHEN the engine is unreachable THEN `_validationStatus` is set to `"pending"` and a background job is scheduled
- [x] Implement
- [x] Test

## 4. ObjectEntityMapper Integration

### Task 4.1: Update ObjectEntityMapper to check isPropagationStopped()
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-stoppable-events`
- **files**: `openregister/lib/Db/ObjectEntityMapper.php`
- **acceptance_criteria**:
  - GIVEN ObjectCreatingEvent is dispatched WHEN `isPropagationStopped()` returns `true` THEN the database INSERT is skipped
  - GIVEN ObjectCreatingEvent is dispatched WHEN `isPropagationStopped()` returns `true` THEN the controller receives the validation errors from the event
  - GIVEN ObjectCreatingEvent is dispatched WHEN `isPropagationStopped()` returns `false` AND `getModifiedData()` returns data THEN the modified data is merged before INSERT
  - GIVEN ObjectUpdatingEvent and ObjectDeletingEvent WHEN stopped THEN the UPDATE/DELETE is similarly skipped
- [x] Implement
- [x] Test

## 5. Event Listener

### Task 5.1: Create HookListener and register for lifecycle events
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-hook-configuration-on-schema`
- **files**: `openregister/lib/Listener/HookListener.php`, `openregister/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN Application.php WHEN event listeners are registered THEN HookListener is registered for ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent, ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent
  - GIVEN an ObjectCreatingEvent fires WHEN the schema has hooks for "creating" THEN HookListener calls HookExecutor with the event and schema
  - GIVEN an ObjectCreatedEvent fires WHEN the schema has async hooks for "created" THEN HookListener calls HookExecutor for async delivery
- [x] Implement
- [x] Test

## 6. Hook Logging

### Task 6.1: Add hook execution logging
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-hook-logging`
- **files**: `openregister/lib/Service/HookExecutor.php`
- **acceptance_criteria**:
  - GIVEN a sync hook executes successfully WHEN the log is inspected THEN it contains: hook ID, event type, object UUID, engine name, workflow ID, response status, duration in milliseconds
  - GIVEN a sync hook fails WHEN the log is inspected THEN it additionally contains: error details, failure mode applied, request payload, response body
  - GIVEN an async hook fires WHEN the log is inspected THEN it contains: hook ID, event type, object UUID, engine name, workflow ID, delivery status
- [x] Implement
- [x] Test

## 7. Validation Metadata

### Task 7.1: Add _validationStatus and _validationErrors metadata fields
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-failure-mode-behavior` (flag and queue scenarios)
- **files**: `openregister/lib/Service/HookExecutor.php`, `openregister/lib/Db/ObjectEntityMapper.php`
- **acceptance_criteria**:
  - GIVEN failure mode "flag" WHEN the hook fails THEN the saved object has `_validationStatus: "failed"` in its JSON data
  - GIVEN failure mode "flag" WHEN the hook returns errors THEN the saved object has `_validationErrors` array in its JSON data
  - GIVEN failure mode "queue" WHEN the engine is down THEN the saved object has `_validationStatus: "pending"`
  - GIVEN all hooks pass WHEN the object is saved THEN `_validationStatus` is `null` or absent
- [x] Implement
- [x] Test

## 8. Background Job for Queue Mode

### Task 8.1: Create HookRetryJob background job
- **spec_ref**: `specs/schema-hooks/spec.md#requirement-failure-mode-behavior` (queue scenario)
- **files**: `openregister/lib/BackgroundJob/HookRetryJob.php`
- **acceptance_criteria**:
  - GIVEN a hook with `onEngineDown: "queue"` and a down engine WHEN the save completes THEN a `HookRetryJob` is scheduled with the object ID, schema ID, and hook configuration
  - GIVEN HookRetryJob runs WHEN the engine is now reachable THEN it re-executes the hook against the saved object
  - GIVEN HookRetryJob runs WHEN the hook succeeds THEN `_validationStatus` is updated to `"passed"` and `_validationErrors` is cleared
  - GIVEN HookRetryJob runs WHEN the engine is still down THEN the job is re-queued with exponential backoff
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [ ] `composer check:strict` passes in openregister
- [ ] Stoppable events work: creating/updating/deleting can be stopped
- [ ] Sync hooks block saves and return validation errors on rejection
- [ ] Modified data from hooks is merged correctly
- [ ] Failure modes (reject, allow, flag, queue) each produce correct behavior
- [ ] Hook execution order is respected
- [ ] CloudEvents payloads are valid 1.0 structured content mode
- [ ] Logging captures all required fields
- [ ] Manual testing against spec scenarios
- [ ] Code review against spec requirements
