# Tasks: seed-from-a-mounted-directory

## 1. Import from the directory (REQ-SMD-001)

- [ ] 1.1 `lib/Service/Configuration/SeedDirectoryImporter.php`: read `openregister.seed_directory` through `IConfig::getSystemValueString()`, list `*.json` in lexical order, refuse symlinks out and files over 50 MB, import each through `ImportHandler::importFromJson()` under `WriteCause` `seed` without a user, store the digest. Verify: `tests/Unit/Service/Configuration/SeedDirectoryImporterTest.php::testFilesImportInLexicalOrder` (fails today: no class), `testASecondRunUpdatesOrSkips`, `testASymlinkOutsideTheDirectoryIsRefused`, `testAMalformedFileIsNamedAndOthersImport`, `testNoSettingDoesNothing`, `testARelativePathIsReported`.
- [ ] 1.2 The three callers: `lib/Repair/SeedFromDirectory.php` (install and post-migration in `appinfo/info.xml`), `lib/BackgroundJob/SeedDirectoryJob.php` (every five minutes, digest gated, `RecordedTimedJob`), `lib/Command/SeedImportCommand.php`. Verify: `tests/Unit/BackgroundJob/SeedDirectoryJobTest.php::testAnUnchangedDigestSkipsTheImport` and `testAChangedFileTriggersTheImport` through `run()`; `tests/Unit/Repair/SeedFromDirectoryTest.php` through the repair step's `run()`; `tests/Unit/Command/SeedImportCommandTest.php::testThePartialExitCodeIsNonZero`.
- [ ] 1.3 Through the caller on a real instance: a CI job (beside the Newman job) mounts `tests/fixtures/seed/` into the container, sets `openregister.seed_directory`, runs `occ maintenance:repair`, and asserts with a Newman request that the fixture register, schemas and objects exist with cause `seed`.

## 2. The report (REQ-SMD-002)

- [ ] 2.1 The report shape with `success`, `partial` and `failed`, counting failed entities from the import result (not from what arrived). Verify: `tests/Unit/Service/Configuration/SeedReportTest.php::testASchemaWithoutASlugMakesTheRunPartial`, `testFailuresAreNamed`.
- [ ] 2.2 `GET /api/settings/seed` (administrator only) and a section on the operations console. Verify: `tests/Unit/Controller/SeedReportControllerTest.php::testANonAdministratorGets403`; `tests/e2e/ci/seed-report.spec.ts` reads the last report on the console after the CI seed in 1.3.
- [ ] 2.3 Document the setting, the file order and the report in `docs/features/configuration.md`. Verify: `npm run build` in `docs/` succeeds.

## 3. Dependency

- [ ] 3.1 If `config-import-seed-objects` has not landed, top-level `objects` are not read: the importer's result must then count them as failed in the report (never as created). Verify: `SeedReportTest::testUnreadTopLevelObjectsAreCountedAsFailed`, removed once that change lands and its own tests cover the merge.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
