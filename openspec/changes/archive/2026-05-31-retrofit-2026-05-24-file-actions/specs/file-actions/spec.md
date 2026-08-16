---
retrofit: true
---

# file-actions Specification

## Purpose

OpenRegister extends Nextcloud's Files API with per-object file actions: CRUD operations (rename, copy, move, delete), distributed file locking, scoped previews + streaming, folder management for the per-Register / per-Object filesystem hierarchy, and object tagging via Nextcloud system tags. These actions back the file sidebar UI and are consumed by Procest / Pipelinq / ZaakAfhandelApp via the controller endpoints under `/apps/openregister/api/objects/{register}/{schema}/{id}/files/...`.

This spec is a **partial retrofit** — it captures the observed behavior of 5 high-cohesion REQs covering 30 methods. Remaining file-actions surface (download / publish / batch / versioning / file metadata enrichment / vectorization indexing / file settings admin / sidebar UI) is deferred to follow-up retrofit passes; see `openspec/changes/retrofit-2026-05-24-file-actions/tasks.md` for the deferred list.

## Requirements

### REQ-001: File CRUD operations on objects

The system SHALL expose per-object file CRUD endpoints — rename, copy, move, delete — through `FilesController` delegating to `FileService` orchestrators (`renameFile`, `copyFile`, `moveFile`) and the `DeleteFileHandler` family. Each mutation SHALL write an audit-trail entry via `FileAuditHandler::logFileAction()` and dispatch a typed CloudEvent (`FileRenamedEvent`, `FileCopiedEvent`, `FileMovedEvent`) when applicable. Copy and move operations SHALL support cross-register and cross-schema targets by accepting `targetRegister` and `targetSchema` request parameters that default to the source's register/schema. Copy SHALL emit a dual audit entry: one on the source object (`file.copied`) and one on the target object (`file.copied_in`); move SHALL emit `file.moved` / `file.moved_in`. Delete operations on a locked file SHALL throw via `FileLockHandler::assertCanModify()` before any filesystem write is attempted.

#### Scenario: Rename a file under an object

- **GIVEN** an authenticated user with write access to object `{register}/{schema}/{id}`
- **AND** the object has an attached file with `fileId=42` and name `report.pdf`
- **WHEN** the user calls `POST /api/objects/{register}/{schema}/{id}/files/42/rename` with body `{"name": "final-report.pdf"}`
- **THEN** the controller SHALL load the object via `ObjectService::setObject($id)`
- **AND** call `FileService::renameFile(object, fileId: 42, newName: "final-report.pdf")`
- **AND** log an audit-trail entry `action: 'file.renamed'` with `data: {oldName, newName}`
- **AND** dispatch `FileRenamedEvent` carrying the object UUID, fileId, and the same data payload
- **AND** return HTTP 200 with the formatted file JSON via `FileService::formatFile($file)`

#### Scenario: Rename produces structured HTTP status codes

- **GIVEN** a rename request that fails inside `FileService::renameFile()` with an exception
- **WHEN** the exception message contains one of the listed substrings
- **THEN** the response status code SHALL be derived as follows:
  - message contains `"already exists"` → HTTP 409
  - message contains `"invalid characters"` → HTTP 400
  - message contains `"required"` → HTTP 400
  - message contains `"locked"` → HTTP 423
  - any other exception → HTTP 400
- **AND** the response body SHALL be `{"error": "<exception message>"}`

#### Scenario: Copy a file between objects across registers

