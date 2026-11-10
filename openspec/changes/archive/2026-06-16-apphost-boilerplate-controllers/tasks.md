# Tasks: AppHost Boilerplate Controllers and Bootstrap

## 0. Setup and Verification

- [x] 0.1 Verify the petstore reference skeleton (petstore + nextcloud-app-template are byte-identical as of 2026-06-12) — its behaviour is the parity contract for every generic class
- [x] 0.2 Confirm controller alias resolution works for `OCA\{App}\Controller\X` → AppHost class on NC 30–34 (spike: one alias in a scratch app, hit the route) — proven via BootstrapTest (alias registration) + BootstrapFactoryChainTest (factory resolves the generic with the leaf appId)
- [x] 0.3 Confirm repair-step/Settings class-name constraints: which info.xml-referenced classes truly need an in-namespace concrete class (expected: repair steps, settings, section) — documented as the stub floor in docs/Technical/building-an-app-on-apphost.md

## 1. Controllers

- [x] 1.1 `GenericDashboardController` (page + catchAll, renders leaf `templates/index.php`, preserves chunk order)
- [x] 1.2 `GenericPreferencesController` (get/set, key validation, IConfig user prefs — key namespace unchanged)
- [x] 1.3 `GenericSettingsController` (index/create/load → AppHostSettingsService; admin-field stripping + full-admin mutations preserved)

## 2. Services and plumbing

- [x] 2.1 `AppHostSettingsService` (register/schema config, `isOpenRegisterAvailable()`, protected `configKeys()` hook for app-specific config maps)
- [x] 2.2 `GenericActionAuthService` + `GenericInitializeActions` (actions.seed.json by appId; fail-closed default-deny)
- [x] 2.3 `GenericInitializeSettings` repair step (register JSON / register.d fragments import via ConfigurationService; repair step, never migration)
- [x] 2.4 `GenericAdminSettings` + `GenericSettingsSection` (IDelegatedSettings #299 pattern; ids/names/icons from info.xml + manifest options)
- [x] 2.5 `GenericDeepLinkRegistrationListener` reading patterns from manifest `deepLinks` block

## 3. Bootstrap and routes

- [x] 3.1 `AppHost\Bootstrap::register(IRegistrationContext, string $appId, array $options)` — aliases for all controllers/services, repair-step factories, listener, dashboard widget passthrough, MCP provider alias passthrough; observability aliases registered when engine classes exist (gated by `observability` option)
- [x] 3.2 `AppHost\Routes::standard(array $extra = [])` — canonical route table, names identical to today's petstore routes; `$extra` merge with duplicate-name guard
- [x] 3.3 Lazy-load proof: NC bootstrap with OR disabled does not fatal; route resolution 5xx; documented in Bootstrap class docblock + asserted by BootstrapTest::testRegistrationIsLazyAndDoesNotAutoloadGenerics (no generic class autoloaded by register())

## 4. Tests

- [x] 4.1 Unit tests per class (parity with petstore behaviour, override hooks, alias registration) — 34 AppHost tests across Routes/ActionAuth/Settings/Preferences/SettingsController/Bootstrap
- [x] 4.2 Integration test exercising the full alias chain (route → alias → factory → generic controller) — BootstrapFactoryChainTest resolves each registered factory through a service-returning container and asserts the produced generic instance. A live route→HTTP integration against an installed fixture app is the per-app `adopt-apphost` change's responsibility (the spec scenarios are `@e2e exclude` for this reason); the in-process factory-chain test covers the engine side here.

## 5. Documentation

- [x] 5.1 `docs/Technical/building-an-app-on-apphost.md` — minimal-app layout, Bootstrap options, override cookbook (subclass + alias), stub floor
- [x] 5.2 Document the composer-package decision (rejected: vendor/ class-collision in NC shared process) — in the docs page under "Why not a Composer package?"

## 6. Quality gates

- [x] 6.1 `composer check:strict` green on lib/AppHost/ (PHPCS 0 errors, Psalm "No errors found"); all 24 hydra gates green (diff-scoped); `@spec` tags throughout
