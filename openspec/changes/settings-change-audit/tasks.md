# Tasks: settings-change-audit

> **Blocked, and by more than its `depends_on` says.** This change depends on
> `apphost-settings-plane` and `audit-log-page`, and `audit-log-page`'s task 1.2
> turns out to be a deliberate re-opening of a security boundary rather than a
> filter (see the note there, measured 2026-09-18). Its reader half, task 2.1
> here, therefore inherits that blocker: a `settings` kind filter is a filter on
> a page whose access rule is the open question.
>
> The WRITER half, tasks 1.1 to 1.3, does not depend on the page at all — it
> writes rows, and rows are readable through the existing admin-gated
> `GET /api/audit-trails` the day they exist. Whoever picks this up can ship the
> writer first and should, rather than waiting for a page whose hardest question
> is unrelated to recording who changed a setting.

## 1. Writer

- [ ] 1.1 `settings` subject kind on the audit trail; per-key diff and entry in `GenericSettingsService::update()`; import entry on `load(force)`.
- [ ] 1.2 `x-openregister-secret` read from the register configuration; masking.
- [ ] 1.3 OpenRegister's own `SettingsService` domains route through the writer.

## 2. Reader

- [ ] 2.1 Kind filter `settings`, diff rendering and export column on the audit leaf.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/settings-audit.spec.ts`: change a setting, filter the audit page, read the diff.
- [ ] 3.2 Unit tests for the diff, masking, chain verification and the preferences exclusion.
