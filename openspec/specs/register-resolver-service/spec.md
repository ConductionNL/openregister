---
status: done
---

# register-resolver-service Specification

## Purpose

Provide a single, DI-resolvable PHP service in OpenRegister that consumer apps use to resolve `<context>_register` / `<context>_schema` `IAppConfig` keys into either bare slug/UUID strings or hydrated `Register` / `Schema` entities, with consistent error semantics, request-scoped caching, and multi-tenant scoping. Replaces the duplicated `getValueString(...)` + manual mapper-lookup pattern observed in 13 call sites across opencatalogi (5 controllers), pipelinq (8 services / jobs), and docudesk (`OpenRegisterResolver`).
## Requirements
### Requirement: The system MUST expose a `RegisterResolverService` resolving register IDs from app config

The service MUST expose `resolveRegisterId(string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): string` that reads `IAppConfig::getValueString($appId, $configKey, '')`, falls back to `$default` if the value is empty, and throws `MissingConfigException` if both are unset/empty. The returned string is the configured slug or UUID; the service does NOT validate that the value resolves to an entity (callers use `resolveRegister` for that).

#### Scenario: Resolve a configured register slug
- GIVEN `theme_register = 'theme-2026'` is set in app config for app `opencatalogi`
- WHEN `resolveRegisterId('opencatalogi', 'theme_register')` is called
- THEN the service MUST return `'theme-2026'`

#### Scenario: Fall back to provided default when config is empty
- GIVEN `theme_register` is unset for app `opencatalogi`
- WHEN `resolveRegisterId('opencatalogi', 'theme_register', 'theme-default')` is called
- THEN the service MUST return `'theme-default'`

#### Scenario: Throw MissingConfigException when no value and no default
- GIVEN `theme_register` is unset for app `opencatalogi`
- WHEN `resolveRegisterId('opencatalogi', 'theme_register')` is called with no default
- THEN the service MUST throw `MissingConfigException`
- AND the exception MUST expose `getAppId() === 'opencatalogi'` and `getConfigKey() === 'theme_register'`

### Requirement: The system MUST expose a `resolveSchemaId` method with the same shape

The service MUST expose `resolveSchemaId(string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): string` mirroring `resolveRegisterId` for schema config keys. Naming convention: `<context>_schema`.

#### Scenario: Resolve a configured schema slug
- GIVEN `listing_schema = 'listing-v2'` is set for app `opencatalogi`
- WHEN `resolveSchemaId('opencatalogi', 'listing_schema')` is called
- THEN the service MUST return `'listing-v2'`

### Requirement: The system MUST expose `resolveRegister` returning a hydrated Register entity

The service MUST expose `resolveRegister(string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): Register` that combines `resolveRegisterId` with a `RegisterMapper` lookup. The lookup MUST honour multi-tenancy (via `MultiTenancyTrait::applyOrganisationFilter`). Throws `MissingConfigException` if the config is unset, `RegisterNotFoundException` if the configured slug/UUID doesn't resolve in the caller's tenant.

#### Scenario: Resolve a configured register to its entity
- GIVEN `theme_register = 'theme-2026'` is set for app `opencatalogi`
- AND a Register with slug `'theme-2026'` exists in the caller's organisation
- WHEN `resolveRegister('opencatalogi', 'theme_register')` is called
- THEN the service MUST return the `Register` entity for `'theme-2026'`

#### Scenario: Throw RegisterNotFoundException when configured slug doesn't exist in tenant
- GIVEN `theme_register = 'theme-2026'` is set for app `opencatalogi`
- AND no Register with slug `'theme-2026'` is visible in the caller's tenant
- WHEN `resolveRegister('opencatalogi', 'theme_register')` is called
- THEN the service MUST throw `RegisterNotFoundException`
- AND the exception MUST expose `getResolvedValue() === 'theme-2026'`

#### Scenario: Throw RegisterNotFoundException when entity exists in another tenant only
- GIVEN `theme_register = 'theme-2026'` is set for app `opencatalogi`
- AND a Register with slug `'theme-2026'` exists in a different tenant than the caller's
- WHEN `resolveRegister('opencatalogi', 'theme_register')` is called
- THEN the service MUST throw `RegisterNotFoundException` (tenant-scope failure, distinct from `MissingConfigException`)
- AND the exception MUST expose `getResolvedValue() === 'theme-2026'` so callers can log it for admin diagnostics

