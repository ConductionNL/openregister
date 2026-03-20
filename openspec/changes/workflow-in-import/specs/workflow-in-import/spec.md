---
status: implemented
---

# Workflow in Import
## Purpose

Extends the OpenRegister JSON configuration import pipeline to deploy workflow definitions to external engines (n8n, Windmill), wire them as schema hooks, track them for versioning and idempotent re-import, and include them in configuration exports -- all from a single import file. This specification bridges the `workflow-engine-abstraction` layer (engine adapters, `WorkflowEngineInterface`) with the `data-import-export` pipeline (`ImportHandler`, `ExportHandler`), enabling portable, self-contained register configurations that include both data structures and automation logic. It also ensures that workflows imported alongside schemas and objects participate in the `schema-hooks` lifecycle so that hooks are active before any objects in the same import are created.

---

## Requirements

### Requirement: Extended Import Format

The JSON import format SHALL support an optional `workflows` array inside `components`. Each entry MUST contain the fields `name` (string), `engine` (string identifying the target engine type, e.g., `"n8n"` or `"windmill"`), and `workflow` (the engine-native workflow definition as a JSON object). Each entry MAY optionally include `description` (human-readable summary) and `attachTo` (hook wiring configuration with `schema`, `event`, `mode`, and optional `order`, `timeout`, `onFailure`, `onTimeout`, `onEngineDown`).

#### Scenario: Import file includes workflows section
- **GIVEN** an import JSON file with a `components.workflows` array containing 3 valid entries
- **WHEN** `ImportHandler::importFromJson()` processes the file
- **THEN** the import pipeline SHALL accept and process the `workflows` section
- **AND** each entry MUST have required fields: `name`, `engine`, `workflow`
- **AND** each entry MAY optionally include `description` and `attachTo`
- **AND** entries missing any required field SHALL be added to `result['workflows']['failed']` with error `"Missing required fields (name, engine, workflow)"`

#### Scenario: Import file without workflows section
- **GIVEN** an import JSON file without a `components.workflows` key
- **WHEN** `ImportHandler::importFromJson()` is executed
- **THEN** the import SHALL proceed as before (backward compatible)
- **AND** no workflow processing occurs
- **AND** `result['workflows']` SHALL contain empty arrays for `deployed`, `updated`, `unchanged`, and `failed`

#### Scenario: Workflow entry with attachTo
- **GIVEN** a workflow entry with an `attachTo` block containing `schema: "organisation"`, `event: "creating"`, and `mode: "sync"`
- **WHEN** `processWorkflowHookWiring()` processes this entry
- **THEN** the workflow SHALL be deployed to its engine via `processWorkflowDeployment()` AND a schema hook SHALL be configured on the target schema
- **AND** optional `attachTo` fields SHALL use defaults when omitted: `order` defaults to `0`, `timeout` defaults to `30`, `onFailure` defaults to `"reject"`, `onTimeout` defaults to `"reject"`, `onEngineDown` defaults to `"allow"`

#### Scenario: Workflow entry without attachTo
- **GIVEN** a workflow entry without an `attachTo` block
- **WHEN** `processWorkflowDeployment()` processes this entry
- **THEN** the workflow SHALL be deployed to its engine via `WorkflowEngineInterface::deployWorkflow()`
- **AND** no schema hook SHALL be configured
- **AND** `processWorkflowHookWiring()` SHALL skip this entry (the `isset($entry['attachTo'])` check returns false)
- **AND** the workflow SHALL still be tracked as a `DeployedWorkflow` entity in the database

#### Scenario: Workflow entry with incomplete attachTo
- **GIVEN** a workflow entry with an `attachTo` block missing either `schema` or `event`
- **WHEN** `processWorkflowHookWiring()` processes this entry
- **THEN** the workflow SHALL have been deployed to its engine (deployment is a separate phase)
- **AND** the hook wiring SHALL be skipped with a warning log: `"Workflow '{name}' has incomplete attachTo"`

---

### Requirement: Workflow Import Processing Order

Workflows SHALL be processed after schemas and before objects. `ImportHandler::importFromJson()` implements a three-phase pipeline: Phase 1 processes schemas (via `importSchemas()`), Phase 2 deploys workflows (via `processWorkflowDeployment()`), and Phase 3 wires hooks (via `processWorkflowHookWiring()`). Objects are imported in Phase 4. This ordering ensures schemas exist for hook wiring and hooks are active when objects are created.

#### Scenario: Import file with schemas, workflows, and objects
- **GIVEN** an import file containing `components.schemas`, `components.workflows`, and `components.objects`
- **WHEN** `ImportHandler::importFromJson()` is executed
- **THEN** schemas SHALL be created/updated first (Phase 1)
- **AND** workflows SHALL be deployed to their engines second (Phase 2 via `processWorkflowDeployment()`)
- **AND** schema hooks SHALL be configured from `attachTo` third (Phase 3 via `processWorkflowHookWiring()`)
- **AND** objects SHALL be created fourth (Phase 4), with hooks now active so that `HookListener` and `HookExecutor` fire for each object creation

#### Scenario: Workflow references non-existent schema
- **GIVEN** a workflow with `attachTo.schema: "organisation"`
- **WHEN** the import runs and `"organisation"` schema does not exist in the database or in `$this->schemasMap`
- **THEN** `processWorkflowHookWiring()` SHALL attempt `SchemaMapper::findBySlug("organisation")`
- **AND** when the slug is not found, a warning SHALL be logged: `"Cannot attach '{name}' -- schema '{schemaSlug}' not found"`
- **AND** the workflow SHALL still be deployed to the engine (deployment occurred in Phase 2)
- **AND** the import SHALL continue (non-fatal)

