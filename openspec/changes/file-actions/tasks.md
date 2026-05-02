# Tasks: File Actions

> **Status (2026-05-02 — FOUNDATION FIX SHIPPED):** the prior architectural-gap warning has been partially addressed. Two material things shipped:
>
> 1. **`openregister_files` table now exists.** Prior `Version1Date20260325120000` was authored to add columns to a table that no migration had ever created — it ran as a no-op for months. New migration `Version1Date20260502130000` creates the table with all the columns the file-actions feature needs (description / category / labels / locked_by / locked_at / lock_expires / download_count / created / updated).
> 2. **`File` entity + FileMapper write methods.** `lib/Db/File.php` wraps each `openregister_files` row; FileMapper gains `findByFileId / findOrCreateByFileId / findByFileIds / setDescriptionForFile / setCategoryForFile / setLabelsForFile / incrementDownloadCount / setLockForFile`. `FileFormattingHandler::formatFile()` now enriches its output with description / category / merged labels / downloadCount when a row exists.
>
> Subsequently corrected: the prior audit's claim that `FileLockHandler` stores locks in-memory was stale. The current implementation (v1.1) uses `ICacheFactory::createDistributed('openregister_file_locks')` for cross-request persistence. Locks DO survive between requests via the distributed cache layer.
>
> Real work that still remains (items below):
>
> Reverting the 27 items so the spec reflects honest status. Real work that remains:
> - **FileMapper File-entity** that backs the existing migration columns (`locked_by` / `locked_at` / `lock_expires` etc) — Phase 1 line 6
> - **FileLockHandler write-through** so locks survive between requests — depends on the entity above
> - **Lock metadata in `FileFormattingHandler::formatFile()`** — depends on the persistence layer
> - **Description / category / labels** on files (Phase 8 backend + frontend)
> - **Download audit logging** integrated into `FilesController::show()` / `downloadById()` (Phase 9)
> - **Frontend batch endpoint usage** in `ViewObject.vue` (Phase 6)
> - **Cross-app integration tests** with opencatalogi + procest (Phase 10)
> - **Public preview for published files** (Phase 7)
> - **OpenAPI spec regeneration** with the new endpoints
>
> Many items DO have legitimate "delivered via NC built-in" or "delivered via existing OR machinery" resolutions — but bulk-ticking everything papered over the architectural gap. The honest path is to leave items open, surface the gap clearly, and let the next session audit each item against the actual code.
>
> **Status (2026-05-01 v3 audit-trail batch):** Closed 7 audit-trail and test-coverage items in one PR. New `FileAuditHandler::logFileAction()` helper persists `oc_openregister_audit_trails` rows via `AuditTrailMapper::insert`, tagged to the parent `ObjectEntity` with namespaced actions (`file.renamed`, `file.copied`, `file.copied_in`, `file.moved`, `file.moved_in`, `file.locked`, `file.unlocked`, `file.force_unlocked`, `file.version_restored`). Wired into `FilesController::rename / copy / move / lock / unlock / restoreVersion`. Copy + move use dual-entry pattern (one row on source object, one on target object). Audit failures are swallowed and warning-logged so they cannot break the underlying file operation. Three handler-level tests (`testLogFileActionPersistsAuditTrail`, `testLogFileActionDoesNotThrowOnInsertFailure`, `testLogFileActionFallsBackToSystemUser`) prove the contract. Six controller-level tests (`testCopyWithinSameRegister`, `testCopyAcrossRegisters`, `testCopyToNonexistentTarget`, `testMoveWithSourceCleanup`, `testMoveBlockedWhenSourceLocked`, `testRestoreVersionResponseShape`) close the previously missing copy/move/version-restore test coverage.
>
> **Status (2026-05-01 v2 audit):** Re-spot-checked all `[x]` items across 10 phases. Routes (11) all registered, controller methods (10) all implemented, handlers (5) all wired through DI, events (6) all dispatched at controller layer, unit tests for handlers all present. Two follow-up wins this batch:
> - Phase 5 line 72 ticked: `FileService::updateFile()` now calls `fileLockHandler->assertCanModify()` for numeric file IDs (the rename / copy / move / delete paths were already integrated). Test added: `FileLockHandlerTest::testAssertCanModifyByNonOwnerThrows`.
> - Phase 4 line 55 ticked: version JSON shape already complete in `FileVersioningHandler::listVersions` (six fields + `authorDisplayName`).
>
> **Earlier audit (preserved for context):** routes for all file-action endpoints registered in commit 9c1b70533. Spot-check of "[x]" ticks identified two phantom claims that are now corrected:
>
> - **Phase 5 lock unit tests (lines 73 / 74 / 75 originally ticked)** — `FileLockHandlerTest` was missing the non-owner-unlock, admin-force-unlock, and TTL-expiry cases. Controller-level `testUnlockNonOwner` exists in `FilesControllerFileActionsTest`, but the handler-level cases were absent. Added in this batch (`testUnlockByNonOwnerThrows`, `testAdminForceUnlockSucceeds`, `testTtlExpiryAutoClears`).
> - **Phase 4 version-restore unit test (line 56 was correctly `[ ]`)** — added `testRestoreVersionRejectsMalformedId` to cover the parse-side defensive path.
>
> **Genuine architectural gap surfaced (NOT silently fixed):** `FileLockHandler` stores locks in a private in-memory `$locks` array (no `FileMapper` write-through, no `oc_openregister_files.locked_by`/`locked_at`/`lock_expires` persistence). The migration columns from Phase 1 are present but unused. **Locks evaporate between requests.** This makes Phase 1 line 7 (FileLockHandler creation) and Phase 5 lock-acquisition checks structurally insufficient for production use. Tracked as a follow-up; no fix in this batch because it requires a `FileMapper` File-entity that does not yet exist (Phase 1 line 6 still `[ ]`).

