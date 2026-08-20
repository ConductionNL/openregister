## 1. Exception type

- [x] 1.1 Add `lib/Exception/FolderAccessDeniedException.php` (namespace `OCA\OpenRegister\Exception`) extending `\Exception`, with a docblock explaining when it's thrown and which HTTP status it maps to. Run `composer dump-autoload` if a classmap regeneration is required.

## 2. Access-control helper

- [x] 2.1 In `lib/Service/File/FolderManagementHandler.php`, add a private `assertFolderIsAccessible(string $folderId, ?IUser $currentUser): Folder` that resolves the folder via the user's folder only (`getOpenRegisterUserFolder()->getById((int) $folderId)`) — deliberately NOT using `$this->rootFolder` and NOT calling `getNodeById()`. Verify the node exists, is a `Folder` instance, and `isReadable()` returns true. Throw `FolderAccessDeniedException` with the attempted ID on any failure. Add an inline comment pointing readers to `getNodeById()` for the general-purpose lookup with root fallback, clarifying why this path doesn't use it.

## 3. Wire the check into `createObjectFolderById()`

- [x] 3.1 In `createObjectFolderById()`, after reading `$folderProperty = $objectEntity->getFolder()`: empty `$folderProperty` → proceed to auto-create (unchanged); non-numeric `$folderProperty` → proceed to auto-create (legacy path, unchanged); non-empty numeric string → call `assertFolderIsAccessible()` and use the returned `Folder` as the resolved existing folder (skip the existing `getExistingFolderFromProperty()` call on this branch).
- [x] 3.2 Remove the implicit auto-create-on-invalid-ID behaviour from this branch: if `assertFolderIsAccessible()` throws, propagate; do not fall through to the register-folder creation path. Confirm `getExistingFolderFromProperty()` is still used by other callers (grep) but is no longer reached from `createObjectFolderById()` for numeric user-supplied values.

## 4. Audit trail on denial

- [x] 4.1 Add a private `logFolderAccessDenied(string $folderId, ?IUser $currentUser): void` that writes an `AuditTrailMapper` entry with `action: "folder_access_denied"`, `actor: $currentUser?->getUID() ?? "system"`, `metadata: ["folder" => $folderId, "timestamp" => ...]`, and handles mapper failures by logging a warning. Call it from inside `assertFolderIsAccessible()` immediately BEFORE throwing `FolderAccessDeniedException` on every failure path (not found, not a folder, not readable) so even a caller that catches the exception still has the forensic record.

## 5. Controller mapping to HTTP 403

- [x] 5.1 Identify every controller method that calls into `RegisterService::saveObject()` / `ObjectEntityService::saveObject()` / the underlying `SaveObjects` pipeline (start with `lib/Controller/ObjectsController.php` and grep for `saveObject(`). In each save endpoint, catch `FolderAccessDeniedException` SPECIFICALLY (before any generic exception catch to avoid absorbing it as a 500) and return a `JSONResponse` per the canonical spec at `specs/self-folder-access-control/spec.md` (status 403, body `{ "error": "folder_access_denied" }` — the response MUST NOT include the attempted folder ID, which would be an enumeration oracle; the ID is recorded in the audit trail only). Prefer extending an existing shared error-handler method (e.g. `handleSaveException`) over copy-pasting the try/catch. Confirm no upstream controller or middleware catches the exception as a generic `\Exception` before the intended 403 mapping runs.

## 6. Audit internal callers

- [x] 6.1 Grep the entire OpenRegister codebase for callsites that set `@self.folder` or `ObjectEntity::setFolder()` outside of `FolderManagementHandler` itself. For each (`TransferCheckJob`, `DestructionCheckJob`, `ImportService`, `SolrNightlyWarmupJob`, `NameCacheWarmupJob`, `RegistersLoader`, etc.), document whether they (a) always use an accessible folder ID, (b) need to pass an explicit `$currentUser`, or (c) need to clear the field before save — most don't set `@self.folder` and are therefore unaffected. If any caller regresses, adjust it in the same PR (pass `$currentUser` through, clear the field, or use the same HTTP 403 flow if the caller serves HTTP).

## 7. Unit tests

- [x] 7.1 Add `Tests/Unit/Service/File/FolderManagementHandlerAccessControlTest.php` covering: owned folder bind succeeds; shared-readable folder bind succeeds; unshared cross-user folder bind throws `FolderAccessDeniedException`; non-existent numeric ID throws; file-ID (not folder) throws; trashed folder throws; empty folder property → auto-create (no exception); legacy non-numeric folder property → auto-create (no exception); explicit `$currentUser` argument overrides the session user; audit-trail entry written on each denial.
- [x] 7.2 Add tests asserting `FolderAccessDeniedException` is a distinct class extending `\Exception` (NOT a subclass of `NotPermittedException` or other Nextcloud exceptions, so catch-blocks don't accidentally absorb it) and that `ObjectsController` returns HTTP 403 with the structured body when the service throws. Run inside the container: `docker exec -w /var/www/html/custom_apps/openregister nextcloud php vendor/bin/phpunit -c phpunit-unit.xml --filter FolderManagementHandlerAccessControl`. Confirm overall unit-test coverage for new code stays ≥75% (ADR-009).

## 8. Integration / manual verification

- [x] 8.1 Reset the local env (`bash clean-env.sh` or `/clean-env`) and bring the stack up. As `alice`, create a folder "alice-private" via Files UI, note its node ID. As `bob`, POST an object with `@self.folder: "<alice-private-id>"` → confirm HTTP 403 with `error: "folder_access_denied"` in the body. As `bob`, POST without `@self.folder` → confirm auto-create proceeds normally (HTTP 201). As `bob`, POST with `@self.folder: "<bob's-own-folder-id>"` → confirm HTTP 201 and the returned object's `folder` matches.
- [x] 8.2 Query the audit trail for `folder_access_denied` entries and confirm `bob`'s denial from 8.1 is present with the attempted folder ID. Restart the Nextcloud container with an intentionally broken `AuditTrailMapper` (or mock the failure) and repeat the cross-user POST: confirm HTTP 403 is still returned (audit failure does not swallow the denial).

## 9. Documentation

- [x] 9.1 Update `docs/api/objects.md` (or equivalent API reference) to document the `@self.folder` access-control contract: success behaviour, denial behaviour (HTTP 403, structured error body), and the preserved auto-create behaviour when the field is absent. Add a CHANGELOG entry under the next release version flagging the BREAKING change: "Callers that set `@self.folder` to a node ID outside the acting user's accessible tree now receive HTTP 403 instead of a silent cross-tenant bind." Note the DocuDesk `add-dossier-schema` change as the first downstream beneficiary and note the follow-up `occ openregister:folder-audit` command as tracked separately.

## 10. Strict quality gates

- [x] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) with no new warnings on touched files; no new `@SuppressWarnings` annotations introduced; full unit-test suite green inside the container.