#### Scenario: Workflow references schema from same import
- **GIVEN** a workflow with `attachTo.schema: "organisation"`
- **WHEN** the import file also contains a schema named `"organisation"` in `components.schemas`
- **THEN** the schema SHALL be created first (Phase 1) and stored in `$this->schemasMap`
- **AND** the workflow SHALL be deployed second (Phase 2)
- **AND** `processWorkflowHookWiring()` SHALL resolve the schema from `$this->schemasMap[$schemaSlug]`
- **AND** the hook SHALL be successfully attached to the newly created schema

#### Scenario: Import with workflows but no schemas or objects
- **GIVEN** an import file with only a `components.workflows` section (no `schemas` or `objects`)
- **WHEN** `ImportHandler::importFromJson()` is executed
- **THEN** Phase 1 (schemas) SHALL be a no-op
- **AND** Phase 2 SHALL deploy workflows to their engines
- **AND** Phase 3 SHALL wire hooks to existing schemas (if `attachTo` references schemas already in the database)
- **AND** Phase 4 (objects) SHALL be a no-op
- **AND** the import summary SHALL reflect zero schemas and zero objects

---

### Requirement: Workflow Deployment via Engine Adapters

Each workflow SHALL be deployed to its specified engine via the `WorkflowEngineInterface` (see `workflow-engine-abstraction` spec). The `processWorkflowDeployment()` method resolves the engine adapter through `WorkflowEngineRegistry::getEnginesByType()` and `resolveAdapter()`, then calls `deployWorkflow()` or `updateWorkflow()`. The engine-returned workflow ID is stored in the `DeployedWorkflow` entity for hook configuration and future reference.

#### Scenario: Deploy n8n workflow
- **GIVEN** a workflow entry with `engine: "n8n"` and valid n8n JSON in the `workflow` field
- **WHEN** `processWorkflowDeployment()` processes this entry
- **THEN** `WorkflowEngineRegistry::getEnginesByType("n8n")` SHALL return at least one registered engine
- **AND** `resolveAdapter()` SHALL return an `N8nAdapter` instance
- **AND** `N8nAdapter::deployWorkflow()` SHALL be called with the workflow definition
- **AND** the returned engine workflow ID SHALL be stored in the `DeployedWorkflow` record via `DeployedWorkflowMapper::createFromArray()`
- **AND** `result['workflows']['deployed']` SHALL include an entry with `name`, `engine`, and `action: "created"`

#### Scenario: Deploy Windmill workflow
- **GIVEN** a workflow entry with `engine: "windmill"` and valid Windmill flow definition
- **WHEN** `processWorkflowDeployment()` processes this entry
- **THEN** `WorkflowEngineInterface::deployWorkflow()` SHALL be called on the `WindmillAdapter`
- **AND** the returned flow path SHALL be stored as `engineWorkflowId` in the `DeployedWorkflow` record

#### Scenario: Engine not available
- **GIVEN** a workflow targeting engine `"windmill"`
- **WHEN** `WorkflowEngineRegistry::getEnginesByType("windmill")` returns an empty array
- **THEN** the workflow SHALL be added to `result['workflows']['failed']` with error `"No registered engine of type 'windmill'"`
- **AND** `processWorkflowDeployment()` SHALL continue processing remaining workflows via `continue`
- **AND** the import SHALL complete with a summary that includes the failure

#### Scenario: Invalid workflow definition
- **GIVEN** a workflow with malformed engine-specific JSON in the `workflow` field
- **WHEN** `adapter->deployWorkflow()` throws an `Exception`
- **THEN** the error SHALL be caught in the try-catch block
- **AND** logged via `$this->logger->error()` with context including the workflow name and error message
- **AND** the workflow SHALL be added to `result['workflows']['failed']` with the exception message
- **AND** the import SHALL continue with remaining workflows

#### Scenario: Workflow deployment with description field
- **GIVEN** a workflow entry with `description: "Validates KvK numbers against the Chamber of Commerce API"`
- **WHEN** the workflow is deployed
- **THEN** the description SHALL be available in the import context for logging
- **AND** the `DeployedWorkflow` entity SHALL store the workflow name for identification
- **AND** the description MAY be used for administrative display in future UI components

---

### Requirement: Hash-Based Idempotent Versioning

Imported workflows SHALL be tracked via the `DeployedWorkflow` entity (`lib/Db/DeployedWorkflow.php`) for update detection and cleanup. A SHA-256 hash of the `workflow` definition (computed via `hash('sha256', json_encode($entry['workflow'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))`) enables idempotent re-imports. The `DeployedWorkflowMapper::findByNameAndEngine()` method locates existing records for comparison.

#### Scenario: Re-import updated workflow
- **GIVEN** a workflow `"Validate Organisation KvK"` was previously imported with hash `"abc123..."`
- **WHEN** the same import file is re-imported with a modified workflow definition producing hash `"def456..."`
- **THEN** `DeployedWorkflowMapper::findByNameAndEngine()` SHALL return the existing `DeployedWorkflow` record
- **AND** the computed hash SHALL differ from `$existing->getSourceHash()`
- **AND** `adapter->updateWorkflow()` SHALL be called with `$existing->getEngineWorkflowId()` and the new definition
- **AND** `$existing->setSourceHash($hash)` SHALL store the new hash
- **AND** `$existing->setVersion($existing->getVersion() + 1)` SHALL increment the version
- **AND** `$existing->setUpdated(new DateTime())` SHALL update the timestamp
- **AND** `DeployedWorkflowMapper::update($existing)` SHALL persist the changes
- **AND** `result['workflows']['updated']` SHALL include an entry with `name`, `engine`, `version`, and `action: "updated"`

