---
kind: code
---

# Proposal: AppHost Boilerplate Controllers and Bootstrap

## Problem

Beyond observability, the 2026-06-12 fleet inventory found ~150 more near-duplicate PHP files implementing the same app plumbing in every repo: 18× `SettingsController`, 16× `SettingsService`, 15× `PreferencesController`, 15× `DashboardController` (SPA page + catch-all), 13× `InitializeSettings` repair step, 12× `DeepLinkRegistrationListener`, 11× `AdminSettings` + `SettingsSection`, 5× `ActionAuthService` + `InitializeActions`, plus structurally identical `Application.php` (~100 lines), `routes.php`, and `templates/index.php` in all 18 apps. Diffing shows the copies differ only in namespace tokens, docblocks, and `@spec` tags — pure drift, no intent. Every fleet-wide fix (e.g. the AuthorizedAdminSetting #299 pattern, the webpack chunk-loading fix in templates/index.php) currently needs 15+ identical PRs.

## Proposed Change

Extract the boilerplate into `OCA\OpenRegister\AppHost\` as parameterised, extension-friendly classes, plus a one-call bootstrap so a leaf app's `Application.php` shrinks to ~20 lines and `routes.php` to one statement. This is the second half of the AppHost (observability engine is the sibling change `apphost-observability-engine`); together they make the long-term "declarative app" target possible: info.xml + manifest.json + register JSON + SVGs + two tiny PHP stubs.

1. **Generic controllers** under `lib/AppHost/Controller/`: `GenericDashboardController` (SPA page + catch-all, loads the app's bundles or the shared shell), `GenericPreferencesController` (get/set per-user prefs, key-validated), `GenericSettingsController` (index/create/load delegating to the settings service).
2. **Generic services**: `AppHostSettingsService` (register/schema config resolution, OR availability — the petstore `SettingsService` generalised), `GenericActionAuthService`.
3. **Generic install/admin plumbing**: `GenericInitializeSettings` + `GenericInitializeActions` repair steps (read the app's register JSON / actions.seed.json by appId — repair-step pattern preserved per the established install-order gotcha), `GenericAdminSettings` + `GenericSettingsSection` (IDelegatedSettings, #299-pattern), `GenericDeepLinkRegistrationListener`.
4. **Bootstrap**: `AppHost\Bootstrap::register(IRegistrationContext $context, string $appId, array $options = [])` — registers all controller service aliases under the leaf app's namespace (`OCA\{App}\Controller\SettingsController` → generic class), repair steps, listeners, admin settings. Alias registration is lazy: a disabled OR never fatals NC bootstrap.
5. **Routes**: `AppHost\Routes::standard(array $extra = [])` returning the canonical route array (dashboard page + catch-all, settings, preferences, health, metrics) so leaf `routes.php` is `return \OCA\OpenRegister\AppHost\Routes::standard();` with app-specific routes appended via `$extra`.
6. **Extension-first design**: no `final`, protected hook methods, every generic behaviour overridable by subclassing in the leaf app — this is the documented tutorial story for petstore/nextcloud-app-template.

### Scope

**In scope**: the classes above, unit tests, a reference integration test against a fixture app id, documentation.
**Out of scope**: observability engine (sibling change), leaf-app adoption (chained per-app changes), frontend shell bundle serving from OR (follow-up once nc-vue ships a prebuilt CnAppRoot shell — tracked as an open question in design.md).

## Impact

- **New files**: ~14 under `openregister/lib/AppHost/`, tests.
- **Modified**: none of OR's own controllers (OR self-adoption is the chained `openregister-adopt-apphost` change).
- **Downstream**: all per-app `adopt-apphost` changes depend on this; future app scaffolding (app-create skill) emits stubs instead of 15 PHP files.
- **Risk**: behavioural drift between an app's old copy and the generic class — adoption specs require endpoint-level parity checks (Newman + existing per-app e2e) before deleting local copies.

## Dependencies

- ADR-040 (hydra) records the AppHost decision; ADR-022 (apps consume OR abstractions) is the architectural basis.
- Sibling: `apphost-observability-engine` (shares the `AppHost\` namespace and Bootstrap; either may merge first — Bootstrap registers observability aliases only when the engine classes exist).
