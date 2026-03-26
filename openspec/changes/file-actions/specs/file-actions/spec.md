---
status: draft
---
# File Actions

## Purpose
Extend OpenRegister's file management capabilities with rename, copy/move, versioning, locking, batch operations, preview generation, metadata enrichment, and download audit logging. These actions complete the file lifecycle management for register objects and enable richer document workflows in consuming apps (Procest, ZaakAfhandelApp, Pipelinq).

**Standards**: WebDAV locking (RFC 4918 Section 6), Nextcloud Files API, Nextcloud IPreview API
**Cross-references**: [object-interactions](../object-interactions/spec.md), [audit-trail-immutable](../../specs/audit-trail-immutable/spec.md), [event-driven-architecture](../../specs/event-driven-architecture/spec.md)

## Requirements

### Requirement: File Rename

The system SHALL support renaming files attached to objects without re-uploading content. The rename operation MUST update the file name in Nextcloud's filesystem via `OCP\Files\File::move()` (moving within the same folder with a new name) and update any cached references. The operation MUST preserve the file's ID, share links, tags, and version history.

#### Scenario: Rename a file successfully
- **GIVEN** object `abc-123` has a file with ID 42 named `scan_001.pdf`
- **WHEN** a PUT request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/rename` with body `{"name": "Inkomende_brief_2026-03-15.pdf"}`
- **THEN** the file MUST be renamed in the Nextcloud filesystem
- **AND** the response MUST return HTTP 200 with the updated file metadata including the new name
- **AND** the file ID MUST remain unchanged
- **AND** existing share links MUST continue to work

#### Scenario: Rename with duplicate name
- **GIVEN** object `abc-123` has files `rapport.pdf` (ID 42) and `rapport.pdf` (ID 43) would create a conflict
- **WHEN** a rename to `rapport.pdf` is attempted for file ID 42 when that name already exists in the folder
- **THEN** the system MUST return HTTP 409 with `{"error": "A file with name 'rapport.pdf' already exists for this object"}`

#### Scenario: Rename with empty name
- **GIVEN** a valid file attached to an object
- **WHEN** a rename request is sent with `{"name": ""}`
- **THEN** the system MUST return HTTP 400 with `{"error": "File name is required"}`

#### Scenario: Rename with invalid characters
- **GIVEN** a valid file attached to an object
- **WHEN** a rename request includes characters forbidden by Nextcloud (`/`, `\`, `:`, `*`, `?`, `"`, `<`, `>`, `|`)
- **THEN** the system MUST return HTTP 400 with `{"error": "File name contains invalid characters"}`

#### Scenario: Rename preserves file extension
- **GIVEN** a file `document.pdf` attached to an object
- **WHEN** renamed to `document.docx`
- **THEN** the rename MUST succeed (extension changes are allowed)
- **AND** the MIME type in the formatted response MUST reflect the actual file content, not the new extension

#### Scenario: Rename generates audit trail entry
- **GIVEN** user `behandelaar-1` renames file `scan.pdf` to `besluit.pdf`
- **WHEN** the rename succeeds
- **THEN** an audit trail entry MUST be created with `action: "file.renamed"` and data containing `{"oldName": "scan.pdf", "newName": "besluit.pdf", "fileId": 42}`


### Requirement: File Copy Between Objects

The system SHALL support copying a file from one object to another within the same register or across registers. The copy operation MUST create an independent copy of the file content in the target object's folder. The source file MUST remain unchanged.