- **GIVEN** a source object `{registerA}/{schemaA}/{sourceId}` with file `fileId=42`
- **AND** a target object `{registerB}/{schemaB}/{targetId}`
- **WHEN** the user calls `POST /api/objects/{registerA}/{schemaA}/{sourceId}/files/42/copy` with body `{"targetObjectId": "{targetId}", "targetRegister": "{registerB}", "targetSchema": "{schemaB}"}`
- **THEN** the controller SHALL load the source object first, then switch ObjectService to the target's register/schema and load the target object
- **AND** call `FileService::copyFile(sourceObject, fileId: 42, targetObject)` which delegates to a CRUD handler that copies the underlying NC Node
- **AND** log a `file.copied` audit entry on the source object with `data: {targetObjectUuid, targetRegister, targetSchema}`
- **AND** log a `file.copied_in` audit entry on the target object with `data: {sourceObjectUuid, sourceFileId}`
- **AND** dispatch `FileCopiedEvent` with the source object UUID and target object UUID
- **AND** return HTTP 201 with the formatted new file JSON

#### Scenario: Move a file between objects with dual audit

- **GIVEN** a source object with file `fileId=42`
- **AND** a target object in any register/schema
- **WHEN** the user calls `POST /api/objects/{register}/{schema}/{id}/files/42/move` with `{"targetObjectId", "targetRegister", "targetSchema"}`
- **THEN** the controller SHALL behave identically to `copy` for object-loading and audit-dual-entry, but call `FileService::moveFile()` instead
- **AND** emit `file.moved` + `file.moved_in` audit entries
- **AND** dispatch `FileMovedEvent`
- **AND** return HTTP 200 with the formatted moved file JSON

#### Scenario: Missing target object returns 404

- **GIVEN** a copy or move request where the target object cannot be resolved
- **WHEN** `ObjectService::getObject()` returns `null` for the target
- **THEN** the response SHALL be HTTP 404 with body `{"error": "Target object not found"}`

#### Scenario: Delete a file via the DeleteFileHandler

- **GIVEN** a file (as Node, path string, or numeric file ID) and an optional `ObjectEntity`
- **WHEN** `DeleteFileHandler::deleteFile($file, $object)` is called
- **THEN** the handler SHALL resolve a non-Node argument via `ReadFileHandler::getFile()`
- **AND** call `FileLockHandler::assertCanModify($file->getId())` — if the file is locked by another user, this SHALL throw and abort the delete
- **AND** call `FileValidationHandler::checkOwnership($file)` defensively before deletion
- **AND** call `$file->delete()` and return `true` on success
- **AND** return `false` (with a logger->error call) on any of these failure modes: file is null, file is not a `File` instance, or `delete()` throws

#### Scenario: Batch delete returns per-file results

- **GIVEN** an array of files (mix of Node / path / int) and an optional `ObjectEntity`
- **WHEN** `DeleteFileHandler::deleteFiles($files, $object)` is called
- **THEN** the handler SHALL iterate the array calling `deleteFile()` on each
- **AND** return an array of result objects: `{file, success}` on success, `{file, success: false, error: <message>}` when `deleteFile` throws

### REQ-002: Distributed file locking

The system SHALL implement soft file locking via `FileLockHandler` to prevent concurrent edits. Locks SHALL be persisted in a Nextcloud distributed cache (`ICacheFactory::createDistributed('openregister_file_locks')`) so they survive across requests on the same Nextcloud instance; when the distributed cache is unavailable at construction time the handler SHALL fall back to a per-instance volatile map and emit a warning log line. Each lock entry SHALL carry a TTL (default 30 minutes) and is keyed by file ID. Only the lock owner SHALL be able to unlock a file; admin users SHALL be able to force-unlock via an explicit `force=true` parameter. Every lock and unlock SHALL write an audit-trail entry and dispatch a typed CloudEvent (`FileLockedEvent`, `FileUnlockedEvent`).

#### Scenario: Acquire a lock on an unlocked file

- **GIVEN** an authenticated user `alice` and a file `fileId=42` that is not currently locked
- **WHEN** the user calls `POST /api/objects/{register}/{schema}/{id}/files/42/lock`
- **THEN** the controller SHALL load the object and call `FileLockHandler::lockFile(42)`
- **AND** `lockFile` SHALL persist a lock entry keyed by `42` with `lockedBy: 'alice'` and TTL 30 minutes
- **AND** the controller SHALL log an audit-trail entry `action: 'file.locked'` and dispatch `FileLockedEvent`
- **AND** return HTTP 200 with the lock metadata array

