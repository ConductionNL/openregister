# Tasks: redaction-policy-as-data

## 1. The register (REQ-RPD-001, REQ-RPD-003)

- [ ] 1.1 `lib/Settings/redaction_policy_register.json` with `termRule`, `detectionPattern` and `redactionProfile`, and `lib/Repair/ImportRedactionPolicyRegister.php` that writes `base` objects by slug and never touches `local` ones; registered in `appinfo/info.xml` on install and post-migration. Verify: `tests/Unit/Repair/ImportRedactionPolicyRegisterTest.php::testAnUpdateKeepsLocalObjects`, `testABaseObjectIsUpdatedBySlug`, `testTheShippedWooProfileIsPresent` (fail today: no register).
- [ ] 1.2 Schema validation refusing a match type other than the four. Verify: `tests/Unit/Service/RedactionPolicy/TermRuleValidationTest.php::testRegexIsRefusedAsAMatchType`.

## 2. Matching at detection (REQ-RPD-001, REQ-RPD-002, REQ-RPD-005)

- [ ] 2.1 `lib/Service/RedactionPolicy/PolicyIndex.php` (in-memory, per run, invalidated by an object listener on the three schemas) and `TermRuleMatcher.php` (exact, normalized with case and accent folding, bsn, kvk; time bounds; always wins; lowest uuid; both recorded). Port the cases of filinq's `tests/unit/Service/PolicyMatchServiceTest.php`. Verify: `tests/Unit/Service/RedactionPolicy/TermRuleMatcherTest.php::testExactDoesNotMatchVariants`, `testNormalizedFoldsCaseAndAccents`, `testBsnMatchesOnlyAResolvedBsn`, `testAlwaysWinsAndBothAreAudited`, `testTheLowestUuidIsRecorded`, `testExpiredAndInactiveRulesAreSkipped`, `testNoQueryPerEntity` (counts mapper calls).
- [ ] 2.2 Call it from `EntityRecognitionHandler` for every method, including the text search for always terms, the pre-set decision, `policyMatch` (migration on `openregister_entity_relations`) and the fill-only-when-empty grounds. Verify: `tests/Unit/Service/TextExtraction/PolicyAtDetectionTest.php::testAMissedTermIsStillDetected` (fails today), `testTheRuleRunsForEveryMethod` parameterised over regex, presidio, openanonymiser, llm and hybrid, `testGroundsFillOnlyWhenEmpty`, `testARequestScopedRuleAppliesToItsRequestOnly`, with real `EntityRelation` entities.
- [ ] 2.3 The override rule in `EntityRelationsController::update()`. Verify: `tests/Unit/Controller/PolicyOverrideTest.php::testAReviewerCannotReleaseAnAlwaysMatch`, `testASupervisorWithAReasonMay`.

## 3. Patterns and layers (REQ-RPD-003, REQ-RPD-004)

- [ ] 3.1 `DetectionPatternValidator` (compile, empty match, length, backtrack limit on a 100 KB text) and the pattern runner beside the model; `NlPatternSet` reported as the base layer. Verify: `tests/Unit/Service/RedactionPolicy/DetectionPatternValidatorTest.php::testCatastrophicBacktrackingIsRefused`, `testAnEmptyMatchIsRefused`; `PolicyAtDetectionTest::testALocalPatternRunsBesideTheLlm`; `testAnOverrideSwitchesOffABaseRuleAcrossAnUpdate`.

## 4. Profiles (REQ-RPD-006)

- [ ] 4.1 `lib/Service/RedactionPolicy/ProfileResolver.php` with the four-step order; `policyContext` accepted by `FileTextController::anonymizeFile()`, the extraction endpoints and `FileService::anonymizeDocument()`; the profile recorded on `AnonymisationLog`; mask forms passed to the substitution map from `anonymisation-placeholder-id-scope`. Verify: `tests/Unit/Service/RedactionPolicy/ProfileResolverTest.php::testANamedProfileWins`, `testADocumentTypeBindingIsUsed`, `testTheOrganisationDefaultIsUsedWhenNothingIsNamed`, `testTheWooProfileIsTheFallback`, `testTwoBindingsForOneTypeAreRefused`; `tests/Unit/Service/File/AnonymiseWithProfileTest.php::testTheBoundProfileShapesTheOutput` through `FileService::anonymizeDocument()`.
- [ ] 4.2 Policy pages under the OpenRegister administration (term lists, patterns, profiles), with a text field for grounds that says the list is dossiq's. Verify: `tests/e2e/ci/redaction-policy.spec.ts` adds an always term and a never term, binds a profile to a document type, anonymises a fixture with that type, and reads the redacted and the kept names and the masked IBAN in the output.

## 5. filinq import (REQ-RPD-007)

- [ ] 5.1 `lib/Command/ImportFilinqPolicyCommand.php`, registered in `appinfo/info.xml`, reading the `filinq` register's `publicationProhibition` and `publicationConsent` objects through `ObjectService` as the system context. Verify: `tests/Unit/Command/ImportFilinqPolicyCommandTest.php::testAProhibitionBecomesAnAlwaysRuleOnce`, `testAnEntityScopedConsentBecomesANeverRule`, `testADocumentScopedConsentIsNotImported`, `testWithoutTheFilinqRegisterNothingIsImported`.
- [ ] 5.2 Contract for filinq: the command name and options, the `termRule` field mapping, and the `policyContext` keys. Verify: `tests/Contract/RedactionPolicyContractTest.php`; `filinq/redaction-guarantees-from-the-engine` carries its consumer test. filinq requires OpenRegister; OpenRegister without filinq imports nothing and works on its own lists.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
