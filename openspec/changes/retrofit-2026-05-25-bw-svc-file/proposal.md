# Retrofit — backend coverage, Service/File (bw-svc-file, 2026-05-25)

Reverse-specs the next behavioral slice of OpenRegister's shipped file-action
surface (`lib/Service/File/*Handler`) by **extending the existing `file-actions`
capability** with 5 new REQs (REQ-006 … REQ-010). Code already exists and is in
production — this change retroactively specifies it. Every one of the 45 methods
in the batch ends tagged either with an `@spec` pointer (annotated to a new or
existing REQ) or with `@spec exclude <reason>` for boilerplate.

This is a follow-up to `retrofit-2026-05-24-file-actions` (REQ-001 … REQ-005),
which covered CRUD/lock/preview/folder/object-tagging. That change's
`## DROP — future-pass:next` section listed the publishing/upload/update/read/
ownership/validation surface as deferred — this change picks up exactly that
deferred slice for `Service/File/`.

## Coexistence with the original `file-actions` change

The `file-actions` capability lives in the still-unarchived
`openspec/changes/file-actions/` (status: draft) plus the
`retrofit-2026-05-24-file-actions` delta. This change adds REQ-006 … REQ-010 as
a further delta. When `file-actions` is finalised into
`openspec/specs/file-actions/spec.md`, the maintainer should fold REQ-001 …
REQ-010 together. Bias toward the retrofit-derived REQs — they describe observed,
shipped behavior.

## Counts

- **Batch size:** 45 methods (`/tmp/or-scan/bw-svc-file.json`).
- **Reverse-spec'd / annotated:** 36 methods.
  - 28 methods annotated to the 5 new REQs (REQ-006 ×3, REQ-007 ×6, REQ-008 ×5,
    REQ-009 ×10, REQ-010 ×4).
  - 8 methods annotated to **existing** REQs from the 2026-05-24 pass
    (REQ-004 folder management ×6, REQ-005 object tagging ×2).
- **Excluded as boilerplate (`@spec exclude`):** 9 methods (8 FileCrudHandler
  Phase-1B stubs + 1 FileAuditHandler no-op).
- **New REQs minted:** 5 (REQ-006 … REQ-010), at the 5-REQ-per-run cap.
- **Total tagged:** 36 spec'd + 9 excluded = 45.

## New REQs (extend `file-actions`)

### REQ-006 — File creation & upsert pipeline
- `lib/Service/File/CreateFileHandler.php::addFile`
- `lib/Service/File/CreateFileHandler.php::saveFile`

### REQ-007 — File retrieval (by id, by name/path, per-object listing)
- `lib/Service/File/ReadFileHandler.php::getFile`
- `lib/Service/File/ReadFileHandler.php::getFileById`
- `lib/Service/File/ReadFileHandler.php::getFiles`

### REQ-008 — File content & OR-side metadata updates
- `lib/Service/File/UpdateFileHandler.php::updateFile`
- `lib/Service/File/UpdateFileHandler.php::updateFileMetadata`

### REQ-009 — File publishing, ZIP export & user/folder sharing
- `lib/Service/File/FilePublishingHandler.php::publishFile`
- `lib/Service/File/FilePublishingHandler.php::unpublishFile`
- `lib/Service/File/FilePublishingHandler.php::createObjectFilesZip`
- `lib/Service/File/FileSharingHandler.php::createShare`
- `lib/Service/File/FileSharingHandler.php::findShares`
- `lib/Service/File/FileSharingHandler.php::shareFileWithUser`
- `lib/Service/File/FileSharingHandler.php::shareFolderWithUser`

### REQ-010 — Upload security validation (executable blocking, ownership repair)
- `lib/Service/File/FileValidationHandler.php::blockExecutableFile`
- `lib/Service/File/FileValidationHandler.php::detectExecutableMagicBytes`
- `lib/Service/File/FileValidationHandler.php::checkOwnership`
- `lib/Service/File/FileValidationHandler.php::ownFile`

