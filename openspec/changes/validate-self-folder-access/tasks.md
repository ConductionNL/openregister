- [ ] 1. Exception type
  - [ ] 1.1 Add `lib/Exception/FolderAccessDeniedException.php` extending `\Exception`. Namespace: `OCA\OpenRegister\Exception`. Include a docblock explaining when it's thrown and which HTTP status it maps to.
  - [ ] 1.2 Add the new class to any composer classmap regeneration if required (`composer dump-autoload`).

- [ ] 2. Access-control helper
  - [ ] 2.1 In `lib/Service/File/FolderManagementHandler.php`, add a private method `assertFolderIsAccessible(string $folderId, ?IUser $currentUser): Folder`. Resolve the folder via the user's folder only (`getOpenRegisterUserFolder()->getById((int) $folderId)`) — deliberately NOT using `$this->rootFolder` and NOT calling `getNodeById()`. Verify the node exists, is a `Folder` instance, and `isReadable()` returns true. Throw `FolderAccessDeniedException` with the attempted ID on any failure.
  - [ ] 2.2 Add an inline comment pointing readers to `getNodeById()` for the general-purpose lookup with root fallback, clarifying why this path doesn't use it.

- [ ] 3. Wire the check into `createObjectFolderById()`
  - [ ] 3.1 In `createObjectFolderById()`, after reading `$folderProperty = $objectEntity->getFolder()`, check: (a) if `$folderProperty` is empty — proceed to auto-create (unchanged); (b) if `$folderProperty` is non-numeric — proceed to auto-create (legacy path, unchanged); (c) if `$folderProperty` is a non-empty numeric string — call `assertFolderIsAccessible()`. Use the returned `Folder` as the resolved existing folder (skip the existing `getExistingFolderFromProperty()` call on this branch).
  - [ ] 3.2 Remove the implicit auto-create-on-invalid-ID behaviour from this branch: if `assertFolderIsAccessible()` throws, propagate the exception; do not fall through to the register-folder creation path.
  - [ ] 3.3 Ensure `getExistingFolderFromProperty()` is still used for any other callers (grep the codebase) but is no longer reached from `createObjectFolderById()` for numeric user-supplied values.

- [ ] 4. Audit trail on denial
  - [ ] 4.1 Add a private `logFolderAccessDenied(string $folderId, ?IUser $currentUser): void` method that writes an `AuditTrailMapper` entry with `action: "folder_access_denied"`, `actor: $currentUser?->getUID() ?? "system"`, `metadata: ["folder" => $folderId, "timestamp" => ...]`, and handles mapper failures by logging a warning.
  - [ ] 4.2 Call the audit method from inside `assertFolderIsAccessible()` immediately before throwing `FolderAccessDeniedException` (every failure path — not found, not a folder, not readable).
  - [ ] 4.3 Verify the audit trail is written BEFORE the exception is thrown (so even a caller that catches the exception still has the forensic record).

- [ ] 5. Controller mapping to HTTP 403
  - [ ] 5.1 Identify every controller method that calls into `RegisterService::saveObject()` / `ObjectEntityService::saveObject()` / the underlying `SaveObjects` pipeline — start with `lib/Controller/ObjectsController.php` and grep for `saveObject(`.
  - [ ] 5.2 In each save endpoint, catch `FolderAccessDeniedException` specifically (catch it BEFORE the generic exception catch to avoid absorbing it as a 500). Return a `JSONResponse` with status 403 and body `{ "error": "folder_access_denied", "folder": "<attempted-id>" }`. Prefer extending an existing shared error-handler method if one exists (`handleSaveException` or similar) rather than copy-pasting the try/catch.
  - [ ] 5.3 Confirm no upstream controller or middleware catches `FolderAccessDeniedException` as a generic `\Exception` before the intended 403 mapping runs.

