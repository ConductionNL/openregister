# Workflow-in-Import Specification

## Purpose

Extends the OpenRegister JSON import pipeline to deploy workflow definitions to engines, wire them as schema hooks, and track them for versioning -- all from a single import file.

---

## ADDED Requirements

### Requirement: Extended Import Format

The JSON import format SHALL support an optional `workflows` array. Each entry contains a workflow name, target engine, the engine-native workflow definition, and an optional `attachTo` block for hook wiring.

#### Scenario: import file includes workflows section

- GIVEN an import JSON file
- WHEN the file contains a `workflows` array with valid entries
- THEN the import pipeline accepts and processes the workflows section
- AND each entry has required fields: `name`, `engine`, `workflow`
- AND each entry may optionally include `description` and `attachTo`

#### Scenario: import file without workflows section

- GIVEN an import JSON file without a `workflows` key
- WHEN the import is executed
- THEN the import proceeds as before (backward compatible)
- AND no workflow processing occurs

#### Scenario: workflow entry with attachTo

- GIVEN a workflow entry with an `attachTo` block containing `schema`, `event`, and `mode`
- WHEN the import processes this entry
- THEN the workflow is deployed to its engine AND a schema hook is configured
- AND optional `attachTo` fields (`order`, `timeout`, `onFailure`, `onTimeout`, `onEngineDown`) use their defaults when omitted

#### Scenario: workflow entry without attachTo

- GIVEN a workflow entry without an `attachTo` block
- WHEN the import processes this entry
- THEN the workflow is deployed to its engine
- AND no schema hook is configured
- AND the workflow is still tracked as a `DeployedWorkflow`

---

### Requirement: Workflow Import Processing

Workflows SHALL be processed after schemas and before objects. This ensures schemas exist for hook wiring and hooks are active when objects are created.

#### Scenario: import file with schemas, workflows, and objects

- GIVEN an import file containing schemas, workflows, and objects
- WHEN the import is executed
- THEN schemas are created/updated first
- AND workflows are deployed to their engines second
- AND schema hooks are configured from `attachTo` third
- AND objects are created fourth (with hooks now active)

#### Scenario: workflow references non-existent schema

- GIVEN a workflow with `attachTo.schema: "organisation"`
- WHEN the import runs and "organisation" schema does not exist (and is not in the import)
- THEN the workflow is still deployed to the engine
- AND a warning is logged that the hook could not be attached
- AND the import continues (non-fatal)

#### Scenario: workflow references schema from same import

- GIVEN a workflow with `attachTo.schema: "organisation"`
- WHEN the import file also contains a schema named "organisation"
- THEN the schema is created first
- AND the workflow is deployed second
- AND the hook is successfully attached to the newly created schema

---

### Requirement: Workflow Deployment

Each workflow SHALL be deployed to its specified engine via the `WorkflowEngineInterface`. The engine-returned workflow ID is stored for hook configuration and future reference.

#### Scenario: deploy n8n workflow

- GIVEN a workflow with `engine: "n8n"` and valid n8n JSON in the `workflow` field
- WHEN the import processes workflows
- THEN `WorkflowEngineInterface::deployWorkflow()` is called on the n8n adapter
- AND the returned workflow ID is stored in the `DeployedWorkflow` record
- AND the returned ID is used for hook configuration if `attachTo` is present

#### Scenario: deploy windmill workflow

- GIVEN a workflow with `engine: "windmill"` and valid Windmill flow definition
- WHEN the import processes workflows
- THEN `WorkflowEngineInterface::deployWorkflow()` is called on the Windmill adapter
- AND the returned workflow ID is stored

#### Scenario: engine not available

- GIVEN a workflow targeting engine "windmill"
- WHEN the Windmill engine is not registered or is down
- THEN the import logs an error for that workflow
- AND continues processing remaining workflows and objects
- AND the import summary includes the failure

#### Scenario: invalid workflow definition

- GIVEN a workflow with malformed engine-specific JSON
- WHEN `deployWorkflow()` is called
- THEN the engine adapter returns an error
- AND the error is logged and included in the import summary
- AND the import continues with remaining workflows

