# File Actions

## Standards

- **GEMMA Documentbeheercomponent** -- Dutch government document management standard
- **WebDAV (RFC 4918)** -- Advisory locking semantics for concurrent file editing

## Overview

Extended file operations for register objects beyond basic CRUD. Provides versioning, advisory locking with TTL, batch operations, thumbnail preview generation, download auditing, and label management. All file action endpoints operate under an object context (`/api/objects/{register}/{schema}/{id}/files/...`).

## Key Capabilities

- **Rename / Copy / Move** -- Rename a file in place, copy to a new object or location, or move between objects. Each operation preserves audit trail entries.
- **Version Management** -- List all versions of a file (`listVersions`) and restore a previous version (`restoreVersion`). Built on Nextcloud's file versioning backend.
- **Advisory Locking with TTL** -- Lock a file to signal editing intent. Locks are advisory (not enforced at filesystem level) and expire after a configurable TTL. Unlock explicitly or let TTL expire.
- **Batch Operations** -- Perform publish, depublish, or delete on multiple files in a single request. Returns HTTP 207 Multi-Status with per-file results.
- **Thumbnail Preview** -- Generate and serve thumbnail previews for supported file types. Returns a stream response for direct embedding.
- **Download Audit** -- All file downloads (via `downloadById`) are tracked for compliance and audit trail purposes.
- **Label Management** -- Attach, update, or remove classification labels on files (`updateLabels`). Supports arbitrary key-value label sets.

## API Endpoints

All endpoints are scoped under `/api/objects/{register}/{schema}/{id}/files` unless otherwise noted.

| Method | URL | Controller | Description | Route Registered |
|--------|-----|------------|-------------|------------------|
| GET | `.../files` | `files#index` | List files for an object | Yes |
| GET | `.../files/{fileId}` | `files#show` | Get single file metadata | Yes |
| POST | `.../files` | `files#create` | Upload file (JSON body) | Yes |
| POST | `.../files/save` | `files#save` | Save file content | Yes |
| POST | `.../filesMultipart` | `files#createMultipart` | Upload via multipart form | Yes |
| PUT | `.../files/{fileId}` | `files#update` | Update file metadata | Yes |
| DELETE | `.../files/{fileId}` | `files#delete` | Delete a file | Yes |
| POST | `.../files/{fileId}/publish` | `files#publish` | Publish a file | Yes |
| POST | `.../files/{fileId}/depublish` | `files#depublish` | Depublish a file | Yes |
| GET | `/api/files/{fileId}/download` | `files#downloadById` | Download file by ID | Yes |
| GET | `.../files/download` | `objects#downloadFiles` | Download all files as ZIP | Yes |
| POST | `.../files/{fileId}/rename` | `files#rename` | Rename a file | No (method only) |
| POST | `.../files/{fileId}/copy` | `files#copy` | Copy a file | No (method only) |
| POST | `.../files/{fileId}/move` | `files#move` | Move a file | No (method only) |
| GET | `.../files/{fileId}/versions` | `files#listVersions` | List file versions | No (method only) |
| POST | `.../files/{fileId}/versions/restore` | `files#restoreVersion` | Restore a file version | No (method only) |
| POST | `.../files/{fileId}/lock` | `files#lock` | Lock a file (advisory) | No (method only) |
| POST | `.../files/{fileId}/unlock` | `files#unlock` | Unlock a file | No (method only) |
| POST | `.../files/batch` | `files#batch` | Batch publish/depublish/delete | No (method only) |
| GET | `.../files/{fileId}/preview` | `files#preview` | Get file thumbnail preview | No (method only) |
| PUT | `.../files/{fileId}/labels` | `files#updateLabels` | Update file labels | No (method only) |

## Implementation Status

- **Registered routes (11)**: Core CRUD, publish/depublish, download, and multipart upload are fully routed.
- **Unregistered methods (10)**: Rename, copy, move, versioning, file-level locking, batch, preview, and labels exist as controller methods in `FilesController` but lack route definitions in `appinfo/routes.php`. These methods are implemented but not yet accessible via HTTP.
- **Object-level locking**: Separate from file-level locking; object lock/unlock routes exist at `/api/objects/{register}/{schema}/{id}/lock` and `/unlock` via `ObjectsController`.

## Related Files

- `/lib/Controller/FilesController.php` -- All file action controller methods
- `/appinfo/routes.php` -- Route definitions (lines 305-318)