#### Scenario: Copy a file to another object in the same register
- **GIVEN** object `abc-123` has file `contract.pdf` (ID 42) in register `zaak-register`, schema `zaken`
- **AND** object `def-456` exists in the same register and schema
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/copy` with body `{"targetObjectId": "def-456"}`
- **THEN** a new copy of `contract.pdf` MUST be created in the target object's file folder
- **AND** the response MUST return HTTP 201 with the new file's metadata (new file ID, same name and content)
- **AND** the source file MUST remain untouched on object `abc-123`

#### Scenario: Copy a file to an object in a different register
- **GIVEN** file `bijlage.pdf` on object `abc-123` in register `intake`, schema `aanvragen`
- **AND** object `xyz-789` exists in register `archief`, schema `dossiers`
- **WHEN** a copy request is sent with `{"targetObjectId": "xyz-789", "targetRegister": "archief", "targetSchema": "dossiers"}`
- **THEN** the file MUST be copied to the target object's folder
- **AND** the response MUST return HTTP 201 with the new file metadata

#### Scenario: Copy with name conflict resolution
- **GIVEN** target object `def-456` already has a file named `contract.pdf`
- **WHEN** a copy of `contract.pdf` from another object is requested
- **THEN** the system MUST auto-rename the copy to `contract (1).pdf`
- **AND** the response MUST include the resolved name

#### Scenario: Copy file to non-existent object
- **GIVEN** a valid source file
- **WHEN** a copy request targets `targetObjectId: "nonexistent"`
- **THEN** the system MUST return HTTP 404 with `{"error": "Target object not found"}`

#### Scenario: Copy generates audit trail entries on both objects
- **GIVEN** a file copy from object A to object B
- **WHEN** the copy succeeds
- **THEN** object A MUST get an audit entry `action: "file.copied_from"` with target details
- **AND** object B MUST get an audit entry `action: "file.copied_to"` with source details


### Requirement: File Move Between Objects

The system SHALL support moving a file from one object to another. Unlike copy, the move operation MUST remove the file from the source object and place it in the target object's folder. This is equivalent to a copy followed by a delete, but MUST be atomic (both operations succeed or neither does).

#### Scenario: Move a file to another object
- **GIVEN** object `abc-123` has file `rapport.pdf` (ID 42)
- **AND** object `def-456` exists in the same register
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/move` with body `{"targetObjectId": "def-456"}`
- **THEN** the file MUST be moved to the target object's folder via `File::move()`
- **AND** the file MUST no longer appear in the source object's file listing
- **AND** the response MUST return HTTP 200 with the file's new metadata (new path, same file ID if Nextcloud preserves it, or new ID if a copy+delete is needed)

#### Scenario: Move with name conflict
- **GIVEN** target object already has a file with the same name
- **WHEN** a move is requested
- **THEN** the system MUST auto-rename with a numeric suffix, same as copy

#### Scenario: Move to non-existent object
- **WHEN** a move targets a non-existent object
- **THEN** the system MUST return HTTP 404 and the source file MUST remain unchanged

#### Scenario: Move generates audit trail entries
- **GIVEN** file `rapport.pdf` is moved from object A to object B
- **WHEN** the move succeeds
- **THEN** object A MUST get audit entry `action: "file.moved_from"` with target details
- **AND** object B MUST get audit entry `action: "file.moved_to"` with source details


### Requirement: File Version Listing and Restore

The system SHALL expose Nextcloud's file versioning capabilities through a JSON API. Users MUST be able to list all versions of a file and restore a specific version. Version listing requires the `files_versions` app to be enabled.

#### Scenario: List file versions
- **GIVEN** file `rapport.pdf` (ID 42) on object `abc-123` has been updated 3 times
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/versions`
- **THEN** the response MUST return a JSON array of version objects, each containing: `versionId`, `timestamp` (ISO 8601), `size` (bytes), `author` (user ID), `authorDisplayName`, `label` (if set)
- **AND** versions MUST be ordered newest-first
- **AND** the current version MUST be included as the first entry with `isCurrent: true`

#### Scenario: Restore a previous version
- **GIVEN** file `rapport.pdf` has version `v-1710892800` from 2 days ago
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/versions/v-1710892800/restore`
- **THEN** the file content MUST be replaced with the content from that version
- **AND** a new version entry MUST be created for the pre-restore state
- **AND** the response MUST return HTTP 200 with the restored file metadata
- **AND** an audit trail entry MUST be created with `action: "file.version_restored"` and `data: {"versionId": "v-1710892800", "fileId": 42}`

