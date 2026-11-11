## 1. Recording the folder id

- [x] 1.1 Add `lib/Db/RegisterFolderRecorder.php` with `record(registerId, expected, folderId): bool`, a single-column compare-and-set write; verify with `tests/Unit/Db/RegisterFolderRecorderTest.php` (statement shape, true on one row, false on none, empty expectation matches null and empty)
- [x] 1.2 Record the folder id through the recorder in `FolderManagementHandler::createRegisterFolderById()` instead of `RegisterMapper::update()`, and inject it (constructor, `Application.php` registration); verify the three existing handler tests now expect the recorder and never `update()`

## 2. Idempotent creation

- [x] 2.1 Make `createFolderPath()` take a folder that a concurrent request created between its lookup and its creation, for the root and the register folder; verify with a race test on a fake root

## 3. Tests

- [x] 3.1 Add `tests/Unit/Service/File/FolderManagementHandlerFirstUploadTest.php`: a fake root with no folder yet, a session-less request, a register mapper whose `update()` refuses as it does for that request; assert the first upload creates and records the folder, a second upload reuses it, a folder recorded by another request is left alone, and a race shares one folder; verify `vendor/bin/phpunit --no-coverage --filter FolderManagementHandler` passes
- [x] 3.2 Add a wiring test that runs the `FolderManagementHandler` registration closure from `Application` with a container double and gets a handler back; verify it passes

## 4. Verification

- [x] 4.1 Run `composer check:strict`, `npm run lint` and the hydra gates once before push, and record each exit code in the PR body
