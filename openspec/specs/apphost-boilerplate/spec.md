# apphost-boilerplate Specification

## Purpose
TBD - created by archiving change apphost-boilerplate-controllers. Update Purpose after archive.
## Requirements
### Requirement: One-Call Bootstrap

`AppHost\Bootstrap::register(IRegistrationContext, string $appId, array $options)` SHALL register service aliases mapping the leaf app's conventional controller/service class names to the AppHost generics, lazily, such that Nextcloud bootstrap never fatals when OpenRegister is disabled.

#### Scenario: Leaf controllers resolve to generics

- **GIVEN** an app whose Application::register calls Bootstrap::register with its appId
- **WHEN** `GET /apps/{appid}/api/settings` is dispatched
- **THEN** the route MUST resolve `OCA\{App}\Controller\SettingsController` to `GenericSettingsController` and respond with the standard settings payload
- @e2e exclude API plumbing — integration-tested in OR with a fixture app; per-app parity asserted by each adopt-apphost change's Newman run

#### Scenario: Disabled OpenRegister degrades, never fatals

- **GIVEN** an adopted app with OpenRegister disabled
- **WHEN** Nextcloud boots and the app's routes are hit
- **THEN** NC bootstrap MUST complete and the requests MUST fail with a 5xx JSON error, not a whitescreen
- @e2e exclude failure-mode backend behaviour — integration-tested, no stable UI surface

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