#### Scenario: List versions when files_versions is disabled
- **GIVEN** the `files_versions` Nextcloud app is not enabled
- **WHEN** a version listing is requested
- **THEN** the system MUST return HTTP 200 with an empty array and a `warning` field: `"File versioning is not enabled on this instance"`

#### Scenario: Restore non-existent version
- **GIVEN** a valid file
- **WHEN** a restore request specifies a version ID that does not exist
- **THEN** the system MUST return HTTP 404 with `{"error": "Version not found"}`


### Requirement: File Locking

The system SHALL provide file-level locking to prevent concurrent modifications. Locks are advisory -- they signal to other users that a file is being worked on. Locks MUST have a configurable TTL (default: 30 minutes) and support force-release by admins.

#### Scenario: Lock a file
- **GIVEN** file `contract.pdf` (ID 42) on object `abc-123` is unlocked
- **WHEN** user `behandelaar-1` sends POST to `/api/objects/{register}/{schema}/abc-123/files/42/lock`
- **THEN** the file MUST be marked as locked
- **AND** the response MUST return HTTP 200 with `{"locked": true, "lockedBy": "behandelaar-1", "lockedByDisplayName": "Jan de Vries", "lockedAt": "2026-03-24T10:00:00Z", "expiresAt": "2026-03-24T10:30:00Z"}`
- **AND** the file metadata in list/show responses MUST include the lock information

#### Scenario: Attempt to lock an already-locked file
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** user `behandelaar-2` attempts to lock the same file
- **THEN** the system MUST return HTTP 423 (Locked) with `{"error": "File is locked by Jan de Vries", "lockedBy": "behandelaar-1", "lockedAt": "...", "expiresAt": "..."}`

#### Scenario: Unlock a file
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** user `behandelaar-1` sends POST to `.../files/42/unlock`
- **THEN** the lock MUST be released
- **AND** the response MUST return HTTP 200 with `{"locked": false}`

#### Scenario: Unlock by a different user (denied)
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** user `behandelaar-2` (non-admin) attempts to unlock
- **THEN** the system MUST return HTTP 403 with `{"error": "Only the lock owner or an admin can unlock this file"}`

#### Scenario: Admin force-unlock
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** an admin user sends POST to `.../files/42/unlock` with `{"force": true}`
- **THEN** the lock MUST be released regardless of lock owner
- **AND** an audit trail entry MUST be created with `action: "file.force_unlocked"`

#### Scenario: Lock expires automatically
- **GIVEN** file 42 was locked 31 minutes ago with default TTL of 30 minutes
- **WHEN** any user attempts to modify or lock the file
- **THEN** the expired lock MUST be automatically cleared
- **AND** the operation MUST proceed as if the file were unlocked

#### Scenario: Modify locked file (blocked)
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** user `behandelaar-2` attempts to update, rename, move, or delete the file
- **THEN** the system MUST return HTTP 423 (Locked) with `{"error": "File is locked by Jan de Vries"}`

#### Scenario: Lock owner can modify locked file
- **GIVEN** file 42 is locked by `behandelaar-1`
- **WHEN** user `behandelaar-1` updates the file content
- **THEN** the operation MUST succeed
- **AND** the lock MUST remain active (not auto-released on modification)


### Requirement: Batch File Operations

The system SHALL provide a single batch endpoint for performing publish, depublish, delete, and label operations on multiple files at once. This replaces the current frontend pattern of N sequential HTTP requests.