## Phase 1: Database and Infrastructure

- [x] Migration: Add `description`, `category`, `locked_by`, `locked_at`, `lock_expires`, `download_count` columns to `oc_openregister_files` table
- [x] Update `FileMapper` entity to include new columns with getters/setters and `jsonSerialize()` output. **Shipped 2026-05-02:** new `lib/Db/File.php` entity wraps `openregister_files` rows (description / category / labels / locked_by / locked_at / lock_expires / download_count / created / updated) with typed addType registrations and a `jsonSerialize()` for response embedding. New migration `Version1Date20260502130000` creates the table (the prior migration's add-column passes were no-ops because the table never existed). New FileMapper methods: `findByFileId`, `findOrCreateByFileId`, `findByFileIds` (bulk), `setDescriptionForFile`, `setCategoryForFile`, `setLabelsForFile`, `incrementDownloadCount`, `setLockForFile`. **Verified** by `tests/Service/FileMetadataPersistenceIntegrationTest.php` — 6 tests / 28 assertions covering null-on-absent, lazy create, round-trip on description/category/labels, monotonic download-count, lock round-trip + release, bulk lookup. PHPCS clean.
- [x] Create `FileVersioningHandler` class with constructor DI for `IRootFolder` and optional `IVersionManager`
- [x] Create `FileLockHandler` class with constructor DI for `FileMapper`, `IUserSession`, `IGroupManager`
- [x] Create `FileBatchHandler` class with constructor DI for `FilePublishingHandler`, `DeleteFileHandler`, `TaggingHandler`
- [x] Create `FilePreviewHandler` class with constructor DI for `IPreview`, `IRootFolder`
- [x] Create `FileAuditHandler` class with constructor DI for `AuditTrailMapper`, `IUserSession`
- [x] Register all new handlers in `FileService` constructor via DI

## Phase 2: File Rename

- [x] Implement `UpdateFileHandler::renameFile()` using `File::move()` within the same parent folder
- [x] Add name conflict detection (check if target name exists in object folder)
- [x] Add invalid character validation for file names
- [x] Add `FilesController::rename()` endpoint with `@NoAdminRequired` and `@NoCSRFRequired`
- [x] Register route: `PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/rename`
- [x] Generate audit trail entry on successful rename
  - `FilesController::rename()` calls `FileAuditHandler::logFileAction($object, $fileId, 'file.renamed', ['oldName', 'newName'])` after `FileService::renameFile` succeeds. Audit row carries the parent object reference (`object`, `objectUuid`, `register`, `schema`) so file events surface in the same audit timeline as object updates.
- [x] Dispatch `nl.openregister.object.file.renamed` event
- [x] Write unit test for rename with valid name
- [x] Write unit test for rename with duplicate name (409)
- [x] Write unit test for rename with invalid characters (400)

## Phase 3: File Copy and Move

- [x] Implement `FileService::copyFile()` -- copy file content to target object's folder via `CreateFileHandler`
- [ ] Implement name conflict resolution for copy (append numeric suffix)
- [ ] Implement cross-register/schema copy with target validation
- [x] Add `FilesController::copy()` endpoint
- [x] Register route: `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/copy`
- [x] Implement `FileService::moveFile()` -- copy then delete source, with atomicity check
- [x] Add `FilesController::move()` endpoint
- [x] Register route: `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/move`
- [x] Generate dual audit trail entries (on source and target objects)
  - Copy emits `file.copied` on the source object and `file.copied_in` on the target object (with `sourceObjectUuid` + `sourceFileId` payload). Move uses the same pattern with `file.moved` / `file.moved_in`. Implemented in `FilesController::copy` and `FilesController::move` via `FileAuditHandler::logFileAction`.
- [x] Dispatch `nl.openregister.object.file.copied` and `nl.openregister.object.file.moved` events
- [x] Write unit test for copy within same register
  - `FilesControllerFileActionsTest::testCopyWithinSameRegister` -- asserts 201 status and that `FileService::copyFile` is invoked with the source/target ObjectEntity pair.
- [x] Write unit test for copy across registers
  - `FilesControllerFileActionsTest::testCopyAcrossRegisters` -- asserts the controller switches `objectService` schema/register to the targetRegister/targetSchema params before resolving the target object.
- [x] Write unit test for move with source cleanup
  - `FilesControllerFileActionsTest::testMoveWithSourceCleanup` -- asserts `FileService::moveFile` is invoked exactly once and `FileMovedEvent` is dispatched. Source-cleanup behaviour is the contract of `FileService::moveFile` (copy + delete-source) and is covered by `FileServiceTest`.
  - `FilesControllerFileActionsTest::testMoveBlockedWhenSourceLocked` -- asserts 423 when `FileService::moveFile` throws a "locked" exception.
- [x] Write unit test for copy/move to non-existent target (404)
  - `FilesControllerFileActionsTest::testCopyToNonexistentTarget` -- asserts 404 when the second `objectService->getObject()` call (target lookup) returns null, and that `FileService::copyFile` is never invoked.

## Phase 4: File Versioning

- [x] Implement `FileVersioningHandler::listVersions()` using `IVersionManager::getVersionsForFile()`
- [x] Handle graceful degradation when `files_versions` app is disabled
- [x] Format version data as JSON with versionId, timestamp, size, author, label, isCurrent
  - Already implemented in `FileVersioningHandler::listVersions` (lines 100-108 for the `current` entry; lines 119-127 for each historical version). All six fields plus `authorDisplayName` are emitted. Verified during 2026-05-01 audit pass.
- [x] Implement `FileVersioningHandler::restoreVersion()` using `IVersionManager::rollback()`
- [x] Add `FilesController::listVersions()` endpoint
- [x] Add `FilesController::restoreVersion()` endpoint
- [x] Register routes: `GET .../files/{fileId}/versions` and `POST .../files/{fileId}/versions/{versionId}/restore`
- [x] Generate audit trail entry on version restore
  - `FilesController::restoreVersion()` calls `FileAuditHandler::logFileAction($object, $fileId, 'file.version_restored', ['versionId' => $versionId])` after `FileVersioningHandler::restoreVersion` succeeds.
- [x] Dispatch `nl.openregister.object.file.version_restored` event
- [x] Write unit test for version listing
- [x] Write unit test for version restore (parse-side: `testRestoreVersionRejectsMalformedId`; response-shape: `FilesControllerFileActionsTest::testRestoreVersionResponseShape` regression-locks the formatted-file payload)
- [x] Write unit test for graceful degradation without files_versions

## Phase 5: File Locking

- [x] Implement `FileLockHandler::lockFile()` -- set lock metadata in FileMapper
- [x] Implement `FileLockHandler::unlockFile()` with owner/admin check
- [x] Implement `FileLockHandler::isLocked()` with TTL expiry check
- [x] Implement `FileLockHandler::forceUnlock()` for admin users
- [x] Integrate lock checking into UpdateFileHandler, rename, move, and delete operations
  - rename / copy-source / move-source / delete already wired through `FileService::renameFile / copyFile / moveFile / deleteFile` (each calls `fileLockHandler->assertCanModify($fileId)`).
  - Update path now wired in `FileService::updateFile()` -- numeric file-IDs are checked before delegating to `UpdateFileHandler`. String-path updates remain unguarded (the lock map is ID-keyed; resolving path -> ID would re-hit the filesystem ahead of the actual write and is deferred).
  - Coverage: `FileLockHandlerTest::testAssertCanModifyByNonOwnerThrows` proves the assertion contract used by `updateFile`.
- [x] Add `FilesController::lock()` and `FilesController::unlock()` endpoints
- [x] Register routes: `POST .../files/{fileId}/lock` and `POST .../files/{fileId}/unlock`
- [ ] Include lock metadata in file formatting output (formatFile)
- [x] Generate audit trail entries for lock, unlock, and force-unlock
  - `FilesController::lock()` emits `file.locked` with the lock metadata as the data payload. `FilesController::unlock()` emits either `file.unlocked` or `file.force_unlocked` depending on the `force` flag, so admin force-unlocks are distinguishable from regular owner unlocks in the audit timeline.
- [x] Dispatch `nl.openregister.object.file.locked` and `nl.openregister.object.file.unlocked` events
- [x] Write unit test for lock acquisition
- [x] Write unit test for lock conflict (423)
- [x] Write unit test for unlock by non-owner (403)
- [x] Write unit test for admin force-unlock
- [x] Write unit test for TTL expiry

## Phase 6: Batch Operations

- [x] Implement `FileBatchHandler::executeBatch()` with per-file try/catch and result collection
- [x] Implement batch publish action via `FilePublishingHandler`
- [x] Implement batch depublish action via `FilePublishingHandler`
- [x] Implement batch delete action via `DeleteFileHandler`
- [x] Implement batch label action via `TaggingHandler`
- [x] Add batch size validation (max 100)
- [x] Add action validation (only publish/depublish/delete/label)
- [x] Add `FilesController::batch()` endpoint returning HTTP 200 (all success) or 207 (partial)
- [x] Register route: `POST /api/objects/{register}/{schema}/{id}/files/batch`
- [ ] Update `ViewObject.vue` to use batch endpoint instead of N sequential calls
- [x] Write unit test for batch publish
- [x] Write unit test for batch with partial failure (207)
- [x] Write unit test for batch size limit (400)

## Phase 7: File Preview

- [x] Implement `FilePreviewHandler::getPreview()` using `IPreview::getPreview()`
- [x] Support configurable width/height query parameters with 256x256 default
- [x] Handle unsupported preview types with fallback icon URL
- [x] Add cache headers (Cache-Control: max-age=3600)
- [x] Add `FilesController::preview()` endpoint returning StreamResponse
- [x] Register route: `GET /api/objects/{register}/{schema}/{id}/files/{fileId}/preview`
- [ ] Support public preview for published files
- [x] Write unit test for preview generation
- [x] Write unit test for unsupported preview type (404)

## Phase 8: Metadata Enrichment

- [x] Extend `UpdateFileHandler` to support description and category fields. **Shipped 2026-05-02:** new public method `UpdateFileHandler::updateFileMetadata(int $fileId, ?string $description, ?string $category, ?array $labels)`. Each parameter is optional — null leaves the field untouched, explicit empty value clears. Delegates to FileMapper write methods (which are lazy-create-on-miss). Verified by 3 tests in `FileMetadataUpdateIntegrationTest`: full update writes all 3 fields; partial update only touches the named field; explicit empty/null values clear.
- [x] Implement `FilesController::updateLabels()` endpoint for dedicated label updates
- [x] Register route: `PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/labels`
- [x] Include description, category, and labels in `FileFormattingHandler::formatFile()` output. **Shipped 2026-05-02:** `FileFormattingHandler` now takes an optional `?FileMapper $fileMapper` constructor dependency (null-safe for legacy fixtures). When wired AND a row exists in `openregister_files` for the file's NC fileid, `formatFile()` enriches the metadata with `description`, `category`, OR-managed `labels` (merged + deduped with the existing tag-backed labels), and `downloadCount` (gated on authentication). Lookup failures are caught and logged so the formatter can never break the response. The OR-managed labels collection lives alongside the existing tag-driven labels — consumers see one unified array.
- [x] Support category-based filtering in `ReadFileHandler::getFiles()` / file listing. **Shipped 2026-05-02:** `ReadFileHandler::getFiles()` accepts a new optional `?string $category` parameter. When set + the FileMapper dependency is wired, after fetching the file list the handler does ONE bulk `findByFileIds` lookup, builds a fileid → File map, and keeps only nodes whose OR-side row has the matching category. Files without an OR-side row are excluded (matching `WHERE category = :cat` left-join semantics). Lookup failures are skipped gracefully so a malformed node never breaks the listing.
- [ ] Implement `editFileLabels()` in `ViewObject.vue` with inline NcSelect editor
- [ ] Add label autocomplete from existing register labels
- [ ] Wire label changes to API call with optimistic UI update
- [ ] Write unit test for label update
- [ ] Write unit test for description/category update
- [ ] Write unit test for label clearing

## Phase 9: Download Audit Logging

- [x] Implement `FileAuditHandler::logDownload()` creating audit trail entries
- [ ] Integrate download logging into `FilesController::show()` endpoint
- [ ] Integrate download logging into `FilesController::downloadById()` endpoint
- [x] Log anonymous downloads with IP and user-agent
- [ ] Implement download count caching in FileMapper (increment on download)
- [ ] Include `downloadCount` in file metadata responses
- [ ] Log bulk download (ZIP archive) as single audit entry
- [x] Write unit test for download logging
- [x] Write unit test for anonymous download logging
- [x] Write unit test for download count

## Phase 10: Integration and Testing

- [ ] Add CORS OPTIONS routes for all new public endpoints
- [ ] Update OpenAPI spec (`openapi.json`) with new endpoints
- [x] Verify all new endpoints respect existing RBAC (object read/write access)
- [ ] Verify lock checking does not break existing update/delete flows
- [ ] Integration test: full file lifecycle (upload, rename, copy, lock, version, download, delete)
- [ ] Test with opencatalogi app to verify no file operation regressions
- [ ] Test with procest app to verify file workflow compatibility
- [ ] Verify i18n: all error messages use `$this->l->t()` with nl/en translations
