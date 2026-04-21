## 1. Ownership-probe rewrite

- [ ] 1.1 Replace `$file->getContent()` probe in `FileValidationHandler::checkOwnership()` with `$file->isReadable()`; preserve the `ownFile()` repair call for users who can read but are not recorded as owners
- [ ] 1.2 Remove the `NotPermittedException` / `NotFoundException` catches that were wrapping the `getContent()` probe; replace with a direct `if ($file->isReadable() === false) { throw new NotPermittedException(...); }` guard
- [ ] 1.3 Keep the outer method signature (`checkOwnership(Node $file, ?string $userId = null): void`) stable — no caller changes
- [ ] 1.4 Add a docblock note explaining the intent (cheap metadata probe, does not read bytes, does not acquire locks) citing ADR context from design.md Decision 1

## 2. Lock metadata wiring

- [ ] 2.1 Inject `OCP\Files\Lock\ILockManager` AND `OCP\IUserSession` into `FileFormattingHandler` via constructor DI; update `FileService` wiring if needed
- [ ] 2.2 Add a private helper `formatLock(int $fileId): ?array` that returns `null` when `isLockProviderAvailable()` is `false` or `getLocks($fileId)` is empty, otherwise returns the `{type, scope, owner, createdAt, expiresAt}` envelope
- [ ] 2.3 Map `ILock::TYPE_USER`/`TYPE_APP`/`TYPE_TOKEN` to `"user"`/`"app"`/`"token"` strings, and `LOCK_EXCLUSIVE`/`LOCK_SHARED` to `"exclusive"`/`"shared"`
- [ ] 2.4 Compute `expiresAt` as `getCreatedAt() + getTimeout()` when `getTimeout() > 0`, otherwise `null`; format both timestamps as ISO 8601 with offset
- [ ] 2.5 In `FileFormattingHandler::formatFile()`, gate both `locked` and `lock` on `$this->userSession->getUser() !== null`: append them only for authenticated callers; emit the pre-change envelope (no `locked`, no `lock`) for anonymous callers
- [ ] 2.6 When the caller is anonymous, `formatLock()` MUST NOT be invoked (no unnecessary `ILockManager` calls on the public path)

## 3. Listing resilience

- [ ] 3.1 In `FileFormattingHandler::formatFiles()`, wrap the per-file `formatFile()` call in a `try/catch OCP\Lock\LockedException` block
- [ ] 3.2 On catch, emit one `info`-level structured log entry with `fileId`, `name`, and best-effort lock owner/type
- [ ] 3.3 On catch, append a minimal envelope to the result set so the locked file is not elided from counts — for authenticated callers: `{fileId, name, locked: true, lock: <best-effort>, error: "locked"}`; for anonymous callers: `{fileId, name, error: "locked"}` (no `locked`, no `lock`)
- [ ] 3.4 Remove the `_limit` upper ceiling entirely in `FileFormattingHandler::formatFiles()`: change `$limit = max(1, min(100, (int) (...)))` to `$limit = max(1, (int) (...))` so any positive `_limit` is honoured; keep the floor at 1 and the default at 30 (when `_limit` is absent)
- [ ] 3.5 Audit `FileService::findShares` and `FileService::checkOwnership` call sites to confirm nothing else in the hot path triggers `getContent()` or storage reads

## 4. Tests

- [ ] 4.1 Unit test `FileValidationHandler::checkOwnership()` with a readable node (no-op), unreadable node (throws `NotPermittedException`), and missing owner on readable node (triggers `ownFile()` repair via mock `FileMapper`)
- [ ] 4.2 Unit test `FileFormattingHandler::formatFile()` (authenticated) with a `LockManager` that returns no locks (result has `locked: false`, no `lock` key), one user lock (`locked: true`, `lock.type === "user"`), and one app lock with timeout (`lock.expiresAt` is createdAt+timeout ISO string)
- [ ] 4.3 Unit test `FileFormattingHandler::formatFile()` (authenticated) with `isLockProviderAvailable()` returning false (result has `locked: false`, no `lock` key, no call to `getLocks`)
- [ ] 4.4 Unit test `FileFormattingHandler::formatFile()` (anonymous — `IUserSession::getUser()` returns `null`): result has NO `locked` key, NO `lock` key, and the `ILockManager` mock records zero calls to `getLocks()` / `isLockProviderAvailable()`
- [ ] 4.5 Unit test `FileFormattingHandler::formatFiles()` with one file that raises `LockedException` during `formatFile()` — remaining files format normally, locked file is included with `error: "locked"`, one `info` log line is emitted; run the test twice (once authenticated, once anonymous) to confirm the anonymous envelope omits `locked`/`lock`
- [ ] 4.6 Unit test the `_limit` handling: `_limit=500` → 500, `_limit=5000` → 5000 (no ceiling), `_limit=0` → 1, `_limit=-1` → 1, missing → 30

## 5. Documentation & quality

- [ ] 5.1 Update `docs/Features/` (or the relevant file-attachments feature doc) describing the new `locked`/`lock` fields, the removal of the `_limit` ceiling (default 30, floor 1, no upper cap), AND the authentication gate (anonymous callers never see `locked`/`lock`); include the string-alias mapping table for `lock.type` and `lock.scope`
- [ ] 5.2 Run `composer check:strict` from the `openregister/` directory; fix PHPCS/PHPMD/Psalm/PHPStan findings until green
- [ ] 5.3 Manual smoke: with the `files_lock` app enabled, lock one file on an object, hit `GET /api/objects/{r}/{s}/{id}/files?_limit=500` as an authenticated user and confirm HTTP 200, all files returned, locked file has `locked: true` and `lock` populated, log line emitted
- [ ] 5.4 Manual smoke: same scenario but hit the endpoint with no session (logged out, public object) — confirm HTTP 200, all files returned, NO file has `locked` or `lock`, server-side log line is still emitted

## 6. Consumer verification

- [ ] 6.1 Exercise the listing from the OpenRegister UI (`localhost:3030`) on a register with >100 files and confirm the cap is no longer hit; confirm the detail page renders when a file is locked
- [ ] 6.2 Skim opencatalogi and softwarecatalog frontend calls to the files endpoint — confirm they do not break on the new fields (they should ignore unknown keys)
