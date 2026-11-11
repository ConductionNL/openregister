# Tasks: records-restore-with-cascade

## 1. Evidence

- [ ] 1.1 Migration adding `trigger_object` and the `(trigger_object, action)` index to `openregister_audit_trails`; written by `buildAuditTrail()` and `logIntegrityAction()`, outside the canonical hash form. Verify: a new `tests/Unit/Db/AuditTrailTriggerObjectTest.php` asserts the column on a cascade row and an unchanged hash for a row without it.
- [ ] 1.2 `logIntegrityAction()` expiry no earlier than the trigger's `destroyableFrom`. Verify: `tests/Unit/Service/Object/ReferentialIntegrityServiceTest.php` with a 90-day window.

## 2. Restore

- [ ] 2.1 `CascadeRestoreService::preview()` with the six classes and the 5,000-row cap. Verify: `tests/Unit/Service/Deletion/CascadeRestoreServiceTest.php` for each class, including a dependant deleted again later (`already`) and a changed field (`changed`).
- [ ] 2.2 `CascadeRestoreService::restore()` in one transaction with relink through the patch path. Verify: the same test class asserts rollback when one relink fails.
- [ ] 2.3 `cascade` on `POST /api/deleted/{id}/restore`, `GET /api/deleted/{id}/restore-preview`, cascade in `restoreMultiple()`, audit entries. Verify: `DeletedControllerTest` for 200 with counts, 403 on a forbidden dependant, 409 over the cap, `cascade: false`.

## 3. Page, tests and docs

- [ ] 3.1 "Restore with related records" and the preview dialog in `src/dialogs/` from `DeletedIndex.vue`, store call in `deleted.js`, texts in en and nl. Verify: component test for the dialog.
- [ ] 3.2 Add `tests/e2e/ci/restore-with-cascade.spec.ts`: delete a lead with two cascading activities and one set-null reference, edit a second survivor, preview, restore, and assert the activities are back, the reference is back and the edited survivor is untouched.
- [ ] 3.3 Document restore with related records in `docs/features/`, with a screenshot of the preview.

Acceptance:

- A restore never writes a value over a field someone changed after the delete.
- A caller without `update` on one dependant changes nothing.
