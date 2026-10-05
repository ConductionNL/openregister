# Tasks: near-duplicate-documents-by-text

## 1. Signatures (REQ-NDT-001)

- [ ] 1.1 `lib/Service/Quality/TextSignature.php` (normalise, shingle, MinHash with 128 fixed seeds) and `lib/Db/TextSignature.php`, mapper and migration (`file_id` unique, `signature` binary or JSON, `shingle_count`, `text_sha256`, `created`). Verify: `tests/Unit/Service/Quality/TextSignatureTest.php::testTheSameTextGivesTheSameSignature`, `testAShortTextIsNotSigned`, `testNearIdenticalTextsEstimateAboveNinety` (fails today: no class).
- [ ] 1.2 Compute and store in `TextExtractionService::indexFilePayload()` (both `extractFile()` and `extractFromProvidedText()` reach it); delete beside `ChunkMapper::deleteBySource()`. Verify: `TextSignatureTest::testReExtractionReplacesTheSignature` through `extractFile()` with the real mapper against the test database.

## 2. Grouping (REQ-NDT-002)

- [ ] 2.1 `DuplicateDetectionService::findTextGroups()` with banding, Jaccard confirmation, union-find grouping and the representative rule; setting `duplicates.textThreshold`. Verify: `tests/Unit/Service/Quality/TextGroupsTest.php::testThreeCopiesFormOneGroup`, `testTheEarliestFileRepresents`, `testBandingDoesNotCompareAllPairs` (counts comparisons on 1000 distinct signatures), `testAThresholdOutsideRangeIsRefused`.
- [ ] 2.2 `GET /api/files/near-duplicates` on `DuplicateController` (`#[NoAdminRequired]`, read rights per file resolved before comparing), with the 5000 file cap. Verify: `tests/Unit/Controller/NearDuplicateEndpointTest.php::testAnUnreadableFileIsLeftOutBeforeComparing`, `testAFileWithoutTextIsNamedNotCompared`, `testMoreThanFiveThousandIsRefused`; hydra gates route-auth and no-admin-idor pass.
- [ ] 2.3 Through the route: a Newman request in `tests/newman/` uploads three fixture files with near-identical text to one object, waits for extraction, calls the endpoint and asserts one group of three.

## 3. Match rule (REQ-NDT-003)

- [ ] 3.1 Accept `method: text` in the `x-openregister-dedup` validator and score it in `DuplicateDetectionService`. Verify: `tests/Unit/Service/Quality/DuplicateTextRuleTest.php::testTwoObjectsWithNearIdenticalFilesArePaired` through `DuplicateController::index()`, `testAnObjectWithoutFilesScoresZero`.
- [ ] 3.2 Show `text` in the duplicates review. Verify: `tests/e2e/ci/near-duplicate-documents.spec.ts` seeds two objects with near-identical PDFs, opens the duplicates review and sees the pair marked as a text match.

## 4. Cross-app

- [ ] 4.1 Contract for consumers: request `GET /api/files/near-duplicates?objectIds[]=...`; response keys `groups[].representative`, `groups[].members[].fileId|similarity|identicalText`, `notCompared[].fileId|reason`. Verify: `tests/Contract/NearDuplicatesContractTest.php` pins the shape; `dossiq/woo-request-corpus-collection` carries its consumer test. Without OpenRegister nothing calls it; every consumer requires OpenRegister.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
