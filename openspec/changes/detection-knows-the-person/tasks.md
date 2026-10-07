# Tasks: detection-knows-the-person

## 1. Capacity (REQ-DKP-001)

- [ ] 1.1 Settings `anonymisation.officialsSource`, `anonymisation.capacityPolicy` (default as REQ-DKP-001) in `FileSettingsHandler`. Verify: `tests/Unit/Service/Settings/CapacityPolicySettingTest.php::testTheShippedDefault` (fails today: no key).
- [ ] 1.2 `lib/Service/Anonymisation/CapacitySuggester.php`: officials lookup and Dutch role words (a versioned list in the pattern set, so `anonymisation-discloses-itself` reports it). Verify: `tests/Unit/Service/Anonymisation/CapacitySuggesterTest.php::testAnAldermanFromTheRegisterIsPublicOffice`, `testRoleWordsWithoutARegister`, `testAProfessionalIsSuggestedWithheldUnder512e`, `testNoEvidenceIsUnknown`.
- [ ] 1.3 Migration: `capacity`, `capacity_source`, `capacity_applied`, `capacity_confirmed_by`, `capacity_confirmed_at` on `openregister_entity_relations`; call the suggester from `EntityRecognitionHandler::storeDetectedEntities()`. Verify: `tests/Unit/Service/TextExtraction/EntityRecognitionCapacityTest.php` drives `storeDetectedEntities()` with a real `EntityRelation` and asserts the stored suggestion.

## 2. Confirmation (REQ-DKP-002)

- [ ] 2.1 Accept `capacity` on `PATCH /api/entity-relations/{id}` through `updateDecisionMetadata`, applying the policy's decision and ground unless set explicitly. Verify: `tests/Unit/Controller/EntityRelationsCapacityTest.php::testConfirmingRecordsWhoAndWhen`, `testAnExplicitDecisionWinsOverThePolicy`.
- [ ] 2.2 Fail closed: `findEntitiesForAnonymization()` includes an occurrence with an unconfirmed suggestion. Verify: `tests/Unit/Db/CapacityDecisionTest.php::testAnUnconfirmedReleaseSuggestionIsAnonymised` through `FileService::anonymizeDocument()`.
- [ ] 2.3 Show the suggestion and a confirm control wherever OpenRegister lists a file's entities. Verify: `tests/e2e/ci/capacity-confirmation.spec.ts` confirms a `public-office` suggestion on a fixture and reads the recorded capacity through the API. filinq's workbench consumes the same fields; the PR body names them for the filinq lane.

## 3. Person register (REQ-DKP-003)

- [ ] 3.1 Settings `anonymisation.personRegister` (source and purpose, purpose required when a source is set), `anonymisation.personRegisterBoost`, `anonymisation.placeRegister`. Verify: `tests/Unit/Service/Settings/PersonRegisterSettingTest.php::testASourceWithoutAPurposeIsRefused`.
- [ ] 3.2 `PersonRegisterMatcher`: in-memory normalised lookup per run, boost, uncertain flag, no record id stored, purpose and lookup count on the run. Verify: `tests/Unit/Service/Anonymisation/PersonRegisterMatcherTest.php::testAMatchRaisesConfidence`, `testAStreetMatchMarksUncertainAndKeepsTheCandidate`, `testWithoutARegisterTheRunSaysNotConfigured`, `testTheRelationHoldsNoRecordIdentifier`.
- [ ] 3.3 Call it from `EntityRecognitionHandler` before relations are stored, for every detection method. Verify: `EntityRecognitionCapacityTest::testTheMatcherRunsForEveryMethod` parameterised over regex, presidio, openanonymiser, llm and hybrid.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
