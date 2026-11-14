# Keep documents on a store of their own

OpenRegister keeps every document in one folder tree in the home of the `openregister` account. By default that tree is the folder `Open Registers`, on the instance's primary storage, next to every other file in Nextcloud.

This page puts the documents on a store of their own: an S3 bucket, an SMB share, a WebDAV or SFTP server, or a local disk. You mount that store with Nextcloud's own tools and tell OpenRegister to use it. You can do that before the first upload, or later, by moving the documents you already have.

## See where the documents are now

```bash
occ openregister:document-store:status
```

```text
Root:        Open Registers
Backend:     local (local)
Storage:     home::openregister
Mount point: /openregister/
Separate:    no, it is on the instance's primary storage
Free space:  not reported by the storage
```

The same information is at the top of **Administration settings → OpenRegister → File configuration**.

## Step 1 — mount the new store for the openregister account

Use the External storage app that ships with Nextcloud.

1. Enable it: `occ app:enable files_external`.
2. Create the mount for the `openregister` account only. For example, a local path:

   ```bash
   occ files_external:create "Document store" local null::null -c datadir=/srv/documents
   occ files_external:applicable --add-user=openregister <mount id>
   ```

   For S3, SMB, WebDAV or SFTP, pick that backend instead; `occ files_external:backends` lists them. The mount point (`Document store` here) is the folder the `openregister` account sees in its home.
3. Check that the account sees it: `occ files_external:verify <mount id>`.

## Step 2a — on a fresh installation: point the root at the mount

When OpenRegister holds no documents yet, set the root and you are done:

```bash
occ config:app:set openregister document_store_root --value="Document store"
occ openregister:document-store:status
```

`Separate` now reads `yes`. Every upload from now on lands on the new store.

## Step 2b — with existing documents: move them

The move copies the documents register by register, checks every copy, and only then points OpenRegister at the copies. Nothing a reader holds breaks: object references, publication links (the same link token), system tags and earlier versions all come along.

1. **Dry run.** See what would move and what would stop it. Nothing changes.

   ```bash
   occ openregister:document-store:move --to "Document store" --dry-run
   ```

2. **Move.** You can move one register at a time with `--register <id>`, or all of them at once.

   ```bash
   occ openregister:document-store:move --to "Document store"
   ```

   Each register prints one line: `switched`, `already switched` or `refused`, with the number of folders, files, earlier versions, shares and objects. While a register moves, uploads into it answer 503 `document-store-move`; reading keeps working. When every register is switched, the root follows the move and the old tree is renamed to `Open Registers (moved <date>)`.

   A run that stopped halfway can be started again with the same command. It continues from what it recorded and does not copy a verified file twice.

3. **Check.** Run `occ openregister:document-store:status` and open a few documents, including a published one through its public link.

4. **Purge the old tree.** Only when you are satisfied:

   ```bash
   occ openregister:document-store:move --purge-source
   ```

   This deletes the renamed old tree only when every file and folder in it has a verified, switched copy. Otherwise it deletes nothing and lists the files that have none.

The command exits with 1 when any register was refused, so it can be used in a script.

## What stops a register

A refused register keeps all its references on the old files, and nothing of it is deleted. The report names each file and the reason.

| Reason | What to do |
| --- | --- |
| A copy does not match its source (SHA-256) | Check the new store's health and run the move again. |
| The target is on the same storage as the current root | Mount a different store; a move within one storage gains nothing. |
| A file has earlier versions and the target keeps none | Use a store whose backend keeps versions, or pass `--without-earlier-versions`. The report and the audit trail then record each file that lost its earlier versions. |
| A file is published through a password-protected link | The password cannot be carried over. Remove the password (or the link) for the move and set it again afterwards. |
| A share could not be recreated, or a link could not keep its token | The report names the file. Fix the share and run the move again. |
| The references could not be rewritten | Nothing was changed for that register; the error is in the report. Run the move again. |

## What this does not change

- Nextcloud's primary storage and the External storage app are configured with Nextcloud's own tools, never by OpenRegister.
- `occ openregister:files:move-to-account` and its refusal of non-local storage are unchanged.
- The configuration cache under `appdata_openregister` holds no documents and stays where it is.
