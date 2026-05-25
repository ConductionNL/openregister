# Tasks — Frontend coverage: modals (chunk 1)

All-exclude annotation change. No REQs minted, so there is no spec delta and no `--strict` validation step.

## Annotation tasks

- [x] task-1: Tag all 224 batch methods under `src/modals/` with JSDoc `@spec exclude <reason>` (ADR-003).
- [x] task-2: Verify 0 untagged methods remain in the batch.
- [x] task-3: Confirm every added line is comment-only (no functional code change).

## Per-file completion

- [x] DeleteApplication.vue — 2 excluded
- [x] CopyObject.vue — 2 excluded
- [x] MassCopyObjects.vue — 3 excluded
- [x] MassDeleteObject.vue — 4 excluded
- [x] UploadObject.vue — 7 excluded
- [x] ViewObject.vue — 85 excluded
- [x] JoinOrganisation.vue — 11 excluded
- [x] ManageOrganisationRoles.vue — 10 excluded
- [x] ImportRegister.vue — 17 excluded
- [x] DeleteSchemaObjects.vue — 5 excluded
- [x] EditSchema.vue — 16 excluded
- [x] ClearCacheModal.vue — 2 excluded
- [x] CollectionManagementModal.vue — 19 excluded
- [x] ConfigSetManagementModal.vue — 4 excluded
- [x] ConnectionConfigModal.vue — 3 excluded
- [x] DeleteConfigSetDialog.vue — 1 excluded
- [x] FileVectorizationModal.vue — 9 excluded
- [x] FileWarmupModal.vue — 6 excluded
- [x] InspectIndexModal.vue — 11 excluded
- [x] ObjectManagementModal.vue — 5 excluded
- [x] SolrTestResultsModal.vue — 2 excluded

Total: 224 excluded / 0 reverse-spec'd / 0 new REQs.
