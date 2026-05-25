---
status: draft
---

# Workflow Engine Abstraction

## Purpose

Extend the workflow-engine-abstraction capability with three HTTP-surface
controllers that drive lifecycle and data-shape operations but are not covered
by the existing engine-adapter and `WorkflowEngineController` requirements:
state-machine transitions (`TransitionController`), blob ↔ magic-table storage
migration (`MigrationController`), and explicit magic-table synchronisation
(`TablesController`). Reverse-specced from the existing implementation.

## ADDED Requirements

### Requirement: Lifecycle Transition HTTP Surface

The system MUST provide a sugar HTTP entry point over the lifecycle transition
engine so apps adopting the `x-openregister-lifecycle` annotation do not write a
per-schema action endpoint. `TransitionController::transition()` MUST read the
transition `action` from the request body (HTTP 400 when missing or empty),
delegate to `TransitionEngine::transition()`, and map failures to distinct
statuses: HTTP 403 when the caller lacks `update` permission
(`NotAuthorizedException`), HTTP 422 when a hook rejects the save
(`HookStoppedException`) or the transition is refused/invalid (`RuntimeException`),
and the serialized object on success. `availableActions()` MUST return the list
of actions allowed from the object's current state, mapping `NotAuthorizedException`
to HTTP 403 and a missing object/schema to HTTP 404.

#### Scenario: Apply a named transition from the request body
- **GIVEN** an object that supports a `publish` transition and a caller with `update` permission
- **WHEN** a request is sent to the transition endpoint with body `{"action": "publish"}`
- **THEN** `TransitionEngine::transition()` MUST be invoked with that action and object id
- **AND** the response MUST be the transitioned object's `jsonSerialize()` output

#### Scenario: Missing action is rejected
- **GIVEN** a transition request with no `action` field
- **WHEN** `transition()` validates the body
- **THEN** the response MUST be HTTP 400 with `error: 'Missing required field "action".'`

#### Scenario: Transition error mapping
- **GIVEN** a transition request
- **WHEN** the engine throws
- **THEN** `NotAuthorizedException` MUST map to HTTP 403, `HookStoppedException` and `RuntimeException` MUST map to HTTP 422

#### Scenario: List available actions from current state
- **GIVEN** an object in a known lifecycle state
- **WHEN** `availableActions()` runs
- **THEN** the response MUST be `{"actions": [...]}` for the actions allowed from that state
- **AND** a missing object MUST map to HTTP 404, an unauthorized caller to HTTP 403

### Requirement: Storage Migration HTTP Surface

The system MUST expose a storage-migration HTTP surface for moving object data
between blob storage and magic tables. `MigrationController::migrate()` MUST
require `register` and `schema` parameters (HTTP 400 when missing), validate
`direction` against `to-magic`/`to-blob` (HTTP 400 otherwise), accept optional
`batchSize` (default 100) and `dryRun` (boolean) parameters, resolve the
register/schema via `MigrationService::resolveRegisterAndSchema()`, and run the
matching migration returning the migration report. `status()` MUST return the
storage status for a register/schema pair. Errors MUST return HTTP 500 with an
`error` message.

#### Scenario: Trigger a migration to magic tables
- **GIVEN** a request with `register`, `schema`, and `direction=to-magic`
- **WHEN** `migrate()` runs
- **THEN** `MigrationService::migrateToMagicTable()` MUST be invoked with the resolved register/schema, `batchSize`, and `dryRun`
- **AND** the migration report MUST be returned

#### Scenario: Invalid direction is rejected
- **GIVEN** a migration request with `direction` other than `to-magic` or `to-blob`
- **WHEN** `migrate()` validates the direction
- **THEN** the response MUST be HTTP 400 with `error: 'direction must be "to-magic" or "to-blob"'`

#### Scenario: Missing register or schema is rejected
- **GIVEN** a migration request missing `register` or `schema`
- **WHEN** `migrate()` validates the parameters
- **THEN** the response MUST be HTTP 400 with `error: 'register and schema parameters are required'`

#### Scenario: Report storage status for a pair
- **GIVEN** a request for a register/schema pair
- **WHEN** `status()` runs
- **THEN** the response MUST be the `MigrationService::getStorageStatus()` result for the resolved pair

### Requirement: Magic-Table Sync HTTP Surface

The system MUST expose a magic-table synchronisation HTTP surface that updates a
schema-backed table structure to match its schema without dropping or recreating
the table. `TablesController::sync()` MUST accept a register and schema reference
(numeric id or slug), resolve both (HTTP 404 when either is not found), invoke
`MagicMapper::syncTableForRegisterSchema()`, and return a result reporting the
columns added, removed, de-required, re-required, and unchanged plus metadata and
property counts. `syncAll()` MUST iterate every register/schema pair, accumulate
per-pair success results and per-pair errors without aborting on a single
failure, and return a summary with `synced`, `errors`, `totalSynced`, and
`totalErrors`. On unexpected failure both endpoints MUST return HTTP 500 with an
`error` message.

#### Scenario: Sync the magic table for one register/schema pair
- **GIVEN** a request for an existing register and schema
- **WHEN** `sync()` runs
- **THEN** `MagicMapper::syncTableForRegisterSchema()` MUST be invoked
- **AND** the response MUST include column add/remove/de-require/re-require/unchanged statistics and the resolved table name

#### Scenario: Unknown register or schema returns 404
- **GIVEN** a sync request whose register or schema cannot be resolved
- **WHEN** the controller resolves the references
- **THEN** the response MUST be HTTP 404 with the appropriate `error`

#### Scenario: Sync all pairs tolerates per-pair failures
- **GIVEN** several register/schema pairs where one sync throws
- **WHEN** `syncAll()` runs
- **THEN** the failing pair MUST be recorded in `errors` while the others succeed
- **AND** the response MUST report `totalSynced` and `totalErrors`
- **AND** `success` MUST be true only when `errors` is empty
