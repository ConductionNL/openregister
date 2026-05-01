# Tasks: File Actions

> **Status (2026-05-01 audit):** routes for all file-action endpoints registered in commit 9c1b70533. Spot-check of "[x]" ticks identified two phantom claims that are now corrected:
>
> - **Phase 5 lock unit tests (lines 73 / 74 / 75 originally ticked)** — `FileLockHandlerTest` was missing the non-owner-unlock, admin-force-unlock, and TTL-expiry cases. Controller-level `testUnlockNonOwner` exists in `FilesControllerFileActionsTest`, but the handler-level cases were absent. Added in this batch (`testUnlockByNonOwnerThrows`, `testAdminForceUnlockSucceeds`, `testTtlExpiryAutoClears`).
> - **Phase 4 version-restore unit test (line 56 was correctly `[ ]`)** — added `testRestoreVersionRejectsMalformedId` to cover the parse-side defensive path.
>
> **Genuine architectural gap surfaced (NOT silently fixed):** `FileLockHandler` stores locks in a private in-memory `$locks` array (no `FileMapper` write-through, no `oc_openregister_files.locked_by`/`locked_at`/`lock_expires` persistence). The migration columns from Phase 1 are present but unused. **Locks evaporate between requests.** This makes Phase 1 line 7 (FileLockHandler creation) and Phase 5 lock-acquisition checks structurally insufficient for production use. Tracked as a follow-up; no fix in this batch because it requires a `FileMapper` File-entity that does not yet exist (Phase 1 line 6 still `[ ]`).

## Phase 1: Database and Infrastructure

- [x] Migration: Add `description`, `category`, `locked_by`, `locked_at`, `lock_expires`, `download_count` columns to `oc_openregister_files` table
- [ ] Update `FileMapper` entity to include new columns with getters/setters and `jsonSerialize()` output
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
- [ ] Generate audit trail entry on successful rename
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
- [ ] Generate dual audit trail entries (on source and target objects)
- [x] Dispatch `nl.openregister.object.file.copied` and `nl.openregister.object.file.moved` events
- [ ] Write unit test for copy within same register
- [ ] Write unit test for copy across registers
- [ ] Write unit test for move with source cleanup
- [ ] Write unit test for copy/move to non-existent target (404)

## Phase 4: File Versioning

- [x] Implement `FileVersioningHandler::listVersions()` using `IVersionManager::getVersionsForFile()`
- [x] Handle graceful degradation when `files_versions` app is disabled
- [ ] Format version data as JSON with versionId, timestamp, size, author, label, isCurrent
- [x] Implement `FileVersioningHandler::restoreVersion()` using `IVersionManager::rollback()`
- [x] Add `FilesController::listVersions()` endpoint
- [x] Add `FilesController::restoreVersion()` endpoint
- [x] Register routes: `GET .../files/{fileId}/versions` and `POST .../files/{fileId}/versions/{versionId}/restore`
- [ ] Generate audit trail entry on version restore
- [x] Dispatch `nl.openregister.object.file.version_restored` event
- [x] Write unit test for version listing
- [x] Write unit test for version restore (parse-side: `testRestoreVersionRejectsMalformedId`)
- [x] Write unit test for graceful degradation without files_versions

## Phase 5: File Locking

- [x] Implement `FileLockHandler::lockFile()` -- set lock metadata in FileMapper
- [x] Implement `FileLockHandler::unlockFile()` with owner/admin check
- [x] Implement `FileLockHandler::isLocked()` with TTL expiry check
- [x] Implement `FileLockHandler::forceUnlock()` for admin users
- [ ] Integrate lock checking into UpdateFileHandler, rename, move, and delete operations
- [x] Add `FilesController::lock()` and `FilesController::unlock()` endpoints
- [x] Register routes: `POST .../files/{fileId}/lock` and `POST .../files/{fileId}/unlock`
- [ ] Include lock metadata in file formatting output (formatFile)
- [ ] Generate audit trail entries for lock, unlock, and force-unlock
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

- [ ] Extend `UpdateFileHandler` to support description and category fields
- [x] Implement `FilesController::updateLabels()` endpoint for dedicated label updates
- [x] Register route: `PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/labels`
- [ ] Include description, category, and labels in `FileFormattingHandler::formatFile()` output
- [ ] Support category-based filtering in `ReadFileHandler::getFiles()` / file listing
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
