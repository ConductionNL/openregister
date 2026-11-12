---
status: proposed
---

# file-actions

## ADDED Requirements

### Requirement: One configurable root holds every managed file (REQ-RDS-001)

A `DocumentStore` service SHALL own the root of OpenRegister's managed tree: the folder named by app config
`document_store_root` in the `openregister` account's home, `Open Registers` when unset. Every class that creates,
finds or recognises managed folders (`FileService`, `FolderManagementHandler` including its path patterns, and
`ObjectFileMigration`) SHALL read the root from `DocumentStore`, and no other literal `Open Registers` SHALL remain
in `lib/`. A root that does not exist SHALL be created on first use exactly as `Open Registers` is today. Setting a
root that already holds managed folders of another installation SHALL be refused.

#### Scenario: A fresh installation on a separate store
<!-- @e2e exclude Integration level: DocumentStoreSwapTest mounts a store with files_external, which a browser test cannot set up. -->
- **GIVEN** an External storage mount `Documents` for the `openregister` account and `document_store_root` set to `Documents`
- **WHEN** an officer uploads the first file to an object
- **THEN** the register and object folders SHALL be created under `Documents` and the file's bytes SHALL be on the mount's storage

#### Scenario: Unset means today's behaviour
<!-- @e2e exclude Unit level: DocumentStoreTest asserts the default; every existing upload e2e test already runs on it. -->
- **GIVEN** no `document_store_root` set
- **WHEN** a file is uploaded
- **THEN** it SHALL land under `Open Registers` as it does today

### Requirement: The product names the store that holds the documents (REQ-RDS-002)

`GET /api/settings/files` SHALL return `documentStore` `{root, storageId, backend, mountPoint, separate, freeBytes}`,
where `backend` is `local`, `object-storage` (with the class name) or `external` (with the External storage backend
name), `separate` is true when the root's storage is not the instance's primary storage, and `freeBytes` is null
when the storage does not report it. The file configuration admin page SHALL show it, and
`occ openregister:document-store:status` SHALL print it and exit 0.

#### Scenario: The administrator reads where documents live
- **GIVEN** the default root on the instance's primary storage
- **WHEN** an administrator opens the file configuration settings page
- **THEN** it SHALL show the root `Open Registers`, the backend of the primary storage and that the store is not separate

#### Scenario: A separate store is named as separate
<!-- @e2e exclude Integration level: DocumentStoreSwapTest reads status after mounting a store with files_external, which the Playwright job cannot create. -->
- **GIVEN** the root on an External storage mount with the `local` backend
- **WHEN** `occ openregister:document-store:status` runs
- **THEN** it SHALL print the root, the backend `external (local)` and that the store is separate from the instance's primary storage

### Requirement: Document bytes are read and written only through the Files API (REQ-RDS-003)

No code in `lib/` that reads or writes a managed file SHALL use a local filesystem path: `getLocalFile()`,
`getLocalFolder()`, the `datadirectory` system value or a PHP file function on a path derived from them. The
configuration cache's `appdata_openregister` path is the one named exception, because it holds no documents.

#### Scenario: A local path shortcut is caught
<!-- @e2e exclude Static check: DocumentBytePathTest scans lib/; there is no runtime behaviour to drive. -->
- **GIVEN** a change that reads a managed file with `getLocalFile()`
- **WHEN** the unit suite runs
- **THEN** `DocumentBytePathTest` SHALL fail naming the file and line

### Requirement: An existing corpus moves to another store without losing anything (REQ-RDS-004)

`occ openregister:document-store:move --to <path>` SHALL move the managed tree, register by register, into a root on a
different storage in the `openregister` account's home, and SHALL refuse a target on the same storage. Per register
it SHALL: refuse uploads into that register with 503 `{error: "document-store-move"}` while it runs; copy every file
and its earlier versions oldest first; verify each copy by SHA-256 against its source; rewrite every OpenRegister
reference to a moved file or folder (each column and object property listed in one `DocumentReferenceMap`) from the
old id to the new id, recording one audit trail entry per object changed; recreate user, group and link shares with
the same token, permissions, expiry and label; copy the system tags; and only when all of that succeeded mark the
register switched. A register with a failed verification, a link share it cannot recreate, or a password-protected
link share SHALL NOT be switched, SHALL keep its old references, and SHALL be named in the report with the files.
When the target keeps no versions and a register's files have earlier versions, that register SHALL be refused
unless `--without-earlier-versions` is passed, which SHALL be recorded per file in the report and the audit trail.
When every register is switched, `document_store_root` SHALL be set to the target and the old root renamed to
`<root> (moved <date>)`. `--dry-run` SHALL report counts and refusals and change nothing. A rerun SHALL continue
from the registers not yet switched, using the recorded mapping. `--purge-source` SHALL delete the renamed old tree
only when every file in it maps to a verified copy, and SHALL otherwise delete nothing and name the files.

#### Scenario: A failed copy switches nothing
<!-- @e2e exclude Unit level: DocumentStoreMoveTest injects a corrupted copy; a browser cannot corrupt a byte in transit. -->
- **GIVEN** a register with three files, where the copy of one does not match its source's SHA-256
- **WHEN** the move runs
- **THEN** the register SHALL NOT be switched, every reference SHALL still point at the old ids, the old tree SHALL be untouched, and the report SHALL name that file

#### Scenario: A password-protected publication stops its register
<!-- @e2e exclude Unit level: DocumentStoreMoveTest with a password-protected share double; the outcome is a refusal in the command report. -->
- **GIVEN** a register with a file published through a password-protected link share
- **WHEN** the move runs
- **THEN** that register SHALL NOT be switched and the report SHALL name the file and the reason

#### Scenario: Uploads wait while a register moves
<!-- @e2e exclude Unit level: FilesControllerMoveGuardTest drives the controller with the move flag set; holding a move open during a browser test is not reproducible. -->
- **GIVEN** a register being moved
- **WHEN** an integrator posts a file to one of its objects
- **THEN** the response SHALL be 503 with error `document-store-move` and nothing SHALL be written, and a read of an existing file SHALL still return its bytes

### Requirement: Swapping the store is proven end to end (REQ-RDS-005)

An integration test SHALL run in CI against a real Nextcloud with the External storage app enabled, and SHALL fail,
never skip, when it cannot. It SHALL create an object with a file in a register on the default store, publish the
file through a link share and tag it, mount a second store with the `local` backend for the `openregister` account,
run the move, and then assert: the object's file property and the register and object folders point at nodes on the
new storage; the bytes read through OpenRegister's file API equal the original; the public link with the same token
serves the same bytes; the tag and the earlier version are present; and a new upload lands on the new storage.

#### Scenario: The store is swapped and nothing a reader holds breaks
<!-- @e2e exclude Integration level: DocumentStoreSwapTest is the end-to-end proof; it needs occ and a files_external mount, which the Playwright job cannot create. -->
- **GIVEN** a published file with an earlier version and a tag in a register on the default store
- **WHEN** a second store is mounted, `occ openregister:document-store:move --to <mount>` runs, and `status` is read
- **THEN** status SHALL name the new store as separate, the same OpenRegister reference and the same public link SHALL return identical bytes, the tag and the earlier version SHALL be present, and the next upload SHALL land on the new store
