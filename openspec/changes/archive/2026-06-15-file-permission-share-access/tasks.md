## 1. Access gate (read)

- [x] 1.1 In `FileValidationHandler::checkOwnership()`, remove the owner-UID-equality deny block; gate on `Node::isReadable()` only
- [x] 1.2 Update the `checkOwnership()` docblock to state the readability-based, ownership-agnostic contract
- [x] 1.3 Leave `ownFile()` / `FileMapper::setFileOwnership()` mechanics intact but no longer invoked on the access path

## 2. Per-action permission (write / delete)

- [x] 2.1 In `UpdateFileHandler`, assert `Node::isUpdateable()` before `putContent()`, throwing `NotPermittedException` when false
- [x] 2.2 In `DeleteFileHandler`, add the `OCP\Files\NotPermittedException` import and assert `Node::isDeletable()` before `delete()`

## 3. Conditional ownership transfer

- [x] 3.1 In `FileOwnershipHandler::transferFileOwnershipIfNeeded()`, return early when `Node::isUpdateable()` is true (skip re-owning when the user has write rights)
- [x] 3.2 Drop the redundant `$currentUserId !== $openRegisterUserId` sub-condition (already guaranteed by the earlier early-return) to keep cyclomatic complexity within the PHPMD threshold
- [x] 3.3 Refresh the stale `@TODO ... hack` comments in `CreateFileHandler` to describe the actual behaviour

## 4. Tests

- [x] 4.1 Rewrite `FileValidationHandlerTest` deny-on-mismatch cases to assert a readable file is allowed regardless of owner (different owner, and null owner)
- [x] 4.2 Confirm the unit suite passes (`FileValidationHandlerTest`: 110/110)
- [x] 4.3 Add `UpdateFileHandlerTest`, `DeleteFileHandlerTest`, `FileOwnershipHandlerTest` covering the new write/delete-permission gates and the no-re-own guard (Docker-only skip guard for `IRootFolder`); 4/4 pass

## 5. Quality gates (disposable env)

- [x] 5.1 phpcs clean on changed files
- [x] 5.2 phpmd clean on changed files (CC within threshold)
- [x] 5.3 psalm / phpstan report no new errors on changed files
- [~] 5.4 Whole-app phpstan + `test:all` gate — NOT run; the whole-app suite has a large pre-existing baseline (phpcs/psalm/phpmd) unrelated to this change. Per-file phpcs/phpmd/psalm/phpstan + the affected unit tests all pass. (Optional, out of scope.)

## 6. Spec & verification

- [x] 6.1 Author delta spec at `openspec/changes/file-permission-share-access/specs/file-actions/spec.md`
- [x] 6.2 `/opsx:verify` the change
- [x] 6.3 API-verify the originating flow on the live instance: create→show→update→delete file on an object all return 200 (404 after delete), no "not owned by the current session" error, file stays owned by the uploading user
- [x] 6.4 `/opsx:archive` to sync the delta into `openspec/specs/file-actions/spec.md`
