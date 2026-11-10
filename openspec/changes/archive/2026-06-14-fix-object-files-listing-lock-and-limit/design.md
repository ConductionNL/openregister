## Context

OpenRegister exposes attached files of an object via `GET /api/objects/{register}/{schema}/{id}/files`. The call chain is:

```
FilesController::index
  → FileService::formatFiles
    → FileFormattingHandler::formatFiles
      → (per file) FileFormattingHandler::formatFile
        → FileService::findShares
          → FileService::checkOwnership
            → FileValidationHandler::checkOwnership
              → $file->getContent()   ← reads the entire file, acquires shared NC lock
```

`FileValidationHandler::checkOwnership()` uses `$file->getContent()` as an "access probe": if content can be read, the current user is treated as the owner; on `NotPermittedException` the code calls `ownFile()` to repair the owner record via a direct mapper write. `ownFile()` itself is a pure DB update — it does not need the file bytes. The bytes were never the point; they were a side effect of using `getContent()` as the probe.

This design choice has two consequences:

1. **Scale cap**: the listing hard-caps `_limit` at 100 (`FileFormattingHandler::formatFiles()`, line ~218) because the per-file cost is O(file-size). A user requesting `_limit=500` silently gets 100 results.
2. **Locked-file 500**: when another process (Collabora, Text app, a WebDAV client) holds a Nextcloud file lock, `getContent()` throws `LockedException`. The exception is not caught in the formatting loop, propagates up to `FilesController::index`, and is returned as HTTP 500. A single locked file takes the whole listing down.

Nextcloud's file lock system (NC 26+) is a separate, first-class primitive — orthogonal to `oc_filecache` permissions. `Node::isReadable()` checks only the permission bitmask in `oc_filecache` (`/home/robert/Repositories/nextcloud/workspace/server/lib/private/Files/FileInfo.php:227`); it is a pure boolean AND against `Constants::PERMISSION_READ`. It does not touch storage and does not inspect or acquire locks. Lock state must therefore be queried separately via `OCP\Files\Lock\ILockManager::getLocks(int $fileId)`, which returns an array of `ILock` objects (`TYPE_USER` / `TYPE_APP` / `TYPE_TOKEN`, with `getOwner()`, `getScope()`, `getCreatedAt()`, `getTimeout()`, `getToken()`).

Because `ILockManager` is only populated when a lock provider is registered (the `files_lock` app), `ILockManager::isLockProviderAvailable()` must gate every use.

Stakeholders: consuming apps that render object file attachments (procest, pipelinq, opencatalogi, docudesk, softwarecatalog). None of them handle 500 responses gracefully today, and several render the attachment panel on detail pages — so one locked file breaks the page.

## Goals / Non-Goals

**Goals:**

- Make the listing endpoint resilient: one locked file MUST NOT break the call.
- Honour user-supplied `_limit` up to a useful ceiling (1000) without silently truncating.
- Expose lock state to the caller as structured metadata (`locked: bool`, optional `lock: {...}`) so frontends can render it.
- Collapse per-file cost from O(file-size) to O(1) so the new ceiling is actually cheap.
- Preserve existing RBAC: the new probe MUST enforce the same read-permission check that the old probe implicitly enforced.
- Preserve the `ownFile()` repair path: when the current user has read access but is not recorded as the owner, the ownership record is still fixed on the listing call.

**Non-Goals:**

- Changing how files are *stored* or how ownership is *persisted* — this is a read-path fix only.
- Exposing or implementing lock acquisition/release from the OpenRegister API. Lock management stays in Nextcloud (files_lock app / WebDAV clients).
- Streaming content of locked files. If a caller wants bytes, the existing download endpoint remains subject to lock semantics.
- Cursoring/keyset pagination. `_limit`/`_page` stays offset-based; raising the ceiling is sufficient for the reported use case.

## Decisions

### Decision 1 — Use `isReadable()` instead of `getContent()` as the ownership probe

`Node::isReadable()` returns `($permissions & Constants::PERMISSION_READ) === Constants::PERMISSION_READ` against the cached permission set for the current user on that node. This is exactly the check the old probe was relying on implicitly — `getContent()` would have thrown `NotPermittedException` for the same cases where `isReadable()` returns `false`. Switching the probe is therefore semantically equivalent for permission checks, but skips reading bytes and acquiring a lock.

**Alternatives considered:**

- **`$file->fopen('r')` followed by immediate close.** Still triggers storage access and lock acquisition on some storage backends. Rejected.
- **Try `getContent()` and catch `LockedException` as "owned, just locked".** Preserves lock information but keeps the O(file-size) cost and still risks backend side-effects. Rejected for performance.
- **Drop the ownership probe entirely and always call `ownFile()`.** Loses the guard that ownership is only repaired for users who can actually read the file. Rejected on safety grounds.

