## Tasks

### 1. Add system-identity helpers to OrganisationService
- [ ] Add `OrganisationService::getSystemUserId(): string` — reads `openregister.systemUserId` via `IAppConfig`, falls back to constant `OrganisationService::SYSTEM_USER_ID_DEFAULT = '__system__'`.
- [ ] Add `OrganisationService::getSystemReaderGroups(): array` — reads `openregister.systemReaderGroups`, splits on `,`, trims each entry, drops empties; returns `string[]`.
- [ ] Add `OrganisationService::SYSTEM_USER_ID_DEFAULT` class constant for the default value.
- [ ] Update `OrganisationService` docblock + SPDX header (no new file, header already present).

### 2. Wire system-attribution into SaveObject
- [ ] In `SaveObject::prepareObjectForCreation()` (around line 3417), when `$this->userSession->getUser()` returns `null`, call `$this->organisationService->getSystemUserId()` and `$objectEntity->setOwner(<id>)` so the row is never persisted with empty `_owner`.
- [ ] Confirm no second code path persists `_owner` empty — search `lib/Service/Object/SaveObject.php` for every `setOwner(` to ensure the system fallback covers the prepare-for-create flow only.
- [ ] Keep `prepareObjectForUpdate` untouched — updates preserve the existing owner.

### 3. Wire system-visibility into MagicRbacHandler
- [ ] In `MagicRbacHandler::applyRbacFilters()`, after the admin-bypass return, before building `$conditions`, compute `$isSystemReader = in_array('admin', $userGroups, true) || count(array_intersect($userGroups, $this->organisationService->getSystemReaderGroups())) > 0;`. When `$isSystemReader === true`, push `_owner = <systemUserId>` (named parameter) as an OR-condition.
- [ ] Apply the equivalent change in `MagicRbacHandler::buildRbacConditionsSql()` (the raw-SQL twin), using `$this->quoteValue($systemUserId)` to stay consistent with the existing quoting pattern at line 795-796.
- [ ] Inject `OrganisationService` via the existing DI constructor — `MagicRbacHandler` already lazy-loads services from the container per the file's pattern; confirm constructor signature.

### 4. PHPUnit coverage
- [ ] `tests/Unit/Service/Object/SaveObjectSystemOwnerTest.php` — new file. Cases:
  - `prepareObjectForCreation` with `IUserSession::getUser() === null` sets `_owner` to `__system__` (default).
  - Same with `openregister.systemUserId = 'cron-bot'` sets `_owner = 'cron-bot'`.
  - Same with user session present sets `_owner` to the user UID (regression — no behaviour change).
- [ ] `tests/Unit/Db/MagicMapper/MagicRbacHandlerSystemOwnerTest.php` — new file. Cases:
  - Admin user list query receives an OR-condition matching `_owner = '__system__'` (or whatever override).
  - Non-admin user in `systemReaderGroups` group receives the same OR-condition.
  - Non-admin user NOT in any reader group does NOT receive the OR-condition (regression — no visibility leak).
  - Reader-group parse: trims, drops empties.
- [ ] `tests/Unit/Service/OrganisationServiceSystemIdentityTest.php` — new file. Cases:
  - `getSystemUserId()` returns `__system__` when key unset.
  - `getSystemUserId()` returns the configured override.
  - `getSystemReaderGroups()` returns `[]` when key unset.
  - `getSystemReaderGroups()` trims and drops empties as documented.

### 5. Quality gates (must be green before PR)
- [ ] `composer phpcs` clean on changed files
- [ ] `composer phpmd` clean on changed files
- [ ] `composer psalm` clean on changed files
- [ ] `composer phpstan` clean on changed files
- [ ] `composer phpunit` — all existing tests pass + new tests pass

### 6. PR
- [ ] Open PR targeting `development` (not `main`).
- [ ] Add labels `ready-for-code-review` and `ready-for-security-review`.
- [ ] PR description references issue #1617 with `Closes #1617`.
- [ ] Note in the PR body that no DB migration runs — operators backfill manually per design.md.

### 7. Out of scope / follow-up
- [ ] (Deferred) Backfill OCC command for existing `_owner=''` rows — open a separate issue if operators request it.
- [ ] (Deferred) Per-schema `systemReaderGroups` override — revisit if multi-tenant deployments ask.
