# Design: Register Resolver Service

## Reuse analysis

- `OCA\OpenRegister\Db\RegisterMapper` exposes `find()`, `findBySlug()`,
  `findByUuid()`, `findAll()` — the resolver wraps these; no new
  mapper methods.
- `OCA\OpenRegister\Db\SchemaMapper` exposes `find()`, `findBySlug()`,
  `findByUuid()` — same.
- `OCA\OpenRegister\Db\MultiTenancyTrait::applyOrganisationFilter` is
  already called by both mappers; the resolver inherits tenancy
  scoping for free by going through the mappers.
- `\OCP\IAppConfig` is the canonical store for app-level config keys;
  no new persistence layer.
- `\OCA\OpenRegister\Exception\OpenRegisterException` (existing
  base class) is the parent for the three new exceptions; no new
  exception hierarchy.

## Public API shape

```php
namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Resolver\RegisterSchemaPair;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;

final class RegisterResolverService {

    /**
     * Read the configured slug/UUID for a register. Throws
     * MissingConfigException if no value is set and no default is
     * provided.
     *
     * @throws MissingConfigException
     */
    public function resolveRegisterId(
        string $appId,
        string $configKey,
        ?string $default = null,
        ?string $organisationUuid = null,
    ): string;

    /**
     * Same shape for schemas.
     *
     * @throws MissingConfigException
     */
    public function resolveSchemaId(
        string $appId,
        string $configKey,
        ?string $default = null,
        ?string $organisationUuid = null,
    ): string;

    /**
     * Read + hydrate a Register entity. Throws RegisterNotFoundException
     * if the configured slug/UUID resolves to no entity in the caller's
     * tenant.
     *
     * @throws MissingConfigException
     * @throws RegisterNotFoundException
     */
    public function resolveRegister(
        string $appId,
        string $configKey,
        ?string $default = null,
        ?string $organisationUuid = null,
    ): Register;

    /**
     * Same shape for schemas.
     *
     * @throws MissingConfigException
     * @throws SchemaNotFoundException
     */
    public function resolveSchema(
        string $appId,
        string $configKey,
        ?string $default = null,
        ?string $organisationUuid = null,
    ): Schema;

    /**
     * Convenience: resolve a register + schema together. Returns a
     * value object with both entities + the two resolved IDs.
     *
     * @throws MissingConfigException
     * @throws RegisterNotFoundException
     * @throws SchemaNotFoundException
     */
    public function resolvePair(
        string $appId,
        string $registerKey,
        string $schemaKey,
        ?string $organisationUuid = null,
    ): RegisterSchemaPair;

    /**
     * Enumerate every `<context>_(register|schema)` config key
     * currently set for the given app. Used by admin UIs.
     *
     * @return array<string,string> map of config-key → resolved value
     */
    public function enumerateAppConfigs(string $appId): array;
}
```

## Naming convention

The audit found apps using both `<context>_register` and bare
`register`. The convention going forward:

- `<context>_register` — the slug/UUID of the Register an app uses for
  a given context. Examples: `theme_register`, `page_register`,
  `catalog_register`, `listing_register`.
- `<context>_schema` — same shape for schemas. Examples:
  `theme_schema`, `listing_schema`.
- Bare `register` / `schema` is grandfathered (pipelinq's existing
  call sites) but the service treats `register` as
  `default_register` for enumeration purposes.

The capability spec documents this; per-app adoption changes either
adopt the prefixed shape or grandfather their bare keys explicitly.

## Caching behaviour

Request-scoped, NOT cross-request. The cache lives on the service
instance; with NC's DI lifecycle that's per-request. The cache is keyed
by `"{appId}:{configKey}:{organisationUuid|''}"` so an explicit
organisation override doesn't collide with the implicit-tenant case.

Cross-request caching is out of scope. If a register is renamed mid-
request, the resolver returns the stale entity from the cache; that's
acceptable because admin renames trigger an explicit cache flush via
the existing `RegisterMapper` invalidation path.

## Error semantics

| Failure | Exception | HTTP status hint | When |
|---|---|---|---|
| Config key not set + no default | `MissingConfigException` | 500 (server misconfig) | Admin forgot to set the key |
| Config key set, register/schema doesn't exist anywhere | `RegisterNotFoundException` / `SchemaNotFoundException` | 500 (stale config) | Register was deleted but the config still references it |
| Config key set, entity exists but not in caller's tenant | `RegisterNotFoundException` / `SchemaNotFoundException` | 404 (tenant scope) | Multi-tenant install where the caller can't see the configured register |

Distinguishing the last two requires a tenant-scoping-aware check at
the mapper layer; the resolver does it via a fall-through query that
ignores tenant scope and compares results.

## Migration path for consumers

Before:
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

After:
```php
try {
    $register = $this->registerResolver->resolveRegister(
        Application::APP_ID,
        'theme_register'
    );
} catch (MissingConfigException $e) {
    return new JSONResponse([
        'error' => 'Theme register not configured',
        'configKey' => $e->getConfigKey(),
    ], 500);
} catch (RegisterNotFoundException $e) {
    return new JSONResponse([
        'error' => 'Theme register not found',
        'resolvedValue' => $e->getResolvedValue(),
    ], 500);
}
```

Per-app adoption changes carry the bulk replacement.

## Tests

Unit tests cover the five public methods + `enumerateAppConfigs` with
an in-memory IAppConfig mock + mocked mappers. Integration tests
exercise the real Nextcloud DI graph against an in-memory SQLite to
verify the request-scoped cache and the tenant-scoping behaviour.
Newman covers the error-path HTTP shapes from a representative
controller (we'll expose a debug endpoint
`/api/admin/resolver-test/{config-key}` gated on admin only, removed
before merge — exists only to drive the Newman test).

## Open design questions

1. **Should `enumerateAppConfigs` also include bare `register` /
   `schema` keys?** Sketch says yes (treat as `default_register`); the
   admin UI may want to flag those as "legacy convention" so consumers
   migrate. Defer until admin UI surfaces — the data is there
   regardless.
2. **Bulk resolve (multiple pairs at once)?** Out of scope for v1;
   add `resolvePairs(array $specs)` if usage patterns surface.
3. **Frontend equivalent?** Out of scope. The FE consumes resolved
   values via OR's REST surface (e.g. `GET /api/registers/{slug}`);
   no need to ship a TypeScript helper.
