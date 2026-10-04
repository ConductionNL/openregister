## 1. One guard for every file action

- [ ] 1.1 Add an object file access guard that takes an object and an action (`read` or `update`), calls `PermissionHandler::hasPermission()` with the object entity, and refuses a read with 404 and a change by a reader with 403 (decision 4); verify with PHPUnit using a real `PermissionHandler` (group grant, `user:<uid>`, owner rule, conditional `match`, admin, anonymous, system operation)
- [ ] 1.2 Call the read guard from `FilesController::index()`, `show()`, `downloadById()`, `preview()`, `listVersions()` and the ZIP export, before any file lookup
- [ ] 1.3 Call the update guard from every change endpoint (create, save, createMultipart, update, delete, rename, labels, metadata, lock, unlock, restoreVersion, batch); copy checks read on source and update on target, move checks update on both
- [ ] 1.4 Stop `ReadFileHandler::getFiles()` loading the object with `_rbac: false` on a person's request
- [ ] 1.5 Resolve the parent object in `FilesController::resolveParentObjectForFile()` from the file's parent folder id, and refuse a person's download by id when no object owns that folder
- [ ] 1.6 Add a route walk test: every `files#` route in `appinfo/routes.php` that takes an object runs the guard; the test fails on a new route without it

## 2. Folders in OpenRegister's own account

- [ ] 2.1 Make `FolderManagementHandler::getOpenRegisterUserFolder()` return the `openregister` account's home always; verify with PHPUnit against a fake root that a signed-in user's save creates the folder in the `openregister` home
- [ ] 2.2 Remove the `transferFolderOwnershipIfNeeded()` and `shareFolderWithCurrentUser()` calls on the managed-folder paths, and the two TODOs they carry
- [ ] 2.3 Resolve stored folder ids in the `openregister` home in `getNodeById()` and `getObjectFolder()`, so a reader no longer triggers the "invalid folder ID, recreating folder" path
- [ ] 2.4 Read and write files as the `openregister` account after the guard, in `ReadFileHandler`, `CreateFileHandler`, `UpdateFileHandler`, `DeleteFileHandler`, `FileVersioningHandler` and `FilePreviewHandler`
- [ ] 2.5 Narrow `isManagedFolderPath()` to the `openregister` home once the migration reports nothing left; until then accept both forms

## 3. Search, sidebar and audit

- [ ] 3.1 Make `FileReadScope` decide file hits in a managed folder through the object read rule; verify that a handler gets the hit and a non-member does not
- [ ] 3.2 Check `FileSidebarController`, `FileTextController`, `FileExtractionController`, `TextExtractionService` and `FilesObjectSourceProvider` for reads through a person's home, and move each managed-folder read behind the guard
- [ ] 3.3 Make `FileAuditHandler` record the acting person, never the `openregister` account; verify through the controller

## 4. Migration

- [ ] 4.1 Add repair step `MoveObjectFilesToOpenRegisterAccount`: find managed folders outside the `openregister` home through `IUserMountCache`, move them with a view rename, merge per object, suffix clashing names, skip locked folders, never delete
- [ ] 4.2 Re-own published link shares on moved nodes (`uid_owner` to `openregister`)
- [ ] 4.3 Report counts (folders moved, files moved, shares re-owned, folders left) and expose the step as an `occ` command for a re-run
- [ ] 4.4 Refuse on any storage other than local, and with server-side encryption on: move nothing there, leave the files, tell the admin in `occ` output and in the admin warnings
- [ ] 4.5 Integration test on the Nextcloud test image: seed two homes with folders for one register, run the step, assert file ids unchanged, stored folder ids resolve, a public link still works, and a handler now lists the files
- [ ] 4.6 Set the `openregister` account's quota to unlimited unless an admin set one, and bump the app version so the repair step runs

## 5. Nextcloud Office

- [ ] 5.1 Add an Office service that, after the read guard, checks richdocuments is enabled (409 otherwise), the mimetype opens in Office (415 otherwise) and the person may use Office (403 otherwise), and creates a guest-type WOPI token with owner `openregister`, editor the acting person and `canWrite` = object `update` AND `userCanEdit()`
- [ ] 5.2 Add `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/office` returning `urlSrc`, `wopiSrc`, `token`, `tokenTtl` and `readOnly`, and the page `GET /apps/openregister/office/{register}/{schema}/{id}/{fileId}` that posts the token into the Collabora frame
- [ ] 5.3 Add `officeUrl` to formatted file metadata for mimetypes Office opens
- [ ] 5.4 Integration test pinned to richdocuments 12.0.x: edit with update saves a new version attributed to the editor, read-only PutFile is refused, no read gets 404 and no token row

## 6. Fleet impact

- [ ] 6.1 Search the fleet for `Open Registers` paths and direct `getUserFolder()` reads of OpenRegister folders on each app's `development` branch, and list each hit in the PR body
- [ ] 6.2 Tell the pipelinq maintainers that the root-share workaround from openregister#4165 can go once this lands
- [ ] 6.3 Live check on a throwaway instance with integriq and Collabora CODE: an intake account saves a submission with attachments, a handler lists and downloads them, an admin lists them, a non-member gets 404, the post-handoff copy to the case succeeds, an update holder edits in Office and a read-only holder opens read-only, and folders from a pre-change install migrate with their file ids

## 7. Verification

- [ ] 7.1 Diff check on changed files while building
- [ ] 7.2 Run `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before the PR, and record the exit codes in the PR body
- [ ] 7.3 Update `file-actions` requirements that describe the transfer and share-back fallback, so the main spec no longer promises a step that is gone
