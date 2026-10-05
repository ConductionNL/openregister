## 1. Recording the folder id

- [x] 1.1 Add `MagicMapper::recordFolder(entity, expected, folderId): bool`, a single-column compare-and-set write with no event; verify with `tests/Unit/Db/MagicMapper/MagicMapperRecordFolderTest.php` (one column, one row, compare-and-set, false on no row, no dispatch, refuses without uuid or context)
- [x] 1.2 Record the folder id through it in `FolderManagementHandler::createObjectFolderById()` instead of `MagicMapper::update()`; verify with `tests/Unit/Service/File/FolderManagementHandlerObjectFolderBookkeepingTest.php` (real handler, fake root, `update()` and `updateObjectEntity()` never called)

## 2. Other callers

- [x] 2.1 Search for other places that store a folder on an object outside a save; none found (`SaveObject` is a save, `RegisterService` is the register save path, `CreateFileHandler` has the call commented out)

## 3. Verification

- [x] 3.1 Mutation check: 11 mutations of the handler and the mapper, all caught by the two test files
- [x] 3.2 Run `composer check:strict` once before the PR and record the exit codes in the PR body
