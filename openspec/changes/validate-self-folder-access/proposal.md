## Why

`@self.folder` is the only OpenRegister metadata field that lets a client hand us an arbitrary Nextcloud node ID and say "bind this object to that folder." Today the binding is unchecked: `FolderManagementHandler::createObjectFolderById()` calls `getExistingFolderFromProperty()` → `getNodeById()`, and `getNodeById()` deliberately falls back to `rootFolder->getById()` when the user-folder lookup misses (`lib/Service/File/FolderManagementHandler.php:641-672`). The root-folder fallback is intended to support anonymous/cross-user file reads, but when applied to folder *binding*, it means any authenticated caller can POST an object with `@self.folder: "<anyone-else's-folder-id>"` and silently attach their object — with all the files it subsequently creates — inside a folder they have no permission to read or write.

A second, smaller problem sits right next to it: if the supplied `@self.folder` points at a non-existent or invalid node ID, `createObjectFolderById()` silently falls through and creates a brand-new folder, discarding the user's intent without surfacing the error. The caller believes the binding succeeded; the data actually lives somewhere else.

Both behaviours are dangerous for multi-tenant deployments and are explicitly called out as a prerequisite by the DocuDesk `add-dossier-schema` change. The fix is to require access control on every non-empty numeric `@self.folder` write and to turn the silent auto-create fallback into an explicit error when the caller supplied a folder ID that could not be honoured.

## What Changes

- **Access check on bind:** `FolderManagementHandler::createObjectFolderById()` SHALL require that the current user (or, for system contexts, the explicit `IUser` passed in) can read the folder referenced by `ObjectEntity::getFolder()`. The check SHALL use Nextcloud's permission model — `$folder->isReadable()` in the user's mount — not the root-fallback path.
- **Hard-fail instead of silent auto-create when a caller-supplied folder fails to resolve:** if `ObjectEntity::getFolder()` is a non-empty *numeric* string (the format produced by explicit `@self.folder` writes) but `getExistingFolderFromProperty()` returns `null`, throw `FolderAccessDeniedException` (new). The current fallthrough that creates a fresh folder SHALL only apply when `folder` is empty or non-numeric (legacy-path case).
- **Distinguish user-supplied vs. system-supplied intent — via data, not flags:** the numeric vs. non-numeric distinction on `folder` already encodes this cleanly (explicit `@self.folder` writes are always numeric node IDs; legacy data is non-numeric). No new flag is introduced.
- **New exception type** `FolderAccessDeniedException` extending `\Exception` in `lib/Exception/`. Thrown by `createObjectFolderById()` when access is denied or a caller-supplied folder ID fails to resolve. Mapped to HTTP 403 by the controllers that call into the save pipeline (reusing the existing exception-to-response mapping in `ObjectsController` and the permission-aware error path in `SaveObject`).
- **Audit-trail entry on rejection:** when a folder-access attempt is denied, emit an audit-trail entry with `action: "folder_access_denied"`, the attempted folder ID, and the actor. Gives tenants a forensic trail for probing attempts.
- **Propagation through the save pipeline:** `SaveObjects::hydrate($object['@self'])` already forwards the folder value verbatim; no change there. The new check runs inside `createObjectFolderById()` so every call path (controllers, services called via DI, cron jobs) inherits it uniformly.
- **No change** to the `@self.folder` write contract from the caller's perspective when the caller supplies a folder they can actually access — same payload, same response. Breaking change is limited to two currently-undocumented failure modes (silent cross-tenant bind, silent auto-create on invalid ID).

**BREAKING (at service level):** Any internal caller that currently sets `@self.folder` to a node ID outside the acting user's accessible tree will start receiving `FolderAccessDeniedException`. The OpenRegister codebase itself is the only surface to audit — downstream apps go through `RegisterService` / HTTP, and their legitimate writes always use accessible folder IDs. See the Impact section for the concrete audit.

## Capabilities

### New Capabilities

- `self-folder-access-control`: defines the access-control contract for `@self.folder` writes — who can bind an object to which folder, how failures surface (new exception, HTTP 403, audit-trail entry), and the distinction between numeric (user-supplied) and non-numeric (legacy) folder values in the hard-fail behaviour.

### Modified Capabilities

None at the spec-of-record level. Existing specs under `openspec/specs/` do not define `@self.folder` binding behaviour, so this is additive. (Candidates audited: `authorization-rbac`, `file-management`, `save-object-pipeline` — none mention folder binding.)

## Impact

**Affected code (OpenRegister):**
- `lib/Service/File/FolderManagementHandler.php` — `createObjectFolderById()` gains the access check and the hard-fail branch. `getExistingFolderFromProperty()` stays as-is (still used for legacy/register-folder lookups that shouldn't hard-fail). A new private helper `assertFolderIsAccessible(Folder $folder, ?IUser $currentUser): void` centralises the permission check.
- `lib/Exception/FolderAccessDeniedException.php` — new, extends `\Exception`.
- `lib/Controller/ObjectsController.php` — catch `FolderAccessDeniedException` and return HTTP 403 with a structured error body. Reuse the existing `OCSForbiddenException`-style mapping if present; otherwise add a `try/catch` at the relevant save endpoints.
- `lib/Service/Object/SaveObjects.php` — propagate `FolderAccessDeniedException` up from the folder-creation step (no catch-and-swallow).
- `lib/Db/AuditTrailMapper.php` — no new columns; `folder_access_denied` is just a new `action` value passed into the existing mapper.

**Internal callers audited:**
- HTTP route `POST/PUT /api/objects/{register}` — already user-scoped; any `@self.folder` the client supplies will be validated against the authenticated user. Legitimate flows (client attaches to a folder they can see) keep working.
- Cron jobs that create objects (e.g. `TransferCheckJob`, `DestructionCheckJob`) — they do not set `@self.folder` explicitly; the auto-create path (`folder` empty → create fresh) remains their flow and is unaffected.
- `RegistersLoader` seed-object installation — supplies placeholder folder slugs that are resolved to newly-created folders; this is the "folder empty after slug resolution" path, still goes through auto-create, unaffected.
- `SolrNightlyWarmupJob`, `NameCacheWarmupJob`, and other read-only jobs — don't touch `@self.folder` at all.
- `ImportService` / sync pipelines — spot-check: if they forward `@self.folder` from source data, they'll need to either (a) run as a user who can see the folder, or (b) clear the field before save. Audited during this change.

**Affected downstream apps:** None until they update. The ones that actively use `@self.folder` (DocuDesk `add-dossier-schema` — pending) will gain correct behaviour for free. Other apps don't currently set the field.

**Audit-trail schema:** no migration — the existing `action` column is free-text. The new `folder_access_denied` value is additive.

**Architectural alignment:**
- ADR-008 (Backend Layering): access control lives in the service layer (FolderManagementHandler), not the controller, so every call path inherits it.
- ADR-014 (RBAC and multitenancy, if present): this change closes a multi-tenant escape hatch and aligns the `@self.folder` contract with the access-control posture of the rest of the API.
