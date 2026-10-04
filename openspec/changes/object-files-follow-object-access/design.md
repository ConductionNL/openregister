# Design: object-files-follow-object-access

## Context

OpenRegister keeps an object's files in a Nextcloud folder, `Open Registers/<register>/<object>`. The folder id is stored on the object (`ObjectEntity::getFolder()`) and on the register. Every file action goes through `FileService` and its handlers under `lib/Service/File/`.

Line numbers below are on `development` at `e80cd62bec`, and on Nextcloud server `stable35` for `lib/private/...` paths.

### Where the files land today

- `FolderManagementHandler::getUser()` returns the session user. It falls back to the `openregister` account only when there is no session (`lib/Service/File/FolderManagementHandler.php:878-891`).
- `getOpenRegisterUserFolder()` opens that user's home (`:782-794`). Despite its name it is not the `openregister` account's home when a person is signed in.
- `createFolderPath()` creates `Open Registers` and the register folder in that home (`:598-648`). `createObjectFolderInRegister()` adds the object folder (`:1439-1459`).
- `transferFolderOwnershipIfNeeded()` would hand the folder to the `openregister` account, but only through `$storage->chown()` (`lib/Service/File/FileOwnershipHandler.php:303`). Nextcloud defines no `chown` on any storage class, so the step does nothing.
- The compensating shares were never written. `createFolderPath()` has a TODO for the root share to the `openregister` group (`FolderManagementHandler.php:613-615`). `shareFolderWithCurrentUser()` has a TODO for the share itself (`:1385`).
- `CreateFileHandler::addFile()` makes the file with `newFile()` in that folder, so the file is owned by the folder's owner (`lib/Service/File/CreateFileHandler.php:202-216`). Its comment and the `file-actions` spec both say this owner is the `openregister` account. In practice it is whoever saved first.

### What another reader gets

