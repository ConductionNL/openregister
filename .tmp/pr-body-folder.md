## Scope

On a fresh instance the first file uploaded into a register failed, because making the register's folder ended in `RegisterMapper::update()`, which a portal request without a Nextcloud session may not perform (portaliq#29, duplicate portaliq#31). The folder id is now recorded as bookkeeping with a single-column compare-and-set, and two first uploads racing share one folder instead of one failing.

Refs openregister#2515 (the same defect on this side; its longer ask, a service identity for callers authenticated outside Nextcloud, is a separate change) and portaliq#29. The open change `consolidate-permission-handling` (proposal point 4, task 4) says this fix belongs in folder initialisation without widening system trust; that is the shape here.

OpenSpec change: `openspec/changes/register-folder-on-first-upload/` (proposal, design, tasks, delta on `file-actions` with REQ-RFFU-001 and 002).

## Why not the issue's option 2

Wrapping `update()` in `SystemOperationContext::run()` passes the permission check but not `verifyOrganisationAccess()`, which has no system bypass. A portal request always acts in the default organisation, so a register owned by any other organisation would still refuse. `update()` also dispatches `RegisterUpdatedEvent`, which writes an activity entry, sends webhooks and notifies about a register edit nobody made.

## What changes

- `RegisterFolderRecorder` (new, `lib/Db/`): `UPDATE openregister_registers SET folder = :id WHERE id = :register AND (folder IS NULL OR folder = :expected)`. One column, no event, no version change, and it only fills an empty or dead slot, so it can never repoint a folder another request recorded.
- `FolderManagementHandler::createRegisterFolderById()` records through it instead of `update()`. The id comes from the folder `createFolderPath()` made or found at the register's conventional path, never from the request. Whether the caller may upload at all is decided before this code, as today.
- `createFolderPath()` takes a folder a concurrent request created between its lookup and its `newFolder()`, for the root and the register folder. A folder that cannot be created and does not exist still fails as before.
- `Application` passes the recorder in its manual registration of the handler.

Tenant safety: the write touches only the folder column of the register the upload resolved; organisation, owner and authorization are untouched, and the value is system-made.

## Headless decisions

- The recorder is its own class: `RegisterMapper` sits at 983 of phpmd's 1000-line class cap, and the method measured 1018.
- The handler's constructor now has ten collaborators, so it carries the codebase's usual `@SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection` (as `FileService` and `MagicMapper` do). Routing a database write through the `FileService` facade or a setter would hide the dependency.
- Eager provisioning at register import (the issue's option 1) is left for a follow-up. Registers created through the API already get their folder at creation (`RegisterService::createFromArray`); imported registers do not, and registers that already exist without a folder, or whose folder was deleted, would still need this path.
- Admin first uploads also stop producing an "updated" activity entry for the register; that entry described nothing a person did.

## Verified

| Command | Exit |
|---|---|
| `openspec validate register-folder-on-first-upload --strict` | 0 |
| `php -l` on every touched PHP file | 0 |
| `vendor/bin/phpcs` on the touched `lib/` files | 0 |
| `vendor/bin/phpmd` on `RegisterFolderRecorder`, `FolderManagementHandler`, `Application` (isolated HOME) | 0 each |
| `vendor/bin/phpstan analyse` and `psalm` on the touched `lib/` files | 0, 0 |
| `phpunit --no-coverage --filter 'FolderManagementHandler\|RegisterFolderRecorder\|CreateFileHandler\|FileService'` (73 tests) | 0 |
| Mutation check on the new first-upload test: putting `RegisterMapper::update()` back fails 3 of its 5 tests; removing the race re-lookup fails the race test | caught, both |
| `composer check:strict` once, via the memory semaphore | 1: lint, check:migration-version, phpcs, phpmd and psalm (0 errors) pass. phpstan reported 1000+ phantom errors (every entity "has no `getId()`", i.e. it lost `OCP\AppFramework\Db\Entity`) from a stale result cache; the same tree with a fresh cache dir: **exit 0**. `test:all` ran 24,179 tests with no failure and exits 1 only on "No code coverage driver available" |
| `vendor/bin/phpstan analyse`, full, fresh cache | 0 |
| `vendor/bin/phpunit --no-coverage`, the full suite | 0 (24,179 tests, 25 skipped, 1 warning that is not from this change) |
| `npm run lint` / `npm run format` / `npm run test:l10n` | 0 (932 inherited warnings) / 0 / 0 |
| hydra gates `--scope-to-diff` (base origin/development, 14 files) | 1: only gate-112 newman-reach, 21 Postman collections outside the CI path, none touched here. gate-6 orphan-auth, gate-16 spec-coverage, gate-46 anchors, gate-57 orphaned writes and 30 more pass; gate-68 checker exited without a count (wiring, no finding) |
| opsx-verify (headless) | 0 CRITICAL, 0 WARNING; the missing docs note was added (`docs/api/objects.md`, 0bbd9ff011) |

## Inherited findings

The gate run fails only on gate-112 (21 Postman collections outside the CI path, all untouched). `test:all` inside `check:strict` exits 1 on the missing coverage driver and phpstan inside it on a stale cache; both are environment, and both pass when run cleanly (rows above).

## For portaliq

Once this lands, portaliq can delete `GREP_INVERT` from `tests/e2e/playwright.config.ts` and confirm `a subject downloads a file on a row they own` passes in its gate (portaliq#29, gate-side definition of done).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
