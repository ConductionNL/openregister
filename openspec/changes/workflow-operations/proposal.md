## Why

Administrators want to automate the connection between Nextcloud file events and OpenRegister operations without leaving the Nextcloud admin interface. Currently, linking files to register objects, triggering entity detection on uploaded documents, or creating objects from file uploads requires either manual action or building custom n8n workflows. Nextcloud's built-in Workflow Engine (Flow) provides a user-friendly, zero-code way to define these rules (e.g., "When a file is uploaded to folder X, create a register object" or "When a file is tagged 'confidential', run entity detection"), but OpenRegister does not yet register any operations or checks with it.

## What Changes

- Register four new Workflow Engine operations that expose OpenRegister capabilities as Flow actions:
  - **Create Register Object** — creates a new object in a specified register/schema when a file event fires, with configurable field mappings and the file attached
  - **Link to Register Object** — links a file to an existing register object using configurable matching strategies (by folder, metadata, or file name pattern)
  - **Run Entity Detection** — extracts text from a file and runs entity recognition (regex, presidio, LLM) on the content
  - **Execute Action** — runs a registered action from the action registry with the file context
- Register two new Workflow Engine checks (conditions) for use in Flow rules:
  - **File has linked entities** — evaluates whether a file has OpenRegister entities detected, with entity type and confidence threshold filters
  - **File is linked to object** — evaluates whether a file is linked to any register object, with optional register/schema filter
- Listen to `RegisterOperationsEvent` in `Application.php` to register all operations and checks with the Workflow Engine
- Add Vue configuration components for each operation, loaded lazily via `src/workflow.js` entry point
- Heavy operations (entity detection) dispatch background jobs; light operations (link to object) run synchronously within a 5s budget

## Capabilities

### New Capabilities
- `workflow-operations`: Registration of OpenRegister operations (Create Object, Link to Object, Run Entity Detection, Execute Action) and checks (Has Entities, Has Object) with Nextcloud's Workflow Engine, including Vue configuration UI components and background job dispatching for heavy operations

### Modified Capabilities
- `event-driven-architecture`: The Workflow Engine operations consume file-based Nextcloud events (file created, updated, tagged, shared) rather than OpenRegister object events; the existing event infrastructure needs to bridge file events to OpenRegister service calls

## Impact

- **New PHP classes**: `OCA\OpenRegister\Workflow\CreateObjectOperation`, `LinkToObjectOperation`, `EntityDetectionOperation`, `ExecuteActionOperation`, `HasEntitiesCheck`, `HasObjectCheck`, plus a `RegisterWorkflowListener` for `RegisterOperationsEvent`
- **New Vue components**: Configuration UIs for each operation (register/schema selector, field mappings, matching strategy, detection method toggles, action picker)
- **New webpack entry**: `src/workflow.js` for lazy-loaded Workflow admin UI components
- **Dependencies**: `action-registry` change (for Execute Action operation); Nextcloud Workflow Engine (`workflowengine` core app, always available)
- **Existing services used**: `ObjectService` (create/link objects), `TextExtractionService` (entity detection), `ActionRegistryService` (action execution)
- **Background jobs**: New `TimedJob` or `QueuedJob` subclass for async entity detection operations
- **No database migrations**: Operations store their configuration in the Workflow Engine's own `oc_flow_operations` table
- **No breaking changes**: All additions are opt-in; existing behavior is unaffected
