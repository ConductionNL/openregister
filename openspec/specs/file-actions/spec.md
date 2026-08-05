# file-actions Specification

## Purpose

@e2e exclude backend file pipeline — covered by PHPUnit
TBD - created by archiving change retrofit-2026-05-25-bw-svc-file. Update Purpose after archive.
## Requirements
### Requirement: File creation and upsert run a fixed validate-write-own-tag pipeline

`CreateFileHandler::addFile()` MUST create a Nextcloud file under the object's
folder following a fixed ordered pipeline, and `CreateFileHandler::saveFile()`
MUST provide an upsert wrapper over it. The pipeline MUST:

- resolve the object folder via `FolderManagementHandler::getObjectFolder()`,
  tolerating an as-yet-uncreated object (a `DoesNotExistException` on a string
  UUID is swallowed so files can be staged ahead of object creation, e.g. during
  synchronisation);
- decode `data:` URI / base64 content heuristically — a `data:<mime>;base64,`
  prefix is stripped, then a strict `base64_decode` round-trip is applied and the
  decoded bytes used only when re-encoding reproduces the input exactly;
- reject an empty `$fileName` by throwing;
- call `FileValidationHandler::blockExecutableFile()` BEFORE the node is created
  (see REQ-010);
- create the node (owned by the folder's mount owner — the `openregister` system
  user for the OpenRegister folder, or the original owner for a folder linked
  from outside it), assert read access via `FileValidationHandler::checkOwnership()`,
  write content, then call `FileOwnershipHandler::transferFileOwnershipIfNeeded()`,
  which re-owns the node to the system user ONLY as a fallback when the current
  user lacks write rights (see the system-user share model requirement);
- optionally create a public share link when `$share === true`;
- ALWAYS attach an automatic `object:<uuid-or-id>` tag merged with caller tags
  via `FileService::generateObjectTag()` + `attachTagsToFile()`.

`saveFile()` MUST first look up an existing file of the same name for the object
via `FileService::getFile()`; when found it MUST delegate to
`FileService::updateFile()` (preserving the existing node), otherwise it MUST
delegate to `addFile()`. Both methods MUST wrap failures and rethrow as
`NotPermittedException` (permission errors) or `Exception` with a
`"Failed to create file <name>"` / `"Cannot save file <name>"` prefix.

#### Scenario: Add a new file to an object
- **GIVEN** an `ObjectEntity` with a resolvable object folder and a non-empty filename
- **WHEN** `CreateFileHandler::addFile(object, "report.pdf", content)` is called
- **THEN** the handler MUST block executable content, create the node, write content, leave ownership following the folder (re-owning to the system user only as a fallback), and attach an `object:<uuid>` tag
- **AND** the created `File` MUST be returned

#### Scenario: Base64 data-URI content is decoded
- **GIVEN** content of the form `data:application/pdf;base64,<b64>`
- **WHEN** `addFile()` processes the content
- **THEN** the handler MUST strip the data-URI prefix and write the decoded bytes when the base64 round-trip is exact

#### Scenario: Empty filename is rejected
- **GIVEN** an empty string filename
- **WHEN** `addFile()` is called
- **THEN** the handler MUST throw an `Exception` naming the object id and MUST NOT create a node

#### Scenario: Upsert updates an existing file
- **GIVEN** an object that already has a file named `data.json`
- **WHEN** `saveFile(object, "data.json", newContent)` is called
- **THEN** the handler MUST delegate to `FileService::updateFile()` for the existing node rather than creating a duplicate

#### Scenario: Upsert creates a missing file
- **GIVEN** an object with no file named `data.json`
- **WHEN** `saveFile(object, "data.json", content)` is called
- **THEN** the handler MUST delegate to `addFile()`

### Requirement: File retrieval resolves by id or name and projects nodes to metadata

`ReadFileHandler` MUST expose three retrieval paths and `FileFormattingHandler`
MUST project Nextcloud nodes into the public metadata shape.

- `ReadFileHandler::getFile(object, file)` MUST treat `$file` as a Nextcloud file
  ID when it is an integer OR an all-digit string (the `$object` parameter is then
  effectively ignored for resolution); otherwise it MUST resolve `$file` as a
  name/path within the object folder, trying the bare filename first and the full
  cleaned path second. It MUST call `FileValidationHandler::checkOwnership()` on
  the resolved node and MUST return `null` (not throw) when the file is absent.
