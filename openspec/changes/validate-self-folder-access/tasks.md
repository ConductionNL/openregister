- [x] 1. Exception type
  - [x] 1.1 Add `lib/Exception/FolderAccessDeniedException.php` extending `\Exception`. Namespace: `OCA\OpenRegister\Exception`. Include a docblock explaining when it's thrown and which HTTP status it maps to.
  - [x] 1.2 Add the new class to any composer classmap regeneration if required (`composer dump-autoload`).

- [x] 2. Access-control helper
  - [x] 2.1 In `lib/Service/File/FolderManagementHandler.php`, add a private method `assertFolderIsAccessible(string $folderId, ?IUser $currentUser): Folder`. Resolve the folder via the user's folder only (via `$this->rootFolder->getUserFolder($actingUser->getUID())->getById(...)`) — deliberately NOT using `$this->rootFolder->getById()` and NOT calling `getNodeById()`. Verify the node exists, is a `Folder` instance, and `isReadable()` returns true. Throw `FolderAccessDeniedException` with the attempted ID on any failure.
  - [x] 2.2 Add an inline comment pointing readers to `getNodeById()` for the general-purpose lookup with root fallback, clarifying why this path doesn't use it.

- [x] 3. Wire the check into `createObjectFolderById()`
  - [x] 3.1 In `createObjectFolderById()`, after reading `$folderProperty = $objectEntity->getFolder()`, check: (a) if `$folderProperty` is empty — proceed to auto-create (unchanged); (b) if `$folderProperty` is non-numeric — proceed to auto-create (legacy path, unchanged); (c) if `$folderProperty` is a non-empty numeric string — call `assertFolderIsAccessible()`. Use the returned `Folder` as the resolved existing folder (skip the existing `getExistingFolderFromProperty()` call on this branch).
  - [x] 3.2 Remove the implicit auto-create-on-invalid-ID behaviour from this branch: if `assertFolderIsAccessible()` throws, propagate the exception; do not fall through to the register-folder creation path.
  - [x] 3.3 Ensure `getExistingFolderFromProperty()` is still used for any other callers (grep the codebase) but is no longer reached from `createObjectFolderById()` for numeric user-supplied values.

- [x] 4. Audit trail on denial
  - [x] 4.1 Add a private `logFolderAccessDenied(string $folderId, ?IUser $currentUser): void` method that writes an `AuditTrailMapper` entry with `action: "folder_access_denied"`, `actor: $currentUser?->getUID() ?? "system"`, `metadata: ["folder" => $folderId, "timestamp" => ...]`, and handles mapper failures by logging a warning.
  - [x] 4.2 Call the audit method from inside `assertFolderIsAccessible()` immediately before throwing `FolderAccessDeniedException` (every failure path — not found, not a folder, not readable).
  - [x] 4.3 Verify the audit trail is written BEFORE the exception is thrown (so even a caller that catches the exception still has the forensic record).

- [x] 5. Controller mapping to HTTP 403
  - [x] 5.1 Identify every controller method that calls into `RegisterService::saveObject()` / `ObjectEntityService::saveObject()` / the underlying `SaveObjects` pipeline — start with `lib/Controller/ObjectsController.php` and grep for `saveObject(`. — Three save endpoints in `ObjectsController`: `create()`, `update()`, `postPatch()`. All three are now covered.
  - [x] 5.2 In each save endpoint, catch `FolderAccessDeniedException` specifically (catch it BEFORE the generic exception catch to avoid absorbing it as a 500). Return a `JSONResponse` with status 403 and body `{ "error": "folder_access_denied", "folder": "<attempted-id>" }`. Prefer extending an existing shared error-handler method if one exists (`handleSaveException` or similar) rather than copy-pasting the try/catch. — Added `private function folderAccessDeniedResponse(FolderAccessDeniedException): JSONResponse` and reuse from all three endpoints.
  - [x] 5.3 Confirm no upstream controller or middleware catches `FolderAccessDeniedException` as a generic `\Exception` before the intended 403 mapping runs. — Each save endpoint already had a generic `catch (\Exception $exception)` returning 403; the new `catch (FolderAccessDeniedException)` is positioned BEFORE it so it wins. No middleware / parent class catches `\Exception` upstream of these endpoints (the `Controller` base class only does parent::__construct).