#### Scenario: Re-import unchanged workflow
- **GIVEN** a workflow was previously imported with source hash `"abc123..."`
- **WHEN** the same import file is re-imported with an identical workflow definition
- **THEN** the computed hash SHALL match `$existing->getSourceHash()`
- **AND** the workflow SHALL NOT be re-deployed to the engine (no adapter call)
- **AND** `result['workflows']['unchanged']` SHALL include the workflow name
- **AND** the existing `DeployedWorkflow` record SHALL be added to `$deployedWorkflows[$name]` for hook wiring in Phase 3

#### Scenario: First import of a workflow
- **GIVEN** a workflow `"Send Welcome Email"` has never been imported
- **WHEN** `DeployedWorkflowMapper::findByNameAndEngine()` returns `null`
- **THEN** `adapter->deployWorkflow()` SHALL be called to deploy to the engine
- **AND** `DeployedWorkflowMapper::createFromArray()` SHALL create a new `DeployedWorkflow` record with `name`, `engine`, `engineWorkflowId`, `sourceHash`, `importSource`, and `version: 1`
- **AND** `result['workflows']['deployed']` SHALL include the new workflow

#### Scenario: Hash computation is deterministic
- **GIVEN** two identical workflow definitions with keys in different order
- **WHEN** `json_encode($entry['workflow'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` is called
- **THEN** PHP's `json_encode` SHALL produce the same JSON string for equivalent objects with same key ordering
- **AND** the SHA-256 hash SHALL be identical, preventing unnecessary re-deployment

---

### Requirement: DeployedWorkflow Entity Tracking

The `DeployedWorkflow` entity (`lib/Db/DeployedWorkflow.php`) SHALL track all deployed workflows with the following properties: `uuid` (external reference), `name` (human-readable name from import), `engine` (engine type identifier), `engineWorkflowId` (ID returned by the engine after deploy), `sourceHash` (SHA-256 hash of the workflow definition), `attachedSchema` (schema slug if attached via hook), `attachedEvent` (hook event type), `importSource` (filename or identifier of the import source), `version` (integer, starts at 1, incremented on update), `created` (DateTime), `updated` (DateTime). The entity extends Nextcloud's `Entity` base class and implements `JsonSerializable`.

#### Scenario: DeployedWorkflow stores complete engine reference
- **GIVEN** a workflow `"KvK Validation"` deployed to n8n with returned ID `"wf-abc-123"`
- **WHEN** the `DeployedWorkflow` is created via `DeployedWorkflowMapper::createFromArray()`
- **THEN** `getEngineWorkflowId()` SHALL return `"wf-abc-123"`
- **AND** `getEngine()` SHALL return `"n8n"`
- **AND** `getSourceHash()` SHALL return the SHA-256 hash of the workflow definition

#### Scenario: DeployedWorkflow tracks schema attachment
- **GIVEN** a workflow attached to schema `"organisation"` on event `"creating"`
- **WHEN** `processWorkflowHookWiring()` updates the entity
- **THEN** `getAttachedSchema()` SHALL return `"organisation"`
- **AND** `getAttachedEvent()` SHALL return `"creating"`
- **AND** `getUpdated()` SHALL reflect the attachment timestamp

#### Scenario: DeployedWorkflow hydration from array
- **GIVEN** an array with keys matching `DeployedWorkflow` properties
- **WHEN** `$deployed->hydrate($array)` is called
- **THEN** each key SHALL be mapped to its setter via `'set' . ucfirst($key)`
- **AND** invalid properties SHALL be silently ignored via the try-catch in `hydrate()`

---

### Requirement: Schema Hook Wiring During Import

When a workflow entry includes an `attachTo` block, `processWorkflowHookWiring()` SHALL configure a schema hook on the target schema. The hook entry SHALL reference the deployed workflow's `engineWorkflowId` so that `HookExecutor` can execute it when the corresponding lifecycle event fires (see `schema-hooks` spec). Duplicate hooks with the same `workflowId` and `event` SHALL be replaced rather than duplicated.

#### Scenario: Wire workflow as sync creating hook
- **GIVEN** a workflow `"KvK Validation"` with `attachTo: { schema: "organisation", event: "creating", mode: "sync", onFailure: "reject" }`
- **WHEN** `processWorkflowHookWiring()` processes this entry
- **THEN** a hook entry SHALL be built: `{ event: "creating", engine: "n8n", workflowId: "{engineWorkflowId}", mode: "sync", order: 0, timeout: 30, enabled: true, onFailure: "reject", onTimeout: "reject", onEngineDown: "allow" }`
- **AND** the hook SHALL be appended to `$schema->getHooks()` via `$schema->setHooks($hooks)`
- **AND** `SchemaMapper::update($schema)` SHALL persist the updated hooks array
- **AND** `HookExecutor` SHALL be able to execute this hook on subsequent object creation events

#### Scenario: Wire workflow as async post-mutation hook
- **GIVEN** a workflow `"Send Notification"` with `attachTo: { schema: "meldingen", event: "created", mode: "async" }`
- **WHEN** the hook is wired
- **THEN** the hook entry SHALL have `mode: "async"` and `event: "created"`
- **AND** `HookExecutor::executeAsyncHook()` SHALL fire this hook after objects are persisted (fire-and-forget)

#### Scenario: Duplicate hook replacement
- **GIVEN** a schema `"organisation"` already has a hook with `workflowId: "wf-abc-123"` and `event: "creating"`
- **WHEN** a re-import wires the same workflow to the same event
- **THEN** `processWorkflowHookWiring()` SHALL remove the existing hook via `array_filter()` matching `workflowId` and `event`
- **AND** add the new hook entry
- **AND** the schema SHALL NOT have duplicate hooks for the same workflow and event

