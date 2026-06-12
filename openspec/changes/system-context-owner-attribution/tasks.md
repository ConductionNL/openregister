## Tasks

### 1. Add system-identity helpers to OrganisationService
- [x] Add `OrganisationService::getSystemUserId(): string` — reads `openregister.systemUserId` via `IAppConfig`, falls back to constant `OrganisationService::SYSTEM_USER_ID_DEFAULT = '__system__'`.
- [x] Add `OrganisationService::getSystemReaderGroups(): array` — reads `openregister.systemReaderGroups`, splits on `,`, trims each entry, drops empties; returns `string[]`.
- [x] Add `OrganisationService::SYSTEM_USER_ID_DEFAULT` class constant for the default value.
- [x] Update `OrganisationService` docblock + SPDX header (no new file, header already present).

### 2. Wire system-attribution into SaveObject
- [x] In `SaveObject::prepareObjectForCreation()` (around line 3417), when `$this->userSession->getUser()` returns `null`, call `$this->organisationService->getSystemUserId()` and `$objectEntity->setOwner(<id>)` so the row is never persisted with empty `_owner`. (Extracted into `applyOwnerAttribution()` helper, called from `prepareObjectForCreation()`.)
- [x] Confirm no second code path persists `_owner` empty — search `lib/Service/Object/SaveObject.php` for every `setOwner(` to ensure the system fallback covers the prepare-for-create flow only. (Both `setOwner(` call sites live inside `applyOwnerAttribution()`.)
- [x] Keep `prepareObjectForUpdate` untouched — updates preserve the existing owner.

### 3. Wire system-visibility into MagicRbacHandler
- [x] In `MagicRbacHandler::applyRbacFilters()`, after the admin-bypass return, before building `$conditions`, compute the system-reader carve-out (via `shouldGrantSystemRowVisibility()`) and push `_owner = <systemUserId>` (named parameter) as an OR-condition. (Admins handled by the existing top-of-method full bypass, per design D5.)
- [x] Apply the equivalent change in `MagicRbacHandler::buildRbacConditionsSql()` (the raw-SQL twin), using `quoteValue($systemUserId)` to stay consistent with the existing quoting pattern.
- [x] Inject `OrganisationService` via the existing DI constructor — `MagicRbacHandler` lazy-loads the service from the container (`resolveOrganisationService()`), consistent with the file's pattern.

### 4. PHPUnit coverage
- [x] `tests/Unit/Service/Object/SaveObjectSystemOwnerTest.php` — new file. Cases:
  - `prepareObjectForCreation` with `IUserSession::getUser() === null` sets `_owner` to `__system__` (default).
  - Same with `openregister.systemUserId = 'cron-bot'` sets `_owner = 'cron-bot'`.
  - Same with user session present sets `_owner` to the user UID (regression — no behaviour change).
- [x] `tests/Unit/Db/MagicMapper/MagicRbacHandlerSystemOwnerTest.php` — new file. Cases:
  - Admin user list query receives an OR-condition matching `_owner = '__system__'` (or whatever override).
  - Non-admin user in `systemReaderGroups` group receives the same OR-condition.
  - Non-admin user NOT in any reader group does NOT receive the OR-condition (regression — no visibility leak).
  - Reader-group parse: trims, drops empties.
- [x] `tests/Unit/Service/OrganisationServiceSystemIdentityTest.php` — new file. Cases:
  - `getSystemUserId()` returns `__system__` when key unset.
  - `getSystemUserId()` returns the configured override.
  - `getSystemReaderGroups()` returns `[]` when key unset.
  - `getSystemReaderGroups()` trims and drops empties as documented.

### 5. Quality gates (must be green before PR)
- [x] `composer phpcs` clean on changed files (0 errors above baseline; only pre-existing class/unrelated-method `@spec` warnings remain).
- [x] `composer phpmd` clean on changed files (no findings on the new methods).
- [x] `composer psalm` clean on changed files (verified at merge of PR #1645).
- [x] `composer phpstan` clean on changed files (verified at merge of PR #1645).
- [x] `composer phpunit` — all existing tests pass + new tests pass (13 tests, 22 assertions, OK).

### 6. PR
- [x] Open PR targeting `development` (not `main`). (PR #1645, merged as `b08d7585a`.)
- [x] Add labels `ready-for-code-review` and `ready-for-security-review`.
- [x] PR description references issue #1617 with `Closes #1617`.
- [x] Note in the PR body that no DB migration runs — operators backfill manually per design.md.

### 7. Out of scope / follow-up
- [x] (Out of scope) Backfill OCC command for existing `_owner=''` rows — open a separate issue if operators request it. **Out of scope handoff.** The spec itself marks this as opt-in based on operator need; no operator request received yet. Per design.md, operators backfill manually with a one-liner UPDATE on each magic table.
- [x] (Out of scope) Per-schema `systemReaderGroups` override — revisit if multi-tenant deployments ask. **Out of scope handoff.** The spec itself marks this as opt-in based on multi-tenant deployment need; the global `openregister.systemReaderGroups` config key is sufficient for the current single-tenant deployments.