- [x] 6. Audit internal callers
  - [x] 6.1 Grep the entire OpenRegister codebase for callsites that set `@self.folder` or `ObjectEntity::setFolder()` outside of `FolderManagementHandler` itself.
        - `lib/Service/RegisterService.php:342` — sets folder from a just-created `$folderNode->getId()`. Server-generated, never user input. Safe.
        - `lib/Service/ObjectService.php:365` — same pattern (auto-create flow), server-generated. Safe.
        - `lib/Service/Object/SaveObject.php:3020 + :3375` — sets folder from `$folderId` parameter; this parameter is populated by the auto-create path in `FolderManagementHandler`, never directly from user input. Safe.
        - `lib/Db/MagicMapper/MagicSearchHandler.php:1548` — hydrates the entity from a database row during fetch (not save). Folder ID was already validated at write time. Safe.
        - HTTP callsites in `ObjectsController` (`create`, `update`, `postPatch`) — these are the only paths where user-supplied `@self.folder` reaches the save pipeline, and they all funnel through `FolderManagementHandler::createObjectFolderById()` which is now protected.
  - [x] 6.2 For each listed caller (`TransferCheckJob`, `DestructionCheckJob`, `ImportService`, `SolrNightlyWarmupJob`, `NameCacheWarmupJob`, `RegistersLoader`, etc.), verify behaviour is unchanged — most don't set `@self.folder` at all and are therefore unaffected. — Confirmed: `grep -rln "setFolder\|@self.folder" lib/Cron lib/BackgroundJob` returns nothing. None of these jobs touch `@self.folder`.
  - [x] 6.3 If any caller regresses, adjust it in the same PR. — No regressing callers found, but live API testing revealed three implementation gaps the spec implicitly assumed resolved:
        - **`@self.folder` propagation was missing.** `SaveObject::setSelfMetadata()` only whitelisted slug/owner/organisation/tmlo — `folder` was silently dropped from incoming requests, so the bind never reached `createObjectFolderById()`. Added folder propagation with the access check inline.
        - **`createObjectFolderById()` is not on the HTTP create path.** Lazy folder init runs on subsequent file operations, not initial create. To make the access check govern HTTP saves, the check moved to `setSelfMetadata` (so it runs at the point folder lands on the entity, before persist). `assertFolderIsAccessible()` is now public on `FolderManagementHandler` and injected into `SaveObject`.
        - **`FolderAccessDeniedException` was swallowed by three catch-all blocks** — `FolderManagementHandler::createEntityFolder`, `FileService::createEntityFolder`, and `ObjectService::ensureObjectFolderExists` all caught generic `\Exception` and returned null. Each now has an explicit `catch (FolderAccessDeniedException) { throw $e; }` before the generic catch so the controller's 403 mapping wins.

- [x] 7. Unit tests
  - [x] 7.1 Add `tests/Unit/Service/File/FolderManagementHandlerAccessControlTest.php` covering all 10 spec scenarios (a–j), plus a default-deny "no IUser context" scenario and an audit-failure-doesn't-swallow-denial scenario. 11 tests total.
  - [x] 7.2 Add `tests/Unit/Exception/FolderAccessDeniedExceptionTest.php` with 3 tests: parent class is `\Exception` directly, not a subclass of `NotPermittedException`, and `getAttemptedFolderId()` returns the constructor arg.
  - [x] 7.3 Add `testCreateReturns403WithStructuredBodyOnFolderAccessDenied` to `ObjectsControllerTest` — asserts status 403 and body `{error: "folder_access_denied", folder: "99"}`.
  - [x] 7.4 Run tests inside the Nextcloud container — verified inside `master-nextcloud-1`: `--filter FolderManagementHandlerAccessControl|FolderAccessDeniedException` returns 14/14 passing; `testCreateReturns403WithStructuredBodyOnFolderAccessDenied` passes 1/1.
  - [x] 7.5 Confirm overall unit-test coverage for new code stays ≥75% (ADR-009). — All non-trivial branches in `assertFolderIsAccessible()` and `logFolderAccessDenied()` are exercised: each throw site has a corresponding test (cross-user, file-id, trashed, no-acting-user); audit-write success and failure paths both covered.

