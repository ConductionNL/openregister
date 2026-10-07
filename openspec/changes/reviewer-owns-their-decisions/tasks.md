# Tasks: reviewer-owns-their-decisions

## 1. Ownership (REQ-ROD-001)

- [ ] 1.1 Accept `x-openregister-review` in `Schema::ANNOTATION_VOCABULARY` (validated: `ownership` `open` or `decider`, `supervisors` a list of existing group ids, `hideOthers` boolean) and the instance defaults in `FileSettingsHandler`. Verify: `tests/Unit/Db/SchemaReviewAnnotationTest.php::testAnUnknownOwnershipIsRefused` (fails today: the key is dropped).
- [ ] 1.2 Migration adding `decision_note` to `openregister_entity_relations`; the ownership rule in `EntityRelationsController::update()` after `actorCanWriteRelationSubject()`. Verify: `tests/Unit/Controller/DecisionOwnershipTest.php::testASecondReviewerIsRefused`, `testASupervisorMayChangeAnotherReviewersDecision`, `testAnAdministratorMay`, `testAnUndecidedRelationIsOpen`, `testOpenOwnershipKeepsTodaysBehaviour`, `testTheNoteFollowsTheDecision`, with real `EntityRelation` entities and `environmentAwareDouble` for `IGroupManager`.
- [ ] 1.3 Through the route: a Newman sequence in `tests/newman/` with two reviewer users, one deciding and the other getting 403 on the same relation.

## 2. Hiding (REQ-ROD-002)

- [ ] 2.1 A `DecisionVisibility` filter applied in every controller that serialises `EntityRelation`. Verify: `tests/Unit/Service/Anonymisation/HiddenDecisionTest.php::testAnotherReviewersDecisionIsHidden`, `testTheSupervisorSeesIt`, `testEveryRelationEndpointMasks` (reflection over controllers returning relations, fails on one without the filter).
- [ ] 2.2 The review UI in OpenRegister shows "decided by another reviewer" without the decision. Verify: `tests/e2e/ci/blind-second-review.spec.ts` seeds four decisions by one user, signs in as another and sees ten detections, four marked decided without their ground.

## 3. Assessment records (REQ-ROD-003)

- [ ] 3.1 After `access-owner-and-condition-scopes` has landed: `tests/Unit/Service/Authorization/AssessmentOwnershipTest.php::testASecondReviewerCannotEditTheFirstsAssessment` and `testTheCoordinatorCan` on a fixture schema. If that change has not landed when this one is built, the test is written and marked skipped with the reason, and the PR body says 12.28's assessment half waits on it.
- [ ] 3.2 Tell the lane that ships `wooAssessment` (dossiq after D1, or opencatalogi while it still ships the schema) the block to set. Verify: the PR body quotes it and links the consumer issue.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