#### Scenario: Hooks active for objects in same import
- **GIVEN** an import file with schemas, workflows (with `attachTo`), and objects
- **WHEN** the import reaches Phase 4 (object creation)
- **THEN** the schema hooks from Phase 3 SHALL already be persisted to the database
- **AND** `HookListener` SHALL fire for each object created (unless `dispatchEvents: false`)
- **AND** the workflows SHALL execute via their engine adapters during object creation

---

### Requirement: Pre-Import Workflow Trigger

The import pipeline SHALL support a mechanism for triggering a workflow before the import data is processed. This enables pre-import validation, authorization checks, and data source verification via external workflow engines.

#### Scenario: Pre-import validation workflow configured on schema
- **GIVEN** a schema `"vergunningen"` has a hook on event `"importing"` with `mode: "sync"` and `onFailure: "reject"`
- **WHEN** a configuration import targets this schema
- **THEN** the pre-import workflow SHALL receive the import metadata (file name, row count, target schema, target register) as a CloudEvent payload
- **AND** if the workflow returns `status: "rejected"`, the entire import SHALL be aborted before any data processing
- **AND** `result['workflows']['failed']` SHALL include an entry indicating the pre-import check failed

#### Scenario: Pre-import workflow approves import
- **GIVEN** a pre-import workflow verifies that the import source is an authorized URL
- **WHEN** the workflow returns `status: "approved"`
- **THEN** the import pipeline SHALL proceed normally through all phases
- **AND** the approval SHALL be logged for audit purposes

#### Scenario: No pre-import workflow configured
- **GIVEN** the target schema has no hook on event `"importing"`
- **WHEN** the import starts
- **THEN** the import SHALL proceed without any pre-import check (backward compatible)

---

### Requirement: Per-Row Workflow Execution During Object Import

When objects are imported with `dispatchEvents: true` (the default for individual object creation), each object creation SHALL trigger the schema's configured hooks via the standard `HookListener` and `HookExecutor` pipeline. This ensures imported objects undergo the same validation and enrichment workflows as manually created objects.

#### Scenario: Per-row validation during import
- **GIVEN** schema `"organisaties"` has a sync hook on `creating` that validates KvK numbers via n8n
- **AND** an import file contains 50 organisation objects
- **WHEN** each object is created in Phase 4 of the import
- **THEN** `MagicMapper::insertObjectEntity()` SHALL dispatch `ObjectCreatingEvent` for each object
- **AND** `HookListener` SHALL delegate to `HookExecutor::executeHooks()` for each event
- **AND** objects with invalid KvK numbers SHALL be rejected (hook returns `status: "rejected"`)
- **AND** the import summary SHALL include rejected objects in the errors array

#### Scenario: Per-row enrichment during import
- **GIVEN** schema `"adressen"` has a sync hook on `creating` that geocodes addresses via a Windmill workflow
- **WHEN** each address object is created during import
- **THEN** the workflow SHALL return `status: "modified"` with latitude and longitude data
- **AND** the enriched data SHALL be merged into the object via `array_merge($objectData, $modifiedData)` before persistence
- **AND** the persisted objects SHALL contain the geocoded coordinates

#### Scenario: Bulk import with events disabled skips per-row workflows
- **GIVEN** a large import of 10,000 objects with query parameter `events=false`
- **WHEN** `MagicMapper::insertObjectEntity()` is called with `dispatchEvents: false`
- **THEN** no `ObjectCreatingEvent` or `ObjectCreatedEvent` SHALL be dispatched
- **AND** no hooks SHALL execute (per the `schema-hooks` bulk operation event suppression requirement)
- **AND** import performance SHALL be significantly faster without per-row workflow overhead

---

### Requirement: Conditional Import Routing by Schema

Different schemas within the same import MAY have different workflows configured. The hook wiring in `processWorkflowHookWiring()` SHALL respect per-schema workflow assignment, enabling different validation and enrichment logic for each schema type.

#### Scenario: Different workflows per schema in same import
- **GIVEN** an import file with two schemas: `"personen"` and `"organisaties"`
- **AND** workflow `"BSN Validator"` with `attachTo: { schema: "personen", event: "creating" }`
- **AND** workflow `"KvK Validator"` with `attachTo: { schema: "organisaties", event: "creating" }`
- **WHEN** the import processes both schemas and their objects
- **THEN** person objects SHALL be validated by `"BSN Validator"` via the hook on `"personen"`
- **AND** organisation objects SHALL be validated by `"KvK Validator"` via the hook on `"organisaties"`
- **AND** each schema's hooks SHALL be independent (per the `schema-hooks` spec requirement that hooks are per-schema)

#### Scenario: Schema with multiple workflows from same import
- **GIVEN** schema `"vergunningen"` receives two workflows: `"Validate BSN"` (order 1, sync) and `"Notify Behandelaar"` (order 2, async)
- **WHEN** both are wired via `processWorkflowHookWiring()`
- **THEN** the schema's `hooks` array SHALL contain both entries
- **AND** `HookExecutor::loadHooks()` SHALL sort them by order and execute the sync hook first, then the async hook

#### Scenario: Workflow targets schema from different register
- **GIVEN** a workflow with `attachTo.schema: "documenten"` but the schema exists in a different register than the one being imported
- **WHEN** `processWorkflowHookWiring()` looks up the schema
- **THEN** `SchemaMapper::findBySlug("documenten")` SHALL find the schema regardless of register
- **AND** the hook SHALL be successfully attached

---

### Requirement: Import Progress with Workflow Status

The import response SHALL include workflow deployment results alongside schema and object counts. The `result` array maintained by `ImportHandler::importFromJson()` SHALL include a `workflows` key with sub-arrays for `deployed`, `updated`, `unchanged`, and `failed`.

