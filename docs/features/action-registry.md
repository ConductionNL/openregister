# Action Registry

**Standards**: GEMMA Procesautomatiseringscomponent, TEC RFP Section 5.2 (Workflow)
**Status**: Implemented (backend entities and services); API routes not yet registered

## Overview

The Action Registry introduces a first-class `Action` entity that decouples workflow automation definitions from schemas, making triggers reusable, discoverable, composable, and independently manageable. Actions wrap the existing hook/workflow infrastructure (HookExecutor, WorkflowEngineRegistry, CloudEventFormatter) with a proper entity lifecycle, CRUD API, audit trail, and scheduling capabilities.

This replaces the pattern of embedding hook configurations as JSON blobs inside schema entities with a normalized, relational model where actions are standalone entities that can be bound to one or more schemas, registers, or event types.

## Key Capabilities

- **First-class entity**: `Action` is a full Nextcloud database entity (`oc_openregister_actions`) with UUID, versioning, soft-delete, and lifecycle states (draft, active, disabled, archived).
- **Multi-schema binding**: Actions can be bound to zero or more schemas. When unbound, they act as global actions firing on all schemas.
- **Register scoping**: Actions can be scoped to specific registers, enabling multi-tenant workflow isolation.
- **Full event coverage**: Supports all 39+ OpenRegister event types (Object, Register, Schema, Source, Configuration, View, Agent, Application, Conversation, Organisation) with wildcard pattern matching via `fnmatch()`.
- **Filter conditions**: JSON-based payload filtering using dot-notation keys for fine-grained event matching.
- **Execution modes**: Synchronous (pre-mutation, can reject operations) and asynchronous (post-mutation, fire-and-forget).
- **Engine abstraction**: Supports multiple workflow engines (n8n, Windmill) via the `engine` field and `WorkflowEngineRegistry`.
- **Execution ordering**: `execution_order` field controls the sequence when multiple actions match the same event.
- **Failure handling**: Configurable `on_failure`, `on_timeout`, and `on_engine_down` policies (reject, allow, flag, queue).
- **Retry support**: Configurable `max_retries` with exponential, linear, or fixed backoff strategies.
- **Scheduling**: Cron expression support for scheduled action execution via `ActionScheduleJob`.
- **Audit trail**: `ActionLog` entity records every execution with status, duration, payload, and error details.
- **Payload mapping**: Optional reference to a `Mapping` entity for payload transformation before workflow invocation.
- **Coexistence**: `ActionListener` runs alongside the legacy `HookListener`, with inline hooks executing first.

## API Endpoints

| Method | Endpoint | Description | Status |
|--------|----------|-------------|--------|
| GET | `/api/actions` | List all actions with pagination and filtering | Routes not registered |
| POST | `/api/actions` | Create a new action | Routes not registered |
| GET | `/api/actions/{id}` | Get a single action by ID | Routes not registered |
| PUT | `/api/actions/{id}` | Full update of an action | Routes not registered |
| PATCH | `/api/actions/{id}` | Partial update of an action | Routes not registered |
| DELETE | `/api/actions/{id}` | Soft-delete an action | Routes not registered |
| POST | `/api/actions/{id}/test` | Dry-run test of an action | Routes not registered |
| GET | `/api/actions/{id}/logs` | Get execution logs for an action | Routes not registered |
| POST | `/api/actions/migrate/{schemaId}` | Migrate inline hooks to action entities | Routes not registered |

**Note**: The `ActionsController` is fully implemented with all endpoints above, but the route registration in `appinfo/routes.php` is missing. The `'Actions'` resource entry and custom route definitions need to be added to enable API access.

## Backend Components

| Component | Path | Description |
|-----------|------|-------------|
| Entity | `lib/Db/Action.php` | Action entity with 30+ fields |
| Mapper | `lib/Db/ActionMapper.php` | QBMapper for database operations |
| Log Entity | `lib/Db/ActionLog.php` | Execution audit log entity |
| Log Mapper | `lib/Db/ActionLogMapper.php` | Audit log mapper |
| Controller | `lib/Controller/ActionsController.php` | Full CRUD + test/logs/migrate endpoints |
| Service | `lib/Service/ActionService.php` | Business logic for action CRUD |
| Executor | `lib/Service/ActionExecutor.php` | Action execution engine |
| Listener | `lib/Listener/ActionListener.php` | Event listener for action dispatch |
| Schedule Job | `lib/BackgroundJob/ActionScheduleJob.php` | Cron-based scheduled execution |
| Retry Job | `lib/BackgroundJob/ActionRetryJob.php` | Failed action retry handler |
| Events | `lib/Event/Action{Created,Updated,Deleted}Event.php` | Lifecycle events |

## Database Tables

- `oc_openregister_actions` -- Action entity storage (confirmed present in database)
- `oc_openregister_action_logs` -- Execution audit trail (confirmed present in database)

## Blocking Issue

The API endpoints return HTTP 404 because the route registration is missing from `appinfo/routes.php`. To activate the API, the following must be added:

1. In the `'resources'` array: `'Actions' => ['url' => 'api/actions']`
2. In the `'routes'` array: PATCH route and custom routes for test, logs, and migrate endpoints

## Test Coverage

Unit tests exist for all major components:
- `tests/Unit/Db/ActionTest.php`
- `tests/Unit/Db/ActionLogTest.php`
- `tests/Unit/Service/ActionServiceTest.php`
- `tests/Unit/Service/ActionExecutorTest.php`
- `tests/Unit/Listener/ActionListenerTest.php`
- `tests/Unit/BackgroundJob/ActionScheduleJobTest.php`
- `tests/Unit/BackgroundJob/ActionRetryJobTest.php`
