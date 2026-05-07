---
retrofit: true
---
# Actions Specification

**Status**: done
**Scope**: openregister
**OpenSpec changes**:
- [retrofit-2026-05-01-actions](../../changes/retrofit-2026-05-01-actions/) _(archived 2026-05-01)_

## Purpose

Actions are schema-attached, administrator-configured workflow triggers that execute external workflow engine payloads in response to object lifecycle events. Unlike inline schema hooks (which are attached to a schema's `hooks` array and execute sequentially), Actions are standalone entities that can target multiple schemas and registers, support both synchronous and asynchronous execution, and carry configurable failure handling and retry policies. Actions coexist with inline hooks via `ActionListener` and `HookListener` — hooks execute first, then Actions run, both respecting event propagation stop signals.

This spec documents the observed behavior of the Actions feature as retroactively introduced by the `retrofit-2026-05-01-actions` ghost change. Code already exists — requirements describe what the code does, not what it should do.

## Requirements

### REQ-001: The system SHALL provide a CRUD API for schema-attached workflow actions

Actions are named entities that declare which lifecycle event type to listen for, which workflow engine to invoke, and which workflow to run. Administrators manage actions via `ActionsController` backed by `ActionService`. CRUD operations dispatch typed events (`ActionCreatedEvent`, `ActionUpdatedEvent`, `ActionDeletedEvent`). Deletion is soft: the `deleted` timestamp is set and `status` becomes `'archived'`; the record is not removed. Execution statistics (total count, success count, failure count, last executed timestamp) are tracked per action by `ActionService::updateStatistics()`.

Required fields on create: `name`, `eventType`, `engine` (workflow engine ID), `workflowId`. Optional fields default to: `status='draft'`, `mode='sync'`, `executionOrder=0`, `timeout=30`, `onFailure='reject'`, `onTimeout='reject'`, `onEngineDown='allow'`, `maxRetries=3`, `retryPolicy='exponential'`, `enabled=true`, `version='1.0.0'`.

Actions may be scoped to zero or more schemas (by UUID) and zero or more registers (by UUID). An empty schemas or registers list means "match all".

#### Scenario: Create a minimal action

- **GIVEN** an administrator wants to run workflow `wf-123` on engine `42` whenever an object is created
- **WHEN** they POST to `/api/actions` with `{ "name": "Notify CRM", "eventType": "ObjectCreatedEvent", "engine": 42, "workflowId": "wf-123" }`
- **THEN** the system creates an Action entity with a generated UUID and returns HTTP 201
- **AND** `status` defaults to `'draft'`, `mode` to `'sync'`, `timeout` to `30`

#### Scenario: Missing required field

- **GIVEN** a POST to `/api/actions` that omits `eventType`
- **WHEN** `ActionService::createAction()` validates the input
- **THEN** the system returns HTTP 400 with `{ "error": "Action eventType is required" }`

#### Scenario: Soft delete

- **GIVEN** an existing action with ID `7`
- **WHEN** an administrator DELETEs `/api/actions/7`
- **THEN** the action's `deleted` timestamp is set and `status` becomes `'archived'`
- **AND** the action record remains in the database; subsequent list queries that filter on `status != archived` will omit it

#### Scenario: Filtered list

- **GIVEN** multiple actions with different statuses and event types
- **WHEN** a GET to `/api/actions?status=active&event_type=ObjectCreatedEvent&_limit=10&_page=2` is made
- **THEN** the system returns the matching page of actions and a total count
- **AND** the `_search` query parameter filters further by name substring match

### REQ-002: The system SHALL execute matching workflow actions when object lifecycle events fire

`ActionListener` is registered for all lifecycle event types (ObjectCreatingEvent, ObjectCreatedEvent, ObjectUpdatingEvent, ObjectUpdatedEvent, ObjectDeletingEvent, ObjectDeletedEvent, and others). On each event it:
1. Checks propagation stop — if a prior hook stopped propagation, no actions run.
2. Extracts the event payload (object data, schemaUuid, registerUuid) via duck-typing on the event class.
3. Queries `ActionMapper::findMatchingActions()` by eventType, schemaUuid, registerUuid — the mapper applies schema/register scoping at DB level.
4. Applies filter_condition matching in PHP: each condition key (dot-notation path) must equal the expected value in the payload.
5. Delegates the filtered, ordered list to `ActionExecutor::executeActions()`.

Within `executeActions()`, actions execute in the order provided. If a previous action stops propagation mid-batch, remaining actions are skipped.

#### Scenario: Matching action executes on object creation

- **GIVEN** an active action scoped to schema `schema-abc` with `eventType='ObjectCreatedEvent'`
- **WHEN** an `ObjectCreatedEvent` fires for an object with schemaUuid `schema-abc`
- **THEN** `ActionListener` finds the action and `ActionExecutor` executes it via the configured workflow engine

#### Scenario: Propagation stop blocks action execution

- **GIVEN** an inline hook that calls `$event->stopPropagation()`
- **WHEN** `ActionListener::handle()` checks propagation at entry
- **THEN** `ActionListener` returns immediately without executing any actions

#### Scenario: Filter condition mismatch

- **GIVEN** an action with `filterConditions: { "object.status": "published" }`
- **WHEN** an event fires for an object with `status='draft'`
- **THEN** the action is not executed

### REQ-003: The system SHALL support synchronous and fire-and-forget execution modes per action

Each action carries a `mode` field: `'sync'` (default) or `'async'`.

In **sync mode**: `ActionExecutor` calls the workflow engine and processes the `WorkflowResult`. If the result is `rejected`, `stopPropagation()` is called on the event and `setErrors()` is called with the result's error list — blocking the object mutation for pre-mutation events. If the result is `modified`, `setModifiedData()` is called — applying data changes from the workflow. Statistics are updated and an `ActionLog` is created regardless of outcome.

In **async mode**: `ActionExecutor` calls the workflow engine but does not process the `WorkflowResult` for event side effects. Failures are handled by the configured `onFailure` policy. The object mutation is never blocked by async actions.

The CloudEvents 1.0 envelope is built by `ActionExecutor::buildCloudEventPayload()` for every execution: `specversion: '1.0'`, `type: 'nl.openregister.action.{eventType}'`, `source: '/openregister/actions/{uuid}'`, `id: Uuid::v4()`, `time: now`, `datacontenttype: 'application/json'`, plus `data` (the event payload) and `action` metadata.

#### Scenario: Sync action rejects object mutation

- **GIVEN** an active sync action on `ObjectCreatingEvent`
- **WHEN** the workflow engine returns a `WorkflowResult` with `isRejected() = true` and errors `["Duplicate case number"]`
- **THEN** `stopPropagation()` is called on the event, blocking the object creation
- **AND** `setErrors(["Duplicate case number"])` is called so the caller can surface the rejection reason

#### Scenario: Async action does not block

- **GIVEN** an active async action on `ObjectCreatedEvent`
- **WHEN** the workflow engine call takes 5 seconds or throws an exception
- **THEN** the object creation completes without waiting for the engine response
- **AND** the failure is handled by the configured `onFailure` policy

#### Scenario: CloudEvents envelope

- **GIVEN** an action with uuid `abc-123` and a triggering event of type `ObjectCreatedEvent`
- **WHEN** `buildCloudEventPayload()` is called
- **THEN** the returned array includes `specversion: '1.0'`, `type: 'nl.openregister.action.ObjectCreatedEvent'`, `source: '/openregister/actions/abc-123'`

### REQ-004: The system SHALL retry failed action executions with configurable backoff until max retries are reached

When an action execution fails and its `onFailure` policy is `'queue'` (or `onEngineDown='queue'` and the engine is unavailable), `ActionExecutor::handleFailure()` adds an `ActionRetryJob` to the background job queue with the action ID, payload, attempt number (starting at 2), and retry configuration.

`ActionRetryJob` implements the retry loop:
- If `attempt > maxRetries`: create an `ActionLog` with `status='abandoned'`, call `updateStatistics(actionId, 'abandoned')`, stop.
- Otherwise: call `ActionExecutor::executeActions()` with the single action. If that fails, re-queue itself with `attempt + 1`. If it succeeds, no further action.

Delay calculation (static `calculateDelay(policy, attempt)`):
- `'exponential'`: `2^attempt × 60` seconds (attempt 2 → 240s, attempt 3 → 480s)
- `'linear'`: `attempt × 300` seconds
- `'fixed'`: 300 seconds

**Note**: `calculateDelay()` is defined but its return value is not currently used inside `ActionRetryJob::run()` to actually delay the re-queuing — the job is re-queued immediately without the calculated delay. This appears to be a bug; the delay should be applied via a `TimedJob` approach or by computing the scheduled run time. Surfaced here, not fixed.

#### Scenario: Action queued for retry on failure

- **GIVEN** an action with `onFailure='queue'`, `maxRetries=3`, `retryPolicy='exponential'`
- **WHEN** the first execution fails
- **THEN** `ActionRetryJob` is queued with `attempt=2`, `max_retries=3`, `retry_policy='exponential'`

#### Scenario: Retry succeeds before max retries

- **GIVEN** `ActionRetryJob` runs for attempt 2 out of 3 max retries
- **WHEN** the retry execution succeeds
- **THEN** no further retry job is queued
- **AND** the action log records `status='success'`

#### Scenario: Max retries exhausted

- **GIVEN** `ActionRetryJob` runs for attempt 4 out of 3 max retries
- **WHEN** `run()` checks `attempt > maxRetries`
- **THEN** an `ActionLog` is created with `status='abandoned'` and the abandon is counted in failure statistics
- **AND** no further retry job is queued

### REQ-005: The system SHALL execute actions with a cron schedule when they fall due

`ActionScheduleJob` is a `TimedJob` that runs every 60 seconds. It queries all actions where `enabled=true`, `status='active'`, and `schedule IS NOT NULL`. For each, it evaluates the cron expression (via `dragonmantank/cron-expression`) against `lastExecutedAt`:
- If `lastExecutedAt` is null → action is due immediately.
- Otherwise → compute `getNextRunDate(lastExecutedAt)` and check if it is ≤ now.

Due actions are executed via `ActionExecutor::executeActions()` with a synthetic `Event` object and a payload containing `schedule`, `schemas`, `registers` arrays. The eventType is `'nl.openregister.action.scheduled'`.

**Dependency**: `dragonmantank/cron-expression` is an optional runtime dependency. If the class is not available, `ActionScheduleJob` will throw an `UndefinedClass` error. The code currently does not guard against this case — `@psalm-suppress UndefinedClass` is used but no runtime check exists.

#### Scenario: Action due on first run

- **GIVEN** an active action with `schedule='0 9 * * *'` and `lastExecutedAt=null`
- **WHEN** `ActionScheduleJob` runs
- **THEN** the action is executed immediately

#### Scenario: Action not yet due

- **GIVEN** an active action with `schedule='0 9 * * *'` and `lastExecutedAt=today 09:00`
- **WHEN** `ActionScheduleJob` runs before 09:00 tomorrow
- **THEN** the action is skipped

#### Scenario: Scheduled payload

- **GIVEN** an action targeting schemas `['schema-abc']` with schedule `'*/5 * * * *'`
- **WHEN** the action is executed by `ActionScheduleJob`
- **THEN** the payload contains `{ "schedule": "*/5 * * * *", "schemas": ["schema-abc"], "registers": [...] }`
- **AND** the eventType is `'nl.openregister.action.scheduled'`

## Non-Functional Requirements

- **Performance:** `ActionMapper::findMatchingActions()` must resolve matching actions in < 100ms under normal load. Schema/register scoping at DB level prevents full table scans on large action sets.
- **Reliability:** Sync action failures do NOT silently swallow errors — `onFailure` policy determines whether the object mutation is blocked (`'reject'`) or the failure is queued for retry (`'queue'`).
- **Observability:** Every action execution creates an `ActionLog` entry with duration, status, request payload, and response payload. `ActionService::updateStatistics()` tracks aggregate counts.

## Acceptance Criteria

- [ ] `POST /api/actions` with valid required fields returns 201 and an Action entity with defaults applied
- [ ] `POST /api/actions` missing name/eventType/engine/workflowId returns 400
- [ ] `DELETE /api/actions/{id}` soft-deletes the action (sets `deleted` timestamp, `status='archived'`)
- [ ] `ObjectCreatedEvent` with matching schema triggers execution of a matching active action
- [ ] Sync action returning `rejected` from workflow stops event propagation
- [ ] Async action failure does not block the object mutation
- [ ] Failed action with `onFailure='queue'` results in an `ActionRetryJob` in the background queue
- [ ] `ActionRetryJob` with `attempt > maxRetries` records `status='abandoned'` and does not re-queue
- [ ] `ActionScheduleJob` executes actions with null `lastExecutedAt` immediately
- [ ] `ActionScheduleJob` skips actions whose cron expression is not yet due

## Notes

- **Test dry-run endpoint**: `ActionsController::test()` + `ActionService::testAction()` implement a dry-run simulation that checks event matching and builds the payload without executing side effects. Not covered in this spec — defer to a follow-up `--extend actions` run.
- **Hook migration utility**: `ActionsController::migrateFromHooks()` + `ActionService::migrateFromHooks()` migrate inline schema hooks to Action entities (one-time migration, deduplication check). Not covered here — defer to follow-up.
- **Retry delay bug**: `ActionRetryJob::calculateDelay()` is defined and tested but its result is not applied to the delay before re-queuing. Open issue: retrofit spec surfaces this; a fix PR should be filed separately.
- **Coexistence with schema hooks**: `HookListener` and `ActionListener` are both registered for the same event types. Hooks execute first (via registration order). `ActionListener` respects the propagation stop set by hooks.
- **Execution ordering**: Actions are executed in the order returned by `findMatchingActions()`. The `executionOrder` field controls DB-level ordering, but the current mapper implementation's sort behavior should be verified (not confirmed in this scan).