### Decision 2 — Query lock state via `ILockManager::getLocks($fileId)`

Locks are orthogonal to permissions and must be queried separately. `ILockManager::isLockProviderAvailable()` gates every call so the code behaves correctly when the `files_lock` app is disabled (getLocks would otherwise throw `NoLockProviderException`).

The first `ILock` in the returned array (typically there is at most one) is translated to the lock sub-object:

```json
{
  "locked": true,
  "lock": {
    "type": "user",                    // mapped from TYPE_USER / TYPE_APP / TYPE_TOKEN
    "scope": "exclusive",              // mapped from LOCK_EXCLUSIVE / LOCK_SHARED
    "owner": "user-id-or-app-id",
    "createdAt": "2026-04-21T10:15:00+00:00",
    "expiresAt": "2026-04-21T10:45:00+00:00"  // getCreatedAt() + getTimeout(), or null if no timeout
  }
}
```

When no lock provider is registered or the file has no locks, the response is simply `locked: false` with no `lock` sub-object.

**Alternatives considered:**

- **Infer lock state from catching `LockedException` during `getContent()`.** Tangles the lock-detection path with the ownership-probe path we are trying to remove. Rejected.
- **Include the raw `ILock` object in the response.** Not JSON-serialisable without explicit mapping; leaks internal types. Rejected.

### Decision 3 — Per-file `try/catch LockedException` in the format loop

Even with the probe switched to `isReadable()`, other code paths inside `formatFile()` (share enumeration, URL generation, mime-type resolution on some storages) may still trigger storage access that can throw `LockedException`. A `try/catch LockedException` wrapper around each `formatFile()` call in `formatFiles()` guarantees that one locked file cannot poison the batch.

On catch:
- Log `info` with `fileId`, `name`, and — if available — the lock owner/type from a best-effort `getLocks($fileId)`.
- Append a minimal metadata envelope (`{fileId, name, locked: true, lock: {...}, error: "locked"}`) to the result set so the client sees the file exists and is locked, rather than it silently disappearing.

**Alternatives considered:**

- **Skip locked files entirely.** Colleague explicitly wanted them traceable; counts would also silently drift. Rejected.
- **Only catch `LockedException` inside `formatFile()` itself.** Leaks the concern into every site that might throw; harder to ensure complete coverage. Rejected.

### Decision 4 — Remove the `_limit` ceiling entirely

Per-file cost after Decision 1 is O(1) (one permission check, one optional lock lookup, plus whatever DB lookups `findShares` already did). The endpoint is scoped per object (`/api/objects/{register}/{schema}/{id}/files`), so the natural upper bound on the result set is the number of attachments on a single object — operationally in the tens to low hundreds. Imposing an artificial ceiling on top of that bound adds no safety in the cases that actually matter (realistic object attachment counts) and just forces clients to paginate what could be a single call.

The floor stays at 1 (non-positive `_limit` values still clamp to 1) and the default stays at 30 (unchanged when `_limit` is absent).

**Alternatives considered:**

- **Keep a ceiling at 1000.** Adds no real safety: the per-object attachment count is already the effective cap, and 1000 is well above realistic usage. The ceiling would only bite on genuinely malicious inputs — see risks below. Rejected for simplicity.
- **Raise to 500 (exactly what the colleague asked for).** Works today but is arbitrary and we will just raise it again. Rejected.
- **Keep the 100 ceiling and tell clients to paginate.** Does not fix the reported bug — callers explicitly want to retrieve all attachments in one call for a page-render. Rejected (this is the status quo we are fixing).

### Decision 5 — Response envelope: additive fields only

For authenticated callers, add `locked: bool` (always present, defaults to `false`) and an optional `lock` object. No existing field is renamed, removed, or repurposed. Clients that ignore unknown fields are unaffected.

**Alternatives considered:**

- **Wrap the existing envelope in a new `file` + `lock` structure.** Breaks every consumer. Rejected.
- **Only emit `locked` when `true`.** Saves a few bytes but forces every consumer to do `?? false`. Rejected for ergonomics.

### Decision 6 — Gate `locked` / `lock` behind an authenticated session

Both new fields are only emitted when `IUserSession::getUser() !== null`. For public / guest callers (published objects served to anonymous visitors), the response envelope is byte-for-byte the same as before this change — no `locked`, no `lock`. This keeps the information surface for unauthenticated callers unchanged: a guest cannot tell whether a file is being edited, by which user, under which app, when the lock was taken, or when it expires.