Additionally REQ-009 absorbs the multi-file batch dispatcher and ownership-
transfer + document-processing helpers as supporting behavior:
- `lib/Service/File/FileBatchHandler.php::executeBatch` (REQ-009 — batch publish/depublish/delete/label)
- `lib/Service/File/FileOwnershipHandler.php::getUser` (REQ-009 — system-user provisioning underpinning shares)
- `lib/Service/File/FileOwnershipHandler.php::transferFileOwnershipIfNeeded` (REQ-006)
- `lib/Service/File/FileOwnershipHandler.php::transferFolderOwnershipIfNeeded` (REQ-009)
- `lib/Service/File/FileFormattingHandler.php::formatFile` (REQ-007 — node→metadata projection)
- `lib/Service/File/FileFormattingHandler.php::formatFiles` (REQ-007 — filtered/paginated listing)
- `lib/Service/File/DocumentProcessingHandler.php::anonymizeDocument` (REQ-008 — content rewrite)
- `lib/Service/File/DocumentProcessingHandler.php::replaceWords` (REQ-008 — content rewrite)
- `lib/Service/File/FileLockHandler.php::assertCanModify` (REQ-006/REQ-008 — write-path lock guard)
- `lib/Service/File/FileLockHandler.php::getLockInfo` (REQ-007 — lock-state read for formatting)

## Annotated to EXISTING REQs (2026-05-24 pass)

### file-actions#REQ-004 (Object & register folder management)
- `lib/Service/File/FolderManagementHandler.php::createEntityFolder`
- `lib/Service/File/FolderManagementHandler.php::createFolder`
- `lib/Service/File/FolderManagementHandler.php::createObjectFolderById`
- `lib/Service/File/FolderManagementHandler.php::createObjectFolderWithoutUpdate`
- `lib/Service/File/FolderManagementHandler.php::getNodeTypeFromFolder`
- `lib/Service/File/FolderManagementHandler.php::getObjectFolder`

### file-actions#REQ-005 (Object tagging via Nextcloud system tags)
- `lib/Service/File/TaggingHandler.php::attachTagsToFile`
- `lib/Service/File/TaggingHandler.php::getFileTags`

(Note: `FileOwnershipHandler::getUser` is annotated once, to REQ-009.)

## Excluded as boilerplate (`@spec exclude <reason>`)

- `lib/Service/File/FileCrudHandler.php::addFile` — Phase-1B stub; throws "pending Phase 2 extraction", no observable behavior.
- `lib/Service/File/FileCrudHandler.php::createFolder` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::deleteFile` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::getFile` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::getFileById` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::getFiles` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::saveFile` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileCrudHandler.php::updateFile` — Phase-1B stub; throws unconditionally.
- `lib/Service/File/FileAuditHandler.php::logDownload` — no-op audit shim; only emits a log line (the AuditTrail insert is commented out), no persisted behavior to spec.

## Notes (observed-but-flagged)

Captured as-is rather than silently "fixed":

- **`CreateFileHandler::addFile` data-URI/base64 auto-detection is heuristic.** It strips a `data:...;base64,` prefix, then runs a strict `base64_decode` round-trip check; legitimately base64-looking text content could be silently decoded. Documented as observed behavior.
- **`ReadFileHandler::getFile` treats numeric strings as IDs, ignoring `$object`.** A filename that is all digits (e.g. `"12345"`) is resolved as a file ID, not a name within the object folder. Edge case captured in REQ-007.
- **`UpdateFileHandler::updateFile` preserves only `object:`-prefixed tags.** On a tag update it keeps existing tags whose name starts with `object:` and replaces all others. Captured in REQ-008.
- **`FilePublishingHandler::publishFile` writes shares directly via `FileMapper`, not `IManager`.** It bypasses the NC share manager and inserts the share row through `FileMapper::publishFile`. Captured in REQ-009.
- **`FileValidationHandler::blockExecutableFile` rejects `.js`/`.php`/etc. on extension AND magic-byte/shebang content scan of the first 1 KiB.** The list is broad; legitimate text files beginning with `#!` or `<?php` are rejected. Captured in REQ-010.
- **`FileSharingHandler::shareFolderWithUser` swallows failures and returns `null`.** A failed folder share logs an error and returns null rather than throwing, unlike `shareFileWithUser` which rethrows. Two different failure contracts on sibling methods. Captured in REQ-009.

Source: `/tmp/or-scan/bw-svc-file.json` (45 methods). Capability home: `file-actions`.
