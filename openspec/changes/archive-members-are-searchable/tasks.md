# Tasks: archive-members-are-searchable

## 1. Enumeration (REQ-AMS-001)

- [ ] 1.1 Add `lib/Service/File/ArchiveMemberEnumerator.php` and `ArchiveListing`, with the four bounds as file settings returned by `GET /api/settings/files`. Verify: `tests/Unit/Service/File/ArchiveMemberEnumeratorTest.php::testMembersAreListedWithTypeAndSize`, `testARatioOverTheBoundStopsEnumeration`, `testTooManyMembersStops`, `testATraversalPathIsRefused`, `testANestedZipIsListedOneLevelDeep`, `testAnEncryptedMemberIsListedAsEncrypted`, all over generated fixtures (fail today: the class does not exist).

## 2. Extraction and search (REQ-AMS-002)

- [ ] 2.1 Teach `FileHandler` / `TextExtractionService` to treat a zip as an archive (not a generic type) and extract members with the existing extractors in memory, writing chunks with `positionReference.member`. Verify: `tests/Unit/Service/TextExtraction/ArchiveMemberExtractionTest.php::testEachMemberGetsItsOwnChunks` through `TextExtractionService::extractFile()`, the path the background job takes.
- [ ] 2.2 Run detection per member and store relations with the member path. Verify: `ArchiveMemberExtractionTest::testDetectionRunsPerMember`.
- [ ] 2.3 Return member hits from the file search API and `lib/Search/` unified search provider with archive file id, member path and owning object, under the archive's read rights. Verify: `tests/Unit/Service/ArchiveMemberSearchTest.php::testAMemberHitNamesItsPath`, `testAMemberHitObeysTheArchiveFileRights`.

## 3. Reporting (REQ-AMS-003)

- [ ] 3.1 Set `partial` status and list refused, encrypted, over-bound and unsupported members on `GET /api/files/{fileId}/extraction-status` (`FileSidebarController::getExtractionStatus`). Verify: `testAnEncryptedMemberMakesThePartialStatus`, `testATarIsReportedUnsupportedAndKeepsItsMetadata`.
- [ ] 3.2 End to end: `tests/e2e/ci/archive-members-search.spec.ts` uploads a zip fixture to an object, waits for extraction through the API, searches a phrase from one member and reads the member path in the result.

## 4. Shared with the malware scan

- [ ] 4.1 Expose the enumerator as a service `upload-malware-scan` reuses. Verify: if that change has landed, its `ArchiveScanTest` uses this class; otherwise the PR body names the class for it.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