Implementation: `FileFormattingHandler` receives `IUserSession` via constructor DI. `formatFile()` checks `$this->userSession->getUser() !== null` before calling the `formatLock()` helper, and conditionally splices `locked` / `lock` into the returned array.

The listing-resilience path (try/catch around `formatFile()`) also respects the gate: when an anonymous caller encounters a locked file that triggers `LockedException`, the minimal envelope added to the result set contains only `fileId`, `name`, and `error: "locked"` — no lock metadata. The file still appears in the listing and is not silently elided.

**Alternatives considered:**

- **Always emit `locked: bool` but redact `lock`.** Still leaks which files are being actively edited, just without owner identity. Rejected — even the boolean is a side-channel for guest visitors watching a published object.
- **Require an explicit opt-in query param (e.g. `_extend=lock`) for authenticated users.** Adds configuration overhead without security benefit; the field is cheap and most UIs want it by default. Rejected.
- **Gate on a registered scope / capability instead of session presence.** Over-engineered for the one concern (guest ≠ logged-in user). Session presence is the right axis. Rejected.

## Risks / Trade-offs

- **Risk — `files_lock` app not enabled:** `ILockManager::getLocks()` throws `NoLockProviderException`. **Mitigation:** gate every call with `isLockProviderAvailable()`; when absent, `locked` is always `false`.
- **Risk — Permission cache staleness:** `isReadable()` reads the cached permission set. If the cache is stale the probe may disagree with the storage layer. **Mitigation:** this is an existing Nextcloud invariant relied on by every list endpoint in the stack; acceptable. The `ownFile()` repair path still catches the case where ownership is recoverable later.
- **Risk — Storage-level locks surfaced during share enumeration:** `findShares()` and parts of `formatFile()` may touch paths that involve storage. **Mitigation:** the outer `try/catch LockedException` guarantees the batch is not broken even if such a lock is encountered. The file appears in the result with `locked: true`.
- **Risk — Pathological `_limit` values combined with expensive `findShares`:** with the ceiling removed, a malicious caller could request `_limit=999999`. In practice the natural cap is the per-object attachment count (tens to low hundreds), and `findShares` does per-file DB queries that dominate only when the result set is genuinely large. **Mitigation:** per-file cost is O(1) bytes-on-disk but still O(query) DB work; if this becomes a hotspot, a follow-up change can batch-load shares by file id set. The endpoint's per-object scoping is the primary defence — it cannot be used to enumerate a whole register. Standard rate limiting (Nextcloud's and the reverse-proxy's) covers the repeated-request angle.
- **Trade-off — Mapping `ILock` to a JSON-friendly shape:** the chosen field names (`type`, `scope`, `owner`, `createdAt`, `expiresAt`) are human-friendly but not a direct 1:1 with Nextcloud's integer constants. Consumers needing the raw integer can compute it from the string; the mapping is stable and documented in the spec.
- **Trade-off — Log volume:** every locked file produces one log line. On a register with hundreds of continuously-locked files, this could be noisy. **Mitigation:** use `info` level, not `warning`; log once per listing per file. Operators can filter.

## Migration Plan

1. No DB migration.
2. No breaking API change — response envelope only gains fields. Frontends continue to work; they can adopt `locked`/`lock` at their own pace.
3. Deploy order is irrelevant: backend can go first; frontends pick up the new fields when they are ready.
4. **Rollback:** revert the PR. No state to unwind.

## Resolved Questions

- **`lock.type` is a string alias** (`"user"` / `"app"` / `"token"`), not an integer. Rationale: human-readable in logs and JSON, does not leak the Nextcloud internal enum surface. No consumer has asked for the integer form; if one emerges, mapping back to `ILock::TYPE_*` is trivial.
- **Locked-file error envelope emits both `locked: true` and `error: "locked"`** when the per-file `try/catch` fires. Rationale: `error` is the signal for *"this entry is a stub from the catch path — only `fileId`, `name`, and `locked` are trustworthy"* vs. `locked: true` alone meaning *"format succeeded and the file happens to be locked, full metadata present"*. Clients that ignore `error` see identical behaviour.
- **The `_limit`-ceiling removal is scoped to this endpoint only.** Rationale: the per-object scoping (`/api/objects/{register}/{schema}/{id}/files`) is what makes removing the ceiling safe here — the result set is naturally bounded by one object's attachment count. Other list endpoints (objects, registers) can span whole registers or the global set and legitimately need a ceiling; auditing them is out of scope for this bug fix. A follow-up change can revisit them with full context if it ever becomes painful.