#### Scenario: Mixed import results
- **GIVEN** an import with 3 workflows: one new, one updated (hash changed), one failed (engine unavailable)
- **WHEN** `processWorkflowDeployment()` completes
- **THEN** `result['workflows']['deployed']` SHALL contain the newly deployed workflow with `name`, `engine`, and `action: "created"`
- **AND** `result['workflows']['updated']` SHALL contain the updated workflow with `name`, `engine`, `version`, and `action: "updated"`
- **AND** `result['workflows']['failed']` SHALL contain the failed workflow with `name`, `engine`, and `error` message

#### Scenario: All workflows unchanged
- **GIVEN** an import where all workflows have matching source hashes
- **WHEN** `processWorkflowDeployment()` completes
- **THEN** `result['workflows']['unchanged']` SHALL contain the workflow names
- **AND** `deployed`, `updated`, and `failed` SHALL be empty arrays

#### Scenario: Import with hook wiring warnings
- **GIVEN** a workflow deployed successfully but its `attachTo.schema` references a non-existent schema
- **WHEN** the import completes
- **THEN** the workflow SHALL appear in `result['workflows']['deployed']` (it was deployed to the engine in Phase 2)
- **AND** a warning SHALL be logged about the hook attachment failure
- **AND** the `DeployedWorkflow` record SHALL have `attachedSchema: null` since the wiring failed

#### Scenario: Import summary includes workflow counts in overall message
- **GIVEN** an import with 2 schemas, 3 workflows (2 deployed, 1 unchanged), and 50 objects
- **WHEN** the import completes
- **THEN** the overall result SHALL include schema count, workflow summary, and object count
- **AND** the workflow summary SHALL be structured identically to the `workflows` result key

---

### Requirement: Workflow Error Handling During Import

Workflow deployment failures during import SHALL be non-fatal. `processWorkflowDeployment()` wraps each workflow's deployment in a try-catch block that catches `Exception` and continues processing. Engine-level errors, network failures, and invalid definitions SHALL all be handled gracefully without aborting the import.

#### Scenario: Network error during workflow deployment
- **GIVEN** a workflow targets an n8n engine that is temporarily unreachable
- **WHEN** `N8nAdapter::deployWorkflow()` throws a `GuzzleException` with `"Connection refused"`
- **THEN** the exception SHALL be caught in the try-catch block
- **AND** `$this->logger->error()` SHALL log the failure with context `['name' => $name, 'error' => $e->getMessage()]`
- **AND** the workflow SHALL be added to `result['workflows']['failed']`
- **AND** the import SHALL continue with remaining workflows and objects

#### Scenario: Partial workflow deployment failure
- **GIVEN** an import with 5 workflows where workflow 3 fails
- **WHEN** `processWorkflowDeployment()` iterates through all 5
- **THEN** workflows 1, 2, 4, and 5 SHALL be processed normally
- **AND** workflow 3 SHALL appear in `result['workflows']['failed']`
- **AND** the `$deployedWorkflows` map SHALL contain entries for 1, 2, 4, and 5 (not 3)
- **AND** Phase 3 (hook wiring) SHALL skip workflow 3 since it is not in `$deployedWorkflows`

#### Scenario: Missing registry or mapper gracefully skips workflow processing
- **GIVEN** `$this->workflowRegistry` or `$this->deployedWfMapper` is `null` (not configured)
- **WHEN** `processWorkflowDeployment()` is called
- **THEN** a warning SHALL be logged: `"Workflow import skipped -- registry or mapper not configured"`
- **AND** the result SHALL be returned unchanged (no workflow processing)

---

### Requirement: Import Rollback Considerations for Workflows

When a workflow has been deployed to an engine but the import fails at a later phase (e.g., object creation errors), the deployed workflow SHALL remain in the engine and be tracked by the `DeployedWorkflow` entity. Full rollback of deployed workflows is not performed because external engine state is difficult to transact. Re-importing the same file SHALL detect the already-deployed workflows via hash comparison and skip re-deployment (idempotent).

#### Scenario: Object import fails after workflow deployment
- **GIVEN** Phase 2 successfully deployed 3 workflows to n8n
- **AND** Phase 4 (object creation) encounters a critical database error at row 500
- **WHEN** the import fails
- **THEN** the 3 deployed workflows SHALL remain active in n8n
- **AND** the `DeployedWorkflow` records SHALL remain in the database (they were persisted in Phase 2)
- **AND** re-importing the same file SHALL detect the workflows as unchanged (hash match) and skip re-deployment

#### Scenario: Workflow cleanup on explicit delete
- **GIVEN** an admin explicitly deletes a register configuration that included deployed workflows
- **WHEN** the cleanup process runs
- **THEN** `WorkflowEngineInterface::deleteWorkflow()` SHOULD be called for each associated `DeployedWorkflow`
- **AND** the `DeployedWorkflow` records SHOULD be removed from the database
- **AND** the schema hooks referencing those workflows SHOULD be removed

#### Scenario: Re-import after partial failure recovers cleanly
- **GIVEN** a previous import deployed workflows 1 and 2 but failed on workflow 3
- **WHEN** the same file is re-imported after fixing the engine issue
- **THEN** workflows 1 and 2 SHALL be detected as unchanged (hash match) and skipped
- **AND** workflow 3 SHALL be deployed for the first time
- **AND** all hook wiring SHALL proceed normally

---

### Requirement: Post-Import Workflow Trigger

The import pipeline SHALL support triggering a workflow after all objects have been imported. This enables post-import notifications, data quality reports, and downstream system synchronization.

