# Tasks: records-saved-templates

## 1. Store

- [ ] 1.1 `RecordTemplate` entity, mapper with reach listing, migration for `openregister_record_templates`. Verify: mapper tests for owner, group-shared and public templates on PostgreSQL and MariaDB.
- [ ] 1.2 `/api/record-templates` controller and routes beside `/api/views`, with owner-or-admin update and delete, and use limited to users who may create in the schema. Verify: `tests/Api/RecordTemplatesTest` including a user without create rights who does not see the template.

## 2. Interface

- [ ] 2.1 "Save as template" on the record menu with the field picker. Verify: `tests/e2e/record-templates.spec.ts` saves a template from a melding.
- [ ] 2.2 "Start from a template" in the create dialog, dropping values that no longer fit and saying which. Verify: same e2e creates a melding from the template and a unit test covers the dropped value.
- [ ] 2.3 nextcloud-vue create form template picker so leaf apps get it. Verify: component test in nextcloud-vue.

## 3. Docs

- [ ] 3.1 `docs/` section on record templates.

Acceptance:
- A record created from a template passes the same validation and RBAC as any other.