- `ReadFileHandler::getFileById(fileId)` MUST resolve via `IRootFolder::getById()`,
  return `null` for a missing node or a non-`File` node, call `checkOwnership()`,
  and swallow lookup errors as `null`.
- `ReadFileHandler::getFiles(object, sharedFilesOnly, category)` MUST list the
  object's files via `FileService::getFilesForEntity()` and, when a `$category`
  is given AND a `FileMapper` is wired, MUST filter to nodes whose OR-side row
  carries exactly that category (nodes without an OR-side row MUST be excluded —
  left-join `WHERE category = :cat` semantics).
- `FileFormattingHandler::formatFile(node)` MUST return a metadata array carrying
  at least `id`, `path`, `title`, `accessUrl`, `downloadUrl`, `type`, `extension`,
  `size`, `hash`, `published`, `modified`, and `labels`; labels containing `:`
  MUST be split into `key`/`value` metadata entries; NC lock state and OR-side
  enrichment (`description`/`category`/`downloadCount`/`orLock`) MUST be appended
  ONLY for authenticated callers.
- `FileFormattingHandler::formatFiles(files, requestParams)` MUST format each node
  (emitting a minimal `{id, title, error: "locked"}` stub on per-file
  `LockedException` rather than failing the whole listing), apply label/extension/
  size/title/search filters, and paginate with a floor-of-1 page and limit and no
  upper limit ceiling.

#### Scenario: Resolve a file by numeric id
- **GIVEN** `getFile(object, 42)` or `getFile(object, "42")`
- **WHEN** the handler resolves the file
- **THEN** it MUST treat the value as a Nextcloud file ID, check ownership, and return the `File` or `null`

#### Scenario: Resolve a file by name within the object folder
- **GIVEN** `getFile(object, "report.pdf")`
- **WHEN** the bare filename is not found
- **THEN** the handler MUST retry with the full cleaned path before returning `null`

#### Scenario: Listing filters by OR-side category
- **GIVEN** `getFiles(object, false, "invoice")` with a wired `FileMapper`
- **WHEN** the object has files with and without an `invoice` OR-side category
- **THEN** only nodes whose OR-side row category equals `"invoice"` MUST be returned

#### Scenario: Locked file in a listing does not break the page
- **GIVEN** a listing where one node raises `LockedException` during formatting
- **WHEN** `formatFiles()` runs
- **THEN** that entry MUST be replaced by a minimal stub `{id, title, error: "locked"}` and the remaining files MUST still be returned

#### Scenario: Lock and OR-side fields are gated on authentication
- **GIVEN** an anonymous caller formatting a file
- **WHEN** `formatFile()` builds the metadata
- **THEN** the `locked`/`lock`/`downloadCount`/`orLock` fields MUST be omitted

### Requirement: File update guards locks, preserves object tags, and persists OR-side metadata separately

`UpdateFileHandler::updateFile()` MUST update content and/or tags of an existing
file resolved by ID or name/path, and `UpdateFileHandler::updateFileMetadata()`
MUST persist OR-side metadata independently of the Nextcloud node.

`updateFile()` MUST:
- resolve the file by ID (object folder first, then OR user folder) or by
  name/path, throwing `"File <path> does not exist"` when unresolved;
- write content ONLY when content is provided AND its md5 differs from the
  current node hash, decoding base64 content, calling
  `FileValidationHandler::blockExecutableFile()` and `checkOwnership()` before the
  write, then `FileOwnershipHandler::transferFileOwnershipIfNeeded()`;
- on a tag update, PRESERVE existing tags whose name starts with `object:` and
  replace all other tags, de-duplicating the merged set.

`updateFileMetadata(fileId, description, category, labels)` MUST throw when no
`FileMapper` is wired, MUST lazy-create the OR-side row, and MUST treat each of
`description`/`category`/`labels` as independently optional — a `null` argument
leaves the field unchanged while an explicit value (including `""` or `[]`)
overwrites it. Document content rewrites (`DocumentProcessingHandler::replaceWords`
for `.doc`/`.docx` via PHPWord and text files, and `::anonymizeDocument` which
builds `[ENTITY_TYPE: key]` replacements) MUST write the result as a new
`<name>_replaced` / `<name>_anonymized` sibling node rather than mutating the
source. Write-path lock guarding MUST go through
`FileLockHandler::assertCanModify()`, which throws when the file is locked by
another user, and lock-state reads MUST go through `FileLockHandler::getLockInfo()`,
which defensively clears malformed or expired entries and returns `null`.

