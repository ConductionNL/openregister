# Tasks: records-saved-templates

## 1. Store

- [ ] 1.1 `RecordTemplate` entity, mapper with reach listing, migration for `openregister_record_templates`. Verify: mapper tests for owner, group-shared and public templates on PostgreSQL and MariaDB.
  - built (11 Oct, build/dep-dossiq-record-templates): `lib/Db/RecordTemplate.php`, `lib/Db/RecordTemplateMapper.php` (`findCandidates`: own, public or shared rows, by register/schema), `lib/Migration/Version1Date20261011090000.php` (`template_values`, as VALUES is an SQL keyword). The reach decision (owner, group share, public, plus create rights) is in `lib/Service/RecordTemplate/RecordTemplateService.php`, unit-tested in `tests/Unit/Service/RecordTemplate/RecordTemplateServiceTest.php`; entity, mapper table and migration in `tests/Unit/Db/RecordTemplateTest.php` and `tests/Unit/Migration/Version1Date20261011090000Test.php`. Open: the DB-backed mapper run on PostgreSQL and MariaDB (CI matrix / live pass, decision 139).
- [ ] 1.2 `/api/record-templates` controller and routes beside `/api/views`, with owner-or-admin update and delete, and use limited to users who may create in the schema. Verify: `tests/Api/RecordTemplatesTest` including a user without create rights who does not see the template.
  - built (11 Oct): `lib/Controller/RecordTemplatesController.php`, six routes after `views#destroy`; `tests/Unit/Controller/RecordTemplatesControllerTest.php` (401, filters, 201/204, 400 with findings, 403, 404, 500 without the message); the read-only member case through the service in `RecordTemplateServiceTest::testAMemberWithoutCreateRightsIsNotOfferedTheTemplate`. Open: `tests/Api/RecordTemplatesTest` against an instance (live pass, decision 139).

## 2. Interface

- [x] 2.1 "Save as template" on the record menu with the field picker. Verify: `tests/e2e/record-templates.spec.ts` saves a template from a melding.
  - built (third pass): "Save as template" on the objects list row and card menus (`src/views/search/SearchIndex.vue`) opens `src/modals/object/SaveRecordTemplate.vue`: the record's fields with a value, identity, dates, relations and files unticked (`templateFieldChoices()` / `belongsToOneRecord()` in `src/services/recordTemplates.js`, design D-3), a name, a description, and only me, one group (`read` share) or everyone; saved through `POST /api/record-templates`, which checks the create right. Unit tests: `src/services/recordTemplates.spec.js` and `src/modals/object/SaveRecordTemplate.spec.js`. Strings in en, nl and de. Open: the e2e (live pass).
- [x] 2.2 "Start from a template" in the create dialog, dropping values that no longer fit and saying which. Verify: same e2e creates a melding from the template and a unit test covers the dropped value.
  - built (second pass): `src/components/object/RecordTemplatePicker.vue` on the create dialog (`ViewObject.vue`, new records only) lists the templates of the register and schema (`GET /api/record-templates`, reach and create right applied by the server) and applies one through `applyTemplate()` in `src/services/recordTemplates.js`: a value whose property is gone or whose type no longer matches is dropped and named in a warning; the rest seeds a blank record, saved through the normal save. Unit tests: `src/services/recordTemplates.spec.js` (the dropped value) and `src/components/object/RecordTemplatePicker.spec.js`. Strings in en, nl and de. Open: the e2e (live pass).
- [ ] 2.3 nextcloud-vue create form template picker so leaf apps get it. Verify: component test in nextcloud-vue.

## 3. Docs

- [x] 3.1 `docs/` section on record templates.

Acceptance:
- A record created from a template passes the same validation and RBAC as any other.
