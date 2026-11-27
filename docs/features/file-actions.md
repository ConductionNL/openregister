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
- **Uploads in parts** -- A file too large for one request is sent in parts with stated boundaries, resumes after a broken connection, and is checked by size and SHA-256 before it exists. See [Uploading in parts](#uploading-in-parts).
- **Create from a URL** -- `POST .../files` accepts `source: {url}` in place of `content`; the server fetches the bytes itself. See [Creating a file from a URL](#creating-a-file-from-a-url).
- **Download Audit** -- All file downloads (via `downloadById`) are tracked for compliance and audit trail purposes.
- **Label Management** -- Attach, update, or remove classification labels on files (`updateLabels`). Supports arbitrary key-value label sets.
- **Permission-based Access** -- File access is authorized by Nextcloud node permissions, not ownership: read permission to list/view, write permission to update, delete permission to delete. Objects may link files owned by any user (including files reached via a share); a file is not force-transferred to the OpenRegister system user when the current user already has write access. Ownership follows the containing folder's owner.

## API Endpoints

All endpoints are scoped under `/api/objects/{register}/{schema}/{id}/files` unless otherwise noted.

| Method | URL | Controller | Description | Route Registered |
|--------|-----|------------|-------------|------------------|
| GET | `.../files` | `files#index` | List files for an object | Yes |
| GET | `.../files/{fileId}` | `files#show` | Get single file metadata | Yes |
| POST | `.../files` | `files#create` | Upload file (JSON body: `content`, or `source.url` to fetch it) | Yes |
| POST | `.../files/save` | `files#save` | Save file content | Yes |
| POST | `.../files-multipart` (legacy alias `.../filesMultipart`) | `files#createMultipart` | Upload via multipart form | Yes |
| PUT | `.../files/{fileId}` | `files#update` | Update file metadata | Yes |
| DELETE | `.../files/{fileId}` | `files#delete` | Delete a file | Yes |
| POST | `.../files/{fileId}/publish` | `files#publish` | Publish a file | Yes |
| POST | `.../files/{fileId}/depublish` | `files#depublish` | Depublish a file | Yes |
| GET | `/api/files/{fileId}/download` | `files#downloadById` | Download file by ID | Yes |
| GET | `.../files/download` | `objectFilesArchive#downloadFiles` | Download all files as ZIP | Yes |
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
| POST | `/api/objects/{register}/{schema}/{id}/uploads` | `chunkedUpload#open` | Open an upload in parts | Yes |
| PUT | `.../uploads/{uploadId}/parts/{n}` | `chunkedUpload#part` | Send part n (raw bytes) | Yes |
| GET | `.../uploads/{uploadId}` | `chunkedUpload#status` | Read received and missing parts | Yes |
| POST | `.../uploads/{uploadId}/complete` | `chunkedUpload#complete` | Assemble, verify and create the file | Yes |

## Creating a file from a URL

`POST /api/objects/{register}/{schema}/{id}/files` takes either `content` or `source`, never both:

```json
{"name": "besluit.pdf", "source": {"url": "https://example.org/besluit.pdf"}, "share": false, "tags": ["woo"]}
```

- The object-level access check runs first, exactly as for a content upload; a caller who may not add files gets 403 or 404 and nothing is fetched.
- The server fetches the URL itself: only http(s) to a public address, no redirects, a 30 second timeout, and the instance's upload ceiling (the lower of PHP's `upload_max_filesize` and `post_max_size`) enforced while reading.
- The fetched bytes pass the same upload checks as uploaded content, and are stored as they came (never read as base64).
- The created file carries where it came from: `metadata.sourceUrl`, `metadata.fetchedAt` (ISO 8601, UTC) and `metadata.sha256` in the response, kept on the file in Nextcloud's file metadata, and a `file.fetched` row on the object's audit trail.
- Both `content` and `source`, or neither: 400. A `source` without a URL: 400.
- A fetch that produces no file answers 422 `{error, reason}`, and nothing is written. `reason` is one of `refused-address`, `timeout`, `status` (anything outside 2xx, a redirect included), `too-large`, `empty`.

A plain string in `content` stays content: a URL there is never fetched. Linking a document by reference without copying it is opencatalogi's publish-by-reference, not this endpoint.

## Uploading in parts

For a file too large for one request (a scan or a mail export of hundreds of megabytes). All four routes run the same object check as a file upload first (404 for a caller who may not read the object, 403 for one who may not add files to it, 409 for a frozen or archived object), and a session answers only to the user who opened it, on its own object, before it expires: anyone else gets 404.

1. **Open.** `POST /api/objects/{register}/{schema}/{id}/uploads` with `{"name": "mail-export.mbox", "size": 99614720, "sha256": "<64 hex digits>"}` answers 201 with `uploadId`, `partSize`, `partCount`, every part's byte range (`{n, start, end}`, `end` inclusive) and `expiresAt`. Every part but the last is exactly `partSize` bytes.
2. **Send parts.** `PUT .../uploads/{uploadId}/parts/{n}` with the part's raw bytes as the body. A part whose length differs from its range, or a number outside the plan, is refused with 400. Parts may arrive in any order.
3. **Resume.** `GET .../uploads/{uploadId}` answers the plan plus `received`, `missing` and `complete`. A client whose connection broke reads this and sends only the missing parts.
4. **Complete.** `POST .../uploads/{uploadId}/complete` assembles the parts, checks the declared size and SHA-256, runs the same upload checks as a single-request upload, and creates the file through the same path as `files#create`; it answers 201 with the file. Missing parts (named), a size mismatch or a checksum mismatch answer 409 and create nothing; the parts stay for a retry.

Parts are kept in the app's data store under `uploads/{uploadId}`, never in the object's folder. Settings (`GET`/`PUT /api/settings/files`):

| Setting | Default | Meaning |
|---------|---------|---------|
| `upload.partSizeBytes` | `10485760` (10 MiB) | The part size a new session states; 1 MiB to 100 MiB, anything else is refused with 400 |
| `upload.sessionTtl` | `PT24H` | How long an unfinished session lives after its last part |

An unfinished session past its expiry is removed with its parts by the hourly `UploadSessionCleanupJob`. Single-request uploads and Nextcloud's own WebDAV chunked upload are unchanged.

## Implementation Status

- **Registered routes (11)**: Core CRUD, publish/depublish, download, and multipart upload are fully routed.
- **Unregistered methods (10)**: Rename, copy, move, versioning, file-level locking, batch, preview, and labels exist as controller methods in `FilesController` but lack route definitions in `appinfo/routes.php`. These methods are implemented but not yet accessible via HTTP.
- **Object-level locking**: Separate from file-level locking; object lock/unlock routes exist at `/api/objects/{register}/{schema}/{id}/lock` and `/unlock` via `ObjectsController`.

## Related Files

- `/lib/Controller/FilesController.php` -- All file action controller methods
- `/appinfo/routes.php` -- Route definitions (lines 305-318)
- `/lib/Controller/ChunkedUploadController.php`, `/lib/Service/File/ChunkedUploadService.php`, `/lib/BackgroundJob/UploadSessionCleanupJob.php` -- Uploads in parts
