## Why

OpenRegister objects must be able to link files owned by users *other than* the
`openregister` system user — including files a user reaches through a Nextcloud
file **share**. The current file-access gate compares the file owner's UID to the
current session user and **denies** on any mismatch, so a legitimately shared or
other-owned file is rejected on upload, view, update and delete. This surfaced to
end users as `Cannot create file <name>: File <name> is not owned by the current
session` (e.g. a DocuDesk-anonymised PDF uploaded through OpenCatalogi).

The deny was introduced as a security hardening (`e21e855acc`) that over-corrected:
it conflated "owns the file" with "is allowed to use the file". Access should be
**permission-based** (read to read, write to write, delete to delete), which
Nextcloud already models via the node permission bitmask and honours for shares.

## What Changes

- **`checkOwnership()` becomes a readability gate.** It grants access on
  `Node::isReadable()` alone — ownership-agnostic — and no longer denies on
  owner mismatch or attempts `ownFile()` repair. **BREAKING** relative to the
  previously-specified "deny on owner drift / best-effort repair" behaviour.
- **Write and delete paths assert per-action permission.** `UpdateFileHandler`
  asserts `Node::isUpdateable()` before `putContent()`; `DeleteFileHandler`
  asserts `Node::isDeletable()` before `delete()` — failing fast with
  `NotPermittedException` in addition to Nextcloud's native enforcement.
- **Ownership transfer becomes conditional.** `transferFileOwnershipIfNeeded()`
  skips re-owning a file to the `openregister` system user when the current user
  already has write rights (`isUpdateable()`). Ownership simply follows the
  folder's mount owner: files created in the OpenRegister folder are already
  owned by the system user; files linked from folders outside it keep their
  original owner. Re-owning remains only as a fallback. **No** ownership-transfer
  mechanics (`chown`, share-back, `ownFile()`) are removed.

## Capabilities

### New Capabilities

<!-- None: this change modifies existing behaviour only. -->

### Modified Capabilities

- `file-actions`: the file create/upsert pipeline, the system-user share model,
  and the upload validation/ownership requirement all change their access and
  ownership-transfer contracts as described above.

## Impact

- **Code (already implemented):** `lib/Service/File/FileValidationHandler.php`,
  `UpdateFileHandler.php`, `DeleteFileHandler.php`, `FileOwnershipHandler.php`,
  `CreateFileHandler.php`; tests in
  `tests/Unit/Service/File/FileValidationHandlerTest.php`.
- **Behaviour:** users with read access (own or shared) can list/view files;
  users with write/delete permission can modify/delete; uploads no longer fail
  on owner mismatch; files are no longer silently re-owned to the system user
  when the user already has write rights.
- **Dependent apps:** OpenCatalogi and DocuDesk file flows that link or upload
  files through OpenRegister are unblocked.
- **Security:** access decisions now rely on the Nextcloud permission bitmask
  (which honours shares); write/delete remain enforced both explicitly and
  natively. The previous SEC-CTRL-5 "deny on owner mismatch" control is
  intentionally retired as incompatible with shared-file linking.
