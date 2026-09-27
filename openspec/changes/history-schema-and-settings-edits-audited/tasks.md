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
