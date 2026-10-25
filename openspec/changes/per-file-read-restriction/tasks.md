# Tasks: per-file-read-restriction

- [x] 1.1 Table `openregister_file_restrictions` (migration), one row per file id
- [x] 1.2 `FileRestrictionStore`: find one, find many, save (insert or update), delete
- [x] 1.3 `FileRestrictionService`: restrict (validates principals and reason, records who and when), lift, restrictionOf
- [x] 1.4 `FileRestrictionAdmitEvent` for apps that admit a caller by their own rule
- [x] 1.5 `FileRestrictionGuard`: mayRead, readable (batch), readableNodes; admin, listed user, listed group, event; fail closed
- [x] 2.1 Object folder listing filters; rename and delete by node id answer 404 (ObjectFolderBrowser)
- [x] 2.2 Files list filters (FilesController::index)
- [x] 2.3 Per-file endpoints answer 404 (FilesController::ensureObjectAccess with the file id)
- [x] 2.4 Download by file id answers 404 (FilesController::downloadById)
- [x] 2.5 ZIP download leaves the file out (FilePublishingHandler::createObjectFilesZip)
- [x] 2.6 Search hits leave the file out (FileReadScope)
- [x] 2.7 Integration files leaf leaves the file out (FilesProvider)
- [x] 3.1 Unit tests: guard verdicts (not listed, listed user, group, admin, event, anonymous, lookup failure), service validation, each path above with a refused and an admitted reader
- [x] 2.8 Rendered object: `@self.files` and file-typed property hydration leave the file out (RenderObject)
- [x] 2.9 Access links never describe a restricted file (AccessLinkReader::fileDescriptor)
- [x] 2.10 Restricting removes public link shares; publishing a restricted file is refused
- [x] 3.2 Tests for 2.8 to 2.10, red before the fix
- [x] 4.1 Proposal and spec name the native Files/WebDAV limit
