# Tasks: scoped-api-tokens-machine-callers

Second part of `scoped-api-tokens`. Build it after that change is merged: W.5 issues tokens through its `TokenGrantValidator`, and W.2 records `actorVia`, the half of its task 2.2 this part closes.

## 1. Service account (REQ-SAT-004, moved from scoped-api-tokens)

- [ ] C40.3 A service account principal owned by a team, holding grants and tokens, with no interactive sign-in (D-C40-2).
- [ ] W.4 Service accounts (REQ-SAT-004 and C40.3): `lib/Service/Authorization/MachineCredentialService.php` creating a Nextcloud user flagged as service account with owner group, contact and purpose; an interactive login refusal through a `BeforeUserLoggedInEvent` listener (or the login credential hook) for flagged users. Verify: `tests/Unit/Service/Authorization/MachineCredentialServiceTest.php::testProvisioningRequiresOwnerContactAndPurpose`, `testDisablingTheCreatorKeepsTheServiceAccountWorking`; `tests/Unit/Listener/ServiceAccountLoginTest.php::testInteractiveLoginIsRefused` with the real event class.

## 2. Acting human (REQ-SAT-006, row 12.20)

- [ ] W.2 `lib/Service/Authorization/ActingHumanGuard.php` called from the object write path (`SaveObject` and `DeleteObject` entry points) for a non-session principal; setting `api.requireActingHuman`; annotation `x-openregister-acting-human` in `Schema::ANNOTATION_VOCABULARY`; header read in the auth middleware. Verify: `tests/Unit/Service/Authorization/ActingHumanGuardTest.php::testAMachineWriteWithoutAHumanIsRefused` (fails today: the write is stored), `testANamedUserWithoutAGrantIsRefused`, `testADelegatedWriteIsAcceptedAndRecorded` (asserts `actingUser` and `actorVia` on the real `AuditTrail` row, which also closes task 2.2's `actorVia` half), `testASessionUserIsNeverRefused`, `testReadsAreNotAffected`; `DelegationResolver` used through `environmentAwareDouble` with its real `DelegationVerdict`.
- [ ] W.3 Through the route: a Newman sequence with a Consumer token writes without the header (403), with an ungranted user (403), and with a granted user (200), reading the audit row.

## 3. Machine credentials an administrator manages (REQ-SAT-007, row 13.11)

- [ ] W.5 `MachineCredentialsController` at `/api/admin/machine-credentials` (POST, GET, DELETE), administrator only, issuing through the token path (so `TokenGrantValidator` and `GrantCeiling` apply), returning the token once, writing audit rows. Verify: `tests/Unit/Controller/MachineCredentialsControllerTest.php::testANonAdministratorGets403`, `testTheTokenIsReturnedOnce`, `testTheListShowsOwnerContactAndExpiry`, `testRevokeTakesEffectImmediately`; hydra gates route-auth, semantic-auth and route-reachability pass.
- [ ] W.6 The administration page. Verify: `tests/e2e/ci/machine-credentials.spec.ts` provisions a credential, sees it listed with owner and contact, calls the API with it (200), revokes it and calls again (401).

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