#### Scenario: Batch publish files
- **GIVEN** object `abc-123` has files with IDs [42, 43, 44], none published
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/batch` with body `{"action": "publish", "fileIds": [42, 43, 44]}`
- **THEN** all 3 files MUST be published via `FilePublishingHandler`
- **AND** the response MUST return HTTP 200 with per-file results: `{"results": [{"fileId": 42, "success": true}, {"fileId": 43, "success": true}, {"fileId": 44, "success": true}], "summary": {"total": 3, "succeeded": 3, "failed": 0}}`

#### Scenario: Batch depublish files
- **GIVEN** 3 published files
- **WHEN** a batch depublish request is sent
- **THEN** all share links MUST be removed for those files
- **AND** the response MUST follow the same per-file result format

#### Scenario: Batch delete files
- **GIVEN** 3 files attached to an object
- **WHEN** a batch delete request is sent with `{"action": "delete", "fileIds": [42, 43, 44]}`
- **THEN** all 3 files MUST be deleted from the filesystem and their metadata removed
- **AND** the response MUST include per-file success/failure

#### Scenario: Batch label (tag) files
- **GIVEN** 3 files attached to an object
- **WHEN** a batch request is sent with `{"action": "label", "fileIds": [42, 43, 44], "labels": ["vertrouwelijk", "definitief"]}`
- **THEN** the specified labels MUST be applied to all 3 files
- **AND** existing labels on those files MUST be replaced (not merged) with the specified labels

#### Scenario: Batch with partial failure
- **GIVEN** a batch delete of files [42, 43, 44] where file 43 is locked by another user
- **WHEN** the batch processes each file
- **THEN** files 42 and 44 MUST be deleted successfully
- **AND** file 43 MUST fail with error "File is locked"
- **AND** the response MUST be HTTP 207 (Multi-Status) with per-file results and summary `{"succeeded": 2, "failed": 1}`

#### Scenario: Batch size limit
- **GIVEN** a batch request with more than 100 file IDs
- **WHEN** the request is validated
- **THEN** the system MUST return HTTP 400 with `{"error": "Batch operations are limited to 100 files per request"}`

#### Scenario: Batch with invalid action
- **GIVEN** a batch request with `{"action": "archive"}`
- **WHEN** the request is validated
- **THEN** the system MUST return HTTP 400 with `{"error": "Invalid batch action. Allowed: publish, depublish, delete, label"}`


### Requirement: File Preview and Thumbnail

The system SHALL provide preview/thumbnail generation for files via Nextcloud's `OCP\IPreview` interface. Previews MUST be served with appropriate cache headers and support configurable dimensions.

#### Scenario: Get file preview
- **GIVEN** file `foto.jpg` (ID 42) on object `abc-123`
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/preview`
- **THEN** the response MUST be a StreamResponse with the preview image
- **AND** Content-Type MUST be `image/png` or `image/jpeg`
- **AND** Cache-Control MUST include `max-age=3600` for client caching

#### Scenario: Preview with custom dimensions
- **GIVEN** a valid file
- **WHEN** a preview request includes query parameters `?width=256&height=256`
- **THEN** the preview MUST be generated at the requested dimensions (or the closest supported size)

#### Scenario: Default preview dimensions
- **GIVEN** a preview request without dimension parameters
- **WHEN** the preview is generated
- **THEN** default dimensions of 256x256 pixels MUST be used

#### Scenario: Preview for unsupported file type
- **GIVEN** file `data.csv` (ID 42) for which `IPreview` cannot generate a preview
- **WHEN** a preview request is made
- **THEN** the system MUST return HTTP 404 with `{"error": "Preview not available for this file type"}`
- **AND** the response SHOULD include a `fallbackIcon` field with the MIME-type-specific icon URL

#### Scenario: Preview for public (anonymous) access
- **GIVEN** file 42 is published (has a public share)
- **WHEN** a preview is requested without authentication via the public endpoint
- **THEN** the preview MUST be served if the file is published
- **AND** the preview MUST be denied with HTTP 401 if the file is not published


### Requirement: File Metadata Enrichment (Labels, Description, Category)

The system SHALL support rich metadata on files beyond the basic tags. Files MUST support labels (tags), a description field, and a category field. The label editing functionality in the UI MUST be fully implemented.

