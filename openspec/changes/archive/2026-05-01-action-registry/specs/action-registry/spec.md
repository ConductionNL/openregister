---
status: proposed
---

# Action Registry

## Purpose
The Action Registry introduces a first-class `Action` entity that decouples automation definitions from schemas, making workflow triggers reusable, discoverable, composable, and independently manageable. Actions wrap the existing hook/workflow infrastructure (HookExecutor, WorkflowEngineRegistry, CloudEventFormatter) with a proper entity lifecycle, CRUD API, RBAC, audit trail, and scheduling capabilities. This replaces the pattern of embedding hook configurations as JSON blobs inside schema entities with a normalized, relational model where actions are standalone entities that can be bound to one or more schemas, registers, or event types.

**Source**: Internal requirement driven by growing complexity of hook management across 10+ entity types with 39+ event classes. Government tender analysis shows 38% of tenders require workflow automation with auditability and governance controls.

**Cross-references**: schema-hooks (current inline hook system, to be augmented), workflow-integration (engine adapters and execution), event-driven-architecture (event dispatch infrastructure), webhook-payload-mapping (mapping transformations for action payloads).

## ADDED Requirements

### Requirement: Action MUST be a first-class Nextcloud database entity with full CRUD lifecycle
The `Action` entity MUST be stored in the `oc_openregister_actions` table with a complete set of fields covering identity, trigger configuration, execution parameters, lifecycle state, and audit metadata. The entity MUST extend `OCP\AppFramework\Db\Entity` and implement `JsonSerializable`. A corresponding `ActionMapper` MUST extend Nextcloud's `QBMapper` for database operations.

#### Scenario: Create a new Action entity
- **GIVEN** an administrator with write access to OpenRegister
- **WHEN** a POST request is sent to `/api/actions` with a valid action definition
- **THEN** a new `Action` entity MUST be persisted in `oc_openregister_actions`
- **AND** the entity MUST have an auto-generated UUID (v4) in the `uuid` field
- **AND** `created` and `updated` timestamps MUST be set automatically
- **AND** `status` MUST default to `'draft'` if not provided
- **AND** an `ActionCreatedEvent` MUST be dispatched via `IEventDispatcher::dispatchTyped()`

#### Scenario: Action entity field definitions
- **GIVEN** the `oc_openregister_actions` table schema
- **THEN** the table MUST include the following columns:
  - `id` (integer, primary key, auto-increment)
  - `uuid` (string, unique, indexed) -- external identifier
  - `name` (string, required) -- human-readable name
  - `slug` (string, unique, indexed) -- URL-safe identifier
  - `description` (text, nullable) -- purpose and documentation
  - `version` (string, nullable, default `'1.0.0'`) -- semantic version
  - `status` (string, default `'draft'`) -- lifecycle state: draft, active, disabled, archived
  - `event_type` (string, required) -- the event class name or pattern this action responds to (e.g., `'ObjectCreatingEvent'`, `'Object*Event'`, `'RegisterUpdatedEvent'`)
  - `engine` (string, required) -- workflow engine identifier (e.g., `'n8n'`, `'windmill'`)
  - `workflow_id` (string, required) -- identifier of the workflow in the engine
  - `mode` (string, default `'sync'`) -- execution mode: `sync` or `async`
  - `execution_order` (integer, default `0`) -- ordering when multiple actions match the same event
  - `timeout` (integer, default `30`) -- execution timeout in seconds
  - `on_failure` (string, default `'reject'`) -- failure behavior: reject, allow, flag, queue
  - `on_timeout` (string, default `'reject'`) -- timeout behavior: reject, allow, flag
  - `on_engine_down` (string, default `'allow'`) -- engine-unavailable behavior: allow, reject, queue
  - `filter_condition` (json, nullable) -- JSON object with key-value pairs for event payload filtering
  - `configuration` (json, nullable) -- additional key-value configuration passed to the workflow
  - `mapping` (integer, nullable) -- reference to a Mapping entity for payload transformation
  - `schemas` (json, nullable) -- array of schema IDs/UUIDs this action is bound to
  - `registers` (json, nullable) -- array of register IDs/UUIDs this action is scoped to
  - `schedule` (string, nullable) -- cron expression for scheduled execution (e.g., `'*/5 * * * *'`)
  - `max_retries` (integer, default `3`) -- maximum retry attempts on failure
  - `retry_policy` (string, default `'exponential'`) -- retry backoff strategy: exponential, linear, fixed
  - `enabled` (boolean, default `true`) -- whether the action is currently active
  - `owner` (string, nullable) -- Nextcloud user ID of the owner
  - `application` (string, nullable) -- application scope for multi-tenancy
  - `organisation` (string, nullable) -- organisation scope for multi-tenancy
  - `last_executed_at` (datetime, nullable) -- timestamp of last execution
  - `execution_count` (integer, default `0`) -- total execution counter
  - `success_count` (integer, default `0`) -- successful execution counter
  - `failure_count` (integer, default `0`) -- failed execution counter
  - `created` (datetime) -- creation timestamp
  - `updated` (datetime) -- last update timestamp
  - `deleted` (datetime, nullable) -- soft-delete timestamp

