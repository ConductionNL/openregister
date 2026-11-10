# RegisterResolverService

`OCA\OpenRegister\Service\RegisterResolverService` is a small, DI-friendly
public service that consumer apps use to resolve `<context>_register` and
`<context>_schema` `IAppConfig` keys into either bare slug/UUID strings or
hydrated `Register` / `Schema` entities. It replaces the duplicated
`getValueString(...)` + manual mapper-lookup pattern observed across
opencatalogi (5 controllers), pipelinq (8 services / jobs), and docudesk
(`OpenRegisterResolver` helper).

The service is **multi-tenant aware** — it goes through the same
`RegisterMapper` / `SchemaMapper` `find()` calls every other OR code path
takes, so `MultiTenancyTrait::applyOrganisationFilter` is honoured for
free. An explicit `$organisationUuid` override on every resolve method is
available for cross-tenant admin tooling. Resolved entities are cached at
the request scope so a single PHP request only pays one mapper hit per
`(appId, configKey, organisationUuid)` tuple.

## Naming convention

| Form                       | When to use                                              | Example                  |
|---------------------------|----------------------------------------------------------|--------------------------|
| `<context>_register`      | Preferred. New code MUST use this shape.                 | `theme_register`         |
| `<context>_schema`        | Preferred. New code MUST use this shape.                 | `listing_schema`         |
| Bare `register` / `schema`| Grandfathered for legacy consumers (pipelinq).           | `register`               |

`enumerateAppConfigs()` surfaces the bare keys as `default_register` /
`default_schema` so admin UIs can flag them as legacy convention.

## Consuming-app migration

Before — every consumer ships some variant of this:

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

After — the resolver returns a hydrated entity or throws a typed exception:

```php
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;

public function __construct(private readonly RegisterResolverService $resolver) {}

public function showTheme(): JSONResponse {
    try {
        $register = $this->resolver->resolveRegister(
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

    return new JSONResponse($register->jsonSerialize());
}
```

## Diagnostic CLI

A new console command lists every resolver-shaped config key currently
set for an app, mirroring `enumerateAppConfigs()`:

```bash
$ occ openregister:resolver:list opencatalogi
Resolver keys for app "opencatalogi":
  listing_register = cms
  listing_schema = listing-v2
  theme_register = theme-2026
  theme_schema = theme-v1
```

## Public API

| Method                                                                                       | Returns                | Throws                                                                                                  |
|----------------------------------------------------------------------------------------------|------------------------|----------------------------------------------------------------------------------------------------------|
| `resolveRegisterId(appId, configKey, default?, organisationUuid?)`                            | `string`               | `MissingConfigException`                                                                                  |
| `resolveSchemaId(appId, configKey, default?, organisationUuid?)`                              | `string`               | `MissingConfigException`                                                                                  |
| `resolveRegister(appId, configKey, default?, organisationUuid?)`                              | `Register`             | `MissingConfigException`, `RegisterNotFoundException`                                                     |
| `resolveSchema(appId, configKey, default?, organisationUuid?)`                                | `Schema`               | `MissingConfigException`, `SchemaNotFoundException`                                                       |
| `resolvePair(appId, registerKey, schemaKey, organisationUuid?)`                               | `RegisterSchemaPair`   | `MissingConfigException`, `RegisterNotFoundException`, `SchemaNotFoundException`                          |
| `enumerateAppConfigs(appId)`                                                                  | `array<string,string>` | -                                                                                                        |
| `clearCache()`                                                                                | `void`                 | -                                                                                                        |

Exceptions live under `OCA\OpenRegister\Service\Resolver\Exception\` to
avoid name collision with the generic
`OCA\OpenRegister\Exception\RegisterNotFoundException` /
`SchemaNotFoundException` used by the entity layer.