#### Scenario: Post-import notification workflow
- **GIVEN** a workflow `"Import Complete Notification"` with `attachTo: { schema: "meldingen", event: "imported", mode: "async" }`
- **WHEN** the import completes Phase 4 (all objects created)
- **THEN** the post-import workflow SHALL receive a CloudEvent payload containing the import summary (created count, updated count, error count, import source)
- **AND** the workflow SHALL fire as async (fire-and-forget) so import completion is not delayed
- **AND** failure of the post-import workflow SHALL NOT affect the import result

#### Scenario: Post-import data quality workflow
- **GIVEN** a sync post-import workflow that checks data consistency across imported objects
- **WHEN** the workflow returns `status: "rejected"` with quality issues
- **THEN** the import result SHALL include a warning with the quality issues
- **AND** the already-imported objects SHALL NOT be rolled back (they are already persisted)

#### Scenario: No post-import workflow configured
- **GIVEN** no workflow is configured for the `"imported"` event
- **WHEN** the import completes
- **THEN** no post-import workflow SHALL fire (backward compatible)

---

### Requirement: Batch Workflow Execution for Performance

For large imports where per-row workflow execution is too slow, the import pipeline SHALL support batch mode where workflows receive multiple objects at once rather than one per invocation. This is controlled by the `events` parameter on the import API (`events=false` disables per-row events) combined with a batch workflow trigger.

#### Scenario: Batch validation of imported objects
- **GIVEN** an import of 5,000 objects with `events=false` to disable per-row hooks
- **AND** a batch validation workflow `"Bulk KvK Check"` configured as a post-import workflow
- **WHEN** the import completes
- **THEN** the batch workflow SHALL receive all 5,000 objects in a single invocation (or chunked per engine limits)
- **AND** the workflow SHALL return validation results keyed by object UUID
- **AND** objects failing validation SHALL be flagged with `_validationStatus: "failed"` in their metadata

#### Scenario: Performance comparison batch vs per-row
- **GIVEN** 10,000 objects to import with a validation workflow
- **WHEN** using batch mode (`events=false` + batch workflow) vs per-row mode (`events=true`)
- **THEN** batch mode SHALL require only 1 workflow invocation (or a small number of chunks) instead of 10,000
- **AND** total import time SHALL be significantly reduced

#### Scenario: Batch workflow unavailable falls back to no validation
- **GIVEN** `events=false` and no batch workflow configured
- **WHEN** the import completes
- **THEN** no workflow validation SHALL occur
- **AND** the import summary SHALL include `validation: false` to indicate no validation was performed

---

### Requirement: Workflow Context with Import Metadata

When workflows execute during import (either per-row via hooks or as batch/post-import triggers), the CloudEvent payload SHALL include import-specific context metadata so the workflow can distinguish import-triggered executions from normal CRUD operations.

#### Scenario: Per-row hook receives import context
- **GIVEN** a sync hook on `creating` fires during Phase 4 of an import
- **WHEN** `HookExecutor::buildCloudEventPayload()` constructs the CloudEvent
- **THEN** the payload SHALL include `data.action: "creating"` (standard hook behavior)
- **AND** the workflow SHALL receive the full object data for processing
- **AND** the hook SHALL behave identically to a non-import object creation (no special import metadata in standard hooks)

#### Scenario: Post-import workflow receives import metadata
- **GIVEN** a post-import workflow fires after Phase 4
- **WHEN** the CloudEvent payload is constructed
- **THEN** `data.importMetadata` SHALL include: `importSource` (filename), `totalRows` (count), `created` (count), `updated` (count), `errors` (count), `timestamp` (ISO 8601)
- **AND** the workflow SHALL be able to use this metadata for reporting and notification logic

#### Scenario: Re-import context includes previous version info
- **GIVEN** a re-import where hashes detected 200 unchanged and 50 updated objects
- **WHEN** the post-import workflow fires
- **THEN** `data.importMetadata` SHALL include `unchanged` count alongside `created` and `updated`
- **AND** the workflow SHALL be able to generate a differential report

---

### Requirement: Import Pause and Resume with Workflow State

For large imports where workflow failures require human intervention, the import pipeline SHALL support pausing the import after a configurable number of failures and resuming from the last successful position.

#### Scenario: Import pauses after threshold failures
- **GIVEN** an import of 1,000 objects with a sync validation hook
- **AND** the import configuration sets `maxWorkflowFailures: 10`
- **WHEN** 10 objects are rejected by the validation workflow
- **THEN** the import SHALL pause and return a partial result with `status: "paused"`
- **AND** the result SHALL include `lastProcessedRow` indicating where the import stopped
- **AND** successfully imported objects SHALL remain in the database

#### Scenario: Resume paused import
- **GIVEN** a paused import with `lastProcessedRow: 350`
- **WHEN** the user calls the import endpoint with `resumeFrom: 351`
- **THEN** the import SHALL skip the first 350 rows
- **AND** continue processing from row 351
- **AND** the deployed workflows and hooks from the original import SHALL still be active

#### Scenario: No pause threshold configured
- **GIVEN** an import without `maxWorkflowFailures` configured
- **WHEN** workflow failures occur
- **THEN** the import SHALL continue processing all rows regardless of failure count (current behavior)
- **AND** all failures SHALL be reported in the summary

---

### Requirement: Workflow Result Mapping Back to Imported Data

When sync hooks modify imported objects (returning `status: "modified"`), the modifications SHALL be applied to the object data before persistence. The `HookExecutor::setModifiedDataOnEvent()` method SHALL call `$event->setModifiedData(data)`, and `MagicMapper` SHALL merge the modified data via `array_merge($objectData, $modifiedData)` (see `schema-hooks` spec).