#### Scenario: Update an existing Action entity
- **GIVEN** an action `validate-bsn` exists with status `'draft'`
- **WHEN** a PUT request is sent to `/api/actions/{id}` with `status: 'active'`
- **THEN** the action's status MUST be updated to `'active'`
- **AND** the `updated` timestamp MUST be refreshed
- **AND** an `ActionUpdatedEvent` MUST be dispatched

#### Scenario: Soft-delete an Action entity
- **GIVEN** an action `validate-bsn` exists with status `'active'`
- **WHEN** a DELETE request is sent to `/api/actions/{id}`
- **THEN** the action MUST NOT be physically deleted from the database
- **AND** the `deleted` timestamp MUST be set to the current datetime
- **AND** the `status` MUST be changed to `'archived'`
- **AND** an `ActionDeletedEvent` MUST be dispatched
- **AND** the action MUST no longer match incoming events (skipped by ActionListener)

#### Scenario: Partial update via PATCH
- **GIVEN** an action `validate-bsn` exists
- **WHEN** a PATCH request is sent to `/api/actions/{id}` with `{ "timeout": 60 }`
- **THEN** only the `timeout` field MUST be updated
- **AND** all other fields MUST remain unchanged
- **AND** the `updated` timestamp MUST be refreshed

### Requirement: Actions MUST support binding to multiple schemas via a many-to-many relationship
An action MUST be bindable to zero or more schemas. When bound to a schema, the action fires on object lifecycle events for that schema. When bound to no schemas and the event_type is an object event, the action fires for ALL schemas (global action). The `schemas` field stores an array of schema identifiers.

#### Scenario: Action bound to specific schemas
- **GIVEN** action `validate-bsn` is configured with `schemas: ["schema-uuid-1", "schema-uuid-2"]` and `event_type: 'ObjectCreatingEvent'`
- **WHEN** an `ObjectCreatingEvent` fires for an object with schema `schema-uuid-1`
- **THEN** the action MUST be executed
- **AND** when an `ObjectCreatingEvent` fires for schema `schema-uuid-3`
- **THEN** the action MUST NOT be executed

#### Scenario: Global action with no schema binding
- **GIVEN** action `audit-all-changes` is configured with `schemas: []` (empty) and `event_type: 'ObjectUpdatedEvent'`
- **WHEN** an `ObjectUpdatedEvent` fires for ANY schema
- **THEN** the action MUST be executed for every schema
- **AND** the `filter_condition` MAY further restrict execution based on payload attributes

#### Scenario: Action bound to register scope
- **GIVEN** action `notify-register-admin` is configured with `registers: ["register-uuid-1"]` and `event_type: 'ObjectCreatedEvent'`
- **WHEN** an `ObjectCreatedEvent` fires for an object in register `register-uuid-1`
- **THEN** the action MUST be executed
- **AND** when the event fires for an object in a different register, the action MUST NOT be executed

#### Scenario: Combined schema and register filtering
- **GIVEN** action `validate-permit` with `schemas: ["vergunningen-uuid"]` and `registers: ["zaken-register-uuid"]`
- **WHEN** an event fires for schema `vergunningen-uuid` in register `zaken-register-uuid`
- **THEN** the action MUST be executed (both filters match)
- **AND** when the event fires for schema `vergunningen-uuid` in a DIFFERENT register
- **THEN** the action MUST NOT be executed (register filter does not match)