#### Scenario: Lock owner refreshes own lock

- **GIVEN** user `alice` already holds the lock on `fileId=42`
- **WHEN** `alice` calls `lockFile(42)` again
- **THEN** the existing lock SHALL be refreshed (new TTL written) rather than rejected
- **AND** `lockFile` SHALL return the refreshed lock metadata

#### Scenario: Lock contention from a different user

- **GIVEN** `alice` holds the lock on `fileId=42`
- **WHEN** user `bob` calls `lockFile(42)`
- **THEN** `lockFile` SHALL throw `Exception('File is locked by alice')`
- **AND** `FilesController::lock` SHALL catch the exception and return HTTP 423 (Locked) when the message contains `"locked"`, HTTP 400 otherwise

#### Scenario: Lock owner unlocks their own file

- **GIVEN** `alice` holds the lock on `fileId=42`
- **WHEN** `alice` calls `POST .../files/42/unlock` (without `force`)
- **THEN** `FileLockHandler::unlockFile(42, false)` SHALL verify the lock owner matches the current user
- **AND** remove the lock entry from the distributed cache
- **AND** the controller SHALL log audit `action: 'file.unlocked'` with `data: {force: false}` and dispatch `FileUnlockedEvent`
- **AND** return HTTP 200 with `{"locked": false}`

#### Scenario: Non-owner unlock attempt is rejected

- **GIVEN** `alice` holds the lock; user `bob` (non-admin) attempts to unlock
- **WHEN** `unlockFile(42, false)` is called
- **THEN** the handler SHALL throw `Exception('Only the lock owner or an admin can unlock this file')`
- **AND** the controller SHALL map the message substring `"Only the lock owner"` to HTTP 403

#### Scenario: Admin force-unlock requires the force flag

- **GIVEN** `alice` holds the lock; admin `root` attempts to force-unlock
- **WHEN** the controller calls `unlockFile(42, force: true)`
- **THEN** the handler SHALL accept the unlock and remove the lock entry
- **AND** the controller SHALL log audit `action: 'file.force_unlocked'` (distinct from the standard `file.unlocked` action)
- **AND** dispatch `FileUnlockedEvent` with `data: {force: true}`

#### Scenario: Non-admin force-unlock is rejected

- **GIVEN** non-admin user `bob` attempts to force-unlock
- **WHEN** `unlockFile(42, force: true)` is called
- **THEN** the handler SHALL throw `Exception('Only administrators can force-unlock files')`
- **AND** the controller SHALL map `"administrators"` substring to HTTP 403

#### Scenario: Unlock a file that is not locked is idempotent

- **GIVEN** `fileId=42` is not currently locked
- **WHEN** any user calls `unlockFile(42)`
- **THEN** the handler SHALL return `['locked' => false]` without throwing
- **AND** no audit / event SHALL be skipped at the handler layer — the controller still logs and dispatches normally

#### Scenario: Distributed cache unavailable at construction

- **GIVEN** `ICacheFactory::createDistributed('openregister_file_locks')` throws at handler construction
- **WHEN** the handler is instantiated
- **THEN** the handler SHALL set `$this->cache = null` and continue to operate
- **AND** the handler SHALL emit a warning log line `'Distributed cache unavailable; falling back to per-instance map (volatile).'`
- **AND** subsequent `lockFile()` / `unlockFile()` calls SHALL operate against the per-instance map (locks do NOT survive across requests in this mode — documented limitation, not a hard failure)

#### Scenario: Expired lock is cleared lazily

- **GIVEN** a lock entry whose `expiresAt` is in the past
- **WHEN** `getLockInfo(42)` is called
- **THEN** the handler SHALL parse `expiresAt` defensively (malformed → drop the entry and return null)
- **AND** if `expiresAt <= now` the entry SHALL be removed and the method SHALL return `null`
- **AND** an info log line SHALL be written: `'Lock on file {fileId} expired, auto-cleared'`

