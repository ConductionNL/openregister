# Building an app on the AppHost

The **AppHost** is OpenRegister's shared application runtime (ADR-040). It lets a
Conduction leaf app delete ~10 near-identical plumbing classes (dashboard/SPA
serving, settings API, per-user preferences, install repair steps, admin
settings panel, deep-link registration, route table) and replace them with a
single `Bootstrap::register()` call plus one `Routes::standard()` statement.

This page covers the minimal leaf-app layout, the `Bootstrap` options, the
override cookbook, and the small stub floor that Nextcloud's class-name-based
instantiation forces to remain.

> The observability half of the AppHost (health + metrics) is documented in
> [Declarative Observability](./declarative-observability.md). Both halves share
> the `OCA\OpenRegister\AppHost\` namespace and the same `Bootstrap`.

## The complete generic set

`Bootstrap::register()` aliases a leaf app's conventional class names to the
following engine-owned generics. Every class below ships under
`lib/AppHost/` — a `Bootstrap::register()` call with all standard options
enabled resolves each one (asserted by
`tests/Unit/AppHost/BootstrapFactoryChainTest::testAllFactoriesResolveToRealClassesWithFullOptions`),
so a leaf app can fully adopt the engine without leaving any plumbing class behind.

| Generic class | Leaf alias | Role |
|---|---|---|
| `Controller\GenericDashboardController` | `Controller\DashboardController` | Serve the SPA page + history-mode catch-all. |
| `Controller\GenericPreferencesController` | `Controller\PreferencesController` | Per-user key/value UI preferences (`#[NoAdminRequired]`, user-scoped, `pref_` namespace). |
| `Controller\GenericSettingsController` | `Controller\SettingsController` | App settings API (admin-write, register binding stripped for non-admins). |
| `Controller\GenericHealthController` | `Controller\HealthController` | `GET /api/health` (observability; opt-out via `observability: false`). |
| `Controller\GenericMetricsController` | `Controller\MetricsController` | `GET /api/metrics` (Prometheus; observability). |
| `Service\AppHostSettingsService` | `Service\SettingsService` | App-scoped settings backed by `IAppConfig`. |
| `Service\GenericActionAuthService` | `Service\ActionAuthService` | Action authorization matrix (fails closed, admin-only default). |
| `Repair\GenericInitializeSettings` | `Repair\InitializeSettings` | Install repair step — seed default settings. |
| `Repair\GenericInitializeActions` | `Repair\InitializeActions` | Install repair step — seed default action matrix. |
| `Settings\GenericAdminSettings` | `Settings\AdminSettings` | `IDelegatedSettings` admin panel (#299). |
| `Settings\GenericSettingsSection` | `Sections\SettingsSection` | `IIconSection` admin section. |
| `Listener\GenericDeepLinkRegistrationListener` | `Listener\DeepLinkRegistrationListener` | Manifest-driven deep-link registration (opt-out via `deepLinks: false`). |

## The minimal leaf app

After adoption, a shell-only app contains:

```
appinfo/info.xml            # id, version, navigation, <repair-steps>, <settings> — NC requires it
appinfo/routes.php          # return \OCA\OpenRegister\AppHost\Routes::standard();
lib/AppInfo/Application.php  # ~20 lines: APP_ID const + Bootstrap::register(...)
src/manifest.json           # UI shell + observability + deepLinks blocks
lib/Settings/{app}_register.json (or register.d/ fragments)  # data model
lib/actions.seed.json       # optional ADR-023 action matrix seed
img/*.svg
templates/index.php         # chunk loader (shared-vendor → shared-nc-vue → main)
```

### `lib/AppInfo/Application.php`

```php
<?php
declare(strict_types=1);

namespace OCA\PetStore\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'petstore';

    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        Bootstrap::register($context, self::APP_ID, [
            // Required in practice: the fleet's namespaces are not derivable
            // from the app id (petstore → PetStore, opencatalogi → OpenCatalogi).
            'namespace'        => 'OCA\\PetStore',
            'sectionName'      => 'Pet Store',
            'dashboardWidgets' => [\OCA\PetStore\Dashboard\ExampleWidget::class],
            'mcpProvider'      => \OCA\PetStore\Mcp\ExampleToolProvider::class,
        ]);
    }

    public function boot(IBootContext $context): void {}
}
```

### `appinfo/routes.php`

```php
<?php
declare(strict_types=1);

return \OCA\OpenRegister\AppHost\Routes::standard();
```

Domain routes are appended via the `$extra` argument:

```php
return \OCA\OpenRegister\AppHost\Routes::standard([
    ['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
]);
```

`$extra` routes are inserted **before** the SPA catch-all so they keep priority
over the `/{path}` fallback. A duplicate route name **within** `$extra` throws;
an `$extra` route whose name matches a canonical one **overrides** the canonical
entry (the documented way to re-point a single endpoint at a local controller).

## `Bootstrap::register()` options

| Option | Default | Purpose |
|---|---|---|
| `namespace` | StudlyCase guess from app id | The app's `OCA\X` base namespace. **Pass it explicitly** — the guess is only a fallback. Drives all sub-namespaces below. |
| `controllerNamespace` / `repairNamespace` / `settingsNamespace` / `sectionsNamespace` / `listenerNamespace` / `serviceNamespace` | derived from `namespace` | Override a single sub-namespace if the app deviates from convention. |
| `sectionId` | `$appId` | Admin settings section id. |
| `sectionName` | StudlyCase id | Admin section display name. |
| `sectionIcon` | `app-dark.svg` | Icon file in the app's `img/`. |
| `sectionPriority` / `adminPriority` | `75` / `10` | Settings ordering. |
| `dashboardWidgets` | `[]` | Widget classes to register. |
| `mcpProvider` | — | MCP tool provider class (ADR-034/035). |
| `deepLinks` | `true` | Register the manifest-driven deep-link listener. |
| `observability` | `true` | Alias the health + metrics controllers. |

### Manifest `deepLinks` block

The generic deep-link listener reads patterns from `src/manifest.json` instead
of hardcoded PHP:

```json
{
  "deepLinks": [
    {
      "registerSlug": "petstore",
      "schemaSlug":   "pet",
      "urlTemplate":  "/apps/petstore/#/pets/{uuid}",
      "displayName":  "Pet"
    }
  ]
}
```

## Why a disabled OpenRegister does not fatal Nextcloud

Every registration in `Bootstrap` is a `registerService(name, Closure)`. The
closure body — which references `OCA\OpenRegister\AppHost\…` classes — runs only
when the app's DI container resolves that service, i.e. when a route is
dispatched. No OR class is referenced outside a closure in `Bootstrap` or
`Routes`.

Consequently, with OpenRegister disabled or absent:

- `Application::register()` completes without loading a single OR class →
  **Nextcloud boots normally**.
- The first request to an aliased route triggers the closure, which cannot
  autoload the generic class and surfaces as a **5xx JSON error** — the correct
  degraded behaviour. The `/api/health` endpoint reports `orAvailable: failed`.

## Override cookbook (extension-first)

No AppHost generic is `final`; every app-specific behaviour sits behind a
`protected` hook. To replace one endpoint while keeping the rest generic,
subclass the generic in the leaf app and let `Bootstrap` alias your conventional
class name — or alias it yourself after calling `Bootstrap::register()`:

```php
// lib/Controller/DashboardController.php
namespace OCA\PetStore\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCP\AppFramework\Http\TemplateResponse;

class DashboardController extends GenericDashboardController
{
    protected function renderIndex(): TemplateResponse
    {
        // Add extra initial state, then defer to the generic template.
        return parent::renderIndex();
    }
}
```

Then, in `Application::register()`, register your subclass *after*
`Bootstrap::register()` so it wins:

```php
Bootstrap::register($context, self::APP_ID, ['namespace' => 'OCA\\PetStore']);
$context->registerServiceAlias(
    \OCA\PetStore\Controller\DashboardController::class,
    \OCA\PetStore\Controller\DashboardController::class // your concrete subclass
);
```

The same pattern applies to the settings service (`AppHostSettingsService::configKeys()`),
the action-auth service, the repair steps, the admin settings, and the deep-link
listener.

## The stub floor (what cannot be deleted)

Nextcloud instantiates a few classes **by class name** read from `info.xml`,
so those classes must physically exist in the app's namespace. `Bootstrap`
binds them to the generics via factory closures, so the stubs carry **no
logic** — they are one line each:

```php
// lib/Repair/InitializeSettings.php
namespace OCA\PetStore\Repair;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings;
class InitializeSettings extends GenericInitializeSettings {}
```

The complete stub floor:

| Stub | Referenced by | Why it must exist |
|---|---|---|
| `Repair\InitializeSettings` | `info.xml` `<repair-steps>` | NC `RepairStep` loaded by class name. |
| `Repair\InitializeActions` | `info.xml` `<repair-steps>` | same. |
| `Settings\AdminSettings` | `info.xml` `<settings><admin>` + `#[AuthorizedAdminSetting(AdminSettings::class)]` | NC `ISettings` + attribute target. |
| `Sections\SettingsSection` | `info.xml` `<settings><admin-section>` | NC `IIconSection` loaded by class name. |
| `Application.php` | NC app loader | resolves controllers via the app's DI container; hosts the `Bootstrap::register()` call. |
| `appinfo/routes.php` | NC router | only read from the app dir; one `return` statement. |

Everything else — `DashboardController`, `PreferencesController`,
`SettingsController`, `HealthController`, `MetricsController`, `SettingsService`,
`ActionAuthService`, `DeepLinkRegistrationListener` — is provided by the generics
and needs **no** file in the leaf app unless you are overriding it.

## Why not a Composer package?

Shipping the AppHost as a `vendor/` Composer dependency was rejected: Nextcloud
loads every app into one shared PHP process, so two apps vendoring different
versions of the same `OCA\OpenRegister\AppHost\…` class would collide at the
class-loader level. Owning the classes in OpenRegister (a hard dependency of
every Conduction app already, per ADR-022) avoids the collision entirely.