#### Scenario: Update file labels
- **GIVEN** file `contract.pdf` (ID 42) on object `abc-123` currently has no labels
- **WHEN** a PUT request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/labels` with body `{"labels": ["definitief", "ondertekend"]}`
- **THEN** the file MUST be tagged with the specified labels via `TaggingHandler`
- **AND** the response MUST return HTTP 200 with the updated file metadata including labels
- **AND** previously existing labels MUST be replaced (set semantics, not merge)

#### Scenario: Clear all labels from a file
- **GIVEN** file 42 has labels `["concept", "vertrouwelijk"]`
- **WHEN** a PUT request is sent with `{"labels": []}`
- **THEN** all labels MUST be removed from the file
- **AND** the response MUST return the file with an empty labels array

#### Scenario: Update file description
- **GIVEN** file `contract.pdf` (ID 42) on object `abc-123`
- **WHEN** a PUT request is sent to `/api/objects/{register}/{schema}/abc-123/files/42` (existing update endpoint) with body `{"description": "Getekend contract met leverancier XYZ d.d. 2026-03-15"}`
- **THEN** the file description MUST be stored in the OpenRegister file metadata (via `oc_openregister_files` table)
- **AND** the description MUST be returned in all file listing and detail responses

#### Scenario: Update file category
- **GIVEN** file `contract.pdf` (ID 42)
- **WHEN** a PUT request includes `{"category": "overeenkomst"}`
- **THEN** the category MUST be stored in the file metadata
- **AND** files MUST be filterable by category in the file listing endpoint

#### Scenario: Labels displayed in UI file table
- **GIVEN** the ViewObject component shows the files table with a Labels column
- **WHEN** a user clicks the "Labels" action button on a file row
- **THEN** an inline tag editor MUST appear using `NcSelect` in creatable mode
- **AND** selecting/deselecting tags MUST immediately call the labels API
- **AND** the labels column MUST update in real-time after the API responds

#### Scenario: Label autocomplete from existing labels
- **GIVEN** other files in the same register have labels `["concept", "definitief", "vertrouwelijk"]`
- **WHEN** the user opens the label editor and starts typing
- **THEN** existing labels MUST be suggested as autocomplete options
- **AND** the user MUST also be able to create new labels


### Requirement: Download with Access Logging

The system SHALL log all file download events to the audit trail for compliance and analytics. Every download of a file (via the show, downloadById, or new download endpoint) MUST create an audit trail entry.

#### Scenario: Authenticated download logged
- **GIVEN** user `behandelaar-1` downloads file `rapport.pdf` (ID 42) from object `abc-123`
- **WHEN** the file is streamed to the client
- **THEN** an audit trail entry MUST be created with:
  - `action: "file.downloaded"`
  - `userId: "behandelaar-1"`
  - `objectUuid: "abc-123"`
  - `data: {"fileId": 42, "fileName": "rapport.pdf", "fileSize": 245760, "mimeType": "application/pdf"}`

#### Scenario: Anonymous download logged
- **GIVEN** file 42 is published and accessed via a public endpoint
- **WHEN** the file is downloaded without authentication
- **THEN** an audit trail entry MUST be created with `userId: "anonymous"` and `data` including the remote IP address and user-agent

#### Scenario: Download count in file metadata
- **GIVEN** file 42 has been downloaded 15 times
- **WHEN** the file metadata is returned in any listing or detail endpoint
- **THEN** the response SHOULD include `downloadCount: 15` computed from audit trail entries
- **AND** the count SHOULD be cached and refreshed periodically (not computed per request)

#### Scenario: Bulk download (ZIP archive) logged
- **GIVEN** a download of all files for object `abc-123` as a ZIP archive
- **WHEN** the archive is generated and streamed
- **THEN** ONE audit trail entry MUST be created with `action: "file.bulk_downloaded"` and `data` listing all included file IDs and names


### Requirement: File Action Events

All new file actions (rename, copy, move, lock, unlock, version restore) MUST dispatch Nextcloud events via `OCP\EventDispatcher\IEventDispatcher` following the existing event-driven architecture patterns. Events enable external workflows (n8n) and webhook integrations.

#### Scenario: Rename dispatches event
- **GIVEN** a file is renamed
- **WHEN** the rename succeeds
- **THEN** an event `nl.openregister.object.file.renamed` MUST be dispatched with payload including object UUID, file ID, old name, new name

#### Scenario: Copy dispatches event
- **GIVEN** a file is copied to another object
- **WHEN** the copy succeeds
- **THEN** an event `nl.openregister.object.file.copied` MUST be dispatched with source and target details

#### Scenario: Move dispatches event
- **WHEN** a file move succeeds
- **THEN** an event `nl.openregister.object.file.moved` MUST be dispatched

#### Scenario: Lock/unlock dispatches events
- **WHEN** a file is locked or unlocked
- **THEN** events `nl.openregister.object.file.locked` and `nl.openregister.object.file.unlocked` MUST be dispatched respectively

#### Scenario: Version restore dispatches event
- **WHEN** a file version is restored
- **THEN** an event `nl.openregister.object.file.version_restored` MUST be dispatched with the version ID and file ID

## Non-Functional Requirements

- **Performance**: File rename, lock, and unlock MUST complete within 500ms. Batch operations of up to 100 files MUST complete within 30 seconds. Preview generation MUST complete within 2 seconds.
- **Concurrency**: Lock checking MUST be atomic to prevent race conditions.
- **Backward Compatibility**: All existing file endpoints MUST continue to work unchanged. New endpoints are additive.
- **i18n**: Error messages MUST be translatable via Nextcloud's `IL10N` interface. Minimum languages: Dutch (nl) and English (en).
- **RBAC**: All new endpoints MUST respect the same access controls as existing file endpoints. Object write access is required for rename, copy, move, lock, unlock, delete, and label operations. Object read access is required for version listing and preview.

## Implementation Notes

### Database Changes
The `oc_openregister_files` table needs additional columns:
- `description` (TEXT, nullable) -- File description
- `category` (VARCHAR(255), nullable) -- File category
- `locked_by` (VARCHAR(64), nullable) -- User ID who locked the file
- `locked_at` (DATETIME, nullable) -- When the lock was acquired
- `lock_expires` (DATETIME, nullable) -- When the lock expires
- `download_count` (INT, default 0) -- Cached download count

### Dependency Diagram

```
FilesController
  |
  +-- FileService (orchestrator)
        |
        +-- FileVersioningHandler
        |     +-- IVersionManager (from files_versions)
        |     +-- IRootFolder
        |
        +-- FileLockHandler
        |     +-- FileMapper (for lock metadata)
        |     +-- IUserSession
        |     +-- IGroupManager (admin check)
        |
        +-- FileBatchHandler
        |     +-- FilePublishingHandler (existing)
        |     +-- DeleteFileHandler (existing)
        |     +-- TaggingHandler (existing)
        |
        +-- FilePreviewHandler
        |     +-- IPreview
        |     +-- IRootFolder
        |
        +-- FileAuditHandler
              +-- AuditTrailMapper (existing)
              +-- IUserSession
```

### Nextcloud Dependencies
| Interface | Used By | Purpose |
|-----------|---------|---------|
| `OCA\Files_Versions\Versions\IVersionManager` | FileVersioningHandler | Version listing and restore |
| `OCP\Lock\ILockingProvider` | FileLockHandler | Storage-level file locking |
| `OCP\IPreview` | FilePreviewHandler | Thumbnail/preview generation |
| `OCP\EventDispatcher\IEventDispatcher` | FileService | Event dispatching for new actions |
| `OCA\OpenRegister\Db\AuditTrailMapper` | FileAuditHandler | Download access logging |
| `OCA\OpenRegister\Db\FileMapper` | FileLockHandler | Lock metadata persistence |
