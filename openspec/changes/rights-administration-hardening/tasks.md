# Tasks: rights-administration-hardening

## 1. Matrix export (REQ-RAH-001, row 12.23)

- [ ] 1.1 `lib/Service/Authorization/PermissionMatrixExporter.php` built on the provenance resolver of `permission-provenance-and-deny`, streaming rows for `level=group` and `level=user`, with the provenance header and the row hash. Verify: `tests/Unit/Service/Authorization/PermissionMatrixExporterTest.php::testEveryGroupSchemaActionIsARow`, `testADenyIsPrintedAsTheRule`, `testObjectGrantsAreRows`, `testTheHashCoversTheRows` (fails today: no class).
- [ ] 1.2 `PermissionsAuditController::matrix()` at `GET /api/permissions/matrix`, administrator only (no `#[NoAdminRequired]`), writing the audit row. Verify: `tests/Unit/Controller/PermissionMatrixControllerTest.php::testANonAdministratorGets403`, `testTheExportWritesAnAuditRow`; hydra gates route-auth and semantic-auth pass; a Newman request downloads the CSV and checks the header lines.
- [ ] 1.3 A download button on the permissions page. Verify: `tests/e2e/ci/permission-matrix-export.spec.ts` downloads the CSV as an administrator and finds a known deny row.

## 2. Grant ceiling (REQ-RAH-002, row 12.24)

- [ ] 2.1 `lib/Service/Authorization/GrantCeiling.php` (`assertWithin(IUser $granter, array $grants): void`, throwing `GrantExceedsGranterException` with the excess). Verify: `tests/Unit/Service/Authorization/GrantCeilingTest.php::testAnAdministratorMayGrantAnything`, `testAnExcessActionIsRefused`, `testManageIsNeverGrantableByANonAdministrator`.
- [ ] 2.2 Call it from `SchemasController` and `RegistersController` saves of the authorization block and role matrix, `ObjectSharingController::createShare()`, the organisation role writes, the derived grant rule writes and the token issue path. Verify: `tests/Unit/Service/Authorization/GrantCeilingCoverageTest.php::testEveryGrantWriteCallsTheCeiling` and one test per path (`testASchemaBlockBeyondTheEditorsRightsIsRefused` through `SchemasController::update()`, `testAShareBeyondTheSharersRightsIsRefused`, and so on), each failing on today's code.
- [ ] 2.3 Through the route: a Newman sequence in `tests/newman/` signs in as a delegated administrator, gets 403 on a schema update adding `delete`, and 200 on one within their rights.

## 3. Product-owned groups (REQ-RAH-003, row 12.26)

- [ ] 3.1 Store `declared_group_rights_<app>` in `ImportHandler` beside `declared_groups_<app>`. Verify: `tests/Unit/Service/Configuration/DeclaredGroupRightsTest.php::testTheImportStoresTheDeclaredRights`.
- [ ] 3.2 Extend `GroupReconciler::reconcile()` with the rights comparison and repair, the audit rows and the log; call it at the end of every configuration import. Verify: `tests/Unit/Service/Authorization/GroupReconcilerRightsTest.php::testARemovedRightIsRestored`, `testAnAddedRightIsRemoved`, `testAnUndeclaredGroupIsNotTouched`, `testMembershipIsNeverChanged` (the first two fail today: the reconciler only creates groups).
- [ ] 3.3 Through the callers: `tests/Unit/BackgroundJob/GroupReconcilerJobRightsTest.php` runs the real job's `run()` over a tampered fixture; `tests/Integration/ImportRevertsOwnedGroupTest.php` imports a fixture configuration twice with a tamper between and asserts the revert.

## V. Verification and done

Follow `/home/rubenlinde/memcap-work/woo-build/LANE-RULES-BUILD.md` (or the copy of those rules in the build brief).

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
