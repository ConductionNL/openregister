# apphost-boilerplate Specification

## Purpose
TBD - created by archiving change apphost-boilerplate-controllers. Update Purpose after archive.
## Requirements
### Requirement: One-Call Bootstrap

`AppHost\Bootstrap::register(IRegistrationContext, string $appId, array $options)` SHALL register service aliases mapping the leaf app's conventional controller/service class names to the AppHost generics, as lazy service closures whose bodies are executed only on resolution.

The laziness applies to the closure BODIES. It does NOT make reaching `Bootstrap::register()` safe: calling it requires resolving the symbol `OCA\OpenRegister\AppHost\Bootstrap`, which is an ordinary autoload. A leaf that has not satisfied the Autoload Prelude requirement below, and does not guard the call with `class_exists()`, WILL fatal out of its own `register()` when that symbol is unavailable.

#### Scenario: Leaf controllers resolve to generics

- **GIVEN** an app whose Application::register calls Bootstrap::register with its appId
- **WHEN** `GET /apps/{appid}/api/settings` is dispatched
- **THEN** the route MUST resolve `OCA\{App}\Controller\SettingsController` to `GenericSettingsController` and respond with the standard settings payload
- @e2e exclude API plumbing — integration-tested in OR with a fixture app; per-app parity asserted by each adopt-apphost change's Newman run

#### Scenario: Disabled OpenRegister degrades, never fatals

- **GIVEN** an adopted app with OpenRegister disabled, whose `register()` guards the `Bootstrap::register()` call with `class_exists()`
- **WHEN** Nextcloud boots and the app's routes are hit
- **THEN** NC bootstrap MUST complete and the requests MUST fail with a 5xx JSON error, not a whitescreen
- @e2e exclude failure-mode backend behaviour — integration-tested, no stable UI surface

---

### Requirement: Autoload Prelude for AppHost Adoption

An app adopting AppHost SHALL register OpenRegister's autoload prefix as the first action of its `Application::register()`, before ANY reference to an `OCA\OpenRegister\AppHost\` symbol — including a `class_exists()` probe:

```php
try {
    $orPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath('openregister');
    \OC_App::registerAutoloading('openregister', $orPath);
} catch (\Throwable) {
    // OpenRegister absent/disabled — fall through to the degraded path.
}
```

`OC_App::registerAutoloading()` touches only the autoloader and is idempotent (it early-returns on an `$alreadyRegistered` key).

An app SHALL NOT substitute `IAppManager::loadApp('openregister')`, which sets `loadedApps[..]=true` and calls `Coordinator::bootApp()`, booting OpenRegister before its own `register()` has run. An app SHALL NOT substitute a relative `include_once` of OpenRegister's `vendor/autoload.php`, which assumes both apps share one apps directory and silently does nothing on a multi-`apps_paths` install.

The prelude is required regardless of where the app id sorts. Sorting after `openregister` makes an app safe by alphabet alone, which is a property of its name rather than of its design.

Rationale: `OC_App::getEnabledApps()` does `sort($apps)`, and `Coordinator::registerApps()` walks that sorted list calling `OC_App::registerAutoloading($appId, $path)` then `$application->register()` one app at a time. Every app therefore registers before the PSR-4 prefix of every alphabetically-later app exists. Enforced by hydra gate-64 (`apphost-autoload-prelude`).

#### Scenario: A leaf sorting before openregister still wires its AppHost plumbing

- **GIVEN** an adopted app whose app id sorts before `openregister` (e.g. `docudesk`, `doriath`, `opencatalogi`), on an instance where OpenRegister is enabled
- **WHEN** Nextcloud registers apps and the app's `register()` runs
- **THEN** `class_exists('OCA\OpenRegister\AppHost\Bootstrap')` MUST answer TRUE inside that method, the AppHost aliases MUST be registered, and every registration below the AppHost call MUST still run
- @e2e exclude app-registration ordering has no UI surface — asserted statically by hydra gate-64 and observable via the app's own health endpoint

#### Scenario: Absent OpenRegister still degrades rather than aborting register()

- **GIVEN** an adopted app on an instance where `openregister` is not installed
- **WHEN** the app's `register()` runs
- **THEN** the prelude's `catch (\Throwable)` MUST swallow the `AppPathNotFoundException`, the `class_exists()` guard MUST skip the AppHost plumbing, and every non-AppHost registration in that method MUST still complete
- @e2e exclude failure-mode backend behaviour — no stable UI surface

---

### Requirement: Canonical Route Table

`AppHost\Routes::standard(array $extra = [])` SHALL return the petstore-reference route set (dashboard page `/`, SPA catch-all, settings index/create/load, preferences get/set, health, metrics) with unchanged route names, URLs and verbs, merging `$extra` with a duplicate-name guard.

#### Scenario: Existing navigation entries keep working

- **GIVEN** an app whose info.xml navigation references route `{app}.dashboard.page`
- **WHEN** routes.php returns `Routes::standard()`
- **THEN** the navigation entry MUST resolve and the SPA MUST load with the shared-vendor → shared-nc-vue → main chunk order
- @e2e exclude covered per-app by existing dashboard-loads e2e in each adopt-apphost change, not testable app-agnostically here

---

### Requirement: Extension-First Generics

Every AppHost generic class SHALL be subclassable (no `final`, protected hook methods for app-specific behaviour), and an app SHALL be able to override any single endpoint by aliasing its conventional class name to a local subclass.

#### Scenario: Tutorial override replaces one method

- **GIVEN** petstore aliasing `OCA\PetStore\Controller\HealthController` to a local subclass overriding one protected hook
- **WHEN** the health endpoint is called
- **THEN** the overridden behaviour MUST apply while all other plumbing stays generic
- @e2e exclude exercised by the petstore demo change's own tests (apphost-tutorial-overwrite)

---

### Requirement: Install Plumbing via Repair Steps

`GenericInitializeSettings`/`GenericInitializeActions` SHALL import the leaf app's register JSON (including `register.d/` fragments) and actions seed through OpenRegister's ConfigurationService as repair steps, never as migrations, preserving the fleet install-order constraint.

#### Scenario: Fresh install seeds the register

- **GIVEN** a leaf app with a register JSON and `<repair-steps>` referencing its InitializeSettings stub
- **WHEN** `occ app:enable {app}` then repair runs
- **THEN** the app's registers/schemas MUST exist in OpenRegister and the app's settings MUST report the configured register
- @e2e exclude install-time backend — covered by OR integration test + each app's existing install smoke

