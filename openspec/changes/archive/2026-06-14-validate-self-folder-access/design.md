## Context

OpenRegister lets clients attach an object to a pre-existing Nextcloud folder by setting `@self.folder` to the folder's numeric node ID. The binding flows through three places:

1. **Hydration** — `SaveObjects::hydrate($object['@self'])` copies every key from the incoming `@self` envelope onto the `ObjectEntity`, including `folder`, without filtering. This is by design — `@self` is the envelope of protected metadata.
2. **Entity storage** — `ObjectEntity::$folder` is `?string`, persisted as-is.
3. **Folder creation / lookup** — `FolderManagementHandler::createObjectFolderById()` reads `$objectEntity->getFolder()` and decides: does the ID resolve? If yes, return that folder. If no, auto-create a new folder under the register folder.

The vulnerability is at step 3, specifically in the supporting `getNodeById()` helper:

```php
// First try via the current user's folder.
$userFolder = $this->getOpenRegisterUserFolder();
$nodes = $userFolder->getById($nodeId);
if (!empty($nodes)) return $nodes[0];

// Fall back to root folder lookup (works for files owned by any user …)
$nodes = $this->rootFolder->getById($nodeId);
if (!empty($nodes)) return $nodes[0];
```

That root-folder fallback returns a `Node` object regardless of whether the current user can read it. For anonymous / cross-user *file reads* the fallback is legitimate. For folder *binding* it is a cross-tenant bind: user A can POST `@self.folder: "<user-B-private-folder-id>"` and get their object's child files placed inside that folder.

Two secondary issues sit alongside:

- **Silent auto-create on invalid ID.** If the supplied ID doesn't resolve at all, `createObjectFolderById()` quietly moves on to creating a brand-new folder under the register root. The caller thinks their ID was honoured; it wasn't.
- **No forensic trail.** Nothing in the audit trail records that a folder-access attempt was rejected, which makes probing undetectable.

Legacy `folder` values (non-numeric strings — old-style paths) still exist on some installs and must keep working through the auto-create fallback. So the hard-fail behaviour must differentiate between "numeric ID, user-supplied intent" and "non-numeric, legacy data".

## Goals / Non-Goals

**Goals:**
- Any non-empty numeric `@self.folder` value SHALL resolve to a folder the acting user can read, or the save SHALL fail with a specific exception and a clear audit-trail entry.
- Silent auto-create fallback is gone for caller-supplied IDs; it remains intact for empty and legacy non-numeric values.
- The service-layer check runs regardless of call path (HTTP controller, DI consumer, cron job), so downstream apps don't need their own guard.
- Existing flows that set `@self.folder` to an accessible folder keep working with no payload change.
- A minimal, standalone exception type so controllers can map to HTTP 403 cleanly without leaking internals.

**Non-Goals:**
- Write-permission checks. Requiring *read* access is the right minimum for "this user is allowed to see this folder exists"; the downstream files-write pipeline already enforces write permissions when children are created. Adding a separate write check here would duplicate that logic and risk false negatives on shared folders.
- Fine-grained per-register access policies (e.g. "register X only allows folders under /shared/X/"). Out of scope; this change enforces the Nextcloud-level permission model, not register-level policy.
- Migration of existing objects with stale `@self.folder` pointing at folders the current owner can no longer access. A one-off audit command (`occ openregister:folder-audit`) can be added in a follow-up; this change is about preventing new bad binds, not retroactively cleaning old ones.
- Refactoring `getNodeById()` to not use the root-folder fallback at all. Other callers (public file reads, anonymous downloads) depend on it. The fix is localised to the *binding* path, not the general-purpose lookup helper.

## Decisions

### D1. Access check in the service, not the controller

The access check lives in `FolderManagementHandler::createObjectFolderById()`, not in `ObjectsController::create()`.

**Rationale:** every call path into the save pipeline (HTTP, DI, cron) funnels through `createObjectFolderById()`. Putting the check there means we can't forget it on a new path. Putting it in the controller would protect HTTP callers only, and the whole point of ADR-008 is that business rules belong in services.