### REQ-003: File preview & download streaming

The system SHALL serve per-file preview thumbnails via `FilesController::preview()` and full-file downloads via `FileService::streamFile()`. The preview endpoint SHALL be marked `@PublicPage`, but anonymous callers SHALL be gated: only files explicitly published with a public share (`FileMapper::isFilePublished($fileId) === true`) SHALL be previewable without authentication. Preview generation SHALL delegate to `FilePreviewHandler::getPreview()`, which uses Nextcloud's `IPreview` manager; unavailable preview types SHALL throw a friendly `'Preview not available for this file type'` exception. Download streams SHALL set `Content-Disposition: attachment; filename="..."` for browser-initiated downloads.

#### Scenario: Authenticated user retrieves a preview

- **GIVEN** an authenticated user with read access to object `{register}/{schema}/{id}`
- **AND** the object has a file `fileId=42` with a previewable MIME type
- **WHEN** the user calls `GET /api/objects/{register}/{schema}/{id}/files/42/preview?width=512&height=512`
- **THEN** the controller SHALL load the object, fetch the file via `FileService::getFile()`
- **AND** call `FilePreviewHandler::getPreview($file, 512, 512)`
- **AND** return a `StreamResponse` of the preview bytes
- **AND** set headers `Content-Type: <preview mime>`, `Cache-Control: max-age=3600, public`, `Content-Length: <bytes>`

#### Scenario: Default preview dimensions when omitted

- **GIVEN** a preview request with no `width` / `height` query parameters
- **WHEN** the controller calls `getPreview()`
- **THEN** width and height SHALL default to 256 (per `FilePreviewHandler::DEFAULT_WIDTH` / `DEFAULT_HEIGHT`)

#### Scenario: Anonymous preview for an unpublished file is blocked

- **GIVEN** a request with no authenticated user (`userSession->getUser() === null`)
- **AND** `FileMapper::isFilePublished($fileId)` returns `false` (or `$fileMapper` is null)
- **WHEN** the controller calls `preview()`
- **THEN** the response SHALL be HTTP 403 with body `{"error": "Preview not available for unpublished files"}`
- **AND** no preview bytes SHALL be generated

#### Scenario: Anonymous preview for a published file is allowed

- **GIVEN** a request with no authenticated user
- **AND** `FileMapper::isFilePublished($fileId)` returns `true`
- **WHEN** the controller calls `preview()`
- **THEN** the anonymous-caller gate SHALL pass and the standard preview path SHALL run

#### Scenario: Preview generation failure returns a fallback icon hint

- **GIVEN** a preview request where `FilePreviewHandler::getPreview()` throws (preview type not supported, or preview manager error)
- **WHEN** the controller catches the exception
- **THEN** the response SHALL be HTTP 404 with body `{"error": "<message>", "fallbackIcon": "/core/img/filetypes/file.svg"}`
- **AND** the caller is expected to render the fallback icon

#### Scenario: Stream a file as a download

- **GIVEN** an authenticated request that resolves to a Nextcloud `OCP\Files\File` node
- **WHEN** `FileService::streamFile($file)` is called
- **THEN** a `StreamResponse` SHALL wrap `$file->fopen('r')`
- **AND** the response headers SHALL include:
  - `Content-Type: <file mime type>`
  - `Content-Disposition: attachment; filename="<file name>"`
  - `Content-Length: <file size>`

### REQ-004: Object & register folder management

The system SHALL maintain a per-Register / per-Object folder hierarchy under a single root folder (`self::ROOT_FOLDER`) within the OpenRegister system user's Files area. Each `Register` entity SHALL have at most one register folder, identified by a numeric NC node ID stored in `Register::getFolder()`. Each `ObjectEntity` SHALL have at most one object folder nested under its register's folder, identified similarly. `FolderManagementHandler` SHALL be idempotent: re-calling `createRegisterFolderById` / `createObjectFolderById` for an existing folder SHALL return the existing node rather than throwing or creating a duplicate. Folder lookups SHALL go through `getNodeById()` and degrade gracefully (return null) when the stored ID no longer resolves to a valid Folder.