#### Scenario: Content is written only when it changed
- **GIVEN** an `updateFile()` call whose new content md5 equals the node's current hash
- **WHEN** the handler evaluates the content branch
- **THEN** it MUST skip the write

#### Scenario: Object tags survive a tag update
- **GIVEN** a file carrying `object:abc` plus user tags `["draft"]`
- **WHEN** `updateFile(..., tags: ["final"])` is called
- **THEN** the resulting tag set MUST retain `object:abc` and replace `draft` with `final`

#### Scenario: Metadata update requires a wired FileMapper
- **GIVEN** `updateFileMetadata()` on a handler with no `FileMapper`
- **WHEN** it is called
- **THEN** it MUST throw `"FileMapper is not wired; cannot update OR-side metadata"`

#### Scenario: Partial metadata update leaves unset fields untouched
- **GIVEN** `updateFileMetadata(fileId, description: "x", category: null, labels: null)`
- **WHEN** the handler runs
- **THEN** only the description MUST be written; category and labels MUST be unchanged

#### Scenario: Document anonymisation produces a new node
- **GIVEN** a `.docx` node and a list of detected entities
- **WHEN** `anonymizeDocument()` runs
- **THEN** a new `<base>_anonymized.docx` sibling MUST be created with `[TYPE: key]` replacements, leaving the source intact

#### Scenario: Write to a file locked by another user is blocked
- **GIVEN** a file locked by user `bob`
- **WHEN** the current user `alice` triggers a write and `assertCanModify(fileId)` is reached
- **THEN** the guard MUST throw an `Exception` naming `bob`

### Requirement: Publishing, ZIP export, sharing and batch operations follow the system-user share model

The system MUST expose file publishing, object-files ZIP export, user/folder
sharing, and a bounded multi-file batch dispatcher, all anchored on the
OpenRegister system user.

- `FilePublishingHandler::publishFile(object, file)` MUST resolve the file by ID
  (via `FileService::getFile()`) or by name/path within the object folder, verify
  it is a `File`, call `checkOwnership()`, and create the public share by writing
  directly through `FileMapper::publishFile()` (NOT `IManager`), returning the
  `File`.
- `FilePublishingHandler::unpublishFile(object, filePath)` MUST resolve the file
  the same way and remove all public shares via `FileMapper::depublishFile()`,
  treating zero deleted shares as a non-error.
- `FilePublishingHandler::createObjectFilesZip(object, zipName)` MUST collect the
  object's files via `FileService::getFiles()`, require the `ZipArchive`
  extension, skip non-`File` nodes and per-file errors (incrementing a skipped
  count), and return `{path, filename, size, mimeType: "application/zip"}`.
- `FileSharingHandler::findShares(node, shareType)` MUST query shares as the
  system user (`FileOwnershipHandler::getUser()`), defaulting `shareType` to
  public-link (3).
- `FileSharingHandler::createShare(shareData)` MUST build an `IShare` from the
  supplied node/nodeId, share type, permissions and `sharedWith`, attribute it to
  the file owner when available (else the system user), and rethrow a wrapped
  `Exception` on failure.
- `FileSharingHandler::shareFileWithUser(file, userId, permissions)` MUST be
  idempotent (skip when a user share already exists) and MUST rethrow on failure.
- `FileSharingHandler::shareFolderWithUser(folder, userId, permissions)` MUST
  return `null` when the target user does not exist AND MUST return `null` (after
  logging) rather than throwing on a share failure — a deliberately softer
  contract than `shareFileWithUser`.
- `FileBatchHandler::executeBatch(object, action, fileIds, params)` MUST accept
  only `publish`/`depublish`/`delete`/`label`, reject more than 100 file IDs and
  an empty list, and return per-file `{fileId, success[, error]}` results plus a
  `{total, succeeded, failed}` summary, continuing past individual failures.
