# Self-Folder Access Control Specification

**Status**: in-progress
**Scope**: openregister
**OpenSpec changes**:
- [validate-self-folder-access](../../changes/validate-self-folder-access/)

## Purpose

Defines the access-control contract for the `@self.folder` metadata field on OpenRegister objects. `@self.folder` binds an object to an existing Nextcloud folder by node ID; without access control, an authenticated caller can bind their object to any other user's folder, producing a cross-tenant data leak on subsequent child-file writes. This capability specifies who can bind to which folder, how denial surfaces (new exception, HTTP 403, audit-trail entry), and the preservation of legacy auto-create behaviour for empty and non-numeric folder values. Applies uniformly to every register (not only dossier) and to every call path (HTTP controllers, DI service consumers, cron jobs). See ADR-008 (Backend Layering) and ADR-014 (RBAC and multitenancy).

## Requirements

_(Requirements are defined in the in-progress change delta at [changes/validate-self-folder-access/specs/self-folder-access-control/spec.md](../../changes/validate-self-folder-access/specs/self-folder-access-control/spec.md). They will be folded into this canonical spec when the change is archived.)_

## Non-Functional Requirements

- **Security:** Denial MUST be the default when the acting user cannot read the requested folder. Forensic audit-trail entries MUST be written on every denial (best-effort — audit failures MUST NOT swallow the denial).
- **Performance:** The access check adds one `Folder::isReadable()` call per object save that specifies `@self.folder`. For saves without the field, the check is a no-op.
- **Backwards-compatibility:** Legacy non-numeric `folder` values (path-style) MUST continue through the existing auto-create path. Empty `folder` values MUST continue to auto-create under the register folder.
- **Internationalization:** Error messages returned in HTTP 403 responses are structured codes (`error: "folder_access_denied"`); any human-readable strings attached MUST support Dutch and English (ADR-005).

## Acceptance Criteria

- [ ] `FolderAccessDeniedException` exists in `lib/Exception/`, extends `\Exception`, and is thrown on every denial path.
- [ ] `FolderManagementHandler::createObjectFolderById()` performs a read-access check for non-empty numeric `folder` values, using a user-folder-scoped lookup (no root-folder fallback) and `Folder::isReadable()`.
- [ ] Invalid / non-existent / file-typed / unreadable / trashed node IDs produce HTTP 403, not auto-created folders.
- [ ] Empty and legacy non-numeric folder values preserve auto-create behaviour.
- [ ] Every denial produces an audit-trail entry with `action: "folder_access_denied"`, actor, attempted folder ID, and timestamp — written before the exception propagates.
- [ ] No regression for existing cron jobs (`TransferCheckJob`, `DestructionCheckJob`, `RegistersLoader` seed flow, etc.) that don't set `@self.folder`.
- [ ] `getNodeById()` retains its root-folder fallback for non-binding callers (anonymous file reads).

## Notes

- The change deliberately does not touch `getNodeById()`. Anonymous / public file-retrieval code paths depend on its root-folder fallback; restricting the helper globally would regress legitimate flows. The fix is localised to the binding path.
- Write-permission checks are explicitly out of scope — read access is the right minimum for "this user is allowed to see this folder exists." Downstream file writes already enforce write permissions separately.
- Cleaning up stale `@self.folder` values on existing objects (folders that the owner no longer accesses) is deferred to an `occ openregister:folder-audit` command tracked separately. This capability only prevents *new* bad binds.
- The DocuDesk `add-dossier-schema` change is the first consumer that will benefit from this hardening, but the contract applies register-wide.