### Requirement: The system MUST expose `resolveSchema` with the same shape

The service MUST expose `resolveSchema(string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): Schema` mirroring `resolveRegister` for schemas. Throws `MissingConfigException` or `SchemaNotFoundException`.

#### Scenario: Resolve a configured schema to its entity
- GIVEN `listing_schema = 'listing-v2'` is set for app `opencatalogi`
- AND a Schema with slug `'listing-v2'` exists in the caller's tenant
- WHEN `resolveSchema('opencatalogi', 'listing_schema')` is called
- THEN the service MUST return the `Schema` entity for `'listing-v2'`

### Requirement: The system MUST expose a `resolvePropertyId` method for property identifiers

The service MUST expose `resolvePropertyId(string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): string` mirroring `resolveRegisterId`/`resolveSchemaId` for property config keys. Naming convention: `<context>_property`. The returned string is the configured property identifier (slug, name, or UUID) — the resolver does NOT validate that it matches any schema property (callers use `resolveProperty` for that).

#### Scenario: Resolve a configured property identifier
- GIVEN `swc_type_property = 'id-9358c742-a631-47b5-80d4-f8e69b3a5d12'` is set in app config for app `openconnector`
- WHEN `resolvePropertyId('openconnector', 'swc_type_property')` is called
- THEN the service MUST return `'id-9358c742-a631-47b5-80d4-f8e69b3a5d12'`

#### Scenario: Fall back to provided default when config is empty
- GIVEN `swc_type_property` is unset for app `openconnector`
- WHEN `resolvePropertyId('openconnector', 'swc_type_property', 'id-default')` is called
- THEN the service MUST return `'id-default'`

#### Scenario: Throw MissingConfigException when no value and no default
- GIVEN `swc_type_property` is unset for app `openconnector`
- WHEN `resolvePropertyId('openconnector', 'swc_type_property')` is called with no default
- THEN the service MUST throw `MissingConfigException`

### Requirement: The system MUST expose `resolveProperty` to hydrate a property definition

The service MUST expose `resolveProperty(string $appId, string $schemaConfigKey, string $propertyConfigKey, ?string $propertyDefault = null, ?string $schemaDefault = null, ?string $organisationUuid = null): array` that combines `resolveSchema` with a property lookup on the resolved schema. The configured property identifier is matched against the property's array key, its `slug`, its `name`, or its `id` (in that order). The return value is a tuple `[string $propertyKey, array $propertyDefinition]` where `$propertyKey` is the property's array key on `Schema::getProperties()` (also the canonical name used in object payloads) and `$propertyDefinition` is the raw definition array. Throws `MissingConfigException` if either config is unset, `SchemaNotFoundException` if the schema doesn't resolve, `PropertyNotFoundException` if no property on the schema matches the identifier.

#### Scenario: Resolve a configured property to its definition
- GIVEN `swc_schema = 'extendview'` and `swc_type_property = 'type'` are set for app `openconnector`
- AND the schema `extendview` has a property keyed `'type'` with definition `['type' => 'string', 'id' => 'prop-7']`
- WHEN `resolveProperty('openconnector', 'swc_schema', 'swc_type_property')` is called
- THEN the service MUST return `['type', ['type' => 'string', 'id' => 'prop-7']]`

#### Scenario: Resolve a property matched by its `id` field
- GIVEN `swc_schema = 'extendview'` and `swc_type_property = 'prop-7'` are set for app `openconnector`
- AND the schema `extendview` has a property keyed `'type'` with definition `['type' => 'string', 'id' => 'prop-7']`
- WHEN `resolveProperty('openconnector', 'swc_schema', 'swc_type_property')` is called
- THEN the service MUST return `['type', ['type' => 'string', 'id' => 'prop-7']]`