- `FileOwnershipHandler::getUser()` MUST get-or-create the `openregister` system
  user and group. `transferFileOwnershipIfNeeded()` / `transferFolderOwnershipIfNeeded()`
  MUST NOT re-own a node when the current user already has write rights on it
  (`Node::isUpdateable()`) — ownership is left following the folder's mount owner.
  Only as a fallback, when the current user owns the node but does not have write
  rights on it, MUST they transfer ownership to the system user and re-share with
  the current user, swallowing failures so the underlying file operation still
  succeeds.

#### Scenario: Publish writes the share via FileMapper
- **GIVEN** a resolvable file under an object
- **WHEN** `publishFile(object, fileId)` runs
- **THEN** the share MUST be created through `FileMapper::publishFile()` and the `File` returned

#### Scenario: Unpublish with no existing shares is not an error
- **GIVEN** a file with no public shares
- **WHEN** `unpublishFile(object, fileId)` runs
- **THEN** `FileMapper::depublishFile()` MUST be called and a zero deleted-shares result MUST NOT raise

#### Scenario: ZIP export skips non-file nodes
- **GIVEN** an object folder containing a sub-folder and two files
- **WHEN** `createObjectFilesZip(object)` runs
- **THEN** the two files MUST be added and the folder skipped, returning a `application/zip` descriptor

#### Scenario: Folder share to a missing user returns null
- **GIVEN** `shareFolderWithUser(folder, "ghost")` where `ghost` does not exist
- **WHEN** the method runs
- **THEN** it MUST return `null` without throwing

#### Scenario: Batch rejects oversized requests
- **GIVEN** `executeBatch(object, "delete", fileIds)` with 101 IDs
- **WHEN** the method runs
- **THEN** it MUST throw before performing any deletion

#### Scenario: Batch continues past per-file failures
- **GIVEN** a 3-file `delete` batch where the middle file fails
- **WHEN** `executeBatch()` runs
- **THEN** the result MUST report `succeeded: 2, failed: 1` with a per-file error entry for the failure

#### Scenario: Owned file the user can write is not re-owned
- **GIVEN** a node the current session user owns and can write (`isUpdateable()` is true)
- **WHEN** `transferFileOwnershipIfNeeded(file)` runs
- **THEN** it MUST return without changing ownership and MUST NOT create a share-back

### Requirement: Uploaded files screened for executable content and ownership repaired safely

`FileValidationHandler` MUST screen uploaded content for executables and MUST
gate file access on readability without forcing content reads.

- `blockExecutableFile(fileName, fileContent)` MUST reject the file when its
  extension is in the dangerous-extension list (Windows/Unix executables,
  scripts including `php`/`js`/`py`/`sh`, packages, mobile/macOS installers) and,
  when content is non-empty, MUST delegate to `detectExecutableMagicBytes()`.
- `detectExecutableMagicBytes(content, fileName)` MUST reject content beginning
  with known executable signatures (`MZ`, `\x7FELF`, shell/bash/env shebangs,
  `<?php`, Java `\xCA\xFE\xBA\xBE`), MUST scan the first 1 KiB for a
  script shebang (`#!.../sh|bash|...|node`), and MUST reject embedded
  `<?php`/`<?=`/`<script language="php">` markers — throwing a descriptive
  `Exception` on any match.
- `checkOwnership(node)` MUST gate access on `Node::isReadable()` alone (a pure
  permission-bitmask check that does NOT read content and does NOT acquire an NC
  lock, so it is safe in a hot listing loop). Access is **ownership-agnostic**:
  a node the session can read MUST be allowed regardless of its owner — including
  a node owned by another user and reached through a Nextcloud file share, or
  owned by the `openregister` system user. It MUST throw `NotPermittedException`
  only when the node is not readable. It MUST NOT deny on owner mismatch and MUST
  NOT invoke `ownFile()` repair on the access path.
- `ownFile(node)` MUST set the OR-side ownership record to the system user via
  `FileMapper::setFileOwnership()`, returning the mapper's boolean result and
  rethrowing a wrapped `Exception` on error. It is invoked only by explicit
  ownership-management flows, never as a side effect of an access check.

