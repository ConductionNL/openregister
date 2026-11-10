# Design: File Actions

## Approach
Extend the existing FileService handler architecture and FilesController with new action endpoints and supporting services. The design follows OpenRegister's established handler decomposition pattern where each concern has a dedicated handler class injected into the orchestrating FileService.

## Architecture Overview

```
FilesController (extended)
    |
    v
FileService (orchestrator)
    |-- CreateFileHandler        (existing)
    |-- ReadFileHandler          (existing)
    |-- UpdateFileHandler        (existing - extended for rename)
    |-- DeleteFileHandler        (existing)
    |-- FilePublishingHandler    (existing)
    |-- FileSharingHandler       (existing)
    |-- FileValidationHandler    (existing)
    |-- FolderManagementHandler  (existing)
    |-- FileFormattingHandler    (existing)
    |-- FileOwnershipHandler     (existing)
    |-- DocumentProcessingHandler(existing)
    |-- TaggingHandler           (existing - extended for labels UI)
    |-- FileVersioningHandler    (NEW - version list/restore)
    |-- FileLockHandler          (NEW - lock/unlock)
    |-- FileBatchHandler         (NEW - batch operations)
    |-- FilePreviewHandler       (NEW - preview/thumbnail)
    |-- FileAuditHandler         (NEW - download tracking)
```

## New Files
- `lib/Service/File/FileVersioningHandler.php` -- Version listing and restore via `OCA\Files_Versions\Versions\IVersionManager`
- `lib/Service/File/FileLockHandler.php` -- Lock/unlock using `OCP\Lock\ILockingProvider` and custom lock metadata
- `lib/Service/File/FileBatchHandler.php` -- Batch publish/depublish/delete/label operations
- `lib/Service/File/FilePreviewHandler.php` -- Preview/thumbnail generation via `OCP\IPreview`
- `lib/Service/File/FileAuditHandler.php` -- Download access logging to audit trail

## Modified Files
- `lib/Controller/FilesController.php` -- Add rename, copy, move, version, lock, batch, preview, and audit endpoints
- `lib/Service/FileService.php` -- Inject new handlers, add orchestration methods
- `lib/Service/File/UpdateFileHandler.php` -- Add rename capability
- `lib/Service/File/TaggingHandler.php` -- Ensure tag/label CRUD is fully functional
- `appinfo/routes.php` -- Register new routes
- `src/modals/object/ViewObject.vue` -- Wire editFileLabels, add rename/copy/version UI
- `src/modals/file/UploadFiles.vue` -- Add rename action
- `src/store/modules/object.js` (or equivalent store) -- Add store actions for new file endpoints

## URL Pattern
All new endpoints extend the existing sub-resource pattern:

```
# Rename
PUT  /api/objects/{register}/{schema}/{id}/files/{fileId}/rename

# Copy file to another object
POST /api/objects/{register}/{schema}/{id}/files/{fileId}/copy

# Move file to another object
POST /api/objects/{register}/{schema}/{id}/files/{fileId}/move

# Versions
GET  /api/objects/{register}/{schema}/{id}/files/{fileId}/versions
POST /api/objects/{register}/{schema}/{id}/files/{fileId}/versions/{versionId}/restore

# Lock/Unlock
POST /api/objects/{register}/{schema}/{id}/files/{fileId}/lock
POST /api/objects/{register}/{schema}/{id}/files/{fileId}/unlock

# Batch operations
POST /api/objects/{register}/{schema}/{id}/files/batch

# Preview
GET  /api/objects/{register}/{schema}/{id}/files/{fileId}/preview

# Labels (tags) update
PUT  /api/objects/{register}/{schema}/{id}/files/{fileId}/labels

# Download with audit
GET  /api/objects/{register}/{schema}/{id}/files/{fileId}/download
```

## Key Design Decisions

### 1. Version Manager Integration
Nextcloud's `files_versions` app manages versions via `IVersionManager`. We wrap this to provide a JSON API that lists versions with timestamps, sizes, and user info, and allows restoring a specific version. The version restore creates a new audit trail entry.

### 2. Lock Mechanism
File locking uses Nextcloud's `ILockingProvider` for storage-level locks plus custom metadata in `oc_openregister_files` table (lock_user, lock_time, lock_type) for UI display. Locks have a configurable TTL (default: 30 minutes) and can be force-released by admins.

### 3. Batch Operations
The batch endpoint accepts a JSON body with `action` (publish|depublish|delete|label) and `fileIds` array. Each operation runs within a try/catch per file, returning per-file results. This replaces the N sequential HTTP calls pattern in the frontend.

### 4. Preview Generation
`IPreview` generates thumbnails for supported file types. The handler returns a StreamResponse with configurable width/height parameters. For unsupported types, a generic icon URL is returned. Previews are served with cache headers.

### 5. Audit Logging
All file downloads (show endpoint and new download endpoint) log to the audit trail with action `file.downloaded`, capturing user, timestamp, IP, and user-agent. This reuses the existing `AuditTrailMapper` and `AuditHandler`.

### 6. Label/Tag UI
The placeholder `editFileLabels()` method in ViewObject.vue will be implemented as an inline tag editor using Nextcloud's `NcSelect` with creatable tags, calling `PUT .../files/{fileId}/labels`.

## Risks and Mitigations
- **files_versions dependency**: The versioning handler must gracefully degrade if `files_versions` is disabled. Check app availability at runtime.
- **Lock staleness**: Locks may become stale if a user's session ends. The TTL mechanism and admin force-release mitigate this.
- **Batch size limits**: Batch operations are capped at 100 files per request to prevent timeout issues.
- **Preview generation load**: Preview requests are rate-limited and cached; the handler delegates entirely to `IPreview` which has its own cache.