#### Scenario: Workflow enriches imported object with external data
- **GIVEN** a sync hook on `creating` that enriches addresses with postal code data
- **AND** an import creates object `{ "straat": "Keizersgracht", "huisnummer": 1 }`
- **WHEN** the workflow returns `{ "status": "modified", "data": { "postcode": "1015AA", "plaats": "Amsterdam" } }`
- **THEN** the persisted object SHALL contain `{ "straat": "Keizersgracht", "huisnummer": 1, "postcode": "1015AA", "plaats": "Amsterdam" }`
- **AND** the import summary SHALL count this as a successful creation (not a separate update)

#### Scenario: Multiple hooks modify same imported object
- **GIVEN** hook 1 (order 1) adds geocoding and hook 2 (order 2) adds a classification
- **WHEN** both hooks execute for the same imported object
- **THEN** the object SHALL contain modifications from both hooks (chain of modifications per `schema-hooks` spec)
- **AND** hook 2 SHALL receive the object data already modified by hook 1

#### Scenario: Workflow rejects imported object
- **GIVEN** a sync hook on `creating` with `onFailure: "reject"` validates BSN numbers
- **WHEN** an imported object has an invalid BSN and the workflow returns `{ "status": "rejected", "errors": [...] }`
- **THEN** the object SHALL NOT be persisted (blocked by `HookStoppedException`)
- **AND** the import summary SHALL include the object in the errors array with the validation error details

---

### Requirement: Export Includes Deployed Workflows

When exporting schemas via `ExportHandler::exportConfig()`, deployed workflows attached to those schemas SHALL be included in the export JSON under `components.workflows`. The `ExportHandler::exportWorkflowsForSchema()` method queries `DeployedWorkflowMapper::findBySchema()` and fetches the workflow definition from the engine via `WorkflowEngineInterface::getWorkflow()`.

#### Scenario: Export schema with attached workflow hooks
- **GIVEN** schema `"organisation"` has 2 attached workflow hooks tracked by `DeployedWorkflow` records
- **WHEN** `ExportHandler::exportConfig()` iterates schemas and calls `exportWorkflowsForSchema()`
- **THEN** `DeployedWorkflowMapper::findBySchema("organisation")` SHALL return the 2 `DeployedWorkflow` records
- **AND** for each record, `adapter->getWorkflow($deployed->getEngineWorkflowId())` SHALL fetch the current definition from the engine
- **AND** each workflow SHALL appear in `components.workflows` with `name`, `engine`, `workflow` (definition), and `attachTo` (reconstructed from `attachedSchema` and `attachedEvent`)

#### Scenario: Export schema without workflow hooks
- **GIVEN** schema `"address"` with no attached workflow hooks
- **WHEN** `exportWorkflowsForSchema("address")` is called
- **THEN** `DeployedWorkflowMapper::findBySchema("address")` SHALL return an empty array
- **AND** no workflow entries SHALL be added to `components.workflows` for this schema

#### Scenario: Export round-trip (export then re-import)
- **GIVEN** a schema was imported with workflows from a file
- **WHEN** the schema is exported and the resulting JSON is re-imported on the same instance
- **THEN** `processWorkflowDeployment()` SHALL detect unchanged workflows (matching SHA-256 hashes)
- **AND** no redundant deployments SHALL occur
- **AND** `result['workflows']['unchanged']` SHALL list the workflow names

#### Scenario: Export with engine unavailable
- **GIVEN** a deployed workflow's engine is temporarily unreachable
- **WHEN** `adapter->getWorkflow()` throws an exception during export
- **THEN** the error SHALL be logged via `$this->logger->error()`
- **AND** the workflow SHALL be skipped in the export (not included in `components.workflows`)
- **AND** the export SHALL continue with remaining schemas and workflows

---

### Requirement: Scheduled Import with Workflow Chain

