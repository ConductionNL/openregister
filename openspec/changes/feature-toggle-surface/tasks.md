# Tasks: feature-toggle-surface

## 1. Plane

- [ ] 1.1 `features` in the manifest schema (nextcloud-vue) with `key`, `label`, `description`, `default`, optional `failMode`.
      > 🔑 **THE MANIFEST CANNOT BE THE ONLY DECLARATION.** It is a client
      > artefact, and `isEnabled()` is a PHP call inside a guard: the server
      > cannot ask it. So the server-side declaration is the `features` block
      > of the app's register configuration, which the plane already resolves,
      > and `AppHostSettingsService::featureDeclarations()` is the hook. The
      > manifest half stays open and belongs to nextcloud-vue; when it lands,
      > one loader feeds both and nothing that reads a toggle changes.
- [x] 1.2 Merged `features`, declared-only `update`, audit on change, in
      `FeatureToggleService` and reachable from the plane as `getFeatures()`
      and `updateFeatures()`. An undeclared key is refused with 422 naming it,
      and one undeclared key refuses the whole write so no half of it lands.
      The audit goes through `SettingsChangeAuditor` with keys spelled
      `features.<key>`, so a trail row names the toggle rather than the JSON
      blob it lives in.
- [x] 1.3a `FeatureToggleService::isEnabled()` with a per-request memo,
      dropped on update. Registered SHARED in `Application.php`, because an
      autowired-per-injection instance turns a per-request memo into a
      per-injection one.
- [ ] 1.3b Initial state: the merged map to the client. It needs a
      `IInitialState` provider on the app's page controller, which is the leaf
      app's, not the plane's; the reader half here is what it would serve.

## 2. Surfaces

- [ ] 2.1 Features section in `GenericAdminSettings` from the declaration.
- [ ] 2.2 `visibleIf.feature` in the manifest runtime for pages, widgets and actions.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/feature-toggles.spec.ts`: flip a toggle, see a page vanish.
- [x] 3.2a Unit tests for the merge, the refusal, the cache invalidation and
      the two coercion traps: a stored `"false"` reading as false (`(bool)"false"`
      is true, and `IAppConfig` hands back strings), and an unreadable override
      map honouring each toggle's declared fail mode. Both mutation-checked.
- [ ] 3.2b vitest for `visibleIf.feature`, which lives with task 2.2 in the
      manifest runtime.
