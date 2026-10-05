# Tasks: object-organisation-from-a-property

## 1. Annotation and save (REQ-OOP-001)

- [ ] 1.1 Add `x-openregister-organisation` to `Schema::ANNOTATION_VOCABULARY` with a validator refusing an undeclared or non-string, non-reference property. Verify: `tests/Unit/Db/SchemaOrganisationAnnotationTest.php::testAnUndeclaredPropertyIsRefused`, `testAReferencePropertyIsAccepted` (fails today: the key is dropped as unknown).
- [ ] 1.2 In `SaveObject`, before the `getOrganisationForNewEntity()` fallback and after `setSelfMetadata()`, resolve the property to an `Organisation` and apply the rules: contradiction 422, unknown 422, non-member 403 (admin passes), empty filled. Verify: `tests/Unit/Service/Object/OrganisationFromPropertyTest.php::testThePropertySetsTheOrganisation`, `testANonMemberIsRefusedWithoutFallback`, `testAContradictingSelfOrganisationIsRefused`, `testAnUnknownOrganisationIsRefused`, `testAnEmptyPropertyIsFilledWithTheStampedOrganisation`, `testAnUpdateThatChangesThePropertyMovesTheObject`; use the real `ObjectEntity` and `Organisation` classes and `environmentAwareDouble` for `OrganisationService`.
- [ ] 1.3 Through the caller: a Newman sequence in `tests/newman/` imports a fixture schema with the annotation, creates an object naming a second organisation as a member of both, and reads it as a user of only the first (absent) and of the second (present); `tests/e2e/ci/organisation-from-property.spec.ts` does the same through the object form.

## 2. Reconcile (REQ-OOP-002)

- [ ] 2.1 `lib/Command/ReconcileOrganisationCommand.php`, registered in `appinfo/info.xml`. Verify: `tests/Unit/Command/ReconcileOrganisationCommandTest.php::testADryRunChangesNothing`, `testApplyWritesAnAuditRowPerObject`.

## 3. Cross-app

- [ ] 3.1 Contract for opencatalogi: the annotation shape `{"x-openregister-organisation": {"fromProperty": "organization"}}` and the 403 and 422 bodies `{error, property, organisation}`. Verify: `tests/Contract/OrganisationFromPropertyContractTest.php` pins both; `opencatalogi/publications-reference-the-shared-organisation` carries the consumer test. opencatalogi requires OpenRegister, so there is no absent-app path.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release. This change closes no row itself: it supports 12.34, which counts once opencatalogi's change has also shipped.
