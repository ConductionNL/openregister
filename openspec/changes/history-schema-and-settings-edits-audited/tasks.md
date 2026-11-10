# Tasks: history-schema-and-settings-edits-audited

## 1. Writer

- [ ] 1.1 Add `lib/Service/Audit/EntityEditAuditor.php`: build the row for create, update and delete of a schema or register (design D-2), with the per-path diff (D-3), the 2 KB cap and credential masking (D-4), and a fail-soft write through `AuditTrailMapper::insertAuditTrails()` (D-5). Verify: `tests/Unit/Service/Audit/EntityEditAuditorTest.php` covers a narrowed `maxLength`, a widened `authorization.read`, a no-op save writing nothing, a 5 KB enum stored as hash and length, a masked `clientSecret`, and a failing mapper returning 0 without throwing.
- [ ] 1.2 Add `lib/Listener/EntityEditAuditListener.php` and register it in `lib/AppInfo/Application.php` for the six schema and register events; take `cause` and `causeRun` from `WriteCause::current()`. Verify: `tests/Unit/Listener/EntityEditAuditListenerTest.php` constructs the real `SchemaUpdatedEvent` and `RegisterDeletedEvent` classes, not doubles, and asserts one row each with the right action.
- [ ] 1.3 Prove every door reaches the listener. Verify: `tests/Integration/EntityEditAuditDoorsTest.php` edits one schema through the controller, `updateFromArray` and a configuration import, and finds three sealed rows whose chain verifies with `GET /api/audit-trails/verify`.

## 2. Read endpoints

- [ ] 2.1 Add `SchemasController::changes()` and `RegistersController::changes()` with routes `GET /api/schemas/{id}/changes` and `GET /api/registers/{id}/changes`, administrator-only, paginated with a maximum `_limit` of 100. Verify: `tests/Unit/Controller/EntityChangesEndpointTest.php`; a Newman request asserts 200 for an administrator and 403 for a non-administrator; hydra gates route-auth and no-admin-idor pass.

## 3. Pages

- [ ] 3.1 Add the "Changes" tab to `src/views/schema/SchemaDetails.vue` and the "Changes" section to `src/views/register/RegisterDetail.vue`, both shown to administrators only, listing who, when, cause and each changed path with old and new values. Verify: `src/views/schema/SchemaDetails.spec.js` renders a fixture of three rows and hides the tab for a non-administrator.

## 4. Docs and end-to-end test

- [ ] 4.1 Document the actions, the row shape, masking and the read endpoints in `docs/features/versioning-and-audit.md`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 4.2 Add `tests/e2e/ci/schema-edit-audit.spec.ts`: an administrator narrows a property on a schema, opens the "Changes" tab and sees the old and new value with their name; a non-administrator gets 403 on `GET /api/schemas/{id}/changes`. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- Every schema and register write that reaches the mapper produces exactly one sealed row.
- No row carries a credential value.

## 5. Woo programme amendment: organisations (REQ-HSA-010, row 12.30)

- [ ] 5.1 Register `EntityEditAuditListener` for `OrganisationCreatedEvent`, `OrganisationUpdatedEvent` and `OrganisationDeletedEvent` in `lib/AppInfo/Application.php`; teach `EntityEditAuditor` the `organisation.*` actions and the organisation diff. Verify: `tests/Unit/Listener/EntityEditAuditListenerTest.php::testAnOrganisationRenameWritesOneRow` and `testAnOrganisationCreatedByRepairNamesItsCause`, both constructing the real event classes with a real `Organisation` entity (fails today: no listener on those events).
- [ ] 5.2 Through the caller: `tests/Unit/Controller/OrganisationAuditDoorTest.php` updates an organisation through `OrganisationController::update()` with the real `OrganisationMapper` event dispatch wired to the listener and asserts one sealed row; a Newman request in `tests/newman/` renames an organisation and reads the row from `GET /api/audit-trails?category=administrative&action=organisation.updated`.

## 6. Woo programme amendment: administrative category (REQ-HSA-011, row 12.22 under D5)

- [ ] 6.1 Migration adding nullable `category` to `openregister_audit_trails`, backfilled by action; `AuditTrail::$category`; the writers set it (`EntityEditAuditor`, `SettingsChangeAuditor`, the object write path). Confirm the hash input in `AuditTrailMapper` does not include it. Verify: `tests/Unit/Db/AuditCategoryBackfillTest.php::testTheChainVerifiesAfterBackfill` and `testEveryWriterSetsACategory` (fails today: no column).
- [ ] 6.2 `AuditTrailController::index()` accepts `category`, defaults to `domain`, and refuses `administrative` to a non-administrator with 403; per-object trail endpoints filter administrative rows out. Verify: `tests/Unit/Controller/AuditCategoryReadTest.php::testTheDefaultIsDomain`, `testANonAdminNeverReceivesAdministrativeRows`; the hydra gates no-admin-idor and semantic-auth pass.
- [ ] 6.3 Setting `audit.administrativeRetention` (ISO 8601 duration, default `P10Y`, refused when unparsable) applied at write time to `retentionPeriod` and `expires`. Verify: `tests/Unit/Service/Audit/AuditCategoryRetentionTest.php::testAdministrativeRowsTakeTheirOwnRetention`, `testAnUnparsableDurationIsRefused`.
- [ ] 6.4 "Administrative changes" view on the audit log page (`audit-log-page`'s view, or `src/views/auditTrail/` where it lives on `development`). Verify: `tests/e2e/ci/administrative-change-log.spec.ts` renames an organisation and edits a schema, opens the view, sees both rows, switches to the default view and sees neither.

## V. Verification and done

Follow `openspec/woo-build-rules.md`.

- [ ] V.1 Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`, with `TMPDIR` set to a sibling directory outside the clone. Verify: `git rev-parse --show-toplevel` runs in the same command as every `git add`.
- [ ] V.2 Every test named above fails on `origin/development` and passes on the branch. Verify: run each new test file once with the change stashed and once with it applied, and quote both `Tests:` lines in the PR body. A test that passes on today's code proves nothing and does not count.
- [ ] V.3 Full unit suite: `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage`, judged by the `Tests:` line (`Failures:` and `Errors:`), never by the exit code alone, because a green suite exits 1 without a coverage driver.
- [ ] V.4 Gates: `run-hydra-gates.sh --base origin/development` from `vendor/conduction/hydra-gates` (without `--base` the gates read NOT APPLICABLE, which is not a pass), and count the gates that ran. Then once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`. CI runs the gates on the full tree, and the coverage guard needs tests for every added statement, so project the coverage arithmetically and say in the PR body that it is arithmetic.
- [ ] V.5 One PR with `--base development`. Merge `development` into the branch, never rebase a pushed branch. No `Co-Authored-By` trailer on any commit. Done means merged on `development` with CI green; the rows this change closes count as `production` only once it ships in a store release.