- [ ] 6. Audit internal callers
  - [ ] 6.1 Grep the entire OpenRegister codebase for callsites that set `@self.folder` or `ObjectEntity::setFolder()` outside of `FolderManagementHandler` itself. Identify every caller and document whether they (a) always use an accessible folder ID, (b) need to pass an explicit `$currentUser`, or (c) need to clear the field before save.
  - [ ] 6.2 For each listed caller (`TransferCheckJob`, `DestructionCheckJob`, `ImportService`, `SolrNightlyWarmupJob`, `NameCacheWarmupJob`, `RegistersLoader`, etc.), verify behaviour is unchanged — most don't set `@self.folder` at all and are therefore unaffected.
  - [ ] 6.3 If any caller regresses, adjust it in the same PR: pass `$currentUser` through, or clear the field, or use the same HTTP 403 flow if the caller is itself serving HTTP.

- [ ] 7. Unit tests
  - [ ] 7.1 Add `Tests/Unit/Service/File/FolderManagementHandlerAccessControlTest.php` covering: (a) owned folder bind succeeds; (b) shared-readable folder bind succeeds; (c) unshared cross-user folder bind throws `FolderAccessDeniedException`; (d) non-existent numeric ID throws; (e) file-ID (not folder) throws; (f) trashed folder throws; (g) empty folder property → auto-create (no exception); (h) legacy non-numeric folder property → auto-create (no exception); (i) explicit `$currentUser` argument overrides the session user for the check; (j) audit-trail entry is written on each denial.
  - [ ] 7.2 Add a test asserting that `FolderAccessDeniedException` is a distinct class extending `\Exception` and is not a subclass of `NotPermittedException` or other Nextcloud exceptions (so catch-blocks don't accidentally absorb it).
  - [ ] 7.3 Add a test asserting that `ObjectsController` returns HTTP 403 with the structured body when the service throws `FolderAccessDeniedException`.
  - [ ] 7.4 Run tests inside the Nextcloud container: `docker exec -w /var/www/html/custom_apps/openregister nextcloud php vendor/bin/phpunit -c phpunit-unit.xml --filter FolderManagementHandlerAccessControl`. Confirm green.
  - [ ] 7.5 Confirm overall unit-test coverage for new code stays ≥75% (ADR-009).

- [ ] 8. Integration / manual verification
  - [ ] 8.1 Reset the local env (`bash clean-env.sh` or `/clean-env`) and bring the stack up.
  - [ ] 8.2 As user `alice`, create a folder "alice-private" via Files UI, note its node ID.
  - [ ] 8.3 As user `bob` (different account), POST an object with `@self.folder: "<alice-private-id>"`. Confirm HTTP 403 with `error: "folder_access_denied"` in the body.
  - [ ] 8.4 As `bob`, POST without `@self.folder`. Confirm auto-create proceeds normally (HTTP 201).
  - [ ] 8.5 As `bob`, POST with `@self.folder: "<bob's-own-folder-id>"`. Confirm HTTP 201 and the returned object's `folder` matches.
  - [ ] 8.6 Query the audit trail for `folder_access_denied` entries and confirm `bob`'s denial from 8.3 is present with the attempted folder ID.
  - [ ] 8.7 Restart the Nextcloud container with an intentionally broken `AuditTrailMapper` (or mock the failure) and repeat 8.3. Confirm HTTP 403 is still returned (audit failure does not swallow the denial).

- [ ] 9. Documentation
  - [ ] 9.1 Update `docs/api/objects.md` (or equivalent API reference) to document the `@self.folder` access-control contract: what happens on success, what happens on denial (HTTP 403, structured error body), and the preserved auto-create behaviour when the field is absent.
  - [ ] 9.2 Add a CHANGELOG entry under the next release version flagging the BREAKING change: "Callers that set `@self.folder` to a node ID outside the acting user's accessible tree now receive HTTP 403 instead of a silent cross-tenant bind."
  - [ ] 9.3 Note the DocuDesk `add-dossier-schema` change as the first downstream beneficiary, and note the follow-up `occ openregister:folder-audit` command as tracked separately.

- [ ] 10. Strict quality gates
  - [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and ensure no new warnings in the touched files.
  - [ ] 10.2 Confirm no new `@SuppressWarnings` annotations were introduced.
  - [ ] 10.3 Run the full unit-test suite inside the container and confirm green.
