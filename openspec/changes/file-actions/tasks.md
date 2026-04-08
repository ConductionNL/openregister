# Tasks: File Actions

## Phase 1: Database and Infrastructure

- [ ] Migration: Add `description`, `category`, `locked_by`, `locked_at`, `lock_expires`, `download_count` columns to `oc_openregister_files` table
- [ ] Update `FileMapper` entity to include new columns with getters/setters and `jsonSerialize()` output
- [ ] Create `FileVersioningHandler` class with constructor DI for `IRootFolder` and optional `IVersionManager`
- [ ] Create `FileLockHandler` class with constructor DI for `FileMapper`, `IUserSession`, `IGroupManager`
- [ ] Create `FileBatchHandler` class with constructor DI for `FilePublishingHandler`, `DeleteFileHandler`, `TaggingHandler`
- [ ] Create `FilePreviewHandler` class with constructor DI for `IPreview`, `IRootFolder`
- [ ] Create `FileAuditHandler` class with constructor DI for `AuditTrailMapper`, `IUserSession`
- [ ] Register all new handlers in `FileService` constructor via DI

## Phase 2: File Rename

- [ ] Implement `UpdateFileHandler::renameFile()` using `File::move()` within the same parent folder
- [ ] Add name conflict detection (check if target name exists in object folder)
- [ ] Add invalid character validation for file names
- [ ] Add `FilesController::rename()` endpoint with `@NoAdminRequired` and `@NoCSRFRequired`
- [ ] Register route: `PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/rename`
- [ ] Generate audit trail entry on successful rename
- [ ] Dispatch `nl.openregister.object.file.renamed` event
- [ ] Write unit test for rename with valid name
- [ ] Write unit test for rename with duplicate name (409)
- [ ] Write unit test for rename with invalid characters (400)

## Phase 3: File Copy and Move

- [ ] Implement `FileService::copyFile()` -- copy file content to target object's folder via `CreateFileHandler`
- [ ] Implement name conflict resolution for copy (append numeric suffix)
- [ ] Implement cross-register/schema copy with target validation
- [ ] Add `FilesController::copy()` endpoint
- [ ] Register route: `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/copy`
- [ ] Implement `FileService::moveFile()` -- copy then delete source, with atomicity check
- [ ] Add `FilesController::move()` endpoint
- [ ] Register route: `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/move`
- [ ] Generate dual audit trail entries (on source and target objects)
- [ ] Dispatch `nl.openregister.object.file.copied` and `nl.openregister.object.file.moved` events
- [ ] Write unit test for copy within same register
- [ ] Write unit test for copy across registers
- [ ] Write unit test for move with source cleanup
- [ ] Write unit test for copy/move to non-existent target (404)

## Phase 4: File Versioning

- [ ] Implement `FileVersioningHandler::listVersions()` using `IVersionManager::getVersionsForFile()`
- [ ] Handle graceful degradation when `files_versions` app is disabled
- [ ] Format version data as JSON with versionId, timestamp, size, author, label, isCurrent
- [ ] Implement `FileVersioningHandler::restoreVersion()` using `IVersionManager::rollback()`
- [ ] Add `FilesController::listVersions()` endpoint
- [ ] Add `FilesController::restoreVersion()` endpoint
- [ ] Register routes: `GET .../files/{fileId}/versions` and `POST .../files/{fileId}/versions/{versionId}/restore`
- [ ] Generate audit trail entry on version restore
- [ ] Dispatch `nl.openregister.object.file.version_restored` event
- [ ] Write unit test for version listing
- [ ] Write unit test for version restore
- [ ] Write unit test for graceful degradation without files_versions

## Phase 5: File Locking

