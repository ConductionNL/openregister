# File Actions

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
