## MODIFIED Requirements

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

## ADDED Requirements

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
