## Scope

Registers created by an app's configuration import (`ConfigurationService::importFromApp()`) now get their Files folder at import, the way API-created registers already get one at creation. A post-migration repair step gives a folder to every register imported before this change. This is option 1 of portaliq#29 and the follow-up #4116's design named.

## What changed

- `lib/Service/File/RegisterFolderProvisioner.php` (new): `ensureFolders(registers)` calls `FileService::createEntityFolder()` per register and returns `{provisioned, present, failed}`. Each register is guarded on its own; it never throws.
- `ImportHandler::importFromApp()`: after `autoCreateRegisterIfApplication()` (so an auto-created register is included), it provisions every register in `$result['registers']`. The provisioner is an optional setter, wired in `Application::attachOptionalImportServices()` next to `FileService`. A failure is logged and never fails the import.
- `lib/Repair/CreateMissingRegisterFolders.php` (new), last in `<post-migration>`: reads every register with `_rbac: false, _multitenancy: false` and runs the same provisioning, reporting the tally. It skips with an info line if its services cannot be built.
- `docs/api/objects.md`: the File Storage paragraph now says when a register gets its folder.
- OpenSpec change `register-folder-at-import` (REQ-RFAI-001, REQ-RFAI-002 on `file-actions`).

## Idempotent and tenant-safe

- The folder id is written by #4116's `RegisterFolderRecorder`, reached through `FolderManagementHandler::createRegisterFolderById()`. It changes one column, and only while that column is empty or still holds the value read before the folder was made. `RegisterMapper::update()` never runs, so no register-updated event fires (no webhook, activity entry or notification), the version does not change, and no organisation check can refuse a register another organisation owns.
- If a register's recorded folder still resolves, it is left alone (`present`), so running the import again records nothing new.
- Only the registers the import returned are touched. The folder id is the node the file service made or found at `Open Registers/<title> Register`, never import data.
- The folder is made where an API-created register's folder is made. The cron and repair paths have no session, so it goes straight into the OpenRegister system user's files. The web-triggered demo import makes it in the admin's files first and then moves ownership to the system user, exactly as the API path does.

## Verification

Each check below was read by its exit code.

- `openspec validate register-folder-at-import --strict`: valid
- `php -l` on touched files: 0. `phpcs --standard=phpcs.xml` on the touched `lib/` files: 0. `phpmd` with the baseline: 0. `phpstan` with a fresh cache: 0. `psalm` on the touched files: 0
- `phpunit --no-coverage --filter 'RegisterFolderProvisionerTest|CreateMissingRegisterFoldersTest|ImportHandlerRegisterFolderTest|ImportHandlerImportJobTest|ImportHandlerApplicationTypeTest'`: 16 tests, OK
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (run once): exit 1, and the only red is `test:all`, from `No code coverage driver available`. lint, check:migration-version, phpcs, phpmd, psalm ("No errors found") and phpstan ("No errors") all passed. The full PHPUnit suite ran 24,287 tests with 0 failures, 0 errors and 25 skipped.
- `npm run lint` 0, `npm run format` 0, `npm run test:l10n` 0 (no frontend files touched)
- Hydra gates `--scope-to-diff --base origin/development` (14 files): exit 1 on gate-110, because a repair step was added without moving `<version>`. Fixed in ef1eb85d4f (`2.1.33-unstable.20260928180000`). After the fix, `check_migration_version_bump.py` exits 0 and `composer check:migration-version` exits 0. Gates 23, 24, 96 and 112 are advisory warnings only.
- Spec check (done by hand): REQ-RFAI-001's three scenarios are covered by `ImportHandlerRegisterFolderTest` (the returned registers are provisioned, a throwing provisioner does not fail the import) and by `RegisterFolderProvisionerTest` (a folder that resolves is `present`, a new id is `provisioned`, a failure is logged and counted). REQ-RFAI-002's two scenarios are covered by `CreateMissingRegisterFoldersTest`. All tasks are ticked.
- Not run live: no instance was used, so there was no `occ upgrade` against real registers. The e2e proof is portaliq's `a subject downloads a file on a row they own` once the exclusion below is removed.

## portaliq: the change to make once this lands (not made here)

#4116 is already on development, and on its own it makes the excluded test pass: the first upload creates the folder without a register edit. This PR removes the dependency on that path for app-imported registers. The portaliq change for portaliq#29 is `tests/e2e/playwright.config.ts` on `development`:

1. Delete the block comment `THE ONE THING THIS GATE DOES NOT RUN` (from `/*` after the `IGNORE` array to its closing `*/`) and the line after it:
   ```ts
   const GREP_INVERT = /a subject downloads a file on a row they own/
   ```
2. In the top-level `defineConfig({...})`, delete these three lines:
   ```ts
   	// Repeated on the project below for the same reason: a project-level
   	// grepInvert takes precedence over this one rather than combining with it.
   	grepInvert: GREP_INVERT,
   ```
3. In `projects[0]` (`chromium`), delete:
   ```ts
   			grepInvert: GREP_INVERT,
   ```

Leave the `testIgnore: IGNORE` lines and their comment as they are. After this, `portal-document-download` › `a subject downloads a file on a row they own` runs in the gate again. portaliq's e2e job installs openregister at `ref: development`, so it picks this up with no other change. Close portaliq#29 (and its duplicate #31) with that PR.

## Inherited

- gate-96: 2 App Store description strings in `appinfo/info.xml` break the copy style. These are advisory and were already there, not written by this PR. gate-112: 21 Postman collections that CI does not run. Report-only.
- This PR bumps `<version>` in `appinfo/info.xml`. Any other open PR that also moves `<version>` will conflict on that one line; take the higher value.

Not in scope: `RegisterService::ensureRegisterFolderExists()` (the API path) still calls `RegisterMapper::update()` after the recorder has already stored the id. That call is redundant and fires an update event, but it is a separate cleanup.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