#### Scenario: Throw PropertyNotFoundException when no property matches
- GIVEN `swc_schema = 'extendview'` and `swc_type_property = 'does-not-exist'` are set for app `openconnector`
- AND the schema `extendview` has no property matching that key, slug, name, or id
- WHEN `resolveProperty('openconnector', 'swc_schema', 'swc_type_property')` is called
- THEN the service MUST throw `PropertyNotFoundException`
- AND the exception MUST expose `getResolvedValue() === 'does-not-exist'` and `getSchemaIdentifier()` returning the schema slug/UUID

### Requirement: The system MUST expose `resolvePair` for the register + schema convenience case

The service MUST expose `resolvePair(string $appId, string $registerKey, string $schemaKey, ?string $organisationUuid = null): RegisterSchemaPair`. The returned `RegisterSchemaPair` is a readonly value object exposing `getRegisterId(): string`, `getSchemaId(): string`, `getRegister(): Register`, `getSchema(): Schema`. Internally `resolvePair` calls `resolveRegister` + `resolveSchema` and surfaces their exceptions unchanged.

#### Scenario: Resolve a register + schema pair
- GIVEN `listing_register = 'cms'` and `listing_schema = 'listing-v2'` are both set for app `opencatalogi`
- AND both entities exist in the caller's tenant
- WHEN `resolvePair('opencatalogi', 'listing_register', 'listing_schema')` is called
- THEN the service MUST return a `RegisterSchemaPair` where `getRegisterId() === 'cms'`, `getSchemaId() === 'listing-v2'`, and both entity getters return non-null

### Requirement: The service MUST cache resolved entities at the request scope

Within a single PHP request, repeated `resolveRegister` / `resolveSchema` calls with the same `(appId, configKey, organisationUuid)` tuple MUST hit the cache and NOT call the underlying mapper a second time. The cache MUST clear at request boundary (no cross-request caching).

#### Scenario: Repeated resolve hits the cache
- GIVEN the resolver is fresh (no cached entries)
- AND `resolveRegister('opencatalogi', 'theme_register')` is called once and returns a Register entity
- WHEN `resolveRegister('opencatalogi', 'theme_register')` is called a second time within the same request
- THEN the underlying `RegisterMapper::findBySlug()` MUST be called exactly once across both invocations
- AND the second call MUST return the same Register instance (object identity preserved)

### Requirement: The system MUST expose `enumerateAppConfigs` for diagnostic surfaces

The service MUST expose `enumerateAppConfigs(string $appId): array<string,string>` returning a map of every `<context>_register`, `<context>_schema`, and `<context>_property` config key currently set for `$appId`, paired with its raw `IAppConfig` value (no entity resolution). Used by admin UIs and the `php occ openregister:resolver:list <app-id>` console command.

#### Scenario: Enumerate every configured resolver key for an app
- GIVEN app `opencatalogi` has `theme_register = 'theme-2026'`, `theme_schema = 'theme-v1'`, `listing_register = 'cms'` configured, plus the unrelated `auto_listing_threshold = '500'` config key
- WHEN `enumerateAppConfigs('opencatalogi')` is called
- THEN the service MUST return `['theme_register' => 'theme-2026', 'theme_schema' => 'theme-v1', 'listing_register' => 'cms']`
- AND the map MUST NOT include `auto_listing_threshold` (does not match the `<context>_(register|schema)` pattern)

### Requirement: The naming convention `<context>_register` / `<context>_schema` MUST be documented and recommended

The service's documentation (in `docs/services/register-resolver.md` and the public PHPDoc on each resolve method) MUST state:

1. Consumer apps SHOULD name register config keys `<context>_register` (e.g. `theme_register`, `listing_register`).
2. Consumer apps SHOULD name schema config keys `<context>_schema` (e.g. `listing_schema`, `theme_schema`).
3. Consumer apps SHOULD name property config keys `<context>_property` (e.g. `swc_type_property`, `publish_property`).
4. The bare key `register` / `schema` is grandfathered for legacy consumers; new code MUST NOT introduce bare keys.
5. The `enumerateAppConfigs()` method MUST surface bare keys as `default_register` / `default_schema` so admin UIs can flag them as legacy convention.

#### Scenario: Documentation surfaces the convention
- GIVEN a consumer-app developer reads OpenRegister's service docs
- WHEN they read `docs/services/register-resolver.md`
- THEN the page MUST state the convention explicitly
- AND the example migration snippet MUST show `<context>_register` (not bare `register`)

