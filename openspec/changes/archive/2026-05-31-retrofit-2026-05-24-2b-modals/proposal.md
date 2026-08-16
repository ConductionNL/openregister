# Retrofit — Vue modal components (Bucket 2b: modals/)

The coverage scanner flagged 71 methods across 49 Vue files under `src/modals/` as Bucket 2b (no existing capability matched). The directory path is structural, not behavioral, so this change introduces **two** new behavioral capabilities and annotates the existing modal components retroactively.

## Why two capabilities?

Inspecting method bodies revealed two cleanly distinct domains:

1. **`entity-management-modals`** — CRUD and bulk-action modals for first-class register entities (agents, applications, configurations, objects, organisations, endpoints, sources, views, webhooks, soft-deleted records). These modals are thin UI shells over entity stores; they hydrate from `{entity}Store.{entity}Item`, mutate via store actions, and reset the `navigationStore.dialog` on close. All ~50 methods under `src/modals/{agent,application,configuration,deleted,endpoint,object,organisation,source,view,webhook}/` follow the identical Edit / Delete / View / Bulk pattern.

2. **`platform-administration-modals`** — Admin-facing modals that read/write platform configuration (`/api/settings/llm`, `/api/settings/collections`, ConfigSets, FacetConfig, FileManagement) or drive long-running operational jobs (SOLR warmup, cache clear, mass-validate, index inspection). The ~21 methods under `src/modals/settings/` hit settings/operations REST endpoints directly via `axios` instead of going through entity stores, and several block the close button while a background job runs — semantics that don't fit the entity-CRUD pattern.

Combining them into a single "ui-modal-system" capability would have obscured the very different concerns (entity-CRUD vs. platform-admin) and made future requirements harder to attribute. Splitting at 3 + 2 = 5 REQs keeps the spec-side surface flat and within the 5-REQ budget.

## Affected files

### entity-management-modals (~50 methods, 35 files)
- src/modals/agent/{DeleteAgent,EditAgent}.vue
- src/modals/application/{DeleteApplication,EditApplication}.vue
- src/modals/configuration/{DeleteConfiguration,EditConfiguration,PreviewConfiguration,PublishConfiguration,ViewConfiguration}.vue
- src/modals/deleted/{PurgeMultiple,RestoreMultiple}.vue
- src/modals/endpoint/{DeleteEndpoint,EditEndpoint}.vue
- src/modals/object/{CopyObject,DeleteObject,DownloadObject,LockObject,MassCopyObjects,MassDeleteObject,MassValidateObjects,MigrationObject,UploadObject,ViewObject}.vue
- src/modals/organisation/{DeleteOrganisation,EditOrganisation,JoinOrganisation,SwitchOrganisationModal}.vue
- src/modals/source/{DeleteSource,EditSource,ViewSource}.vue
- src/modals/view/{DeleteView,EditView}.vue
- src/modals/webhook/{EditWebhook,ViewWebhookLog}.vue

### platform-administration-modals (~21 methods, 14 files)
- src/modals/settings/ClearCacheModal.vue
- src/modals/settings/CollectionManagementModal.vue
- src/modals/settings/ConfigSetManagementModal.vue
- src/modals/settings/ConnectionConfigModal.vue
- src/modals/settings/CreateConfigSetDialog.vue
- src/modals/settings/DeleteCollectionModal.vue
- src/modals/settings/DeleteConfigSetDialog.vue
- src/modals/settings/InspectIndexModal.vue
- src/modals/settings/LLMConfigModal.vue
- src/modals/settings/MassValidateModal.vue
- src/modals/settings/ObjectManagementModal.vue
- src/modals/settings/ObjectVectorizationModal.vue
- src/modals/settings/SolrSetupResultsModal.vue
- src/modals/settings/SolrTestResultsModal.vue
- src/modals/settings/SolrWarmupModal.vue

## Approach

- Two new capabilities created with `status: implemented` and `retrofit: true` front-matter.
- Both capabilities collectively define **5** REQs (3 in entity-management-modals, 2 in platform-administration-modals).
- Per-method `@spec` JSDoc annotations are added to every real method in the batch.
- Methods that don't represent real behavior are dropped (see Notes).

## Notes / drifts

- **22 of the 71 "methods" in the batch JSON are spurious `if` matches** from the scanner picking up inline `if (...)` statements inside `methods:` blocks (e.g. `if (newValue === 'editAgent')` at the top of an `initializeAgent()`). These are dropped — they are not real methods.
- **Real method count: ~49**, matching the file count almost 1:1.
- The Vue templates use JSDoc `@spec` style annotation rather than PHPDoc — the comment block sits above the method inside the Vue `methods:` object, formatted as a standard JSDoc block. PHPCS blank-line rules do not apply to Vue.
- A handful of behavioral edges live in store-method calls that already belong to other capabilities (e.g. `objectStore.deleteObject()` flows through `object-lifecycle#REQ-001`). The modal `confirmDelete()` wrapper is annotated under `entity-management-modals#REQ-002` for its UI-orchestration role; the underlying mutation stays with `object-lifecycle`.
- Some modals (e.g. `EditOrganisation.vue::searchGroups`) call Nextcloud OCS APIs directly for user/group autocomplete; these stay under `entity-management-modals#REQ-001` because the orchestration (load on open, validate on input, surface error) is the modal-pattern behavior we're capturing, not the OCS contract itself.
- `ViewObject.vue::_getFileParams` is a private rendering helper; annotated under `entity-management-modals#REQ-002` (view modal pattern) for now.

Source: `/tmp/or-scan/rspec-2b-modals.json` cluster=modals, generated 2026-05-24. Retrofit playbook step 2b (no existing capability matched).