- [ ] Implement `FileLockHandler::lockFile()` -- set lock metadata in FileMapper
- [ ] Implement `FileLockHandler::unlockFile()` with owner/admin check
- [ ] Implement `FileLockHandler::isLocked()` with TTL expiry check
- [ ] Implement `FileLockHandler::forceUnlock()` for admin users
- [ ] Integrate lock checking into UpdateFileHandler, rename, move, and delete operations
- [ ] Add `FilesController::lock()` and `FilesController::unlock()` endpoints
- [ ] Register routes: `POST .../files/{fileId}/lock` and `POST .../files/{fileId}/unlock`
- [ ] Include lock metadata in file formatting output (formatFile)
- [ ] Generate audit trail entries for lock, unlock, and force-unlock
- [ ] Dispatch `nl.openregister.object.file.locked` and `nl.openregister.object.file.unlocked` events
- [ ] Write unit test for lock acquisition
- [ ] Write unit test for lock conflict (423)
- [ ] Write unit test for unlock by non-owner (403)
- [ ] Write unit test for admin force-unlock
- [ ] Write unit test for TTL expiry

## Phase 6: Batch Operations

- [ ] Implement `FileBatchHandler::executeBatch()` with per-file try/catch and result collection
- [ ] Implement batch publish action via `FilePublishingHandler`
- [ ] Implement batch depublish action via `FilePublishingHandler`
- [ ] Implement batch delete action via `DeleteFileHandler`
- [ ] Implement batch label action via `TaggingHandler`
- [ ] Add batch size validation (max 100)
- [ ] Add action validation (only publish/depublish/delete/label)
- [ ] Add `FilesController::batch()` endpoint returning HTTP 200 (all success) or 207 (partial)
- [ ] Register route: `POST /api/objects/{register}/{schema}/{id}/files/batch`
- [ ] Update `ViewObject.vue` to use batch endpoint instead of N sequential calls
- [ ] Write unit test for batch publish
- [ ] Write unit test for batch with partial failure (207)
- [ ] Write unit test for batch size limit (400)

## Phase 7: File Preview

- [ ] Implement `FilePreviewHandler::getPreview()` using `IPreview::getPreview()`
- [ ] Support configurable width/height query parameters with 256x256 default
- [ ] Handle unsupported preview types with fallback icon URL
- [ ] Add cache headers (Cache-Control: max-age=3600)
- [ ] Add `FilesController::preview()` endpoint returning StreamResponse
- [ ] Register route: `GET /api/objects/{register}/{schema}/{id}/files/{fileId}/preview`
- [ ] Support public preview for published files
- [ ] Write unit test for preview generation
- [ ] Write unit test for unsupported preview type (404)

## Phase 8: Metadata Enrichment

- [ ] Extend `UpdateFileHandler` to support description and category fields
- [ ] Implement `FilesController::updateLabels()` endpoint for dedicated label updates
- [ ] Register route: `PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/labels`
- [ ] Include description, category, and labels in `FileFormattingHandler::formatFile()` output
- [ ] Support category-based filtering in `ReadFileHandler::getFiles()` / file listing
- [ ] Implement `editFileLabels()` in `ViewObject.vue` with inline NcSelect editor
- [ ] Add label autocomplete from existing register labels
- [ ] Wire label changes to API call with optimistic UI update
- [ ] Write unit test for label update
- [ ] Write unit test for description/category update
- [ ] Write unit test for label clearing

## Phase 9: Download Audit Logging

- [ ] Implement `FileAuditHandler::logDownload()` creating audit trail entries
- [ ] Integrate download logging into `FilesController::show()` endpoint
- [ ] Integrate download logging into `FilesController::downloadById()` endpoint
- [ ] Log anonymous downloads with IP and user-agent
- [ ] Implement download count caching in FileMapper (increment on download)
- [ ] Include `downloadCount` in file metadata responses
- [ ] Log bulk download (ZIP archive) as single audit entry
- [ ] Write unit test for download logging
- [ ] Write unit test for anonymous download logging
- [ ] Write unit test for download count

## Phase 10: Integration and Testing

- [ ] Add CORS OPTIONS routes for all new public endpoints
- [ ] Update OpenAPI spec (`openapi.json`) with new endpoints
- [ ] Verify all new endpoints respect existing RBAC (object read/write access)
- [ ] Verify lock checking does not break existing update/delete flows
- [ ] Integration test: full file lifecycle (upload, rename, copy, lock, version, download, delete)
- [ ] Test with opencatalogi app to verify no file operation regressions
- [ ] Test with procest app to verify file workflow compatibility
- [ ] Verify i18n: all error messages use `$this->l->t()` with nl/en translations
