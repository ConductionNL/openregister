# Tasks: workflow-in-import

## 1. Create DeployedWorkflow entity and mapper

### Task 1.1: DeployedWorkflow entity
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-versioning`
- **files**: `openregister/lib/Db/DeployedWorkflow.php`
- **acceptance_criteria**:
  - GIVEN the entity class THEN it has fields: `name`, `engine`, `engineWorkflowId`, `sourceHash`, `attachedSchema`, `attachedEvent`, `importSource`, `version`, `uuid`
  - GIVEN a new DeployedWorkflow THEN `version` defaults to 1
  - GIVEN the entity THEN it extends `\OCP\AppFramework\Db\Entity`
- [x] 1.1 Create `DeployedWorkflow` entity with all fields, column mappings, and getters/setters

### Task 1.2: DeployedWorkflowMapper
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-versioning`
- **files**: `openregister/lib/Db/DeployedWorkflowMapper.php`
- **acceptance_criteria**:
  - GIVEN `findByNameAndEngine('Validate KvK', 'n8n')` WHEN a matching record exists THEN the entity is returned
  - GIVEN `findByNameAndEngine('Unknown', 'n8n')` WHEN no match exists THEN `null` is returned
  - GIVEN `findBySchema('organisation')` THEN all deployed workflows attached to that schema are returned
  - GIVEN `findByImportSource('domains.json')` THEN all deployed workflows from that import are returned
- [x] 1.2 Create `DeployedWorkflowMapper` extending `QBMapper` with `findByNameAndEngine`, `findBySchema`, and `findByImportSource` methods

### Task 1.3: Database migration
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-versioning`
- **files**: `openregister/lib/Migration/VersionXXXXDate.php`
- **acceptance_criteria**:
  - GIVEN the migration runs THEN a table `oc_openregister_deployed_workflows` is created with all required columns
  - GIVEN the table THEN it has a unique index on `(name, engine)`
  - GIVEN the table THEN `attached_schema` and `attached_event` are nullable
- [x] 1.3 Create database migration for the `deployed_workflows` table

---

## 2. Extend import JSON parser to handle workflows section

### Task 2.1: Parse workflows from import JSON
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-extended-import-format`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN an import JSON with a `workflows` array WHEN parsed THEN each entry is validated for required fields (`name`, `engine`, `workflow`)
  - GIVEN an import JSON without a `workflows` key WHEN parsed THEN no workflow processing occurs (backward compatible)
  - GIVEN a workflow entry missing required fields WHEN parsed THEN a validation error is logged and the entry is skipped
- [x] 2.1 Extend `ImportService` JSON parsing to extract and validate the `workflows` section

---

## 3. Implement workflow deployment during import

### Task 3.1: Deploy workflows to engines
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-deployment`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN a workflow with `engine: "n8n"` WHEN processed THEN `WorkflowEngineInterface::deployWorkflow()` is called on the n8n adapter
  - GIVEN a successful deployment THEN the returned engine workflow ID is stored in a `DeployedWorkflow` record
  - GIVEN an engine that is not registered or is down WHEN deployment is attempted THEN an error is logged and the import continues
  - GIVEN a deployment failure THEN the import summary includes the failure details
- [x] 3.1 Add workflow deployment phase to `ImportService` between schema and object processing, using `WorkflowEngineManager` to resolve engine adapters

---

## 4. Implement attachTo hook configuration during import

### Task 4.1: Wire workflow hooks to schemas
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-import-processing`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN a deployed workflow with `attachTo.schema: "organisation"` and `attachTo.event: "creating"` WHEN the schema exists THEN a hook is registered via the hook service
  - GIVEN `attachTo.schema` references a non-existent schema WHEN hook wiring runs THEN a warning is logged and the import continues
  - GIVEN a workflow without an `attachTo` block WHEN hook wiring runs THEN no hook is registered for that workflow
  - GIVEN `attachTo` with optional fields omitted WHEN the hook is registered THEN defaults are used (`mode: "async"`, `order: 0`, `timeout: 30`, `onFailure: "allow"`)
