# Tasks: large-file-handling

## 1. Extraction ceiling (REQ-LFH-001, row 16.7)

- [ ] 1.1 Replace `maxFileSize` and `maxFileSizeMB` in `lib/Service/Settings/FileSettingsHandler.php` (and the copy in `ConfigurationSettingsHandler.php`) with `textExtraction.maxBytes`, migrated from `maxFileSizeMB` in a repair step. Verify: `tests/Unit/Service/Settings/FileSettingsCeilingTest.php::testTheCeilingIsReturnedAndMigrated` (fails today: the key does not exist).
- [ ] 1.2 In `TextExtractionService::extractFile()`, check the size from `FileMapper::getFile()` before `extractSourceText()`; above the ceiling write the metadata chunk only and status `skipped-too-large`. Verify: `tests/Unit/Service/ExtractionCeilingTest.php::testAFileOverTheCeilingIsNotOpened`, `testAFileOverTheCeilingGetsItsMetadataChunk`, `testAFileAtTheCeilingIsExtracted`.
- [ ] 1.3 Carry `textSearched` on file search hits (`FileSearchController`) and on `FileSidebarController::getExtractionStatus()`. Verify: `tests/Unit/Controller/FileSearchTextSearchedTest.php::testAFileOverTheCeilingIsFoundByNameAndMarked`, through the controller with the real search handler.
- [ ] 1.4 Through the caller: `CronFileTextExtractionJob` and `FileTextExtractionJob` reach the check. Verify: `tests/Unit/BackgroundJob/FileTextExtractionCeilingTest.php` runs the real job's `run()` on a file over the ceiling and asserts the status.
- [ ] 1.5 Show the ceiling on `src/views/settings/sections/FileConfiguration.vue`. Verify: `FileConfiguration.spec.js` renders the value in MB.

## 2. Upload sessions (REQ-LFH-002, row 17.13)

- [ ] 2.1 `lib/Db/UploadSession.php` and mapper with migration (`upload_id`, `user_id`, `object_uuid`, `name`, `size`, `sha256`, `part_size`, `received` JSON, `expires_at`); `lib/Service/File/ChunkedUploadService.php` storing parts under `IAppData` folder `uploads/{uploadId}`. Verify: `tests/Unit/Service/File/ChunkedUploadServiceTest.php::testTheSessionStatesItsPartBoundaries`, `testAPartOfTheWrongLengthIsRefused`, `testPartsAreNotWrittenIntoTheObjectFolder`.
- [ ] 2.2 `lib/Controller/ChunkedUploadController.php` with routes `POST /api/objects/{register}/{schema}/{id}/uploads`, `PUT .../uploads/{uploadId}/parts/{n}`, `GET .../uploads/{uploadId}`, `POST .../uploads/{uploadId}/complete`, all `#[NoAdminRequired]` with the object update check and the session owner check in the method. Verify: `tests/Unit/Controller/ChunkedUploadControllerTest.php::testOpeningNeedsUpdateRightsOnTheObject`, `testAnotherUserGets404OnTheSession`; hydra gates route-auth, route-reachability and no-admin-idor pass.
- [ ] 2.3 Setting `upload.partSizeBytes` (1 MiB to 100 MiB, refused outside) in `FileSettingsHandler`. Verify: `ChunkedUploadServiceTest::testThePartSizeComesFromTheSetting`.

## 3. Resume and complete (REQ-LFH-003, row 17.14)

- [ ] 3.1 `complete()` assembles by stream, verifies size and SHA-256, runs the shared upload checks (`ExecutableContentDetector`, and the malware scan once `upload-malware-scan` has landed) and calls the same file creation code `FilesController::create()` uses. Fail closed: a missing part, a size or checksum mismatch, or a refused check creates no file and keeps the parts for a retry until expiry. Verify: `ChunkedUploadServiceTest::testCompletionCreatesTheFileWithTheDeclaredChecksum`, `testAChecksumMismatchCreatesNoFile`, `testAMissingPartIsNamed`, `testARefusedUploadCheckCreatesNoFile`.
- [ ] 3.2 `lib/BackgroundJob/UploadSessionCleanupJob.php` (hourly, registered in `appinfo/info.xml`). Verify: `tests/Unit/BackgroundJob/UploadSessionCleanupJobTest.php::testAnExpiredSessionIsRemoved` through the job's `run()`.
- [ ] 3.3 Through the route: a Newman collection in `tests/newman/` opens a session for a 25 MiB generated file at 10 MiB parts, sends parts 1 and 3, reads the status (part 2 missing, `complete: false`), sends part 2, completes and reads the file from `GET .../files`.
- [ ] 3.4 Document the session API in the OpenAPI spec and `docs/features/files.md`. Verify: the API surface contract test (`tests/Contract/`) lists the four routes.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
