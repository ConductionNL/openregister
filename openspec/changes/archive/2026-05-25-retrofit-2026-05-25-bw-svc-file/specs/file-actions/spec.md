# file-actions

## Purpose

OpenRegister's per-object file surface (`lib/Service/File/*Handler`) ships
creation/upsert, retrieval, content+metadata update, publishing/ZIP export,
sharing, and upload-security behavior that the `retrofit-2026-05-24-file-actions`
pass explicitly deferred to a follow-up (its `## DROP — future-pass:next`
section). This delta reverse-specs that deferred slice as five new requirements
so the spec reflects the live, shipped system. No code changes — these
requirements describe observed behavior.

**Cross-references**: [file-actions main spec](../../../../specs/file-actions/spec.md) (when finalised); `retrofit-2026-05-24-file-actions` (the prior REQ-001…REQ-005 pass covering CRUD/lock/preview/folder/object-tagging); [audit-trail-immutable](../../../../specs/audit-trail-immutable/spec.md).

## ADDED Requirements

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
- create the node, call `FileValidationHandler::checkOwnership()`, write content,
  then `FileOwnershipHandler::transferFileOwnershipIfNeeded()`;
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
- **THEN** the handler MUST block executable content, create the node, write content, transfer ownership, and attach an `object:<uuid>` tag
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
  user and group; `transferFileOwnershipIfNeeded()` / `transferFolderOwnershipIfNeeded()`
  MUST transfer ownership to that system user (when the current user owns the node
  and is not the system user) and re-share with the current user, swallowing
  failures so the underlying file operation still succeeds.

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

### Requirement: Uploaded files screened for executable content and ownership repaired safely

`FileValidationHandler` MUST screen uploaded content for executables and repair
drifted OpenRegister ownership without forcing content reads.

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
- `checkOwnership(node)` MUST probe access via `Node::isReadable()` (a pure
  permission-bitmask check that does NOT read content and does NOT acquire an NC
  lock, so it is safe in a hot listing loop). It MUST throw
  `NotPermittedException` when the node is not readable, and when readable but the
  owner has drifted from the system user it MUST attempt `ownFile()` as a
  best-effort repair whose failure is logged and swallowed.
- `ownFile(node)` MUST set the OR-side ownership record to the system user via
  `FileMapper::setFileOwnership()`, returning the mapper's boolean result and
  rethrowing a wrapped `Exception` on error.

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

#### Scenario: Unreadable node is refused without repair
- **GIVEN** a node where `isReadable()` returns false
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST throw `NotPermittedException` and MUST NOT attempt ownership repair

#### Scenario: Readable node with drifted owner is repaired best-effort
- **GIVEN** a readable node whose owner is not the system user
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST call `ownFile()`, and a failure inside `ownFile()` MUST be logged and swallowed so the caller is not failed

## Non-Functional Requirements

- **i18n (ADR-007)**: These handlers are a service-layer file surface. The exception messages they throw (e.g. `"Failed to create file <name>"`, `"File <path> does not exist"`, the executable-rejection messages) are propagated by the calling controller as error-response bodies, so they are user-facing and SHOULD be translatable (Dutch + English). The shipped handlers throw plain English `Exception`/`NotPermittedException` messages and do not route through `IL10N`; per the reverse-spec mandate this is captured as observed behaviour, not changed here. Translation belongs to the controller layer that wraps these throws.
- **Security (ADR-002)**: Upload screening (REQ-010) MUST reject executable content by both extension and magic-byte/shebang/`<?php` content scan of the first 1 KiB before a node is created; ownership probing uses a permission-bitmask check (`isReadable()`) that neither reads content nor acquires an NC lock, keeping it safe in hot listing loops. Lock state (REQ-008) is gated to authenticated callers only.
- **Layering (ADR-003)**: Behaviour lives in single-responsibility `Service/File/*Handler` classes coordinated by `FileService`; OR-side metadata is persisted via `FileMapper`, NC nodes via `IRootFolder`. No controller-to-mapper shortcut is specified. The `FilePublishingHandler` writes shares directly through `FileMapper` rather than `IManager` — captured as an observed deviation in the change Notes, not endorsed as a target pattern.
- **Resilience**: `formatFiles` emits a per-file `{id, title, error: "locked"}` stub on `LockedException` rather than failing the whole listing; batch operations continue past per-file failures and report a `{total, succeeded, failed}` summary.

## Acceptance Criteria

- [x] REQ-006: create/upsert runs the fixed resolve-folder → decode → block-executable → create → check-ownership → write → transfer-ownership → tag pipeline; `saveFile()` upserts (update existing same-name file, else add).
- [x] REQ-007: retrieval resolves numeric/all-digit `$file` as a file id and other values as a name/path (bare then full path), gates lock/OR-side fields to authenticated callers, and filters listings by OR-side category when a `FileMapper` is wired.
- [x] REQ-008: content is written only when md5 differs; `object:`-prefixed tags are preserved across tag updates; OR-side metadata fields are independently optional; document rewrites produce `_replaced`/`_anonymized` sibling nodes; writes are lock-guarded via `assertCanModify()`.
- [x] REQ-009: publishing/unpublishing write through `FileMapper`; ZIP export skips non-file nodes; sharing follows the system-user model with the asymmetric file-vs-folder failure contract; batch rejects empty/>100 lists and continues past per-file failures.
- [x] REQ-010: uploads are screened by extension and content signature; `checkOwnership()` refuses unreadable nodes without repair and repairs drifted ownership best-effort on readable nodes.
- [x] All 36 in-scope methods annotated with `@spec file-actions#...` pointers; 9 boilerplate methods tagged `@spec exclude <reason>`.