### Requirement: Actions MUST support all entity event types including non-object events
Actions MUST not be limited to object lifecycle events. They MUST support binding to any of the 39+ event types dispatched by OpenRegister, including register, schema, source, configuration, view, agent, application, conversation, and organisation lifecycle events. The `event_type` field supports exact class names and `fnmatch()` wildcard patterns.

#### Scenario: Action responds to RegisterUpdatedEvent
- **GIVEN** action `sync-register-metadata` with `event_type: 'RegisterUpdatedEvent'`
- **WHEN** a register is updated and `RegisterUpdatedEvent` is dispatched
- **THEN** the `ActionListener` MUST match this action and execute the configured workflow
- **AND** the CloudEvents payload MUST contain the register entity data

#### Scenario: Action responds to SchemaCreatedEvent
- **GIVEN** action `initialize-schema-defaults` with `event_type: 'SchemaCreatedEvent'`
- **WHEN** a new schema is created
- **THEN** the action MUST fire and the workflow MUST receive the schema entity data

#### Scenario: Wildcard event_type matching
- **GIVEN** action `log-all-object-events` with `event_type: 'Object*Event'`
- **WHEN** any object lifecycle event fires (ObjectCreatingEvent, ObjectCreatedEvent, ObjectUpdatingEvent, ObjectUpdatedEvent, ObjectDeletingEvent, ObjectDeletedEvent, ObjectLockedEvent, ObjectUnlockedEvent, ObjectRevertedEvent)
- **THEN** the action MUST match and execute for each of these events
- **AND** when a `RegisterCreatedEvent` fires, the action MUST NOT match

#### Scenario: Multiple event_type values
- **GIVEN** action `dual-trigger` with `event_type` stored as a JSON array `['ObjectCreatedEvent', 'ObjectUpdatedEvent']`
- **WHEN** an `ObjectCreatedEvent` fires
- **THEN** the action MUST match
- **AND** when an `ObjectDeletedEvent` fires, the action MUST NOT match

### Requirement: ActionListener MUST replace/augment HookListener for action-based event handling
A new `ActionListener` MUST be registered in `Application::registerEventListeners()` for all event types. When an event is dispatched, `ActionListener` MUST query `ActionMapper` for all enabled, active actions matching the event type, filter by schema/register scope, apply filter conditions, sort by `execution_order`, and delegate execution to the existing `HookExecutor` infrastructure (or a new `ActionExecutor` that wraps it).

#### Scenario: ActionListener resolves matching actions for ObjectCreatingEvent
- **GIVEN** three actions exist:
  - `validate-bsn` (event_type: `ObjectCreatingEvent`, schemas: `["meldingen-uuid"]`, status: `active`, enabled: `true`)
  - `enrich-address` (event_type: `ObjectCreatingEvent`, schemas: `["meldingen-uuid"]`, status: `active`, enabled: `true`)
  - `audit-log` (event_type: `Object*Event`, schemas: `[]`, status: `active`, enabled: `true`)
- **WHEN** an `ObjectCreatingEvent` fires for schema `meldingen-uuid`
- **THEN** `ActionListener` MUST resolve all three actions as matching
- **AND** sort them by `execution_order` ascending
- **AND** execute them sequentially via `ActionExecutor`

#### Scenario: ActionListener skips disabled and non-active actions
- **GIVEN** action `validate-bsn` has `enabled: false` or `status: 'draft'`
- **WHEN** a matching event fires
- **THEN** the action MUST be skipped by `ActionListener`
- **AND** a debug-level log MUST note the skip reason

#### Scenario: ActionListener coexists with HookListener
- **GIVEN** a schema has both inline hooks (via `getHooks()`) AND bound actions (via Action entities)
- **WHEN** an event fires
- **THEN** both `HookListener` (for inline hooks) and `ActionListener` (for Action entities) MUST execute
- **AND** inline hooks MUST execute BEFORE action-registry actions (preserving backward compatibility)
- **AND** if an inline hook stops propagation, action-registry actions MUST also be skipped

