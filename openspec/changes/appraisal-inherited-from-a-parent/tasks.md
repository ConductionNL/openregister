# Tasks: appraisal-inherited-from-a-parent

## 1. The rule (REQ-AIP-001, REQ-AIP-002)

- [ ] 1.1 Accept `x-openregister-retention.inheritAppraisalFrom` in the schema annotation validator; refuse unknown or non-reference properties. Verify: `tests/Unit/Service/Schemas/RetentionAnnotationValidatorTest.php::testAnInheritFromANonReferenceIsRefused` (fails today: the key is unknown).
- [ ] 1.2 Follow the declared references in `RetentionService::isEligibleForDestruction()`, five levels, cycle-guarded, reading as the system context. Verify: `tests/Unit/Service/RetentionInheritedAppraisalTest.php::testAPublicationUnderAHotspotIsNotEligible`, `testWithoutTheDeclarationOnlyTheOwnAppraisalCounts`, `testACycleIsVisitedOnce`, `testAnUnresolvableAncestorHoldsTheObjectBack`, with real `ObjectEntity` instances.
- [ ] 1.3 Apply the same check in `DestructionCheckJob::sendPreDestructionNotifications()`. Verify: `tests/Unit/BackgroundJob/DestructionCheckJobInheritedTest.php` runs the real job's `run()` with a hotspot fixture and asserts no notification and no list entry, which proves the caller reaches the rule.

## 2. Visible hold-back (REQ-AIP-003)

- [ ] 2.1 Add `occ openregister:retention:dry-run` (no such command exists today; register it in `appinfo/info.xml`) listing what the next run would put on a destruction list and what it holds back with reasons, and add the held-back section to the destruction list review view. Verify: `tests/Unit/Command/RetentionDryRunHeldBackTest.php`; `tests/e2e/ci/retention-held-back.spec.ts` opens the review view on a seeded hotspot and reads the subject's name.

## 3. Hand-over

- [ ] 3.1 Tell the opencatalogi lane the contract: declare `inheritAppraisalFrom: ["subjects"]` on the publication schema and set `retention.archiefnominatie` on the subject. Verify: the PR body quotes the key and the `opencatalogi/theme-archive-hotspot` issue links back here.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
