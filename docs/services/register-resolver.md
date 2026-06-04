# RegisterResolverService

`OCA\OpenRegister\Service\RegisterResolverService` is a DI-injectable service that resolves `Register` and `Schema` entities from `IAppConfig` keys. Consumer apps inject it instead of hand-rolling the 4-6-line inline resolver that the 2026-05-03 OR-abstraction audit found duplicated across 13 call sites in opencatalogi, pipelinq, and docudesk.

The service provides clear domain exceptions, request-scoped caching, tenant-aware resolution, and an enumeration method for admin-UI inventory of configured keys.

## Config Key Naming Convention

Every config key that identifies a Register or Schema **must** follow the `<context>_register` / `<context>_schema` shape. Examples: `theme_register`, `listing_schema`, `page_register`. Bare `register` / `schema` keys are grandfathered (pipelinq's existing call sites) but consumer apps adopting this service should use the prefixed form so admin tooling can enumerate them uniformly.

## Consuming App Migration

**Before** (inline boilerplate — typical pipelinq / opencatalogi pattern):

```php
$registerSlug = $this->appConfig->getValueString(
    Application::APP_ID,
    'theme_register',
    ''
);
if ($registerSlug === '') {
    return new JSONResponse(['error' => 'Theme register not configured'], 500);
}
$register = $this->registerMapper->findBySlug($registerSlug);
```

**After** (service call):

```php
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;

// Inject RegisterResolverService via constructor.

try {
    $register = $this->registerResolver->resolveRegister(
        Application::APP_ID,
        'theme_register'
    );
} catch (MissingConfigException $e) {
    return new JSONResponse([
        'error'     => 'Theme register not configured',
        'configKey' => $e->getConfigKey(),
    ], 500);
} catch (RegisterNotFoundException $e) {
    return new JSONResponse([
        'error'         => 'Theme register not found',
        'resolvedValue' => $e->getResolvedValue(),
    ], 500);
}
```

## Available Methods

| Method | Returns | Throws |
|--------|---------|--------|
| `resolveRegisterId($appId, $configKey, $default, $org)` | `string` | `MissingConfigException` |
| `resolveSchemaId($appId, $configKey, $default, $org)` | `string` | `MissingConfigException` |
| `resolveRegister($appId, $configKey, $default, $org)` | `Register` | `MissingConfigException`, `RegisterNotFoundException` |
| `resolveSchema($appId, $configKey, $default, $org)` | `Schema` | `MissingConfigException`, `SchemaNotFoundException` |
| `resolvePair($appId, $registerKey, $schemaKey, $org)` | `RegisterSchemaPair` | `MissingConfigException`, `RegisterNotFoundException`, `SchemaNotFoundException` |
| `enumerateAppConfigs($appId)` | `array<string,string>` | — |
| `clearCache()` | `void` | — |

## Admin CLI

```bash
php occ openregister:resolver:list <app-id>
```

Prints all `<context>_register` / `<context>_schema` config keys currently set for the given app. Useful for diagnosing stale slugs or misconfigured register/schema references.