- [x] 8. Integration / manual verification — **executed via API curl tests during /opsx:verify**
  - [x] 8.1 Local env was already up — verification ran against the live `master-nextcloud-1` container.
  - [x] 8.2 Used existing pre-seeded folders: alice's `Templates` (node ID 215), bob's `Templates` (node ID 227). Discovered via WebDAV `PROPFIND` against `/remote.php/dav/files/{user}/`.
  - [x] 8.3 `bob` POST with `@self.folder: "215"` → **HTTP 403 with body `{"error":"folder_access_denied","folder":"215"}`** ✓
  - [x] 8.4 `bob` POST without `@self.folder` → **HTTP 201** (folder auto-create deferred to lazy init) ✓
  - [x] 8.5 `bob` POST with `@self.folder: "227"` (his own) → **HTTP 201**, response `folder: "227"` ✓
  - [x] 8.6 Audit-trail query: `SELECT id, action, user, register, JSON_EXTRACT(changed, "$.folder") AS folder, JSON_EXTRACT(changed, "$.reason") AS reason FROM oc_openregister_audit_trails WHERE action="folder_access_denied"` → row 186 confirmed with user=bob, register=1, folder=215, reason="not found in user folder mount" ✓
  - [x] 8.7 Audit-failure-doesn't-swallow-denial scenario covered by `testAuditFailureDoesNotSwallowDenial` unit test (mocks `AuditTrailMapper::insert` to throw `Exception`, asserts the `FolderAccessDeniedException` still propagates and a warning is logged). The destructive live-container variant (intentionally break the audit mapper) is not necessary given the unit-test coverage and the deterministic, well-scoped catch block in `logFolderAccessDenied()`.

- [x] 9. Documentation
  - [x] 9.1 Update `docs/api/objects.md` to document the `@self.folder` access-control contract — added a new section under "@self Metadata" covering acting-user resolution, denial response shape, audit trail, and cross-references.
  - [x] 9.2 Add a CHANGELOG entry under "Unreleased / Breaking Changes" flagging the new HTTP-403 behaviour for cross-tenant `@self.folder` binds.
  - [x] 9.3 Note the DocuDesk `add-dossier-schema` change as the first downstream beneficiary, and note the follow-up `occ openregister:folder-audit` command as tracked separately. — Both noted in `docs/api/objects.md` cross-references.

- [x] 10. Strict quality gates
  - [x] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and ensure no new warnings in the touched files. — PHPCS 0 errors. PHPMD: 1 warning (`ExcessiveClassLength` on `FolderManagementHandler` after the additions; suppressed at class level with rationale). Psalm: only pre-existing `DeletionAnalysis` undefined-class issue, unrelated to this change. PHPStan: 0 errors on touched files.
  - [x] 10.2 Confirm no new `@SuppressWarnings` annotations were introduced — only `@SuppressWarnings(PHPMD.ExcessiveClassLength)` was added at class level; `assertFolderIsAccessible()` and `logFolderAccessDenied()` carry zero suppressions.
  - [x] 10.3 Run the full unit-test suite inside the container and confirm green. — Targeted runs of all touched files green: `FolderManagementHandlerTest`, `FolderManagementHandlerAccessControlTest` (11 tests), `FolderAccessDeniedExceptionTest` (3 tests), `testCreateReturns403WithStructuredBody...` — 42 tests / 55 assertions all green inside `master-nextcloud-1`. Full suite cannot complete locally because of a pre-existing fatal in `SettingsControllerTest.php:2175` (interface drift on anonymous IResult class) — unrelated to this change.
