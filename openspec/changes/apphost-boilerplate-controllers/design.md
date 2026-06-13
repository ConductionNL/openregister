# Design: AppHost Boilerplate Controllers and Bootstrap

## The minimal leaf app

After this change + observability engine + per-app adoption, a shell-only Conduction app contains:

```
appinfo/info.xml          # id, version, navigation, repair-steps, settings — NC requires it
appinfo/routes.php        # return \OCA\OpenRegister\AppHost\Routes::standard();
lib/AppInfo/Application.php  # ~20 lines: APP_ID const + Bootstrap::register($context, self::APP_ID)
src/manifest.json         # UI (CnAppRoot shell) + observability block
lib/Settings/{app}_register.json (or register.d/ fragments)  # data model
lib/actions.seed.json     # optional
img/*.svg
templates/index.php       # generic chunk loader (until OR-served shell lands)
```

Domain logic, when present, stays as ordinary app controllers/services appended via `Routes::standard($extra)`.

## Why two stubs must remain

- **`appinfo/routes.php`**: NC's router only reads this file from the app dir — but it may `return` any array, so one delegation statement suffices.
- **`Application.php`**: NC resolves controllers as `OCA\{AppNamespace}\Controller\X` through the app's DI container; `Bootstrap::register()` aliases those exact strings to the generic classes. The class itself must live in the app's namespace, hence a stub. Alias registration is string-based and lazy — referencing `OCA\OpenRegister\...` class names here does NOT load them, so NC bootstrap survives a disabled OR; the first resolved route then 503s and health reports `orAvailable: failed`, which is the correct degraded behaviour.

## Class inventory

| Generic class | Replaces (fleet count) | Parameterisation |
|---|---|---|
| `Controller/GenericDashboardController` | DashboardController ×15 | appId (page + catchAll render `templates/index.php` of the leaf app) |
| `Controller/GenericPreferencesController` | PreferencesController ×15 | appId; key allowlist regex; values stored via IConfig user prefs |
| `Controller/GenericSettingsController` | SettingsController ×18 | appId; delegates to AppHostSettingsService |
| `Service/AppHostSettingsService` | SettingsService ×16 | appId; register/schema config keys read from appconfig; `isOpenRegisterAvailable()` |
| `Service/GenericActionAuthService` | ActionAuthService ×5 | appId; actions.seed.json |
| `Repair/GenericInitializeSettings` | InitializeSettings ×13 | appId via parameterised factory service; imports register JSON through ConfigurationService (repair step, NOT migration — install-order constraint) |
| `Repair/GenericInitializeActions` | InitializeActions ×5 | appId; seeds actions |
| `Settings/GenericAdminSettings` + `GenericSettingsSection` | AdminSettings/SettingsSection ×11 | appId, section id/name/icon from info.xml + manifest; implements IDelegatedSettings (#299 pattern) |
| `Listener/GenericDeepLinkRegistrationListener` | DeepLinkRegistrationListener ×12 | deep-link patterns read from manifest (`deepLinks` block) instead of hardcoded PHP |
| `Bootstrap` | Application.php body ×18 | one call registers everything |
| `Routes` | routes.php ×18 | `standard()` + `$extra` merge; route names match today's (`dashboard#page`, `settings#index`, …) so info.xml navigation entries keep working |

NC repair-steps and Settings classes are instantiated by class name from info.xml / registration — they cannot be cross-app classes directly. Resolution: `Bootstrap` registers a tiny per-app factory (closure service) under the leaf-app class name; the per-app adoption change keeps 2–4 one-line subclass stubs ONLY where NC demands a concrete class in the app namespace (repair steps in info.xml `<repair-steps>`, `<settings>` entries). These stubs are `class InitializeSettings extends GenericInitializeSettings {}` — acceptable floor.

## Behavioural parity rules (binding on adoption specs)

1. Route names, URLs, verbs, and response shapes are bit-compatible with the petstore reference skeleton.
2. `templates/index.php` chunk-loading order (shared-vendor → shared-nc-vue → main) is preserved by the generic dashboard controller.
3. Preferences keys already written by deployed apps keep resolving (no key-namespace change).
4. Every overridable behaviour has a protected method; no `final` anywhere in `AppHost\`.

## Open questions (tracked, non-blocking)

- **OR-served shell bundle**: serving a prebuilt CnAppRoot shell from OR's `js/` via `Util::addScript('openregister', ...)` would delete per-app webpack builds for shell-only apps. Requires nc-vue to publish the prebuilt shell; follow-up spec once that lands.
- **info.xml navigation without local routes**: stays on `dashboard#page` route name provided by `Routes::standard()` — no NC change needed.
