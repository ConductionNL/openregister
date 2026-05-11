---
title: Entity Serializers
sidebar_position: 4
description: How to use lib/Service/Serializer/ for `_extend`-aware payload assembly
---

# Entity Serializers

Entity serializers under `lib/Service/Serializer/` apply `_extend` post-processing to entity payloads — replacing references with full objects, attaching computed metadata, and producing the same output for every caller (HTTP controllers and DI consumers alike).

## Why a serializer namespace

`Register::jsonSerialize()` returns the raw entity shape (e.g. `schemas` is always an array of IDs). That contract is stable and ID-only by design; it does not know about `_extend`.

When a caller wants `_extend`-aware output (full schema objects, per-schema stats, etc.), they go through a serializer. The HTTP controller and the DI service path share the same serializer, so the wire shape is identical regardless of how the caller reached it.

The namespace is the long-term home for additional entity serializers (`SchemaSerializer`, `ObjectSerializer`, …).

## `RegisterSerializer`

Class: `OCA\OpenRegister\Service\Serializer\RegisterSerializer`

Recognised `_extend` keys:

| Key            | Effect                                                                                                                                                                                            |
|----------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `schemas`      | Replace each schema ID in `schemas` with the full `Schema::jsonSerialize()` output. Orphan IDs (schemas that no longer resolve via `SchemaMapper::find()`) are retained in their original position. |
| `@self.stats`  | Attach `stats.objects.total` to each successfully expanded schema. Only effective alongside `schemas`. Orphan IDs are not annotated.                                                              |

Unknown keys are ignored silently. The serializer never strips `properties` from expanded schemas — consumer-side filtering stays in the consumer.

### Recommended call paths

For HTTP / DI consumers, use the convenience methods on `RegisterService`:

```php
use OCA\OpenRegister\Service\RegisterService;

// Single register, with `_extend` post-processing applied:
$serialized = $registerService->findSerialized(
    id: $registerId,
    _extend: ['schemas', '@self.stats'],
    _multitenancy: false,
);

// Many registers:
$serializedList = $registerService->findAllSerialized(
    filters: [],
    _extend: ['schemas'],
    _multitenancy: false,
);
```

Both methods orchestrate the stats fetch (when `@self.stats` is requested alongside `schemas`) and delegate to `RegisterSerializer`. The entity-returning methods `RegisterService::find()` and `::findAll()` keep their original signatures and return `Register[]` / `Register`; `_extend` on those methods is a documented no-op placeholder for signature compatibility.

### Direct serializer use

If you already hold an entity, inject `RegisterSerializer` and call it directly:

```php
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;

public function __construct(private readonly RegisterSerializer $registerSerializer) {}

public function show(Register $register): array
{
    return $this->registerSerializer->serialize(
        register: $register,
        extend: ['schemas'],
    );
}
```

Pre-computed stats are passed in via `$schemaStats` (single) or `$schemaStatsByRegisterId` (many), which keeps the serializer free of business-logic dependencies and avoids circular DI on `RegisterService`.

### Performance note — `@self.stats` is N+1 per register

`RegisterService::findAllSerialized()` runs one `getSchemaObjectCounts()` query per register in the result set when both `schemas` and `@self.stats` are requested. The pattern existed in the pre-refactor controller and is preserved here. It is acceptable for paginated admin endpoints (~10–50 registers per page) but undesirable for cron jobs and high-volume batch paths. Use the entity-returning `findAll()` and reach for the schema mapper yourself when you need finer control. A batched variant of `getSchemaObjectCounts()` can be added if a workload demonstrates the need.

See also the `_extend=@self.files` warning further up — same N+1 shape, different per-row cost.

### Wire-format note

When `schemas` is in `_extend`, the response's `schemas` field is an array of objects (happy path). When one or more referenced schemas have been deleted, the array is heterogeneous: a mix of objects and bare IDs (in original order). JSON consumers in statically-typed clients (Go, Java, Kotlin) MUST handle both.

## Capability spec

The full contract — recognised keys, output shapes, edge cases, scenarios — lives in the `register-service-extensions` capability spec. See [openspec/specs/register-service-extensions/spec.md](../../../openspec/specs/register-service-extensions/spec.md) once the change is archived.