#### Scenario: Executable extension is rejected
- **GIVEN** a file named `payload.exe`
- **WHEN** `blockExecutableFile("payload.exe", content)` runs
- **THEN** it MUST throw an `Exception` identifying the executable extension before any node is created

#### Scenario: Renamed executable is caught by magic bytes
- **GIVEN** a file named `notes.txt` whose content begins with `MZ`
- **WHEN** `blockExecutableFile()` runs
- **THEN** `detectExecutableMagicBytes()` MUST throw because the content signature is a Windows executable

#### Scenario: PHP content is rejected regardless of name
- **GIVEN** content beginning with `<?php`
- **WHEN** `detectExecutableMagicBytes()` runs
- **THEN** it MUST throw, blocking the upload

#### Scenario: Unreadable node is refused
- **GIVEN** a node where `isReadable()` returns false
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST throw `NotPermittedException` and MUST NOT attempt ownership repair

#### Scenario: Readable node owned by another user is allowed
- **GIVEN** a readable node whose owner is a different user (e.g. reached via a file share) or `null`
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST return without error and MUST NOT call `ownFile()` / `setFileOwnership()`

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

### Requirement: File update and delete enforce per-action node permissions

File mutation MUST be gated on the matching Nextcloud node permission for the
action, in addition to Nextcloud's native enforcement, so the caller fails fast
with a clear `NotPermittedException` rather than an opaque storage error.

- `UpdateFileHandler` MUST assert `Node::isUpdateable()` before calling
  `File::putContent()`, throwing `NotPermittedException` naming the file when the
  session lacks write permission.
- `DeleteFileHandler` MUST assert `Node::isDeletable()` before calling
  `File::delete()`, throwing `NotPermittedException` naming the file when the
  session lacks delete permission.

#### Scenario: Update without write permission is refused
- **GIVEN** a readable file the session may NOT write (`isUpdateable()` is false)
- **WHEN** `UpdateFileHandler` updates its content
- **THEN** it MUST throw `NotPermittedException` and MUST NOT call `putContent()`

#### Scenario: Delete without delete permission is refused
- **GIVEN** a readable file the session may NOT delete (`isDeletable()` is false)
- **WHEN** `DeleteFileHandler` deletes it
- **THEN** it MUST throw `NotPermittedException` and MUST NOT call `delete()`

#### Scenario: Writable file is updated
- **GIVEN** a file the session may write (`isUpdateable()` is true)
- **WHEN** `UpdateFileHandler` updates its content
- **THEN** `File::putContent()` MUST be called with the new content


### Requirement: Object register folder management

Every register, schema and object owns a backing Nextcloud folder, and that
folder is provisioned on demand rather than assumed to exist. Callers reach
files through the entity, so an entity without a folder is not a degraded
state to be reported — it is one to be repaired before the first file arrives.

- `RegisterService::createFromArray()` MUST provision the register's folder
  after the mapper has created the row, and MUST persist the resulting folder
  id back onto the register.
- `RegisterService::updateFromArray()` MUST ensure the folder exists on every
  update. A register whose `folder` is null, empty, or a legacy string PATH
  (rather than a numeric node id) MUST be healed by creating the folder and
  storing its id, so documents written before folder ids were stored keep
  working without a migration.
- `FileService::createEntityFolder()` MUST nest an object's folder under its
  register's folder, so the hierarchy on disk mirrors the data model.
- Folder creation MUST be idempotent: creating a folder that already exists
  MUST return the existing node rather than fail or duplicate it.
- Failure to create a folder MUST be logged and MUST NOT abort the create or
  update. The entity is still valid; only its file surface is unavailable, and
  the next write repairs it.

#### Scenario: Creating a register provisions its folder
- **GIVEN** a register created from array data
- **WHEN** `createFromArray()` returns
- **THEN** the register MUST carry the numeric node id of a folder that exists

#### Scenario: A legacy string folder path is healed on update
- **GIVEN** a stored register whose `folder` is a string path, not a node id
- **WHEN** `updateFromArray()` runs
- **THEN** a folder MUST be created and its numeric id stored on the register

#### Scenario: Folder creation failure does not abort the write
- **GIVEN** a register whose folder cannot be created
- **WHEN** `createFromArray()` runs
- **THEN** the register MUST still be returned, and the failure MUST be logged
