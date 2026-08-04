# Retrofit — Frontend coverage: modals (chunk 1, all `@spec exclude`)

The coverage scanner flagged 224 uncovered methods across `src/modals/` Vue components (batch `fw-fe-modals-1`). This change annotates every one of them with a JSDoc `@spec` tag per ADR-003.

## Outcome: 100% exclude (annotation-only)

Modal components are CRUD/admin dialogs: open/close handlers, form-state resets, debounced search loaders, computed display/validation helpers, pagination toggles, and submit handlers that delegate straight to an entity store action or a settings/operations REST endpoint. None of the 224 methods carries a spec-worthy domain contract that isn't already owned elsewhere:

- **Submit handlers** (`saveObject`, `importRegister`, `joinSelectedOrganisation`, `startVectorization`, `createCollection`, …) are thin wrappers that delegate to a store action (`objectStore.saveObject`, `registerStore.importRegister`, `organisationStore.joinOrganisation`) or a settings/SOLR API — the behavioral contract lives in the backend capability / store action, not in the modal shell.
- **Loaders / form-state initializers** (`loadInitialUsers`, `loadConfigSets`, `loadObjectCount`, `loadNextcloudGroups`, …) hydrate select options and dirty-tracking state; they orchestrate UI, they don't define a contract.
- **Computed display & validation helpers** (`getModalTitle`, `formatFileSize`, `metadataProperties`, `getPropertyValidationClass`, `estimatedCost`, …) are pure presentation/derivation.
- **Open/close, watcher, pagination, and toast helpers** are pure UI plumbing.

Every method therefore received `@spec exclude <reason>` with a specific reason. **Zero new REQs are minted.**

Per the retrofit playbook, an all-exclude change carries no spec delta. `--strict` requires a delta, so this change is intentionally delta-less and should not be validated with `--strict` on a (nonexistent) spec delta.

## Counts

- **R (reverse-spec'd / new REQs):** 0
- **E (excluded):** 224
- **New REQs:** 0

## Affected files (21)

- src/modals/application/DeleteApplication.vue (2)
- src/modals/object/CopyObject.vue (2)
- src/modals/object/MassCopyObjects.vue (3)
- src/modals/object/MassDeleteObject.vue (4)
- src/modals/object/UploadObject.vue (7)
- src/modals/object/ViewObject.vue (85)
- src/modals/organisation/JoinOrganisation.vue (11)
- src/modals/organisation/ManageOrganisationRoles.vue (10)
- src/modals/register/ImportRegister.vue (17)
- src/modals/schema/DeleteSchemaObjects.vue (5)
- src/modals/schema/EditSchema.vue (16)
- src/modals/settings/ClearCacheModal.vue (2)
- src/modals/settings/CollectionManagementModal.vue (19)
- src/modals/settings/ConfigSetManagementModal.vue (4)
- src/modals/settings/ConnectionConfigModal.vue (3)
- src/modals/settings/DeleteConfigSetDialog.vue (1)
- src/modals/settings/FileVectorizationModal.vue (9)
- src/modals/settings/FileWarmupModal.vue (6)
- src/modals/settings/InspectIndexModal.vue (11)
- src/modals/settings/ObjectManagementModal.vue (5)
- src/modals/settings/SolrTestResultsModal.vue (2)

## Notes / drifts

- The Vue components use JSDoc `@spec` blocks (above the method, inside the `methods:`/`computed:`/`watch:` object), not PHPDoc. PHPCS blank-line rules do not apply.
- Watcher `handler` functions and Vue lifecycle hooks (`mounted`, `updated`, `beforeDestroy`) were also tagged where present in the batch.
- Several methods were already annotated under prior `retrofit-2026-05-24-2b-modals` tasks (e.g. `ViewObject.vue::_getFileParams`, `deleteApplication`); those were left untouched. Only the 224 still-uncovered methods were tagged here.

Source: `/tmp/or-scan/fw-fe-modals-1.json`, generated 2026-05-25. Retrofit playbook — frontend modal coverage, chunk 1.