#### Scenario: Pre-mutation action can reject the operation
- **GIVEN** action `validate-bsn` with `event_type: 'ObjectCreatingEvent'` and `mode: 'sync'`
- **WHEN** the workflow returns `status: 'rejected'` with errors
- **THEN** `ActionExecutor` MUST call `$event->stopPropagation()` and `$event->setErrors()`
- **AND** the object MUST NOT be persisted
- **AND** subsequent actions in the execution order MUST be skipped

#### Scenario: Post-mutation action executes after persistence
- **GIVEN** action `send-notification` with `event_type: 'ObjectCreatedEvent'` and `mode: 'async'`
- **WHEN** the object has been persisted and `ObjectCreatedEvent` fires
- **THEN** the action MUST execute in fire-and-forget mode
- **AND** failure of the async action MUST NOT affect the already-persisted object

### Requirement: Actions MUST support filter conditions for fine-grained event matching
Beyond schema/register binding, actions MUST support a `filter_condition` JSON object that matches against the event payload using dot-notation keys. An action only fires if ALL filter conditions match. This uses the same mechanism as webhook filters (`WebhookService::matchesFilters()`).

#### Scenario: Filter by object property value
- **GIVEN** action `escalate-critical` with `filter_condition: { "data.object.priority": "critical" }`
- **WHEN** an `ObjectCreatedEvent` fires for an object with `priority: 'critical'`
- **THEN** the action MUST match and execute
- **AND** when the object has `priority: 'low'`, the action MUST NOT execute

#### Scenario: Filter with array values for multi-match
- **GIVEN** action `track-status-changes` with `filter_condition: { "data.object.status": ["open", "in_progress"] }`
- **WHEN** an event fires for an object with `status: 'open'`
- **THEN** the action MUST match (value is in the array)
- **AND** when `status: 'closed'`, the action MUST NOT match

#### Scenario: Empty filter_condition matches all payloads
- **GIVEN** action `log-everything` with `filter_condition: null` or `{}`
- **WHEN** any matching event fires (based on event_type and schema/register scope)
- **THEN** the action MUST always execute (no payload filtering applied)

#### Scenario: Nested dot-notation filtering
- **GIVEN** action `monitor-register-5` with `filter_condition: { "data.register": 5 }`
- **WHEN** an `ObjectCreatedEvent` fires with register ID 5 in the payload
- **THEN** the action MUST match
- **AND** when register ID is 8, the action MUST NOT match

### Requirement: Actions MUST support scheduled (cron-based) execution
Actions with a `schedule` field (cron expression) MUST be executable on a time-based schedule via a Nextcloud `TimedJob`. The `ActionScheduleJob` MUST evaluate all actions with non-null `schedule` fields and execute them at the appropriate intervals. Scheduled actions do not respond to events -- they run independently on a timer.

#### Scenario: Action with cron schedule
- **GIVEN** action `daily-report` with `schedule: '0 8 * * *'` (daily at 08:00) and `engine: 'n8n'` and `workflow_id: 'generate-report'`
- **WHEN** the `ActionScheduleJob` TimedJob runs at 08:00
- **THEN** the action MUST be executed via `ActionExecutor`
- **AND** the CloudEvents payload MUST include `type: 'nl.openregister.action.scheduled'` and `data.schedule: '0 8 * * *'`
- **AND** `last_executed_at` MUST be updated on the action entity

#### Scenario: Scheduled action respects enabled/active status
- **GIVEN** action `daily-report` with `schedule: '0 8 * * *'` but `enabled: false`
- **WHEN** the `ActionScheduleJob` evaluates the action
- **THEN** the action MUST be skipped

#### Scenario: Scheduled action with filter_condition scoped to registers
- **GIVEN** action `weekly-cleanup` with `schedule: '0 0 * * 0'` and `registers: ["register-uuid-1"]`
- **WHEN** the schedule triggers
- **THEN** the workflow MUST receive `data.registers: ["register-uuid-1"]` so it knows which register to operate on
- **AND** the action MUST execute even though no event was dispatched

### Requirement: Actions MUST have full CRUD API with pagination, search, and filtering
An `ActionsController` MUST expose RESTful CRUD endpoints under `/api/actions` following the same patterns as other OpenRegister resources (Registers, Schemas, etc.). The controller MUST support listing with pagination, searching by name/slug, filtering by status/event_type/engine, and full resource CRUD.

