# Retrofit — file-actions (partial, 5 REQs)

Describes the observed behavior of OpenRegister's shipped file-action surface (FilesController + FileService + the `lib/Service/File/*Handler` family) as 5 new REQs under a new `file-actions` capability. Code already exists and is in production — this change retroactively specifies it.

## Why this is a partial pass

The `file-actions` cluster from `coverage-report.json` has **268 methods across 41 files** — far beyond the 5-REQ-per-run cap. This run drafts the FIRST 5 highest-cohesion REQs, covering the most-implemented and highest-impact behaviors:

1. File CRUD operations on objects (rename / copy / move / delete)
2. Distributed file locking (acquire / release / admin force-unlock)
3. File preview & download streaming (auth + public)
4. Object & register folder management
5. Object tagging via system tags

The remaining ~63 in-scope methods (after subtracting the 191 pre-triaged DROPs) are listed in `tasks.md` under `## DROP — future-pass:next` with their behavioral grouping for follow-up runs.

## Coexistence with the existing `file-actions` change

There is an **unarchived `openspec/changes/file-actions/` change** (status: draft) that describes the same surface using forward-looking language. That change is the original implementation plan with phase-tracking and gap audits — many of its REQs were drafted before / during the build, so the verbs are aspirational ("MUST add", "SHALL register"). This retrofit captures **observed behavior of the shipped code as of 2026-05-24** so the spec accurately reflects the live system.

When the original `file-actions` change is eventually archived (and finalised into a real spec), the maintainer should reconcile its REQs against the retrofit REQs here. Bias toward keeping the retrofit-derived REQs (they describe observed behavior); merge or supersede the original's draft REQs where they overlap.

## Affected code units (5 REQs / 35 methods covered)

### REQ-001 — File CRUD operations on objects
- `lib/Controller/FilesController.php::rename()`
- `lib/Controller/FilesController.php::copy()`
- `lib/Controller/FilesController.php::move()`
- `lib/Service/FileService.php::renameFile()` (orchestrator)
- `lib/Service/FileService.php::copyFile()` (orchestrator)
- `lib/Service/FileService.php::moveFile()` (orchestrator)
- `lib/Service/File/DeleteFileHandler.php::deleteFile()`
- `lib/Service/File/DeleteFileHandler.php::deleteFiles()`
- `lib/Event/FileCopiedEvent.php::__construct()`
- `lib/Event/FileMovedEvent.php::__construct()`
- `lib/Event/FileRenamedEvent.php::__construct()`

### REQ-002 — Distributed file locking
- `lib/Controller/FilesController.php::lock()`
- `lib/Controller/FilesController.php::unlock()`
- `lib/Service/File/FileLockHandler.php::lockFile()`
- `lib/Service/File/FileLockHandler.php::unlockFile()`
- `lib/Event/FileLockedEvent.php::__construct()`
- `lib/Event/FileUnlockedEvent.php::__construct()`

### REQ-003 — File preview & download streaming
- `lib/Controller/FilesController.php::preview()`
- `lib/Service/File/FilePreviewHandler.php::getPreview()`
- `lib/Service/FileService.php::streamFile()`

### REQ-004 — Object & register folder management
- `lib/Service/File/FolderManagementHandler.php::createRegisterFolderById()`
- `lib/Service/File/FolderManagementHandler.php::getRegisterFolderById()`
- `lib/Service/File/FolderManagementHandler.php::createFolderPath()`
- `lib/Service/File/FolderManagementHandler.php::getRegisterFolderName()`
- `lib/Service/File/FolderManagementHandler.php::getObjectFolderName()`
- `lib/Service/File/FolderManagementHandler.php::getOpenRegisterUserFolder()`
- `lib/Service/File/FolderManagementHandler.php::getNodeById()`
- `lib/Service/FileService.php::getObjectFolder()`

### REQ-005 — Object tagging via system tags
- `lib/Service/File/TaggingHandler.php::generateObjectTag()`
- `lib/Service/File/TaggingHandler.php::getObjectTags()`
- `lib/Service/File/TaggingHandler.php::addObjectTag()`
- `lib/Service/File/TaggingHandler.php::removeObjectTag()`
- `lib/Service/File/TaggingHandler.php::getAllTags()`
- `lib/Service/FileService.php::generateObjectTag()` (delegating facade)
- `lib/Service/FileService.php::getAllTags()` (delegating facade)

## Approach

- One REQ per coherent behavioral surface, with WHEN/THEN scenarios drawn from the observed code paths.
- Annotate the covered code units with `@spec openspec/changes/retrofit-2026-05-24-file-actions/tasks.md#task-N` tags using the same per-file single-pass approach as `/opsx-annotate`.
- The `## DROP — future-pass:next` section in tasks.md lists methods deferred to subsequent retrofit runs, grouped by likely future REQ.

## Notes (observed-but-flagged)

These are observations from reading the code that the retrofit captures **as-is** rather than silently "fixing" via the spec:

- **`FilesController::preview` mixed-result type.** Returns `JSONResponse|StreamResponse` — JSON on error, stream on success. The status-code-on-fallback returns 404 with a `fallbackIcon` key, which conflates "file not found" with "preview generation failed".
- **`FilesController::unlock` swallows admin-check error messages.** The 403 trigger is regex-matched on substrings like `"Only the lock owner"` and `"administrators"`. Localising those strings would break the status-code mapping.
- **`FileLockHandler::__construct` cache fallback is volatile.** When `ICacheFactory::createDistributed()` throws (e.g. no APCu / Redis), the handler falls back to a per-instance array, which means locks **do not survive between requests** under that configuration. The handler logs a warning but does not refuse to operate. Spec captures this as observed behavior; tightening it (e.g. fail-closed) is a future change.
- **`DeleteFileHandler::deleteFile` returns false on most error paths.** Missing file, wrong instance type, or `delete()` exception all log + return `false` rather than throwing. The 207-partial-success pattern in `FilesController::batch` relies on this. The `assertCanModify` lock-guard call, however, **does throw** — so callers can see two failure modes (return false vs throw) from the same method.
- **`TaggingHandler::generateObjectTag` falls back to numeric ID when UUID is missing.** The format is always `object:{uuid-or-id}`. Mixed-id-type tags can collide if UUID and numeric ID overlap (unlikely in practice but undefined).
- **`FolderManagementHandler::createRegisterFolderById` writes the folder ID back into `$register->setFolder()` as a string.** Property is typed `?string` on the Register entity — the cast `(string) $folderNode->getId()` is intentional but stringifies an integer.

Source: `coverage-report.json` generated 2026-05-24. See `/tmp/or-scan/rspec-cluster-file-actions.json` for the input batch.
