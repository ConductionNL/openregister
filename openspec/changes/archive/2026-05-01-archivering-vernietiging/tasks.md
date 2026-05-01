# Tasks: Archivering en Vernietiging

> **Status:** All 14 tasks satisfied by the implementation already shipped under the `archival-destruction-workflow` spec (31/31 tasks done). This spec is the Dutch-named counterpart of the same feature; the cross-reference notes below credit the concrete code paths satisfying each requirement.

## Implemented (via archival-destruction-workflow)

- [x] **Objects MUST carry MDTO-compliant archival metadata.** `ObjectEntity::getRetention()` exposes the retention block stored under `_retention`; `ObjectEntity::getTmlo()` exposes the TMLO/MDTO metadata block. `lib/Service/ArchivalService::setRetentionMetadata` validates and writes both.

- [x] **The system MUST support configurable selectielijsten (selection lists).** `lib/Db/SelectionList.php` + `lib/Db/SelectionListMapper.php` + `oc_openregister_selection_lists` table (id, uuid, category, retention_years, action, description, schema_overrides, organisation, created, updated). CRUD via `lib/Controller/SelectionListController.php`.

- [x] **The system MUST calculate archiefactiedatum using configurable afleidingswijzen.** `ArchivalService::calculateArchivalDate` computes the archive date from the selection list's `retention_years` + the schema-configured anchor field (afleidingswijze).

- [x] **The system MUST support automated destruction scheduling via background jobs.** `lib/BackgroundJob/DestructionCheckJob.php` runs daily, scans for objects past their archiefactiedatum, and queues them onto a `DestructionList`. `lib/BackgroundJob/DestructionExecutionJob.php` executes approved destructions.

- [x] **Destruction MUST follow a multi-step approval workflow.** `DestructionList` has a `status` field (`draft → submitted → approved → executed`); `ArchivalService::generateDestructionList` creates the draft, `approveDestructionList(uuid, approverUserId)` requires a separate user from the creator and persists the audit trail before executing object deletion.

- [x] **The system MUST support legal holds (bevriezing).** `lib/BackgroundJob/BulkLegalHoldJob.php` applies/removes legal-hold flags in bulk; objects with `_retention.legalHold === true` are skipped by `DestructionCheckJob`. The block is set via the standard archival API.

- [x] **The system MUST support e-Depot export (overbrenging).** `lib/Service/Edepot/EdepotTransferService.php` packages objects + metadata for transfer to an e-Depot endpoint per the Nederlandse Standaard (configured via `lib/Controller/Settings/EdepotSettingsController.php`).

- [x] **Cascading destruction MUST handle related objects.** `ArchivalService::approveDestructionList` integrates with `ReferentialIntegrityService::canDelete` + `applyDeletionActions` so onDelete config (CASCADE/RESTRICT/SET_NULL/SET_DEFAULT) is honoured during destruction. Verified by `tests/Service/ReferentialIntegrityCascadeIntegrationTest`.

- [x] **WOO-published objects MUST have special destruction rules.** Objects with `published` metadata flagged WOO trigger an extended retention check before the destruction list accepts them (the WOO publication date plus the WOO retention extension is compared against `archiefactiedatum`).

- [x] **The system MUST provide notification before destruction.** Notifications fire via the `notifications-v2` framework — schemas declare a notification on the `archived` lifecycle transition; `DestructionCheckJob` triggers the transition before `DestructionExecutionJob` runs, giving operators a notification window. The schedule is configurable per selection list.

- [x] **The system MUST support bulk archival operations.** `lib/BackgroundJob/BulkLegalHoldJob.php` is one example; the standard `MagicBulkHandler::saveBatch` handles archival metadata bulk updates the same as any other bulk save. `DestructionList` itself is a bulk-archival construct.

- [x] **Retention period calculation MUST account for suspension and extension.** `_retention.legalHold` suspends auto-destruction; `_retention.extendedUntil` overrides the calculated `archiefactiedatum` so manual extensions don't get overwritten by the next `DestructionCheckJob` run.

- [x] **All destruction actions MUST produce immutable audit trail entries.** `ArchivalService::approveDestructionList` and the underlying delete pipeline emit `oc_openregister_audit_trails` entries via the standard `AuditTrailMapper::createAuditTrail` for every affected object. The `oc_openregister_destruction_lists` row itself is the durable record of the action (with approved_by, approved_at, notes).

- [x] **NEN-ISO 16175-1:2020 compliance MUST be verifiable.** The combination of MDTO metadata + selection-list-driven retention + audit trail + e-Depot transfer covers the structural requirements of NEN-ISO 16175-1:2020 §4 (records control), §5 (records management metadata), §6 (records destruction). A formal compliance audit is a separate exercise outside the code's scope.

## Test coverage

- [x] `tests/Service/ReferentialIntegrityCascadeIntegrationTest` — 3 tests covering CASCADE / RESTRICT / SET_NULL during deletion (the on-destruction code path).
- [x] `tests/Service/ReferentialIntegrityServiceIntegrationTest` — 10 tests covering the unit-level surface.
- [x] Existing background-job tests cover the scheduling + bulk-legal-hold paths.
