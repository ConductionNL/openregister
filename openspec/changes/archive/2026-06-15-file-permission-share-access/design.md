## Context

OpenRegister stores object files in Nextcloud. Historically every managed file
was expected to be owned by the `openregister` system user, and
`FileValidationHandler::checkOwnership()` enforced this by comparing the file
owner's UID to the current session user and denying on mismatch (introduced by
the security-hardening commit `e21e855acc`, replacing an earlier best-effort
`ownFile()` repair).

The product decision changed: OpenRegister objects must be able to link files
owned by **any** user, including files the current user only reaches through a
Nextcloud file **share**. Under the old gate those files are rejected, producing
`File <name> is not owned by the current session` on upload/view/update/delete.

Nextcloud already models the correct authorization: the node permission bitmask
(`isReadable()` / `isUpdateable()` / `isDeletable()`) reflects access granted via
ownership *or* shares, and the underlying node operations enforce it natively.
The fix realigns OpenRegister with that model.

## Goals / Non-Goals

**Goals:**
- Access decisions are permission-based and ownership-agnostic: read permission
  to read, write permission to write, delete permission to delete.
- Files reached via a share, or owned by a user other than the system user, are
  usable when the session has the relevant permission.
- Ownership follows the folder's mount owner; no silent re-owning when the user
  already has write rights.
- Preserve existing safety: executable screening, and write/delete still gated.

**Non-Goals:**
- Changing the ownership-transfer mechanics themselves (`chown`, share-back,
  `ownFile()`/`setFileOwnership()`); they remain, only their trigger narrows.
- Re-owning files in bulk or migrating existing ownership records.
- Folder-ownership transfer (`transferFolderOwnershipIfNeeded`) is currently
  inactive (TODO call sites) and is only touched for consistency of contract.
- Any change to the public-share / publish model.

## Decisions

1. **`checkOwnership()` gates on `isReadable()` only.** Remove the
   owner-UID-equality deny block. Readability already honours shares and
   non-system owners. This is the minimal, correct authorization gate for read
   paths and the create path (the freshly created node must be readable).

2. **Retire SEC-CTRL-5's deny-on-mismatch.** That control was meant to prevent a
   silent cross-user read via mount-visibility drift, but it cannot distinguish
   drift from a legitimate share and therefore breaks the new linking model. The
   `isReadable()` bitmask is the trusted signal; `ownFile()` is no longer called
   on the access path (so there is no state-changing side effect to exploit).

3. **Explicit per-action permission on write/delete.** `UpdateFileHandler`
   asserts `isUpdateable()` before `putContent()`; `DeleteFileHandler` asserts
   `isDeletable()` before `delete()`. Native NC enforcement already throws, but
   the explicit check fails fast with a clear, file-named message.

4. **Conditional ownership transfer.** `transferFileOwnershipIfNeeded()` returns
   early when `isUpdateable()` is true. Given the folder model — OpenRegister
   folder files are already system-user-owned, external-folder files keep their
   owner and the linking user can write them — the transfer is effectively a
   no-op in normal flows, with the `chown`+share-back retained only as a fallback
   for the rare case where the owner cannot write the node. The redundant
   `$currentUserId !== $openRegisterUserId` sub-condition (already guaranteed by
   an earlier early-return) was dropped, keeping cyclomatic complexity within
   the PHPMD threshold.

5. **Keep method/identifier names.** `checkOwnership` keeps its name despite now
   checking readability, to avoid churning ~15 call sites across `FileService`
   and the handlers; the contract is documented in its docblock and this spec.

## Risks / Trade-offs

- **Broader read access.** Any file the session can read via a share is now
  usable through OpenRegister. This is the intended behaviour and matches NC's
  own model; it does not grant access NC would otherwise deny.
- **Title drift.** The existing requirement title still says "ownership repaired
  safely"; the body now describes readability-based access. Kept verbatim so the
  delta matches at archive time — a follow-up may rename it.
- **Fallback transfer rarely exercised.** Because the guard short-circuits the
  common path, the `chown`/share-back branch is now seldom hit; it is retained
  deliberately and covered by the existing transfer logic, not removed.

## Seed Data

Not applicable — this change introduces no new and modifies no existing
OpenRegister register/schema, so no `_registers.json` seed entries are produced.