When a scheduled import (via Nextcloud's `QueuedJob` infrastructure) processes a configuration file that includes workflows, the full import pipeline SHALL execute: schema processing, workflow deployment, hook wiring, and object creation. Scheduled imports with workflows enable automated, repeatable provisioning of complete register configurations.

#### Scenario: Scheduled import deploys workflows
- **GIVEN** a `QueuedJob` is configured to import a configuration file daily from a Nextcloud Files path
- **AND** the file includes 2 workflow definitions
- **WHEN** the scheduled job runs
- **THEN** `ImportHandler::importFromJson()` SHALL process the full pipeline including workflow deployment
- **AND** on subsequent runs, the workflows SHALL be detected as unchanged (hash match) and skipped

#### Scenario: Scheduled import with updated workflow definition
- **GIVEN** the source configuration file is updated with a modified workflow definition
- **WHEN** the scheduled job runs the next day
- **THEN** `processWorkflowDeployment()` SHALL detect the hash change
- **AND** `adapter->updateWorkflow()` SHALL deploy the updated definition to the engine
- **AND** the `DeployedWorkflow` version SHALL be incremented

#### Scenario: Scheduled import failure notification
- **GIVEN** a scheduled import's workflow deployment fails because the engine is unreachable
- **WHEN** the import completes with workflow failures
- **THEN** the import result SHALL include the failures in `result['workflows']['failed']`
- **AND** a Nextcloud notification SHOULD be sent to the admin user via `INotifier`

---

## Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/Service/Configuration/ImportHandler.php` -- Extended import pipeline processes `components.workflows` array after schemas, before objects:
  - `processWorkflowDeployment()` deploys workflows via `WorkflowEngineInterface::deployWorkflow()` or updates via `updateWorkflow()`
  - `processWorkflowHookWiring()` wires schema hooks from `attachTo` configuration, building hook entries compatible with `HookExecutor`
  - Supports hash-based idempotent re-import (SHA-256 comparison via `DeployedWorkflowMapper::findByNameAndEngine()`)
  - Handles engine-not-available and invalid-definition errors gracefully (non-fatal, try-catch with continue)
  - Reports deployment results in import summary under `result['workflows']`
- `lib/Service/Configuration/ExportHandler.php` -- Export includes deployed workflows attached to schemas:
  - `exportWorkflowsForSchema()` queries `DeployedWorkflowMapper::findBySchema()` and fetches definitions from engines
  - Includes `attachTo` configuration in export for round-trip compatibility
- `lib/Db/DeployedWorkflow.php` -- Entity with properties: uuid, name, engine, engineWorkflowId, sourceHash, attachedSchema, attachedEvent, importSource, version, created, updated
- `lib/Db/DeployedWorkflowMapper.php` -- Database mapper with `findByNameAndEngine()`, `findBySchema()`, `createFromArray()`
- `lib/WorkflowEngine/WorkflowEngineInterface.php` -- Defines `deployWorkflow()`, `updateWorkflow()`, `deleteWorkflow()`, `getWorkflow()`, `executeWorkflow()` used by the import/export pipeline
- `lib/WorkflowEngine/N8nAdapter.php` and `WindmillAdapter.php` -- Engine adapters implementing deployment and execution
- `lib/Service/WorkflowEngineRegistry.php` -- Registry for resolving engine adapters by type via `getEnginesByType()` and `resolveAdapter()`

**What is NOT yet implemented:**
- Pre-import workflow trigger (`"importing"` event on schema hooks)
- Post-import workflow trigger (`"imported"` event)
- Batch workflow execution (sending multiple objects to a workflow in one invocation)
- Import pause/resume with workflow failure threshold
- Import metadata in CloudEvent payload for import-specific context
- Workflow cleanup on configuration/register deletion
- Scheduled import with Nextcloud notification on workflow failure

## Standards & References
- SHA-256 content hashing (RFC 6234) for idempotent deployment detection
- n8n workflow JSON format (https://docs.n8n.io/workflows/)
- Windmill flow definition format (https://app.windmill.dev/openapi.html)
- CloudEvents 1.0 (https://cloudevents.io/) for hook payload format
- OpenAPI 3.0.0 with `x-openregister` extensions for configuration import/export format
- Nextcloud Entity base class (`OCP\AppFramework\Db\Entity`) and QBMapper for database access
- Nextcloud QueuedJob (`OCP\BackgroundJob\QueuedJob`) for scheduled and background imports
- Semantic versioning for workflow version tracking (integer increment on re-deploy)

## Cross-References
- **workflow-engine-abstraction** -- Provides the `WorkflowEngineInterface`, `N8nAdapter`, `WindmillAdapter`, and `WorkflowEngineRegistry` that this spec uses for deployment and execution. Engine configuration entities define base URLs and credentials.
- **data-import-export** -- The `ImportHandler` and `ExportHandler` are part of the configuration import/export pipeline defined in this spec. Workflow processing is a phase within the broader import pipeline that also handles schemas, objects, and mappings.
- **schema-hooks** -- The hook entries created by `processWorkflowHookWiring()` are consumed by `HookExecutor` and `HookListener` for runtime execution. The hook configuration format (event, engine, workflowId, mode, order, timeout, onFailure, etc.) is defined by the schema-hooks spec.
- **workflow-integration** -- Defines the broader workflow automation infrastructure including event triggers, workflow monitoring, and approval chains that build on the import-deployed workflows.

## Specificity Assessment
- **Specific enough to implement?** Yes -- the spec is detailed with clear import/export scenarios, the three-phase processing pipeline, hash-based idempotency, error handling, and edge cases.
- **Missing/ambiguous:**
  - No specification for workflow definition schema validation before deployment (should definitions be validated against engine-specific schemas?)
  - No specification for the `"importing"` and `"imported"` event types (pre/post-import hooks are specified but not yet mapped in `HookExecutor::resolveEventType()`)
  - No specification for batch workflow payload format (how to send multiple objects in one CloudEvent)
  - No specification for import pause/resume state persistence (where is `lastProcessedRow` stored?)
- **Open questions:**
  - Should workflow definitions be validated against engine-specific schemas before deployment?
  - How should workflow versions relate to schema configuration versions?
  - Should `DeployedWorkflow` cleanup cascade when a register or schema is deleted?
  - Should batch workflow execution use a separate adapter method or reuse `executeWorkflow()` with an array payload?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `ImportHandler` processes the `components.workflows` array in a three-phase pipeline (deployment, hook wiring, object creation). Deploys via `WorkflowEngineInterface::deployWorkflow()` through `WorkflowEngineRegistry`. Supports SHA-256 hash-based idempotent re-import via `DeployedWorkflowMapper`. `ExportHandler` includes deployed workflows in configuration exports via `exportWorkflowsForSchema()`. Hook entries created during import are compatible with `HookExecutor` for runtime execution.
- **Nextcloud Core Integration**: Uses Nextcloud's background job system (`QueuedJob`) for large imports and scheduled imports. Import/export uses NC's file handling infrastructure. The `DeployedWorkflow` entity uses NC's `Entity` base class and `QBMapper` for database access. Engine adapters route through NC's `IAppApiService` for ExApp communication. The `WorkflowEngineRegistry` and adapters are registered via NC's DI container (`IBootstrap::register()`). Hook wiring integrates with NC's PSR-14 event dispatcher via `HookListener`.
- **Recommendation**: Mark as implemented. The import pipeline is well-integrated with NC's job system and database layer. Future enhancements: (1) Add `"importing"`/`"imported"` event types to `HookExecutor::resolveEventType()` for pre/post-import triggers. (2) Implement `DeployedWorkflow` cleanup when registers/schemas are deleted. (3) Consider batch workflow execution mode for large imports with `events=false`.
