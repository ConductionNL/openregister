# RegisterSerializer — Entity Serialization with `_extend` Support

## Overview

`lib/Service/Serializer/RegisterSerializer` converts Register entities into arrays with optional `_extend` transformations applied. It is the canonical way for any caller — HTTP controller or DI consumer — to obtain a register payload with expanded schemas or per-schema object counts.

## Namespace

`OCA\OpenRegister\Service\Serializer`

This namespace follows the existing subfolder convention under `lib/Service/` (`Archival/`, `Chat/`, `Configuration/`, `Edepot/`, `File/`). Future entity serializers (`SchemaSerializer`, `ObjectSerializer`, …) should be placed here.

## Public API

```php
// Serialize a single register with optional _extend.
public function serialize(
    Register $register,
    array $extend = [],
    ?array $schemaStats = null
): array;

// Serialize many registers with optional _extend.
public function serializeMany(
    array $registers,
    array $extend = [],
    ?array $schemaStatsByRegisterId = null
): array;
```

## Supported `_extend` keys

| Key | Effect |
|---|---|
| `'schemas'` | Replace each schema ID in the `schemas` field with the full schema object from `SchemaMapper`. Ordering is preserved. The `properties` field is included. |
| `'@self.stats'` | Attach `stats.objects.total` to each successfully expanded schema. Only effective when `'schemas'` is also present. Pre-computed stats must be passed in by the caller. |

Unknown keys are silently ignored.

## Missing schema IDs

When `SchemaMapper::find()` throws `DoesNotExistException` for a schema ID, the **original ID is retained in its array position** — it is never dropped. A warning is logged via `LoggerInterface`. This produces a mixed array of objects and bare IDs when some schemas are missing.

This is a deliberate divergence from the pre-refactor controller behaviour (which silently dropped missing IDs) and aligns with OpenRegister's established "preserve original identifier on hydration failure" convention.

## Usage via `RegisterService`

The preferred way to call the serializer from application code is through the service layer:

```php
// Single register with schema expansion.
$registerArr = $registerService->findSerialized(
    id: $registerId,
    _extend: ['schemas']
);

// Multiple registers with schema expansion + per-schema stats.
$registers = $registerService->findAllSerialized(
    limit: 25,
    offset: 0,
    _extend: ['schemas', '@self.stats']
);
```

**Do not** inject `RegisterSerializer` directly into controllers or other services that also consume `RegisterService` — it introduces tight coupling and may create circular DI.

## DI injection

`RegisterSerializer` is registered automatically via Nextcloud's DI container (autowiring). Its constructor takes `SchemaMapper` and `LoggerInterface`.

## Stats pre-computation

When `@self.stats` + `schemas` are both requested, `RegisterService` calls `getSchemaObjectCounts()` up front and passes the result to the serializer. The serializer itself has no dependency on `RegisterService`, avoiding circular DI.

## Why a dedicated serializer?

See `openspec/changes/extend-schemas-in-register-service/design.md` Decision 1. In short: the controller's inline expansion loop was the only place `_extend: ['schemas']` was honoured. DI consumers calling `RegisterService::findAll(_extend: ['schemas'])` received un-expanded ID arrays because the mapper never acted on `_extend`. The serializer centralises expansion so HTTP and DI consumers share the same code path.
