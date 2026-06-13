# Spec: Register Resolver Service

## Overview

`RegisterResolverService` is a first-class service in OpenRegister's public service layer that resolves `Register` and `Schema` entities from `IAppConfig` keys. Consumer apps inject it to avoid the hand-rolled 4-6-line boilerplate that the audit found duplicated across 13 call sites.

## Config Key Naming Convention

All config keys that identify a Register or Schema **MUST** follow this convention:

| Pattern | Meaning |
|---------|---------|
| `<context>_register` | Slug or UUID of the Register for a given context |
| `<context>_schema` | Slug or UUID of the Schema for a given context |
| `register` | Grandfathered bare key (treated as `default_register` for enumeration) |
| `schema` | Grandfathered bare key (treated as `default_schema` for enumeration) |

Examples: `theme_register`, `theme_schema`, `page_register`, `listing_schema`.

Using `enumerateAppConfigs()` to inventory configured keys relies on this convention.

---

## Requirement 1 — resolveRegisterId

**Statement:** Given a valid `<appId>` and `<configKey>`, the service returns the configured slug/UUID string.

### Scenario 1.1 — Happy path

**Given** app `myapp` has config key `theme_register` set to `themes`.  
**When** `resolveRegisterId('myapp', 'theme_register')` is called.  
**Then** the return value is `'themes'`.

### Scenario 1.2 — Missing config, no default

**Given** app `myapp` has no config key `theme_register`.  
**When** `resolveRegisterId('myapp', 'theme_register')` is called with no default.  
**Then** `MissingConfigException` is thrown with `getAppId() === 'myapp'` and `getConfigKey() === 'theme_register'`.

### Scenario 1.3 — Missing config with default

**Given** app `myapp` has no config key `theme_register`.  
**When** `resolveRegisterId('myapp', 'theme_register', default: 'fallback')` is called.  
**Then** the return value is `'fallback'`.

---

## Requirement 2 — resolveSchemaId

**Statement:** Same contract as Requirement 1 but for schema config keys.

### Scenario 2.1 — Happy path

**Given** app `myapp` has config key `theme_schema` set to `themes-schema`.  
**When** `resolveSchemaId('myapp', 'theme_schema')` is called.  
**Then** the return value is `'themes-schema'`.

### Scenario 2.2 — Missing config, no default

**Given** app `myapp` has no config key `theme_schema`.  
**When** `resolveSchemaId('myapp', 'theme_schema')` is called.  
**Then** `MissingConfigException` is thrown.

---

## Requirement 3 — resolveRegister

**Statement:** Given a valid config key, the service returns a hydrated `Register` entity scoped to the caller's organisation.

### Scenario 3.1 — Happy path

**Given** app `myapp` has `theme_register = 'themes'` and a Register with slug `themes` exists in the caller's tenant.  
**When** `resolveRegister('myapp', 'theme_register')` is called.  
**Then** the returned value is the `Register` entity with slug `themes`.

### Scenario 3.2 — Register not found in any scope

**Given** app `myapp` has `theme_register = 'deleted-reg'` and no Register with that slug exists.  
**When** `resolveRegister('myapp', 'theme_register')` is called.  
**Then** `RegisterNotFoundException` is thrown with `getResolvedValue() === 'deleted-reg'`.

### Scenario 3.3 — Request-scoped cache

**Given** `resolveRegister('myapp', 'theme_register')` was called once in the same request.  
**When** `resolveRegister('myapp', 'theme_register')` is called again.  
**Then** the mapper is NOT called a second time (cache hit).

---

## Requirement 4 — resolveSchema

**Statement:** Same contract as Requirement 3 but for schemas.

### Scenario 4.1 — Happy path

**Given** app `myapp` has `theme_schema = 'themes-schema'` and a Schema with that slug exists.  
**When** `resolveSchema('myapp', 'theme_schema')` is called.  
**Then** the returned value is the `Schema` entity with that slug.

### Scenario 4.2 — Schema not found

**Given** app `myapp` has `theme_schema = 'stale-schema'` and no Schema with that slug exists.  
**When** `resolveSchema('myapp', 'theme_schema')` is called.  
**Then** `SchemaNotFoundException` is thrown with `getResolvedValue() === 'stale-schema'`.

---

## Requirement 5 — resolvePair

**Statement:** `resolvePair` resolves a Register + Schema together and returns an immutable `RegisterSchemaPair`.

### Scenario 5.1 — Happy path

**Given** `theme_register = 'themes'` and `theme_schema = 'themes-schema'` are both configured and their entities exist.  
**When** `resolvePair('myapp', 'theme_register', 'theme_schema')` is called.  
**Then** the returned `RegisterSchemaPair` has `getRegister()` and `getSchema()` set to the respective entities, and `getResolvedRegisterId()` / `getResolvedSchemaId()` return the raw configured strings.

### Scenario 5.2 — Register missing propagates exception

**Given** `theme_register` resolves to a non-existent register.  
**When** `resolvePair(...)` is called.  
**Then** `RegisterNotFoundException` is thrown before schema resolution is attempted.

---

## Requirement 6 — enumerateAppConfigs

**Statement:** `enumerateAppConfigs(string $appId)` returns all `<context>_register` and `<context>_schema` config keys set for the given app.

### Scenario 6.1 — Happy path

**Given** app `myapp` has keys `theme_register`, `listing_schema`, `api_token`, and `register`.  
**When** `enumerateAppConfigs('myapp')` is called.  
**Then** the result map contains `theme_register`, `listing_schema`, and `register`, but NOT `api_token`.

### Scenario 6.2 — No matching keys

**Given** app `myapp` has only `api_token` and `debug` keys.  
**When** `enumerateAppConfigs('myapp')` is called.  
**Then** the result is an empty array.
