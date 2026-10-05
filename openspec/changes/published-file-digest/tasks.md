# Tasks: published-file-digest

## 1. Record and seal (REQ-PFD-001, rows 11.7, 11.8)

- [ ] 1.1 `lib/Db/FileDigest.php`, mapper and migration; `lib/Service/File/PublishedDigestService.php` computing the SHA-256 by stream and writing the audit row through `AuditTrailMapper::insertAuditTrails()`. Verify: `tests/Unit/Service/File/PublishedDigestTest.php::testPublishingRecordsTheDigest` through `FilePublishingHandler::publishFile()` (fails today: nothing is recorded), `testTheAuditRowCarriesTheDigest`, `testAFailedAuditWriteKeepsTheFilePrivate`, `testRepublishingCreatesANewRecord`.
- [ ] 1.2 Hook the publication window's opening from `file-publication-window` (or, if it has not landed, leave a named `TODO` with the issue link and a skipped test `testTheWindowOpeningRecordsTheDigest` whose skip reason names the change). Verify: that test, live once the window lands.
- [ ] 1.3 Integration: `tests/Integration/PublishedDigestChainTest.php` publishes a file and verifies the chain with the existing verifier.

## 2. Read and verify (REQ-PFD-002)

- [ ] 2.1 `Repr-Digest` and the etag or size check on the public download path, with the 409, the audit row and an operations alert. Verify: `tests/Unit/Service/File/PublishedDigestReadTest.php::testTheDigestHeaderIsSent`, `testAnUnchangedEtagSkipsTheRecompute`, `testAChangedFileIsRefusedAndAlerted`.
- [ ] 2.2 `GET /api/files/{fileId}/digest/verify` (`#[NoAdminRequired]`, `#[PublicPage]` only for publicly shared files, read check in the method). Verify: `tests/Unit/Controller/DigestVerifyControllerTest.php::testIdenticalIsReported`, `testChangedIsReported`, `testAPrivateFileIsNotVerifiableAnonymously`; hydra gates route-auth, no-admin-idor and route-reachability pass.
- [ ] 2.3 `published` on `files#index`, `files#show` and the public listings. Verify: `tests/Unit/Controller/PublishedDigestApiTest.php::testListingsCarryTheDigest`.
- [ ] 2.4 `lib/BackgroundJob/PublishedDigestVerifyJob.php` (daily, registered in `appinfo/info.xml`) and a consistency probe for the console. Verify: `tests/Unit/BackgroundJob/PublishedDigestVerifyJobTest.php` runs `run()` over one changed and one unchanged fixture.
- [ ] 2.5 Through the route: a Newman sequence publishes a file, reads `published.sha256`, compares it with the SHA-256 of the downloaded bytes, and calls verify (`identical`); `tests/e2e/ci/published-file-digest.spec.ts` shows the digest on the object's files tab.

## 3. Cross-app

- [ ] 3.1 Contract for opencatalogi and portaliq: `published.sha256`, `published.sealedBy.uuid|hash`, the verify response keys. Verify: `tests/Contract/PublishedDigestContractTest.php`; `opencatalogi/published-file-carries-its-facts` carries its consumer test. Both require OpenRegister.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
