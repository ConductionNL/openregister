# Tasks: feature-toggle-surface

## 1. Plane

- [ ] 1.1 `features` in the manifest schema (nextcloud-vue) with `key`, `label`, `description`, `default`, optional `failMode`.
- [ ] 1.2 `GenericSettingsService`: merged `features` on `index`, declared-only `update`, audit on change.
- [ ] 1.3 `FeatureToggleService::isEnabled()` with per-request cache and invalidation; initial state.

## 2. Surfaces

- [ ] 2.1 Features section in `GenericAdminSettings` from the declaration.
- [ ] 2.2 `visibleIf.feature` in the manifest runtime for pages, widgets and actions.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/feature-toggles.spec.ts`: flip a toggle, see a page vanish.
- [ ] 3.2 Unit tests for merge, refusal, cache invalidation; vitest for `visibleIf.feature`.