- [x] 4.1 Add hook wiring phase to `ImportService` after workflow deployment, using the schema hooks API to register hooks from `attachTo` configuration

---

## 5. Add hash-based versioning for re-imports

### Task 5.1: Implement idempotent re-import
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-versioning`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN a workflow "Validate KvK" was previously imported with hash "abc123" WHEN re-imported with the same definition THEN the hash matches and the workflow is NOT re-deployed
  - GIVEN the re-import is skipped THEN the import summary shows the workflow as "unchanged"
  - GIVEN a workflow was previously imported WHEN re-imported with a modified definition THEN the hash differs, `updateWorkflow()` is called on the engine adapter, and the version is incremented
  - GIVEN the `attachTo` changed but the workflow definition is identical WHEN re-imported THEN the workflow is NOT re-deployed but the hook wiring IS updated
- [x] 5.1 Before deploying each workflow, compute SHA-256 hash and compare with existing `DeployedWorkflow` record; skip deploy if hashes match; update engine + increment version if different

---

## 6. Extend import summary with workflow results

### Task 6.1: Add workflow results to import response
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-import-summary`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN an import with workflows WHEN it completes THEN the response JSON includes `summary.workflows` with `deployed`, `updated`, `unchanged`, and `failed` arrays
  - GIVEN a workflow that was newly deployed THEN it appears in `deployed` with `name`, `engine`, and `action: "created"`
  - GIVEN a workflow that was updated THEN it appears in `updated` with `action: "updated"`
  - GIVEN a hook attachment warning THEN the response message is "Import completed with warnings" and a `warnings` array is included
- [x] 6.1 Collect workflow processing results throughout the import and include them in the final response summary

---

## 7. Extend export to include workflows

### Task 7.1: Include workflows in schema export
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-export-includes-workflows`
- **files**: `openregister/lib/Service/ExportService.php`
- **acceptance_criteria**:
  - GIVEN a schema "organisation" with 2 attached workflow hooks WHEN exported THEN the JSON includes a `workflows` array with 2 entries
  - GIVEN each exported workflow THEN it includes the full engine-specific definition fetched via `WorkflowEngineInterface::getWorkflow()`
  - GIVEN each exported workflow THEN it includes the `attachTo` configuration matching its hook registration
  - GIVEN a schema with no attached workflows WHEN exported THEN no `workflows` section is included
  - GIVEN an exported file is re-imported THEN workflows are detected as unchanged (round-trip idempotency)
- [x] 7.1 Query `DeployedWorkflowMapper::findBySchema()` during export; fetch workflow definitions from engines; include in export JSON with `attachTo` config

---

## 8. Add workflow-only import support

### Task 8.1: Support import files with only workflows
- **spec_ref**: `specs/workflow-in-import/spec.md#requirement-workflow-only-import`
- **files**: `openregister/lib/Service/ImportService.php`
- **acceptance_criteria**:
  - GIVEN an import file with only a `workflows` section (no `schemas` or `objects`) WHEN imported THEN workflows are deployed and hooks are attached to existing schemas
  - GIVEN a workflow-only import THEN the summary shows zero schemas created and zero objects created
  - GIVEN a workflow-only import where `attachTo` references a non-existent schema THEN a warning is logged and the import completes successfully
- [x] 8.1 Ensure `ImportService` does not require `schemas` or `objects` sections; handle missing sections gracefully while still processing workflows

---

## Verification

- [x] `composer check:strict` passes in openregister
- [x] Import a JSON file with schemas + workflows + objects -- verify all four phases execute in order
- [x] Re-import the same file -- verify workflows show as "unchanged"
- [x] Modify a workflow definition and re-import -- verify it shows as "updated" with incremented version
- [x] Import a file with a workflow targeting an unavailable engine -- verify non-fatal error in summary
- [x] Import a workflow-only file -- verify deployment without schema/object creation
- [x] Export a schema with hooks -- verify the exported JSON includes the workflows section
- [x] Round-trip test: export then re-import -- verify idempotent (no redundant deployments)