#### Scenario: Create register folder when none exists

- **GIVEN** a `Register` entity whose `getFolder()` is null or does not resolve to an existing folder
- **WHEN** `FolderManagementHandler::createRegisterFolderById($register, $currentUser)` is called
- **THEN** the handler SHALL build the folder path as `self::ROOT_FOLDER . '/' . getRegisterFolderName($register)`
- **AND** call `createFolderPath()` to materialise the path (creating each intermediate folder as needed)
- **AND** persist the new folder's numeric node ID via `Register::setFolder((string) $folderNode->getId())` and `RegisterMapper::update($register)`
- **AND** attempt to share the folder with `$currentUser` via the internal `shareFolderWithCurrentUser` helper
- **AND** return the created Node

#### Scenario: Register folder lookup is idempotent

- **GIVEN** a `Register` whose `getFolder()` returns a numeric ID that resolves to an existing folder
- **WHEN** `createRegisterFolderById($register)` is called
- **THEN** the handler SHALL return the existing folder without creating a new one or re-sharing
- **AND** an info log line `'Register folder already exists with ID: {id}'` SHALL be emitted

#### Scenario: Retrieve register folder by ID

- **GIVEN** a `Register` with `getFolder()` returning a stored numeric ID
- **WHEN** `getRegisterFolderById($register)` is called
- **THEN** the handler SHALL resolve the ID via `getNodeById()`
- **AND** return the Folder if it exists and is of type folder, else return `null`

#### Scenario: Get the OpenRegister user root folder

- **GIVEN** the OpenRegister system user is initialised
- **WHEN** `getOpenRegisterUserFolder()` is called
- **THEN** the handler SHALL return the user's NC `Folder` root via `IRootFolder::getUserFolder()`

#### Scenario: Create a multi-segment folder path

- **GIVEN** a path string like `"Registers/MyRegister/sub-folder"`
- **WHEN** `createFolderPath($folderPath)` is called
- **THEN** the handler SHALL ensure each path segment exists, creating folders that are missing
- **AND** return the Node at the terminal segment

#### Scenario: Resolve a node by stored numeric ID

- **GIVEN** a numeric node ID
- **WHEN** `getNodeById($nodeId)` is called
- **THEN** the handler SHALL look up the node in the OpenRegister user's Files area
- **AND** return the Node if it exists, or `null` if not found / inaccessible

#### Scenario: Generate register and object folder names

- **GIVEN** a `Register` entity
- **WHEN** `getRegisterFolderName($register)` is called
- **THEN** a string SHALL be returned (or `null` if the register has no resolvable name) suitable for use as a single folder-name segment

- **GIVEN** an `ObjectEntity` or its UUID/ID string
- **WHEN** `getObjectFolderName($objectEntity)` is called
- **THEN** a string SHALL be returned suitable for use as a single folder-name segment under the register folder

#### Scenario: FileService delegates object-folder lookup

- **GIVEN** an `ObjectEntity` or object UUID/ID string and optional register ID
- **WHEN** `FileService::getObjectFolder($objectEntity, $registerId)` is called
- **THEN** the call SHALL delegate to `FolderManagementHandler::getObjectFolder()`
- **AND** return the Folder if present, or `null` if the object's stored folder ID does not resolve

### REQ-005: Object tagging via Nextcloud system tags