**Alternative considered:** Controller-level middleware / annotation. Rejected: annotations wouldn't catch `RegisterService::saveObject()` calls from DocuDesk's DI path.

### D2. Use the user folder's `isReadable()`, not `rootFolder->getById()`

The new check resolves the folder via `getOpenRegisterUserFolder()->getById($folderId)` and verifies the returned node is readable via `$folder->isReadable()`. It does **not** fall back to `rootFolder` for the binding path.

**Rationale:** `isReadable()` is the Nextcloud-sanctioned permission primitive. A folder that appears in the user's user-folder lookup is, by construction, visible to that user — we then additionally check readability to guard against mount edge cases (shared-link-only access, trash, etc.). Root-fallback is the vulnerability; removing it from *this* path (while keeping it in `getNodeById()` for other callers) surgically closes the hole.

**Alternative considered:** Keep using `getNodeById()` and add a post-hoc readability check on the returned node. Rejected: the root-fallback can return a node from a trash bin or an admin-only mount where `isReadable()` behaviour is ambiguous. Restricting lookup to the user's mount is clearer and matches what a human would verify.

### D3. Numeric vs. non-numeric folder property distinguishes user intent

Hard-fail behaviour applies only when `$folder` is numeric. Non-numeric and empty values continue through the existing path.

**Rationale:** every `@self.folder` write produced by the system is a numeric node ID (see `RegisterService::saveObject` and `FolderManagementHandler::createObjectFolderInRegister`, which stores `$folder->getId()` as string). Legacy data that pre-dates the numeric-ID convention uses paths. The discriminator is already there in the data — we don't need to add an `explicitFolder` flag.

**Trade-off:** If a future caller somehow passes a non-numeric user-intended value, it would silently auto-create. Acceptable: the hydration path has always assumed numeric IDs; the `SaveObjects::hydrate()` flow never produces a non-numeric folder string.

### D4. New exception type, not reuse of generic `Exception`

`FolderAccessDeniedException` extends `\Exception` and lives in `lib/Exception/`. Controllers catch it specifically to return HTTP 403.

**Rationale:** specific exception types keep error handling testable — catching `\Exception` in a controller would silently absorb unrelated failures. The existing codebase already uses specific types (e.g. `ValidationException`, `NotFoundException`), so this follows established convention.

**Alternative considered:** Reuse `OCP\Files\NotPermittedException`. Rejected: `NotPermittedException` is thrown by many Nextcloud file primitives (including legitimate failures that are not access denials), making it a lossy signal. A dedicated type is one extra file for clearer semantics.

### D5. Audit-trail entry on denial is best-effort, not transactional

When the check fails, we write an `action: "folder_access_denied"` entry via `AuditTrailMapper` **before** throwing. If the audit-trail write itself fails, we log and still throw the exception (the security outcome — blocked bind — takes priority over the forensic record).

**Rationale:** The primary outcome of the check is "the bad bind did not happen." Losing an audit entry is a monitoring regression; failing the whole request because audit logging is down is an availability regression.

### D6. Legacy non-numeric folder values continue to auto-create

If `$folder` is a non-numeric legacy path, the existing fallthrough-to-create behaviour is preserved. No new access check runs on these because there's no node ID to check against.

**Rationale:** legacy data compatibility. Some production instances still have path-style folder values that predate the node-ID convention; breaking those on upgrade would be a regression unrelated to the security goal.

**Trade-off:** Legacy-data clean-up remains a separate `occ openregister:folder-audit` follow-up.

## Risks / Trade-offs

**[Cron or service context has no `IUser`]** — Some pipelines run as the system user or with no session user at all. If they then try to bind to a `@self.folder`, `getCurrentUser()` returns null and the check would always fail.
→ Mitigation: the check is **only triggered when `$folder` is non-empty and numeric**. System/cron contexts that don't set `@self.folder` (all current callers — audited in the proposal) are unaffected. If a future cron legitimately needs to bind to a specific folder, it explicitly passes an `IUser $currentUser` to `createObjectFolderById()` — the signature already supports it (`?IUser $currentUser=null`).

