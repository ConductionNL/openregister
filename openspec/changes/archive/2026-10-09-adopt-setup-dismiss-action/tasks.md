# Tasks: adopt-setup-dismiss-action

## 1. Library

- [x] 1.1 `@conduction/nextcloud-vue` `^2.71.0` in package.json and the lockfile.
- [x] 1.2 Re-vendor `tests/schemas/app-manifest-v2.schema.json` from the package (schema 2.53.0).

## 2. Adopt `setup.dismissAction`

- [x] 2.1 `src/manifest.json`: `setup.dismissAction` is `dismiss-setup`.
- [x] 2.2 Remove `src/services/wizardDismissal.js`, its spec and the `App.vue` wiring.
- [x] 2.3 Test `tests/unit/wizardDismissAction.spec.js`: the manifest names the action, `SetupController` answers it, and the workaround is gone.
- [x] 2.4 `SetupControllerTest` keeps both `dismiss-setup` tests (step answered, a real load kept).
