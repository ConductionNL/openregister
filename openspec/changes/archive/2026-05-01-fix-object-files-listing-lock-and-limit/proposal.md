## Why

The object files listing endpoint (`GET /api/objects/{register}/{schema}/{id}/files`) has two user-visible bugs that were reported from production: user-supplied `_limit` values above 100 are silently capped, and a single Nextcloud-locked file causes the entire listing to fail with a 500. The root cause of both is an ownership-probe in `FileValidationHandler::checkOwnership()` that calls `$file->getContent()` on every file in the loop, forcing the full byte stream into memory and acquiring a shared lock — which is why the cap was set low to begin with, and why one locked file crashes the whole call. Replacing the probe with a cheap metadata check unblocks both fixes and lets us expose lock state to callers instead of hiding it behind a 500.

## What Changes

- Replace the `$file->getContent()` probe in `FileValidationHandler::checkOwnership()` with `$file->isReadable()` (pure `oc_filecache` permission bitmask check — no storage access, no lock acquisition, O(1)).
- Add lock detection in `FileFormattingHandler::formatFile()` via `OCP\Files\Lock\ILockManager::getLocks($fileId)`, guarded by `isLockProviderAvailable()` so the code degrades gracefully when the files_lock app is disabled.
- Wrap the per-file format call in `FileFormattingHandler::formatFiles()` with `try/catch LockedException` as a belt-and-braces safety net; log and skip locked-file failures rather than propagating them up the stack.
- Remove the `_limit` ceiling entirely in `FileFormattingHandler::formatFiles()`. Keep the floor at 1 (negative / zero are still clamped) and the default at 30, but allow arbitrarily large `_limit` values — now that each file is O(1) instead of O(file-size), and because this endpoint is scoped to a single object's attachments (blast radius is bounded by per-object file count, which is operationally small).
- **BREAKING (API addition, non-breaking for existing clients)**: Add `locked: bool` to every file entry in the list response, plus an optional `lock` object `{type, owner, scope, createdAt, expiresAt}` when a lock is present. Existing clients ignore unknown fields.
- **Authentication gate**: both `locked` and `lock` are only emitted when the request is made by an authenticated user (`IUserSession::getUser() !== null`). Unauthenticated / public visitors receive the exact same response shape as before this change — no `locked`, no `lock` — so lock owner names, tokens, and activity timestamps never leak to guests.
- Log one structured debug/info entry per locked or unreadable file encountered during listing, with `fileId`, `path`, and lock owner/type if available, so operators can trace which files are blocked.

## Capabilities

### New Capabilities
<!-- None — this is a behavior/contract fix to an existing capability. -->

### Modified Capabilities
- `object-interactions`: The **File Attachments on Objects** requirement gains new scenarios for the `_limit` ceiling, resilience against Nextcloud-locked files (listing returns unlocked files; locked files are included with lock metadata rather than crashing the call), and the `locked`/`lock` fields in the per-file response envelope.

## Impact

- **Code**:
  - `openregister/lib/Service/File/FileValidationHandler.php` — rewrite `checkOwnership()` to use `isReadable()` in place of `getContent()`; still call `ownFile()` on the fix path via `fileMapper->setFileOwnership()`.
  - `openregister/lib/Service/File/FileFormattingHandler.php` — inject `ILockManager`; extend `formatFile()` output with `locked`/`lock`; raise the `_limit` ceiling in `formatFiles()` and add a `try/catch LockedException` wrapper around each call.
  - `openregister/lib/Service/FileService.php` — if constructor signatures of the handlers change, update the wiring; no public API change beyond the response envelope.
  - `openregister/lib/Controller/FilesController.php` — unchanged (the new behaviour is transparent — successful listings just no longer blow up).
- **API contract**: Additive for authenticated users — every file entry gains `locked: bool` and an optional `lock: {...}` sibling. For unauthenticated (public) callers the response envelope is unchanged — `locked` and `lock` are omitted entirely so guests learn nothing about who is editing what. `_limit` is no longer capped (previously silently clamped to 100); any positive integer is honoured for both authenticated and public callers.
- **Dependencies**: Relies on `OCP\Files\Lock\ILockManager` / `ILockProvider` / `ILock` — all public Nextcloud interfaces available from NC 26+. Guarded by `isLockProviderAvailable()` so it is safe when the `files_lock` app is not enabled.
- **Consumers** (`opencatalogi`, `softwarecatalog`, `procest`, `pipelinq`, `docudesk`, anything rendering object attachments): gain richer metadata; no migration required. Frontends can choose to display a lock badge.
- **Performance**: Per-file cost drops from O(file-size) to O(1). The listing is now genuinely cheap at any size — a 500-file listing that previously would have read every byte of every file reads zero file bytes. The endpoint is scoped per-object, so the practical upper bound is the number of attachments on one object (operationally small: tens to low hundreds).
- **Security/RBAC**: Unchanged — `isReadable()` honours the same permission bitmask that `getContent()` checked, so access control is not relaxed. The `ownFile()` repair path is still gated by the same handler logic. The new `locked`/`lock` fields are additionally gated behind authentication: guests never see them, so the change does not widen the information surface exposed by public-access endpoints.
- **Observability**: New structured log lines for each locked/unreadable file encountered; existing listings that previously succeeded continue to log nothing extra.
