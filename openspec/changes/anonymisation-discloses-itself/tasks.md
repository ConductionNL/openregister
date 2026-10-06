# Tasks: anonymisation-discloses-itself

## 1. Version records (REQ-ADI-001, REQ-ADI-004)

- [ ] 1.1 Add `VERSION` and `CHANGED_AT` public constants to `JurisdictionPatternSet` implementations, starting with `lib/Service/TextExtraction/PatternSet/NlPatternSet.php`, and a `DetectorVersion` value object. Verify: `tests/Unit/Service/Anonymisation/AnonymisationBackendServiceVersionTest.php::testThePatternSetReportsItsVersionAndChangeDate` (fails today: no constant exists).
- [ ] 1.2 Read engine and model versions from each backend probe in `AnonymisationBackendService::probe()`, reporting `unknown` for anything not exposed, and return the record from `getState()`. Verify: `testAnUnexposedModelIsReportedAsUnknown`.
- [ ] 1.3 Store the record on `AnonymisationLog` (migration adding a `detector` JSON column) from the call site in `DocumentProcessingHandler::anonymizeDocument()`. Verify: `tests/Unit/Db/AnonymisationLogVersionTest.php::testARunStoresTheBackendVersionRecord`, which drives the real `DocumentProcessingHandler` call path, not the entity alone.
- [ ] 1.4 Keep a version history per backend and set `changedAt` on first sight of a new record. Verify: `tests/Unit/Service/Anonymisation/DetectorVersionHistoryTest.php::testANewModelVersionStartsAHistoryEntry`.

## 2. Measured accuracy (REQ-ADI-002)

- [ ] 2.1 Ship `tests/Fixtures/anonymisation-eval/nl-woo-synthetic/` (version 1): synthetic Dutch texts with gold annotations for PERSON, EMAIL, PHONE, IBAN, BSN, ADDRESS, ORGANIZATION. No real personal data; the README says how it was generated. Verify: `tests/Unit/Service/Anonymisation/EvaluationCorpusTest.php` asserts every gold span lies inside its text and every BSN passes the elfproef with a reserved test range.
- [ ] 2.2 Add `DetectorEvaluationService` and `occ openregister:anonymisation:evaluate`, registered in `appinfo/info.xml`, storing precision, recall and support per type with corpus and detector version. Verify: `tests/Unit/Command/EvaluateDetectorCommandTest.php` runs the regex backend over a three-document corpus and asserts known figures; `testFiguresForAnotherDetectorVersionAreNotCurrent`.
- [ ] 2.3 Show the current figures, or "not measured for this version", in `src/views/settings/sections/FileConfiguration.vue`. Verify: `src/views/settings/sections/FileConfiguration.spec.js` renders both states.

## 3. Statements (REQ-ADI-003)

- [ ] 3.1 Add `trainingUse` and `retention` per backend to `FileSettingsHandler`, product-declared and read-only for the internal ExApp and regex, `unknown` default for external ones; write changes through the settings audit writer. Verify: `tests/Unit/Service/Anonymisation/BackendStatementTest.php::testTheInternalExAppIsDeclaredExcluded`, `testAnExternalBackendDefaultsToUnknown`, `testAChangedStatementIsAudited`.
- [ ] 3.2 Edit both statements on the file configuration page. Verify: `FileConfiguration.spec.js` case for the external backend form, and `tests/e2e/ci/anonymisation-disclosure.spec.ts` (created here) opens the page and covers the page scenarios of REQ-ADI-002 and REQ-ADI-003. `anonymisation-discloses-itself-pipeline` extends the same spec.

## 6. Threshold setting (REQ-ADI-007)

- [ ] 6.1 Add `anonymisation.confidenceThreshold` (default 0.5, refused outside 0 to 1) to `FileSettingsHandler` and replace the literals in `TextExtractionService.php` and `EntityRecognitionHandler.php`. Verify: `tests/Unit/Service/TextExtraction/EntityRecognitionThresholdTest.php::testTheSettingReplacesTheLiteral` and `testAnOutOfRangeValueIsRefused`; a grep test asserts no `'confidence_threshold' => 0.5` literal remains in `lib/`.
- [ ] 6.2 Cross-app contract for filinq: the key is `anonymisation.confidenceThreshold` in the body of `GET /api/settings/files`, a float. Verify: `tests/Contract/FileSettingsContractTest.php` pins the key and type. filinq's `anonymization-review-workbench` carries the consumer test; when OpenRegister is absent filinq uses its own default and says so.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