- `FolderManagementHandler::getNodeById()` looks in the reader's home, then in the root (`:808-838`).
- The root lookup, with no user in the path, filters the mount infos to the current filesystem user. That user has no mount for a file in someone else's home, so it returns nothing (`lib/private/Files/Node/Root.php:441-461`).
- `getObjectFolder()` reads that as an invalid folder id and calls `createObjectFolderById()` (`FolderManagementHandler.php:500-510`). The numeric id goes to `assertFolderIsAccessible()`, which refuses a folder outside the reader's own mount (`:992-1074`).
- `FileService::getFilesForEntity()` catches the refusal and returns an empty list (`lib/Service/FileService.php:801-812`). The reader gets HTTP 200 and zero files. That is run-19 in the evidence: `of-intake` 2, `admin` 0, `behandelaar` 0.
- Uploading as the other reader fails the same way, with a folder access denial (openregister#4165).
- `FileService::copyFile()` reads the source through `ReadFileHandler::getFile()`, which resolves the same folder (`FileService.php:2220-2270`). integriq's post-handoff copy calls it as the handler (`lib/Service/OpenFormulierenIntakeService.php:527` in integriq), so the copy fails.

### How access is decided today

- Objects: `PermissionHandler::hasPermission()` (`lib/Service/Object/PermissionHandler.php:414`) evaluates the schema and object `authorization` block. It covers groups, `user:<uid>`, the owner rule, conditional `match`, role expansion, organisation scope and the `public` and `authenticated` pseudo-groups. Members of `admin` pass every action (`:832-835`).
- Files, write actions: `FilesController::ensureObjectAccess()` re-reads the object with `_rbac: true` (`lib/Controller/FilesController.php:259-282`). It is a read check, not the write action (openregister#2733).
- Files, list: `FilesController::index()` has no object check (`:342-376`). `ReadFileHandler::getFiles()` loads the object with `_rbac: false` (`lib/Service/File/ReadFileHandler.php:269-274`). The Nextcloud mount is the only gate.
- Files, one file: `show()` applies the object read check for signed-in callers (`FilesController.php:417-419`).
- Files, by id: `downloadById()` checks only that the file is readable to the session. Its own TODO says the object check is missing (`:1296-1302`, openregister#1952).
- File search: `FileReadScope` keeps a hit when the file id resolves in the caller's own tree (`lib/Service/File/FileReadScope.php:140-150`).

So two access systems answer the same question. The object answers with the `authorization` block. The file answers with Nextcloud mounts. They agree only when one person both saves and reads.

## Goals

- A person who may read an object may read its files. A person who may update an object may change its files.
- A person who may not read the object gets no file, no file name and no file count.
- This holds for every app, with no app code.
- Existing files keep working after the upgrade, with the same file ids.

## Non-goals

- Changing how object access is decided.
- Folders a caller bound with `@self.folder` to a node outside `Open Registers/`. They keep their owner and Nextcloud's rules.
- Write-action RBAC per verb beyond read and update (openregister#2733 stays its own change, see the open decisions).

## Options

### Option A: OpenRegister's own account holds the folders, OpenRegister checks the object

Every register and object folder is made in the `openregister` account's home. The account already exists: `FileOwnershipHandler::getUser()` gets or creates it (`FileOwnershipHandler.php:91-126`). Every file endpoint first applies the object rule, then does the file work as that account.

- Access follows the object exactly. Conditions, owner rule, roles and organisation scope all apply, because the same `PermissionHandler` decides.
- Access changes take effect at once. Nothing has to be resynchronised when a group, a schema rule or an object field changes.
- One owner for all files. Deleting a person's account no longer deletes the files of objects they saved.
- This is what the code and the `file-actions` spec already claim happens (`CreateFileHandler.php:202-205`). It makes the claim true.
- Cost: the files leave people's Files app. WebDAV, desktop sync and office editing from Files no longer reach them. See "Files app and WebDAV" below.

### Option B: share each folder with the principals in the authorization block

Keep the folders where they are and create Nextcloud shares: a group share per group in `read`, a user share per `user:<uid>`, with permissions from `update`.

- Files show up in the readers' Files app, with Nextcloud's own preview, versions and editing.
- A share cannot carry the object rule. A conditional `match`, the owner rule, organisation scope, role expansion and the `authenticated` pseudo-group have no share equivalent. The share either grants too much or too little.
- Shares must follow every change: a schema rule edit, an object update that flips a condition, a group rename. A missed event leaves a stale grant, and a stale grant is a leak.
- Each share is a mount for every member. A handler group on a busy register gets one mount per object. Nextcloud sets up mounts at sign-in and on file access, so this grows with the register.
- Sharing settings can forbid it. An instance that restricts sharing to group members, or disables group sharing, breaks the feature.
- The folder still lives in the saver's home and dies with the saver's account.

### Option C: keep the saver's home, share lazily on first read

When the object read check passes and the file does not resolve, OpenRegister shares the folder with that reader. It has the problems of B without its upfront cost, and revocation never happens. Rejected.

### Option D: Team folders (the `groupfolders` app)

A team folder per register with ACLs. It adds a dependency on an app that is not in Nextcloud core and not installed by default. Its ACLs are groups and users, so it has the same gap with conditions as B. Rejected as the base. It stays possible as an opt-in mirror later.

### Option E: app data (`IAppData`)

Store files in `appdata_<instanceid>/openregister`. Nothing appears in any Files app. Previews, versions, text extraction and tags work on nodes in user homes and would all need rework. Rejected.

## Decision

**Option A.** OpenRegister's own account holds every managed folder, and OpenRegister answers who may read or change a file by asking the object.

Option B looks cheaper because Nextcloud does the checking. It cannot express the rules apps already write, so it would make the file rule a weaker copy of the object rule. The intake case shows why that matters: the schema grants `read` to a handler group and `create` to an intake group. Only the object knows that, and only the object should decide.

## Design details

### Folder placement

- `FolderManagementHandler::getOpenRegisterUserFolder()` returns the `openregister` account's home, always. The session user no longer decides where a folder is made.
- `createFolderPath()`, `createRegisterFolderById()`, `createObjectFolderById()` and `createObjectFolderWithoutUpdate()` use it. The `transferFolderOwnershipIfNeeded()` and `shareFolderWithCurrentUser()` calls on these paths go: the folder is already owned by the right account, and there is no private copy to share back.
- `getNodeById()` for a stored folder id resolves in the `openregister` account's home. `isManagedFolderPath()` (`FolderManagementHandler.php:1243-1247`) narrows from `/<any uid>/files/Open Registers/` to `/openregister/files/Open Registers/` once migration has run. Until then both forms are managed, so a half-migrated instance keeps working.
- A caller-bound `@self.folder` outside the managed tree keeps today's check (`assertFolderIsAccessible()`, `self-folder-access-control`).

### The access check

One guard, used by every file endpoint and by `FileService` callers that act for a person:

- Read actions need `read` on the object: list, show, download, download by id, preview, versions, ZIP export, text, search hits.
- Change actions need `update` on the object: create, save, multipart upload, update, rename, labels, metadata, lock, unlock, restore version, delete (see the open decision on delete).
- Copy and move need `read` on the source and `update` on the target. Move also needs `update` on the source.
- The guard calls `PermissionHandler` with the object entity, so conditions and the owner rule apply. It runs as the acting person: the session user, or the identity of a `runAs()` scope (ADR-099).
- A sessionless system operation (`SystemOperationContext`) passes, as it does for objects (`PermissionHandler::hasPermission()`).
- Anonymous callers keep their rule: only published files, through `FileMapper::isFilePublished()`.

After the guard, the handler does the file work as the `openregister` account. A person never needs a Nextcloud mount on the file.

`ReadFileHandler::getFiles()` stops loading the object with `_rbac: false` for a person. `FilesController::index()` applies the read guard before listing. A refused read returns no file list at all, not an empty one, so a count cannot leak.

### Download by file id and search

`downloadById()` has only a file id. The file's parent folder is the object folder, and the object stores that folder id. A lookup from folder id to object (one indexed query on the folder column) gives the object, and the read guard runs on it. A file whose folder maps to no object is refused for a person. `resolveParentObjectForFile()` (`FilesController.php:1329-1345`) is the place for it.

`FileReadScope::readableResults()` does the same for file hits in a managed folder: resolve the object, ask the read rule. Hits outside the managed tree keep today's own-tree check.

### The saving account

The saving account keeps no folder, no copy and no share. It reads the object's files when the object rule lets it read the object, which for its own objects is the owner rule. The intake case works that way already for the object itself (evidence run-24). The account's home stays empty of `Open Registers` content.

### Migration

A repair step, `MoveObjectFilesToOpenRegisterAccount`, runs on upgrade and can be run again with `occ`.

1. Find every managed folder outside the `openregister` home: register folders from `openregister_registers.folder`, object folders from each magic table's folder column. Resolve each id through `IUserMountCache::getMountsForFileId()`, as `isManagedFolder()` does (`FolderManagementHandler.php:1206-1228`), so no person's mounts need to be set up.
2. For each person's `Open Registers` root, move each register folder into the `openregister` home. Where the target register folder already exists, move the object folders one by one into it.
3. Use Nextcloud's view rename across homes. On local storage this is a filesystem rename plus a cache move that keeps the file id (`lib/private/Files/Storage/Local.php:650-668`, `lib/private/Files/Cache/Cache.php:755`). So stored folder ids, file ids in object data, tags, OpenRegister file rows and text chunks keep pointing at the right node.
4. Re-own published link shares. `FileMapper::publishFile()` writes `uid_owner` directly (`lib/Db/FileMapper.php:846-879`). Every share on a moved node gets `uid_owner` set to `openregister`, so the public link keeps working.
5. Leave anything it cannot move where it is, log it, and count it. Never delete. A second run picks up what the first left.
6. Report counts: folders moved, files moved, shares re-owned, folders left.

Name clashes: two people may each hold a register folder for the same register. The step merges at object level. Two object folders for one object are merged file by file, with a numeric suffix on a clashing name (the rule `copyFile()` already uses, `FileService.php:2250` and `:2286`).

The step must be safe while people work. It takes a per-folder lock and skips a folder with an open lock (`FileLockHandler`).

### Files app and WebDAV

After this change a person's Files app no longer shows `Open Registers`. Files are reached through the app's object views and OpenRegister's API. The Files sidebar tab that lists objects linked to a file (`FileSidebarController`) only sees files in the person's own tree, so it stops showing objects for these files.

Two ways to give a group a Files view, both optional and both later:

- A read-only share of a register folder with a named group, declared on the register. The share is a convenience view, not the access rule. It is off by default, and the register admin answers for what it exposes.
- A WebDAV view served by OpenRegister that lists only objects the caller may read. More work, exact access.

### Quota

Files count against the `openregister` account's quota, not the saver's. Nextcloud's `default_quota` applies to it unless set. The install and repair step sets the account's quota to unlimited (open decision). An admin can still set a limit.

### Performance

- Opening the `openregister` home once per request is one mount setup for one account. Today each reader's home is set up instead, so the cost is the same order.
- The object read check is one `PermissionHandler` evaluation per object, not per file. A list of an object's files costs one check.
- Rendering `@self.files` on an object list does not add a check per object: the list query already applied the object read rule.
- Download by id adds one indexed lookup from folder id to object.
- Migration cost is one rename per folder. It runs in batches and can resume.

### Audit

- OpenRegister's audit trail records the acting person for every file action (`FileAuditHandler::logFileAction()`). The `openregister` account is never the actor.
- Nextcloud's activity app records file events by the node owner's view. Those events show the `openregister` account. OpenRegister's audit trail is the record of who did what.
- Text extraction is triggered by `FileChangeListener` on paths containing `/Open Registers/` (`lib/Listener/FileChangeListener.php:116`). The new path still contains it.

### Versions and previews

`FileVersioningHandler` asks Nextcloud for versions as the session user (`lib/Service/File/FileVersioningHandler.php:124-126`). It must ask as the `openregister` account, after the update guard for restore and the read guard for listing. Previews follow the same pattern.

## Risks

- **A missing guard becomes a leak.** Today a forgotten check on a file endpoint fails closed because the mount hides the file. After this change it fails open, because the `openregister` account can read everything. Mitigation: one guard class, called from every endpoint, and a test that walks every route in `appinfo/routes.php` under `files#` and asserts the guard runs.
- **Storage backends.** File-id preservation is shown for local storage only. Object storage as primary storage and encryption need their own check before the migration runs there.
- **Apps that read files from a person's home.** Any app that opens `/<uid>/files/Open Registers/...` directly breaks. A fleet search for `Open Registers` and for direct `getUserFolder()` reads of OpenRegister folders is part of the tasks.
- **Activity attribution** in Nextcloud's own stream shows the `openregister` account.

## Open decisions

1. **Files app visibility.** Accept that files leave people's Files app, or ship the opt-in register share in this change. Recommendation: accept, and add the opt-in share as a follow-up.
2. **Deleting a file.** Treat it as `update` on the object, or require `delete`. Recommendation: `update`, because removing an attachment changes the object, it does not remove it.
3. **Quota of the `openregister` account.** Set it to unlimited on install and upgrade, or leave Nextcloud's default. Recommendation: unlimited, with a note in the admin docs.
4. **Refusal status.** A person who may not read the object gets 404 (as the object read does, evidence run-18) or 403 (as `ensureObjectAccess()` does today). Recommendation: 404 on read, 403 on change for a person who may read but not update.
5. **Office editing.** Editing a document in Collabora or OnlyOffice from the Files app stops for non-owners. Accept for now, or route editing through the office abstraction (ADR-087) in this change. Recommendation: accept, follow up separately.
6. **Storage backends in scope for migration.** Local storage only, or also S3 primary storage and server-side encryption. Recommendation: local in this change, the step refuses (and reports) on other backends until checked.
7. **Write-action RBAC (openregister#2733).** Fold the per-verb check into this change, or keep `read`/`update` here and leave the rest to #2733. Recommendation: this change uses `update` for every change action, which already closes #2733's main gap.
