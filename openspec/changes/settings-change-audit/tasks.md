# Tasks: settings-change-audit

## 1. Writer

- [ ] 1.1 `settings` subject kind on the audit trail; per-key diff and entry in `GenericSettingsService::update()`; import entry on `load(force)`.
- [ ] 1.2 `x-openregister-secret` read from the register configuration; masking.
- [ ] 1.3 OpenRegister's own `SettingsService` domains route through the writer.

## 2. Reader

- [ ] 2.1 Kind filter `settings`, diff rendering and export column on the audit leaf.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/settings-audit.spec.ts`: change a setting, filter the audit page, read the diff.
- [ ] 3.2 Unit tests for the diff, masking, chain verification and the preferences exclusion.