#### Scenario: List all actions with pagination
- **GIVEN** 25 actions exist in the database
- **WHEN** a GET request is sent to `/api/actions?limit=10&offset=0`
- **THEN** the response MUST contain 10 actions
- **AND** the response MUST include pagination metadata (`total`, `limit`, `offset`)

#### Scenario: Filter actions by status
- **GIVEN** 10 active actions and 5 draft actions
- **WHEN** a GET request is sent to `/api/actions?status=active`
- **THEN** only the 10 active actions MUST be returned

#### Scenario: Search actions by name
- **GIVEN** actions named `validate-bsn`, `validate-kvk`, `send-notification`
- **WHEN** a GET request is sent to `/api/actions?_search=validate`
- **THEN** `validate-bsn` and `validate-kvk` MUST be returned
- **AND** `send-notification` MUST NOT be returned

#### Scenario: Get action by ID or UUID
- **GIVEN** action `validate-bsn` with ID 5 and UUID `abc-123`
- **WHEN** a GET request is sent to `/api/actions/5` or `/api/actions/abc-123`
- **THEN** the full action entity MUST be returned as JSON
- **AND** the response MUST include all fields from the entity

#### Scenario: Create action via API
- **GIVEN** a valid action payload with required fields (name, event_type, engine, workflow_id)
- **WHEN** a POST request is sent to `/api/actions`
- **THEN** the action MUST be created with defaults applied for optional fields
- **AND** HTTP 201 MUST be returned with the created entity
- **AND** the response MUST include the auto-generated UUID

#### Scenario: Delete action via API
- **GIVEN** action `validate-bsn` exists with ID 5
- **WHEN** a DELETE request is sent to `/api/actions/5`
- **THEN** the action MUST be soft-deleted (deleted timestamp set, status changed to archived)
- **AND** HTTP 200 MUST be returned

### Requirement: Actions MUST support a dry-run (test) endpoint
A test endpoint MUST allow administrators to simulate action execution against a sample payload without triggering actual side effects. This enables validation of filter conditions, payload transformation, and workflow reachability before activating an action.

#### Scenario: Dry-run action execution
- **GIVEN** action `validate-bsn` exists with ID 5
- **WHEN** a POST request is sent to `/api/actions/5/test` with a sample event payload
- **THEN** the system MUST:
  1. Validate that the action would match the sample event (event_type, schema, register, filter_condition)
  2. Build the CloudEvents payload that would be sent to the workflow engine
  3. Optionally execute the workflow in dry-run mode if the engine supports it
  4. Return the match result, built payload, and engine response (if executed)
- **AND** NO actual object mutations, event dispatches, or audit trail entries MUST occur

#### Scenario: Dry-run reports filter mismatch
- **GIVEN** action `validate-bsn` with `filter_condition: { "data.object.type": "person" }`
- **WHEN** a test payload with `data.object.type: 'organization'` is submitted
- **THEN** the response MUST indicate `matched: false`
- **AND** MUST include the reason: `"filter_condition mismatch: data.object.type expected 'person', got 'organization'"`

### Requirement: Action execution MUST be logged and tracked with statistics
Every action execution MUST be logged in the `oc_openregister_action_logs` table via an `ActionLog` entity. The action entity itself MUST track aggregate statistics (execution_count, success_count, failure_count, last_executed_at).

#### Scenario: Successful action execution creates a log entry
- **GIVEN** action `validate-bsn` is executed for object `obj-1`
- **WHEN** the workflow returns `status: 'approved'` in 250ms
- **THEN** an `ActionLog` entry MUST be created with:
  - `action_id`: the action's database ID
  - `action_uuid`: the action's UUID
  - `event_type`: `'ObjectCreatingEvent'`
  - `object_uuid`: `'obj-1'` (if applicable)
  - `schema_id`: the schema's database ID (if applicable)
  - `register_id`: the register's database ID (if applicable)
  - `engine`: `'n8n'`
  - `workflow_id`: the workflow identifier
  - `status`: `'success'`
  - `duration_ms`: `250`
  - `request_payload`: the CloudEvents payload sent (JSON)
  - `response_payload`: the workflow response (JSON)
  - `error_message`: `null`
  - `attempt`: `1`
  - `created`: current timestamp