---

### Requirement: Workflow Versioning

Imported workflows SHALL be tracked via a `DeployedWorkflow` entity for update detection and cleanup. A SHA-256 hash of the workflow definition enables idempotent re-imports.

#### Scenario: re-import updated workflow

- GIVEN a workflow "Validate Organisation KvK" was previously imported
- WHEN the same import file is re-imported with a modified workflow definition
- THEN the source hash is compared to the stored hash
- AND because they differ, `WorkflowEngineInterface::updateWorkflow()` is called
- AND the `DeployedWorkflow` version is incremented
- AND the hash is updated to the new value
- AND the hook configuration is updated if `attachTo` changed

#### Scenario: re-import unchanged workflow

- GIVEN a workflow was previously imported with hash "abc123"
- WHEN the same import file is re-imported with an identical workflow definition
- THEN the computed hash matches the stored hash
- AND the workflow is NOT re-deployed to the engine (idempotent)
- AND the import summary shows it as "unchanged"

#### Scenario: first import of a workflow

- GIVEN a workflow "Send Welcome Email" has never been imported
- WHEN the import processes this workflow
- THEN a new `DeployedWorkflow` record is created with version 1
- AND the SHA-256 hash of the workflow definition is stored
- AND the import source (filename or identifier) is recorded

---

### Requirement: Workflow-only Import

It SHALL be possible to import a file containing only a `workflows` section (no schemas or objects).

#### Scenario: deploy workflows without data

- GIVEN an import file with only a `workflows` section (no `schemas` or `objects`)
- WHEN the import is executed
- THEN workflows are deployed to their engines
- AND hooks are attached to existing schemas (if `attachTo` references existing schemas)
- AND no schemas or objects are created
- AND the import summary reflects zero schemas and zero objects

#### Scenario: workflow-only import with non-existent schema reference

- GIVEN a workflow-only import where `attachTo.schema` references a schema that does not exist
- WHEN the import is executed
- THEN the workflow is deployed to the engine
- AND a warning is logged for the unresolvable hook target
- AND the import completes successfully

---

### Requirement: Import Summary

The import response SHALL include workflow deployment results alongside schema and object counts.

#### Scenario: mixed import results

- GIVEN an import with 3 workflows (1 new, 1 updated, 1 failed)
- WHEN the import completes
- THEN the response includes a `workflows` section in the summary
- AND `deployed` lists workflows that were newly created with their name, engine, and action "created"
- AND `updated` lists workflows that were re-deployed with action "updated"
- AND `failed` lists workflows that could not be deployed with their name, engine, and error message

#### Scenario: all workflows unchanged

- GIVEN an import where all workflows have matching hashes
- WHEN the import completes
- THEN the summary includes an `unchanged` list with the workflow names
- AND `deployed`, `updated`, and `failed` are empty arrays

#### Scenario: import with warnings

- GIVEN an import where a workflow deployed successfully but its `attachTo` schema was not found
- WHEN the import completes
- THEN the overall message is "Import completed with warnings"
- AND the workflow appears in `deployed` (it was deployed to the engine)
- AND a separate `warnings` array includes the hook attachment failure

---

### Requirement: Export Includes Workflows

When exporting schemas, deployed workflows attached to those schemas SHALL be included in the export JSON.

#### Scenario: export schema with hooks

- GIVEN a schema "organisation" with 2 attached workflow hooks
- WHEN the schema is exported
- THEN the export JSON includes a `workflows` section
- AND each workflow includes the full engine-specific definition fetched from the engine via `WorkflowEngineInterface::getWorkflow()`
- AND each workflow includes the `attachTo` configuration matching its hook registration

#### Scenario: export schema without hooks

- GIVEN a schema "address" with no attached workflow hooks
- WHEN the schema is exported
- THEN the export JSON does not include a `workflows` section (or includes an empty array)

#### Scenario: export round-trip

- GIVEN a schema was imported with workflows from a file
- WHEN the schema is exported and the resulting JSON is re-imported
- THEN the re-import detects unchanged workflows (matching hashes)
- AND no redundant deployments occur
