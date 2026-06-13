# Tasks: AppHost Boilerplate Controllers and Bootstrap

## 0. Setup and Verification

- [ ] 0.1 Verify the petstore reference skeleton (petstore + nextcloud-app-template are byte-identical as of 2026-06-12) — its behaviour is the parity contract for every generic class
- [ ] 0.2 Confirm controller alias resolution works for `OCA\{App}\Controller\X` → AppHost class on NC 30–34 (spike: one alias in a scratch app, hit the route)
- [ ] 0.3 Confirm repair-step/Settings class-name constraints: which info.xml-referenced classes truly need an in-namespace concrete class (expected: repair steps, settings, section) — document the stub floor

## 1. Controllers

- [ ] 1.1 `GenericDashboardController` (page + catchAll, renders leaf `templates/index.php`, preserves chunk order)
- [ ] 1.2 `GenericPreferencesController` (get/set, key validation, IConfig user prefs — key namespace unchanged)
- [ ] 1.3 `GenericSettingsController` (index/create/load → AppHostSettingsService)

## 2. Services and plumbing

- [ ] 2.1 `AppHostSettingsService` (register/schema config, `isOpenRegisterAvailable()`, protected hooks for app-specific config maps)
- [ ] 2.2 `GenericActionAuthService` + `GenericInitializeActions` (actions.seed.json by appId)
- [ ] 2.3 `GenericInitializeSettings` repair step (register JSON / register.d fragments import via ConfigurationService; repair step, never migration)
- [ ] 2.4 `GenericAdminSettings` + `GenericSettingsSection` (IDelegatedSettings #299 pattern; ids/names/icons from info.xml + manifest)
- [ ] 2.5 `GenericDeepLinkRegistrationListener` reading patterns from manifest `deepLinks` block

## 3. Bootstrap and routes

- [ ] 3.1 `AppHost\Bootstrap::register(IRegistrationContext, string $appId, array $options)` — aliases for all controllers/services, repair-step factories, listener, dashboard widget passthrough, MCP provider alias passthrough; observability aliases registered when engine classes exist
- [ ] 3.2 `AppHost\Routes::standard(array $extra = [])` — canonical route table, names identical to today's petstore routes; `$extra` merge with duplicate-name guard
- [ ] 3.3 Lazy-load proof: NC bootstrap with OR disabled does not fatal; route resolution 503s; document in class docblock

## 4. Tests

- [ ] 4.1 Unit tests per class (parity with petstore behaviour, override hooks, alias registration)
- [ ] 4.2 Integration test with a fixture app id exercising the full alias chain (route → alias → generic controller → response)

## 5. Documentation

- [ ] 5.1 `docs/` page: "Building an app on the AppHost" — minimal-app layout, Bootstrap options, override cookbook (subclass + alias), stub floor
- [ ] 5.2 Document the composer-package decision (rejected: vendor/ class-collision in NC shared process) for future reference

## 6. Quality gates

- [ ] 6.1 `composer check:strict` green; all 18 hydra gates green; `@spec` tags throughout