- **AND** the action's `execution_count` MUST be incremented by 1
- **AND** `success_count` MUST be incremented by 1
- **AND** `last_executed_at` MUST be updated

#### Scenario: Failed action execution logs the error
- **GIVEN** action `validate-bsn` execution fails with a timeout after 30s
- **WHEN** the failure is processed
- **THEN** an `ActionLog` entry MUST be created with `status: 'failure'` and `error_message` containing the timeout details
- **AND** `failure_count` MUST be incremented on the action entity

#### Scenario: Action execution logs are queryable via API
- **GIVEN** action `validate-bsn` with ID 5 has been executed 100 times
- **WHEN** a GET request is sent to `/api/actions/5/logs?limit=10&offset=0`
- **THEN** the 10 most recent log entries MUST be returned with pagination metadata

### Requirement: Action retry MUST use the existing retry infrastructure
When an action execution fails and `on_failure` is `'queue'` or `on_engine_down` is `'queue'`, the action MUST be re-queued using Nextcloud's `IJobList` with an `ActionRetryJob` (QueuedJob). The retry logic MUST follow the action's `retry_policy` and `max_retries` configuration, using the same backoff calculation patterns as `WebhookRetryJob`.

#### Scenario: Exponential backoff retry for failed action
- **GIVEN** action `validate-bsn` with `retry_policy: 'exponential'`, `max_retries: 3`, and `on_failure: 'queue'`
- **WHEN** the first execution attempt fails
- **THEN** `ActionRetryJob` MUST be added to `IJobList` with `attempt: 2`
- **AND** the retry delay MUST be `2^attempt * 60` seconds (attempt 2 = 4 minutes)
- **AND** the original CloudEvents payload MUST be preserved in the job arguments

#### Scenario: Max retries exceeded
- **GIVEN** action `validate-bsn` has failed 3 times (max_retries: 3)
- **WHEN** `ActionRetryJob` evaluates the failed execution
- **THEN** it MUST NOT re-queue
- **AND** the `ActionLog` MUST record `status: 'abandoned'` with the final error
- **AND** a warning MUST be logged indicating retry limit exceeded

### Requirement: Action events MUST be dispatched for action lifecycle changes
The system MUST dispatch typed events for action entity lifecycle changes, following the same pattern as all other OpenRegister entities. This enables external apps and webhooks to respond to action configuration changes.

#### Scenario: ActionCreatedEvent dispatched on creation
- **GIVEN** a new action is created via the API
- **WHEN** the action is persisted
- **THEN** an `ActionCreatedEvent` MUST be dispatched with the full `Action` entity accessible via `getAction()`

#### Scenario: ActionUpdatedEvent dispatched on update
- **GIVEN** an action is updated (e.g., status changed from draft to active)
- **WHEN** the update is persisted
- **THEN** an `ActionUpdatedEvent` MUST be dispatched

#### Scenario: ActionDeletedEvent dispatched on deletion
- **GIVEN** an action is soft-deleted
- **WHEN** the deletion is processed
- **THEN** an `ActionDeletedEvent` MUST be dispatched with the pre-deletion entity snapshot

### Requirement: Schema migration from inline hooks to Action entities MUST be supported
A migration utility MUST convert existing inline hook configurations (from `Schema::getHooks()`) into Action entities. This enables gradual adoption without breaking existing configurations.

#### Scenario: Migrate inline hooks to actions
- **GIVEN** schema `meldingen` has inline hooks: `[{"id": "validate-bsn", "event": "creating", "engine": "n8n", "workflowId": "wf-123", "mode": "sync", "order": 1, "timeout": 10, "onFailure": "reject"}]`
- **WHEN** the migration endpoint `POST /api/actions/migrate-from-hooks/{schemaId}` is called
- **THEN** for each inline hook, an Action entity MUST be created with:
  - `name`: hook `id` or `"Hook {index} for {schemaTitle}"`
  - `event_type`: mapped from hook `event` to event class name (e.g., `creating` -> `ObjectCreatingEvent`)
  - `engine`: from hook `engine`
  - `workflow_id`: from hook `workflowId`
  - `mode`: from hook `mode`
  - `execution_order`: from hook `order`
  - `timeout`: from hook `timeout`
  - `on_failure`: from hook `onFailure`
  - `schemas`: `[schemaUuid]`
