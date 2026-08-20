---
retrofit: true
status: done
---

# Entity Management Modals

## Purpose

Describes the user-facing modal dialog components that mediate create / read / update / delete and bulk operations on first-class register entities (registers, schemas, objects, applications, organisations, configurations, endpoints, sources, views, webhooks, soft-deleted records, audit-trail entries, files). Every register entity in the OpenRegister UI is mutated through a small family of modal Vue components (`Edit{Entity}.vue`, `Delete{Entity}.vue`, `View{Entity}.vue`, plus per-entity bulk variants) that share a consistent open / load / submit / close / error-handling lifecycle driven by the `navigationStore` dialog state and the corresponding entity store.

This is the canonical spec for modal-based entity management UI. Backend CRUD endpoints are specified in their own per-entity capabilities; this spec describes only the modal lifecycle, validation surfacing, and dialog wiring that connects user input to those endpoints.

**OpenSpec changes**:
- [retrofit-2026-05-24-2b-modals](../../changes/retrofit-2026-05-24-2b-modals/)

## Requirements

### Requirement: Entity create/edit modals SHALL load context on mount, submit through the entity store, and surface validation errors

Every `Edit{Entity}.vue` modal (e.g. `EditApplication.vue`, `EditConfiguration.vue`, `EditEndpoint.vue`, `EditOrganisation.vue`, `EditSource.vue`, `EditView.vue`, `EditWebhook.vue`) MUST:
1. Run an `initialize{Entity}()` (or equivalent `loadX()`) method on mount or when the matching `navigationStore.dialog` value is selected, hydrating local form state from the entity store's currently-selected item.
2. Run an `async save{Entity}()` (or equivalent `update*` / `*from*` action) that delegates persistence to the entity store and awaits the response.
3. Set `loading=true` before the call and `loading=false` in `finally`, disable the submit button while loading, and surface store-returned errors in a dedicated error state rather than throwing.
4. Close the dialog by calling `navigationStore.setDialog(null|false)` only on a successful save.

#### Scenario: Open edit modal hydrates from store
- **GIVEN** `sourceStore.sourceItem` is set and `navigationStore.dialog === 'editSource'`
- **WHEN** `EditSource.vue` mounts
- **THEN** `initializeSourceItem()` MUST copy the store item into the modal's local form state
- **AND** related collections MUST be hydrated as arrays

#### Scenario: Save success closes the dialog
- **GIVEN** the user has edited the source form and clicks Save
- **WHEN** `saveSource()` resolves successfully
- **THEN** the modal MUST call `navigationStore.setDialog(null)` exactly once
- **AND** `loading` MUST be reset to `false` in the `finally` block

#### Scenario: Save failure keeps the dialog open
- **GIVEN** the entity store throws or returns a validation error
- **WHEN** `save{Entity}()` catches the error
- **THEN** the dialog MUST remain open
- **AND** the error MUST be logged or rendered in an error region rather than swallowed silently

### Requirement: Entity delete and single-object action modals SHALL confirm intent and delegate the mutation to the entity store

Every `Delete{Entity}.vue` modal (e.g. `DeleteApplication.vue`, `DeleteConfiguration.vue`, `DeleteEndpoint.vue`, `DeleteOrganisation.vue`, `DeleteSource.vue`, `DeleteView.vue`, `DeleteObject.vue`) and the single-object action modals (`CopyObject.vue`, `DownloadObject.vue`, `LockObject.vue`, `PublishConfiguration.vue`, `PreviewConfiguration.vue`) MUST present a confirmation prompt, expose a single async handler (`confirmDelete()`, `delete{Entity}()`, `copyObject()`, `loadPreview()`, etc.), and delegate the actual mutation or fetch to the matching entity store action against the currently-selected item.

#### Scenario: Confirm delete on a single source
- **GIVEN** `sourceStore.sourceItem` is set and the user clicks the delete confirm button on `DeleteSource.vue`
- **WHEN** the modal's `deleteSource()` confirm handler runs
- **THEN** the modal MUST call `sourceStore.deleteSource(sourceStore.sourceItem)`
- **AND** on success it MUST call `closeDialog()` which sets `navigationStore.setDialog(null)`

#### Scenario: Copy single object names the duplicate
- **GIVEN** the user opens `CopyObject.vue` for object `abc-123` and enters a new name
- **WHEN** `copyObject()` runs
- **THEN** the modal MUST strip server-managed metadata (`id`, `@self.id`, `@self.uuid`, `@self.uri`, `@self.created`, `@self.updated`, `@self.version`) from the source object before delegating to the object store save action
- **AND** the new name MUST be written into `@self.name`

#### Scenario: Delete failure preserves dialog and selection
- **GIVEN** `applicationStore.deleteApplication()` rejects
- **WHEN** `DeleteApplication.vue::deleteApplication()` catches the rejection
- **THEN** the dialog MUST stay open and the entity MUST remain selected for retry
- **AND** the loading indicator MUST be cleared

### Requirement: Bulk-action modals SHALL operate over a selection set staged in the entity store and report per-item outcomes

Bulk-operation modals (`MassDeleteObject.vue`, `MassCopyObjects.vue`, `MassValidateObjects.vue`, `PurgeMultiple.vue`, `RestoreMultiple.vue`, `MigrationObject.vue`, `UploadObject.vue`) MUST:
1. Read the staged selection from the entity store (`deletedStore.selectedForBulkAction`, `objectStore.selectedForBulkAction`, etc.) on mount via an `initializeSelection()` (or `initializeMigration()` / `initializeMappings()`) method.
2. Allow the user to remove individual items from the selection before submitting.
3. Submit through a bulk store action and aggregate the returned `{processed, failed, skipped}` counts into a user-facing success message.
4. Auto-close after a short delay on full success, or remain open and display the per-item failure list on partial success.
5. Clear the staged selection on close via `clearSelectedForBulkAction()` (or equivalent).

#### Scenario: Initialize purge selection from store
- **GIVEN** `deletedStore.selectedForBulkAction` contains 5 soft-deleted objects
- **WHEN** `PurgeMultiple.vue` mounts
- **THEN** `initializeSelection()` MUST populate `selectedObjects` with those 5 items
- **AND** if the staged selection is empty the modal MUST close immediately

#### Scenario: Bulk delete reports partial success
@e2e exclude bulk partial-failure (mixed deleted/failed result) needs an orchestrated backend failure — covered by PHPUnit; not deterministically reproducible via the UI
- **GIVEN** the user submits a mass-delete over 50 objects and the backend returns `{deleted: 47, failed: 3}`
- **WHEN** the bulk modal resolves
- **THEN** the success banner MUST report both the success count (47) and the failure count (3)
- **AND** the modal MUST NOT auto-close while failures are visible

## Cross-References
- **object-lifecycle** — modal save actions ultimately call the object save pipeline described there
- **rbac-scopes** — store actions invoked from modals are subject to RBAC checks before persistence
- **register-i18n** — modal labels and success/error strings are translated via `@nextcloud/l10n`
