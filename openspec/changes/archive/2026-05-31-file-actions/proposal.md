# File Actions

## Why

OpenRegister already wraps the Nextcloud Files API with a `FileService` plus a family of single-purpose handlers and exposes per-object file CRUD/publish/depublish endpoints. But everyday document workflows in consuming apps (Procest, Pipelinq, ZaakAfhandelApp) need richer actions: rename without re-upload, copy/move between objects, version listing and restore, soft locking to prevent concurrent edits, batch publish/depublish/delete to avoid N HTTP calls, scoped previews, label/description metadata enrichment, and download audit logging. These actions complete the file lifecycle and align with WebDAV locking (RFC 4918 §6) and Nextcloud's `IPreview` / `IVersionManager` APIs. Most of the surface has shipped (handlers + controller methods), but route registrations and audit/event integration still have gaps.

## What Changes

- Add `description`, `category`, `locked_by`, `locked_at`, `lock_expires`, `download_count` columns to `oc_openregister_files` (migration shipped) and surface them on `FileMapper` getters/setters and `jsonSerialize()`.
- Introduce five new handlers under `lib/Service/File/` — `FileVersioningHandler`, `FileLockHandler`, `FileBatchHandler`, `FilePreviewHandler`, `FileAuditHandler` — wired into `FileService` via DI.
- Implement file rename via `UpdateFileHandler::renameFile()` (using `OCP\Files\File::move()` within the same folder), with conflict and invalid-character validation; expose as `PUT .../files/{fileId}/rename`.
- Implement file copy and move between objects (`FileService::copyFile()` / `moveFile()`), including cross-register/schema targets and name-conflict resolution; expose as `POST .../files/{fileId}/copy` and `.../move`.
- Implement file version listing and restore via `IVersionManager`, with graceful degradation when `files_versions` is disabled; expose as `GET .../files/{fileId}/versions` and `POST .../files/{fileId}/versions/{versionId}/restore`.
- Implement soft file locking via `FileLockHandler` (acquire / release / TTL expiry / admin force-unlock) and integrate lock checks into update / rename / move / delete; expose as `POST .../files/{fileId}/lock` and `.../unlock`.
- Implement batch operations via `FileBatchHandler` (publish / depublish / delete / label, max 100 per batch, returning HTTP 207 on partial failure); expose as `POST .../files/batch`.
- Implement scoped file previews via `FilePreviewHandler` and `IPreview` (configurable width/height, cache-control, fallback icon); expose as `GET .../files/{fileId}/preview`.
- Add metadata enrichment for files (description, category, labels) with autocomplete, optimistic UI, and category-based filtering in `ReadFileHandler`; dedicated label endpoint at `PUT .../files/{fileId}/labels`.
- Add download audit logging via `FileAuditHandler::logDownload()` (anonymous and authenticated, with bulk-ZIP single-entry treatment) and download-count caching on `FileMapper`.
- Dispatch CloudEvents for every action (`nl.openregister.object.file.renamed/copied/moved/locked/unlocked/version_restored/...`) and write audit-trail entries for all mutations.
- Register all new endpoints in `appinfo/routes.php` with CORS OPTIONS routes and update `openapi.json`.

## Problem
OpenRegister has a comprehensive file management layer (FileService with 13 handler classes, FilesController, routes for CRUD/publish/depublish) but critical gaps remain in the file action capabilities:

1. **No file rename** -- Users cannot rename files after upload without re-uploading them.
2. **No file copy/move between objects** -- Files cannot be transferred from one object to another without download/re-upload.
3. **No file versioning API** -- Nextcloud stores file versions internally, but OpenRegister exposes no version listing, restore, or comparison endpoints.
4. **No file lock/unlock** -- No mechanism to prevent concurrent edits or signal that a file is being worked on.
5. **Incomplete mass actions** -- Mass publish/depublish/delete exist in the UI but there are no batch API endpoints; each action requires N sequential HTTP calls.
6. **No file preview/thumbnail API** -- Consumers must use Nextcloud's internal preview URLs with full auth context; no OpenRegister-scoped preview endpoint exists.
7. **No file metadata enrichment** -- Labels (tags) editing is a placeholder in the UI (`editFileLabels` logs to console), and file descriptions, categories, and custom metadata fields are unsupported.
8. **No download tracking / access logging** -- File downloads are not logged for audit or analytics purposes.

## Proposed Solution
Extend the existing file infrastructure with 10 new requirements covering rename, copy/move, versioning, locking, batch operations, preview, metadata enrichment, and download audit logging. The implementation SHALL reuse existing handler classes (UpdateFileHandler, FilePublishingHandler, FileSharingHandler, TaggingHandler) and introduce new handlers only where separation of concerns demands it (VersioningHandler, LockHandler). All new endpoints follow the existing sub-resource URL pattern under `/api/objects/{register}/{schema}/{id}/files/`.