- **AND** the response MUST list all created actions with their IDs
- **AND** the original inline hooks MUST NOT be removed (dual-running until manually disabled)

#### Scenario: Migration is idempotent
- **GIVEN** migration has already been run for schema `meldingen`
- **WHEN** the migration endpoint is called again
- **THEN** it MUST detect existing actions with matching `name` + `schemas` + `event_type`
- **AND** MUST skip creation of duplicates
- **AND** MUST return a report indicating which actions were skipped vs created

### Requirement: Multi-tenancy and RBAC MUST be enforced on Action entities
Actions MUST respect the existing multi-tenancy model (owner, application, organisation fields) and RBAC authorization. Only users with appropriate permissions can create, modify, or delete actions. The `MultiTenancyTrait` MUST be applied to `ActionMapper`.

#### Scenario: Tenant isolation for actions
- **GIVEN** organisation `org-1` has actions `A1` and `A2`, and organisation `org-2` has actions `A3`
- **WHEN** a user in `org-1` lists actions via GET `/api/actions`
- **THEN** only actions `A1` and `A2` MUST be returned
- **AND** action `A3` MUST NOT be visible

#### Scenario: RBAC on action creation
- **GIVEN** a user without `openregister_admin` or `openregister_actions_manage` permissions
- **WHEN** they attempt to create an action via POST `/api/actions`
- **THEN** the request MUST be rejected with HTTP 403

#### Scenario: Action execution respects action's tenant scope
- **GIVEN** action `validate-bsn` owned by `org-1`
- **WHEN** an event fires for an object in `org-2`
- **THEN** the action MUST NOT be executed (tenant scope mismatch)

## Current Implementation Status
- **Implemented:**
  - Schema-level hooks via `Schema::getHooks()` JSON property
  - `HookExecutor` for orchestrating hook execution with CloudEvents payloads
  - `HookListener` registered for all object lifecycle events
  - `WorkflowEngineRegistry` with n8n and Windmill adapters
  - `CloudEventFormatter` for CloudEvents 1.0 payload generation
  - `HookRetryJob` for retry with exponential backoff
  - 39+ typed event classes for all entity types
  - Event listener registration in `Application::registerEventListeners()`
  - Multi-tenancy infrastructure (`MultiTenancyTrait`, owner/application/organisation fields)
  - Webhook entity with similar concepts (events, filters, retry, HMAC, mapping)
- **NOT implemented:**
  - `Action` entity and `ActionMapper`
  - `ActionLog` entity and `ActionLogMapper`
  - `ActionsController` with CRUD API
  - `ActionListener` for event-to-action dispatch
  - `ActionExecutor` for action execution orchestration
  - `ActionScheduleJob` for cron-based scheduled actions
  - `ActionRetryJob` for retry of failed action executions
  - `ActionCreatedEvent`, `ActionUpdatedEvent`, `ActionDeletedEvent` events
  - Migration utility from inline hooks to Action entities
  - Dry-run/test endpoint for action simulation
  - Action-specific RBAC permissions
  - Database migration for `oc_openregister_actions` and `oc_openregister_action_logs` tables

## Standards & References
- **CloudEvents v1.0 (CNCF)** -- Event format specification
- **Nextcloud Entity pattern** -- `OCP\AppFramework\Db\Entity` + `QBMapper`
- **Nextcloud IEventDispatcher** -- Typed event dispatch
- **PSR-14 StoppableEventInterface** -- Pre-mutation event rejection
- **Cron expressions** -- Standard Unix cron syntax for scheduled actions
- **OpenRegister entity conventions** -- UUID, slug, soft-delete, audit timestamps, multi-tenancy fields

## Cross-References
- **schema-hooks** -- Current inline hook system; actions augment/replace this
- **workflow-integration** -- Engine adapters (N8nAdapter, WindmillAdapter) used by ActionExecutor
- **event-driven-architecture** -- Event dispatch infrastructure consumed by ActionListener
- **webhook-payload-mapping** -- Mapping entity referenced by Action for payload transformation
