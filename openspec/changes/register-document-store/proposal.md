---
kind: code
depends_on: []
---

# Proposal: register-document-store

## Summary

Document bytes get a store of their own that the organisation chooses and can replace: OpenRegister names the store that holds them, keeps them under one configurable root that can sit on its own storage, and moves an existing corpus into a new store without breaking a reference, a publication link or a version, proven by a test that swaps the store.

- Rows: 11.11 "Document bytes live in a separate store the organisation can replace" (not a statutory row).
- Wave: 1, size M.
- Depends on: nothing.
- Decision: D10, second answer (Ruben, 2026-10-06): row 11.11 is kept and specified.
- Mostly met already: the bytes live in Nextcloud Files and are read only through the Files API, so Nextcloud's primary storage decides where they are. This change is the missing half plus the test that proves the swap.
- Build rules: openspec/woo-build-rules.md

## Why

Row 11.11 is `partial` in our column: "documents live in Nextcloud Files, which an operator can back with external
storage, so the store is replaceable at the platform level and not as a product choice."

What the code does today, read on `development` at 7fa4babd:

- Every managed file lives in the `openregister` account's home under the folder `Open Registers/<register>/<object>`
  (`FolderManagementHandler::createFolderPath()`, `getOpenRegisterUserFolder()`). Registers and objects store their
  folder's node id (`getFolder()`); file-typed object properties store Nextcloud file ids
  (`FilePropertyHandler::processSingleFileProperty()`); `openregister_file_texts`, `openregister_chunks`,
  `openregister_anonymisation_log`, `openregister_entity_relations` and the export tables also hold file ids.
- Bytes are read and written through the Files API by node id (`ReadFileHandler` uses `getById()`). A search for
  `getLocalFile`, `getLocalFolder` and `datadirectory` in `lib/` finds no document byte path. The one hit,
  `Application::registerConfigurationServices()`, builds a local `appdata_openregister` path for the configuration
  cache, which holds no documents.
- So the bytes are already wherever Nextcloud's storage puts them: the local data directory, or S3, Swift or Azure
  when the instance runs on object storage as primary storage. An operator can replace that store for the whole
  instance. That is the half that is met.

What is missing:

1. **Separate.** The documents share the instance's primary storage with every other file. There is no way to give
   them a store of their own: the root folder name `Open Registers` is a constant in three classes
   (`FileService`, `FolderManagementHandler`, `ObjectFileMigration`) and in two path patterns in
   `FolderManagementHandler`, so the tree cannot be placed on a mount of its own beside the existing one.
2. **Says which.** Nothing in the product tells an administrator which store holds the documents.
3. **Replaceable for an existing corpus.** Moving the tree to another storage gives every file a new id, and the
   references above, the link shares that make a file public, the system tags and the earlier versions would all be
   lost. `ObjectFileMigration` refuses any storage that is not local (decision 6 of
   `object-files-follow-object-access`), which is right for that move and leaves this one unanswered.
4. **Proven.** No test swaps the store.

## What changes

1. **One configurable root.** A `DocumentStore` service owns the root: app config `document_store_root` in the
   `openregister` account's home, default `Open Registers`. The three constants and the two patterns read it. An
   organisation that wants a separate store mounts it with Nextcloud's own tools (the External storage app, for the
   `openregister` account, on S3, SMB, WebDAV, SFTP or a local path) and points the root at it, before the first
   upload or with the move below.
2. **The product names the store.** `GET /api/settings/files` returns `documentStore` (root, storage id, backend,
   mount point, whether it is separate from the instance's primary storage, free space when the storage reports it);
   the file configuration admin page shows it; `occ openregister:document-store:status` prints it.
3. **Moving to another store.** `occ openregister:document-store:move --to <path>` moves the corpus register by
   register into a root on a different storage: it copies each file and its earlier versions, verifies every copy
   by SHA-256, rewrites every OpenRegister reference from the old file id to the new one, recreates the shares with
   the same tokens and the system tags, and only then switches. The old tree is kept, renamed, until a later
   `--purge-source` run deletes it after checking every file in it has a verified copy. `--dry-run` reports what it
   would do. A run is resumable.
4. **The swap is proven** by an integration test in CI that mounts a second store with the External storage app,
   moves a register with a published file into it, and reads the same bytes through the same OpenRegister references
   and the same public link afterwards.

## What does not change

- Nextcloud's primary storage configuration and the External storage app: the store is configured with Nextcloud's
  own tools, never by OpenRegister.
- `openregister:files:move-to-account` and its local-only refusal.
- The configuration cache under `appdata_openregister`: it holds no documents and is out of scope.

## Fail closed

- A register whose copies do not all verify is not switched: its references stay on the old ids and the run names
  the files. Nothing is deleted before a verified switch, and `--purge-source` deletes only files with a verified
  copy.
- A link share (a publication) that cannot be recreated with the same token, permissions and expiry stops the
  switch for its register. A password-protected link share is reported and stops the switch: its password cannot be
  carried through Nextcloud's public share API.
- Earlier versions are carried. When the target storage keeps no versions, a register whose files have earlier
  versions is refused, unless the administrator passes `--without-earlier-versions`, which the report and the audit
  trail then record per file.
- While a register is being moved, uploads into it are refused with 503 `document-store-move`; reads keep working.

## Dependencies

None. The External storage app is shipped with Nextcloud and needed only by an organisation that wants a separate
mount, and by the CI integration test.

## Wave and done

Wave 1, size M. Done means merged on `development` with CI green. Row 11.11 then reads `yes` (build), and
`production` only once an openregister store release carries it.