The system SHALL maintain a one-to-many relationship between OpenRegister `ObjectEntity` rows and Nextcloud `ISystemTag` entries via `TaggingHandler`. The handler SHALL use a constant `OBJECT_TAG_TYPE` for `objectType` in calls to `ISystemTagObjectMapper`, keeping OpenRegister object-tag mappings isolated from file-tag mappings. Object tags SHALL be addressable by the object's UUID. A standardised tag identifier of the form `object:{uuid-or-id}` SHALL be produced by `generateObjectTag()` for use as a stable tag name when attaching object metadata to NC nodes. Tag operations SHALL be exposed as facades on `FileService` (`generateObjectTag`, `getAllTags`) so consumers do not need to inject `TaggingHandler` directly.

#### Scenario: Generate an object tag identifier

- **GIVEN** an `ObjectEntity` whose `getUuid()` returns `"550e8400-e29b-41d4-a716-446655440000"`
- **WHEN** `TaggingHandler::generateObjectTag($entity)` is called
- **THEN** the return value SHALL be `"object:550e8400-e29b-41d4-a716-446655440000"`

- **GIVEN** an `ObjectEntity` with no UUID (only a numeric `getId()` of `42`)
- **WHEN** `generateObjectTag($entity)` is called
- **THEN** the return value SHALL be `"object:42"` (fallback to numeric ID)

- **GIVEN** a raw string identifier `"my-id"`
- **WHEN** `generateObjectTag("my-id")` is called
- **THEN** the return value SHALL be `"object:my-id"` (no UUID lookup)

#### Scenario: List tags assigned to an object

- **GIVEN** an object UUID and the object has 3 system tags assigned
- **WHEN** `getObjectTags($objectUuid)` is called
- **THEN** the handler SHALL call `ISystemTagObjectMapper::getTagIdsForObjects([$uuid], OBJECT_TAG_TYPE)`
- **AND** resolve the tag IDs to tag names via `ISystemTagManager::getTagsByIds()`
- **AND** return a `list<string>` of tag names sorted alphabetically
- **AND** return `[]` if no tags are mapped or if any underlying call throws (with an error log)

#### Scenario: Add a tag to an object

- **GIVEN** an object UUID and a tag name (possibly not yet present in the system)
- **WHEN** `addObjectTag($objectUuid, $tagName)` is called
- **THEN** the handler SHALL call its internal `findOrCreateTag($tagName)` helper to ensure the tag exists
- **AND** call `ISystemTagObjectMapper::assignTags($objectUuid, OBJECT_TAG_TYPE, [$tag->getId()])`
- **AND** return `void`

#### Scenario: Remove a tag from an object

- **GIVEN** an object UUID and a tag name currently assigned to the object
- **WHEN** `removeObjectTag($objectUuid, $tagName)` is called
- **THEN** the handler SHALL query `getAllTags(nameSearchPattern: $tagName)` and find the exact-name match
- **AND** call `ISystemTagObjectMapper::unassignTags($objectUuid, OBJECT_TAG_TYPE, [$tag->getId()])`
- **AND** return `void`

#### Scenario: Removing a non-existent tag throws

- **GIVEN** a tag name that does not exist in the system tag manager
- **WHEN** `removeObjectTag($objectUuid, $tagName)` is called
- **THEN** the handler SHALL throw `Exception('Tag not found: ' . $tagName)`

#### Scenario: List all system tags

- **GIVEN** the NC system tag manager has N tags
- **WHEN** `TaggingHandler::getAllTags()` (or its facade `FileService::getAllTags()`) is called
- **THEN** the handler SHALL call `ISystemTagManager::getAllTags(visibilityFilter: null)`
- **AND** return a `list<string>` of tag names (order matches the manager's native ordering)
- **AND** return `[]` if the underlying call throws (with an error log)

#### Scenario: FileService facade delegates to TaggingHandler

- **GIVEN** a consumer that injects `FileService` but not `TaggingHandler`
- **WHEN** the consumer calls `FileService::generateObjectTag($entity)` or `FileService::getAllTags()`
- **THEN** the call SHALL delegate to the corresponding `TaggingHandler` method
- **AND** the return value SHALL be identical to a direct handler call
