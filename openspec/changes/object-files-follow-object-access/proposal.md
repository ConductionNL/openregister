---
kind: code
depends_on: []
---

# Proposal: object-files-follow-object-access

## Why

An object's files are private to the account that saved them. The object's own access rules do not reach them.

integriq showed it on 2026-10-04. Its intakes (DSO STAM and Open Formulieren) now save as a dedicated intake account. The schemas grant read and update to handler groups (`dso-behandelaars`, `openformulieren-behandelaars`) and to admins. The handler reads the submission. The handler sees none of its attachments. Neither does an admin. Only the intake account sees them. integriq's copy of the attachments to the case fails for the same reason.

Live evidence, `~/memcap-work/fleet-appdir/iof-live/commands.md`, run-10 and run-19:

- run-10: the two attachments of submission `a184fccf` sit in `/of-intake/files/Open Registers/OpenConnector Register/a184fccf/`, the intake account's home.
- run-18: a member of both handler groups reads and updates the submission (200).
- run-19: the same submission's files through the OpenRegister files API: `of-intake` 2, `admin` 0, `behandelaar` 0.

The same defect was reported from pipelinq on 2026-09-28 as openregister#4165. A colleague with read and update on a lead sees an empty file list and cannot attach a file.

## Cause, in the code

All line numbers are on `development` at `e80cd62bec`.

1. **The folder is made in the saver's home.** `FolderManagementHandler::getUser()` returns the session user and falls back to the `openregister` account only without a session (`lib/Service/File/FolderManagementHandler.php:878-891`). `getOpenRegisterUserFolder()` opens that user's home (`:782-794`). `createRegisterFolderById()` and `createObjectFolderById()` create `Open Registers/<register>/<object>` there (`:219-221`, `:266-344`).
2. **The hand-over to the `openregister` account does nothing.** `FileOwnershipHandler::transferFolderOwnershipIfNeeded()` calls `$storage->chown()` only when the storage has that method (`lib/Service/File/FileOwnershipHandler.php:303`). No Nextcloud storage class defines `chown` (no match under `lib/private/Files/` in server `stable35`). It then shares the folder with the current user, who is its owner (`:309`). openregister#4165 reports that the Share API refuses that share.
3. **The share to other readers was never written.** Two TODOs stand where it would be: the root share to the `openregister` group (`FolderManagementHandler.php:613-615`) and the share to the current user (`:1385`). The handler's own docblock says so: "the compensating share back to other readers is still a TODO" (`:1122`).
4. **Another reader resolves nothing, and gets an empty list.** `getNodeById()` looks in the reader's home first, then in the root (`:808-838`). The root lookup picks the mount of the current filesystem user and finds none for a file in someone else's home (server `lib/private/Files/Node/Root.php:441-461`). `getObjectFolder()` then treats the folder id as invalid and tries to recreate it (`FolderManagementHandler.php:504-510`). The bound-folder check refuses the reader (`:992-1074`). `FileService::getFilesForEntity()` catches that and returns an empty list (`lib/Service/FileService.php:801-812`). So the reader gets HTTP 200 with zero files.

## What changes

Object files follow the object's access rules, for every app, without the app doing anything.

- **OpenRegister's own account holds every folder it manages.** Register and object folders are made in the `openregister` account's home, whoever saves. The saving account holds no folder, no copy and no share of its own.
- **OpenRegister decides who reads and changes a file, from the object.** Reading a file needs read on the object. Changing a file needs update on the object. The check is the one `PermissionHandler` already applies to the object (`lib/Service/Object/PermissionHandler.php:414`), including groups, `user:<uid>`, the owner rule, conditions and the admin rule. After the check, OpenRegister does the file work as its own account.
- **The list endpoint gets the object check it lacks today.** `FilesController::index()` lists files with no object read check (`lib/Controller/FilesController.php:342-376`). `ReadFileHandler::getFiles()` loads the object with `_rbac: false` (`lib/Service/File/ReadFileHandler.php:269-274`). Today the Nextcloud mount is the only gate. Once every file sits in one home, that gate is gone, so the object check becomes mandatory.
- **Download by file id checks the object too.** `downloadById()` resolves the file's object and applies the read check. Today it only checks that the file is readable (`FilesController.php:1296-1302`, openregister#1952).
- **Search hits on object files follow the object.** `FileReadScope` keeps a hit when the file resolves in the caller's own tree (`lib/Service/File/FileReadScope.php:140-150`). For a file in a managed folder it asks the object's read rule instead.
- **Existing files move.** A repair step moves every managed folder from a person's home into the `openregister` account's home. File ids, folder ids, tags, published links and OpenRegister's file metadata survive the move.
- **The person stays the actor.** Audit entries and OpenRegister's file events name the person who acted, not the `openregister` account.

## What does not change

- A folder a caller bound with `@self.folder` to a node outside `Open Registers/` keeps its owner and Nextcloud's own access rules (`self-folder-access-control`).
- Anonymous access keeps its rule: only published files, through the public share.
- Object access rules themselves do not change. This change makes files obey them.

## Impact

- Apps: integriq's copy to the case (`lib/Service/OpenFormulierenIntakeService.php:527` on integriq `development`) starts working. pipelinq can drop the root-share workaround described in openregister#4165. No app API changes.
- People who browsed `Open Registers` in the Files app no longer see their objects' files there. Ruben accepted this; an opt-in share into Files is a follow-up.
- Nextcloud Office opens object documents through OpenRegister: edit with `update`, read-only with `read`.
- Quota moves from each saver to the `openregister` account.
- Nextcloud's own activity stream shows the `openregister` account on file events in those folders.
- Closes openregister#4165 part 1. Touches openregister#1952 and #2733.

## Decisions

Approved by Ruben on 2026-10-04, recorded in `design.md` under "Decisions". Read needs `read` on the object and every change needs `update`. A refused read answers 404 and a refused change 403. Quota is unlimited. Nextcloud Office editing works in this change. The migration covers local storage only. An opt-in share into Files is a follow-up.