**[Breaking an internal flow that sets `@self.folder` to an arbitrary ID]** — The proposal's audit lists no such callers today, but a future one might silently regress.
→ Mitigation: add a unit-test asserting that `createObjectFolderById()` throws when given a cross-user folder ID. Any future caller breaking this test is immediately visible.

**[False positives on public/anonymous reads]** — `getNodeById()`'s root-fallback exists specifically for anonymous file retrieval. Removing the fallback here doesn't affect those paths (they don't go through `createObjectFolderById()`), but readers of the code may conflate the two helpers.
→ Mitigation: inline comment in `createObjectFolderById()` explaining why this path deliberately does not use the root-fallback helper, pointing at `getNodeById()` for the general-purpose lookup.

**[Shared folders and unusual mounts]** — `isReadable()` on a shared folder should return true for the recipient, but edge cases (expired shares, partially-mounted external storage) could produce flaky results.
→ Mitigation: test matrix covers owned folder (happy path), shared-readable folder (happy path), shared-readonly folder (happy path, still readable), unshared cross-user folder (DENY), deleted/trashed folder (DENY), external storage that's disconnected (DENY, logged as warning).

**[Throwing instead of silent create is itself a breakage for someone, somewhere]** — Any caller who previously relied on "if my folder ID was bad, it would auto-create for me" sees a new exception.
→ Mitigation: the behaviour is documented as a BREAKING change in the proposal. The symptoms of the old behaviour are almost always bugs in the caller's side (bad ID → silently wrong data placement) — the new exception surfaces those bugs rather than hiding them.

## Seed Data

Not applicable — this change introduces no new schemas, no new registers, and no new seed objects. ADR-016 does not require a Seed Data section for changes that don't touch schemas.

## Migration Plan

1. Land the exception class, the `assertFolderIsAccessible()` helper, and the guarded `createObjectFolderById()` changes behind the same PR.
2. Add unit tests before the behaviour switch: cases for allowed, denied, non-numeric legacy, empty folder.
3. Ship the controller mapping to HTTP 403 in the same PR.
4. Deploy. No data migration is required — existing objects with valid `@self.folder` stay valid; ones with invalid values remain in the database but would fail on next re-bind attempt (which is the desired behaviour — these are already broken).
5. Post-deploy: tail logs for `folder_access_denied` audit entries. A burst from a specific caller is a signal that either (a) a legitimate workflow needs adjustment, or (b) someone was probing — both are worth seeing.
6. Follow-up (separate change): `occ openregister:folder-audit` to report orphan / cross-user `@self.folder` references in existing data.

Rollback: revert the PR. Stored data is unchanged, so rollback has no migration cost.

## Resolved Questions

1. **Folder vs File type check.** Decided: `assertFolderIsAccessible()` MUST verify `$node instanceof Folder` explicitly (not piggyback on `getExistingFolderFromProperty`'s null return). A file-ID bind attempt is denied, with the same `FolderAccessDeniedException`. Codified in the spec scenario *"Binding to a file (not a folder) fails with 403"* and in `tasks.md` 2.1.
2. **Where the controller-level HTTP-403 mapping lives.** Decided: prefer reusing or extending an existing shared error-handler method (e.g. `handleSaveException`) rather than adding copy-pasted try/catch blocks per endpoint. Codified in `tasks.md` 5.2 ("Prefer extending existing shared error-handler method over copy-paste try/catch") and verified in 5.3 (no upstream absorption).

## Deferred (genuinely open, tracked separately)

- **Deprecation path for the `rootFolder` fallback in `getNodeById()`.** Out of scope for this change. Now that the binding path has its own restrictive lookup, the general-purpose helper's root-fallback becomes the only remaining path where cross-user exposure could matter. A separate change should audit `getNodeById()` callers (anonymous file reads, public downloads) and decide whether to keep, restrict, or replace the fallback per-callsite.
