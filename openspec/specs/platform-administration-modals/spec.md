---
retrofit: true
status: done
---

# Platform Administration Modals

## Purpose

Describes the administrator-facing modal dialogs that configure OpenRegister's platform-level infrastructure (SOLR search backend, LLM provider wiring, configuration sets, collection assignments, vectorization, faceting, file management) and that drive long-running operational tasks (cache clear, index warmup, mass validation, index inspection, SOLR setup). Unlike the entity-management modals — which mutate user data via per-entity stores — these modals read and write platform settings via `/api/settings/*` REST endpoints and dispatch background operational jobs.

Each modal in this capability presents an admin form or run-results view, hits a settings endpoint or operations endpoint via `axios`, surfaces progress / streaming results, and persists configuration changes that affect the whole tenant rather than a single object.

**OpenSpec changes**:
- [retrofit-2026-05-24-2b-modals](../../changes/retrofit-2026-05-24-2b-modals/)

## Requirements

### Requirement: Settings modals SHALL load configuration from the settings API on open and persist edits via the matching settings endpoint

Settings modals (`LLMConfigModal.vue`, `CollectionManagementModal.vue`, `ConfigSetManagementModal.vue`, `ConnectionConfigModal.vue`, `ObjectManagementModal.vue`, `ObjectVectorizationModal.vue`, `FacetConfigModal.vue`, `FileManagementModal.vue`, `CreateConfigSetDialog.vue`, `DeleteConfigSetDialog.vue`, `DeleteCollectionModal.vue`, `RebaseConfirmationModal.vue`) MUST:
1. On open, invoke a `loadConfiguration()` (or domain-specific `loadCollections()` / `loadConfigSets()` / `loadAvailableFields()`) method that GETs the current platform configuration from `/apps/openregister/api/settings/{area}`.
2. Maintain edits in modal-local state until the user confirms.
3. On confirm, persist via PUT/POST to the same `/api/settings/{area}` endpoint and update local state only after the server returns success.
4. Show a loading indicator during both load and save, and surface server-side errors in an error region with a Retry button rather than closing the modal silently.

#### Scenario: LLM modal loads existing settings on open
- **GIVEN** the administrator opens `LLMConfigModal.vue` with `show=true`
- **WHEN** `loadConfiguration()` runs on mount
- **THEN** the modal MUST GET `/apps/openregister/api/settings/llm`
- **AND** populate `llmEnabled`, `selectedEmbeddingProvider`, `selectedChatProvider`, `openaiConfig`, and `ollamaConfig` from the response

#### Scenario: Settings save preserves modal on backend failure
- **GIVEN** the administrator clicks Save on `CollectionManagementModal.vue` and the settings endpoint returns 500
- **WHEN** the save promise rejects
- **THEN** the modal MUST remain open, render the error message, and offer a Retry action
- **AND** the modal MUST NOT optimistically apply the failed change to local state

### Requirement: Operational-task modals SHALL run long-lived jobs against operations endpoints and stream per-step results back to the dialog

Operational-task modals (`ClearCacheModal.vue`, `ClearIndexModal.vue`, `SolrWarmupModal.vue`, `SolrSetupResultsModal.vue`, `SolrTestResultsModal.vue`, `InspectIndexModal.vue`, `MassValidateModal.vue`, `ValidateSchema.vue`) MUST:
1. Disable the close button while the job is running (`:can-close="!warmingUp"`).
2. Invoke a job-start method (`confirmClear()`, `startWarmup()`, `startMassValidate()`, etc.) that POSTs to the matching operations endpoint and tracks the resulting run state in `loading` / `running` / `completed` flags.
3. On completion, display per-step status (`getStepStatus()` for setup, `formatComponentName()` for tests) with explicit success / error visual indicators.
4. Block re-entry until the operation finishes; never fire a second job from the same modal instance while one is still running.
5. Allow the user to close the modal only after the operation reaches a terminal state.

#### Scenario: SOLR warmup blocks close while running
- **GIVEN** the administrator clicks Start on `SolrWarmupModal.vue`
- **WHEN** `startWarmup()` posts to the warmup endpoint and `warmingUp=true`
- **THEN** the dialog's `can-close` prop MUST be `false`
- **AND** the start button MUST be disabled until the response resolves

#### Scenario: Setup results render per-step status
- **GIVEN** `SolrSetupResultsModal.vue` receives a results object with five steps where step 3 failed
- **WHEN** the modal renders
- **THEN** `getStepStatus()` MUST return a distinct visual state (success / failure) per step
- **AND** the failure step MUST display its error detail rather than a generic message

#### Scenario: Clear cache returns to idle on success
- **GIVEN** the administrator confirms `ClearCacheModal.vue`
- **WHEN** `confirmClear()` resolves successfully
- **THEN** the modal MUST display a success message, re-enable the close button, and emit the close event
- **AND** subsequent reopen of the modal MUST start in idle state (no stale results)

## Cross-References
- **faceting-configuration** — `FacetConfigModal.vue` writes facet definitions consumed by the faceting capability
- **mcp-discovery** — `LLMConfigModal.vue` configures the chat/embedding providers used by MCP-driven flows
- **data-import-export** — `UploadObject.vue` and import-related operational modals feed the import pipeline
