# Retrofit — frontend coverage: modals (chunk 3)

The coverage scanner flagged 224 uncovered methods across 24 Vue files under `src/modals/` (batch `fw-fe-modals-3`). This change brings every one of those methods under the ADR-003 spec-traceability convention by adding a per-method `@spec` JSDoc tag.

## Outcome

- **Reverse-spec'd (new REQs): 0**
- **Excluded (`@spec exclude <reason>`): 224**
- **New REQs minted: 0**

Per the frontend-retrofit strategy, modal methods are overwhelmingly UI plumbing — form-field bindings, modal open/close lifecycle, data-load fetches for pickers, display/format helpers, and thin store-delegating save/delete actions. None of these 224 methods represent a novel user-facing domain contract that is not already owned by an existing capability (the underlying domain mutations live in store actions specified under `entity-management-modals`, `object-lifecycle`, `platform-administration-modals`, etc.). Accordingly every method is annotated `@spec exclude <reason>` and no spec delta is produced.

## Exclude categories used

The reason text on each `@spec exclude` falls into a small set of plumbing categories:

- **Vue lifecycle hook** — `mounted` / `created` / `updated` that hydrate the modal.
- **Form-field binding** — `update*` / `add*` / `remove*` / `toggle*` that mutate local form state.
- **Modal open/close plumbing** — `closeModal` / `closeDialog` / `handleDialogClose` resetting `navigationStore`.
- **Modal data-load plumbing** — `fetch*` / `load*` populating pickers and counts.
- **Modal save/action plumbing** — `save*` / `delete*` / `publish*` / `validate*` delegating to store actions or settings/ops endpoints.
- **UI display / state / validation helpers** — computed properties and `format*` / `get*` / `*Options` helpers, plus `is*` / `can*` / `has*` guards.
- **UI watchers / event handlers** — `watch` handlers and click/change handlers.

## Affected files (24)

- src/modals/agent/EditAgent.vue (20)
- src/modals/application/EditApplication.vue (18)
- src/modals/configuration/ExportConfiguration.vue (5)
- src/modals/configuration/PublishConfiguration.vue (10)
- src/modals/configuration/ViewConfiguration.vue (3)
- src/modals/logs/ClearAuditTrails.vue (4)
- src/modals/object/DeleteObject.vue (1)
- src/modals/object/MassValidateObjects.vue (3)
- src/modals/objectAuditTrail/ViewObjectAuditTrail.vue (1)
- src/modals/register/DeleteRegister.vue (2)
- src/modals/register/PublishRegister.vue (11)
- src/modals/schema/DeleteSchema.vue (5)
- src/modals/schema/EditSchemaProperty.vue (28)
- src/modals/schema/ExploreSchema.vue (24)
- src/modals/schema/UploadSchema.vue (4)
- src/modals/schema/ValidateSchema.vue (7)
- src/modals/settings/FacetConfigModal.vue (5)
- src/modals/settings/FileManagementModal.vue (2)
- src/modals/settings/LLMConfigModal.vue (13)
- src/modals/settings/SolrWarmupModal.vue (17)
- src/modals/source/EditSource.vue (3)
- src/modals/source/ViewSource.vue (10)
- src/modals/view/DeleteView.vue (1)
- src/modals/webhook/EditWebhook.vue (27)

## Notes / drifts

- **Two files carried a duplicate `handler` watcher** the batch JSON listed once: `ValidateSchema.vue` (schemaItem + dialog watchers) and `SolrWarmupModal.vue` (config + localConfig watchers). Both watchers were untagged, so both were annotated — the actual `@spec exclude` count is 226, 2 above the 224 batch methods, with 0 untagged remaining.
- A handful of files already had partial `@spec` tags from `retrofit-2026-05-24-2b-modals` / `retrofit-2026-04-23-annotate-openregister`; this change only touches the still-untagged methods from this batch.

Source: `/tmp/or-scan/fw-fe-modals-3.json`, generated 2026-05-25.
