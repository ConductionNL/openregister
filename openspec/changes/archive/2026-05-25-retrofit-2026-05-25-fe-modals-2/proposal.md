# Retrofit — frontend coverage: modals (chunk 2)

The coverage scanner flagged **224 uncovered methods** across Vue components under
`src/modals/` as still missing `@spec` traceability. These are the leftover
methods in modal files that were partially annotated by
`retrofit-2026-05-24-2b-modals` — the prior pass tagged the obvious entity-CRUD
entry points (Edit/Delete/View) but left the surrounding UI plumbing untagged.

Per ADR-003 §Spec traceability, every public method needs a `@spec` tag. Per the
retrofit playbook and ADR-003 exclude convention, frontend modal plumbing
(open/close handlers, form-field update setters, computed display helpers,
watchers, validation guards, value formatters, search/autocomplete callbacks,
multi-step wizard navigation) is **boilerplate UI orchestration** and is tagged
`@spec exclude <reason>` rather than minting new behavioral requirements.

## Counts

- **Methods in batch:** 224
- **Spec'd (linked to a REQ):** 0
- **Excluded (`@spec exclude`):** 224
- **New REQs minted:** 0

All 224 methods are UI plumbing: dialog lifecycle, reactive form setters,
computed/display helpers, value/byte/date formatters, wizard step navigation,
multi-select toggles, file-picker handlers, search-store passthroughs, and
operational-job progress readouts. None introduces a novel user-facing domain
contract beyond what `entity-management-modals` and `platform-administration-modals`
(created in the 2b-modals change) already capture. This change is therefore a
**delta-less, all-exclude annotation pass** — no `specs/` delta, so it is not
subject to `--strict` spec validation.

## Affected files

- src/modals/agent/DeleteAgent.vue
- src/modals/configuration/{DeleteConfiguration,EditConfiguration,ImportConfiguration,PreviewConfiguration}.vue
- src/modals/deleted/{PurgeMultiple,RestoreMultiple}.vue
- src/modals/file/UploadFiles.vue
- src/modals/object/{DownloadObject,LockObject,MergeObject,MigrationObject}.vue
- src/modals/organisation/{DeleteOrganisation,EditOrganisation}.vue
- src/modals/register/ExportRegister.vue
- src/modals/schema/DeleteSchemaProperty.vue
- src/modals/settings/{CreateConfigSetDialog,DeleteCollectionModal,MassValidateModal,ObjectVectorizationModal,SolrSetupResultsModal}.vue
- src/modals/source/DeleteSource.vue
- src/modals/view/EditView.vue
- src/modals/webhook/ViewWebhookLog.vue

## Approach

- No new capabilities, no `specs/` delta.
- Every one of the 224 methods receives a `@spec exclude <reason>` JSDoc tag with
  a required, specific reason.
- Edit tool only; no logic changes, no refactors, no formatting cleanups.

Source: `/tmp/or-scan/fw-fe-modals-2.json`, generated 2026-05-25.
