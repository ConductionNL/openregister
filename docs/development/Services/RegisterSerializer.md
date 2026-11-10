# RegisterSerializer

`OCA\OpenRegister\Service\Serializer\RegisterSerializer`

**Filesystem path:** `lib/Service/Serializer/RegisterSerializer.php`

## Purpose

Serializes `Register` entities to arrays with optional `_extend` post-processing. Any caller — HTTP controller or DI consumer — that needs fully expanded registers (schema objects instead of bare IDs, per-schema object counts) uses this class.

Prior to this serializer, schema expansion only happened inside `RegistersController::index()`. Any app calling `RegisterService::findAll(_extend: ['schemas'])` via DI received ID-only schemas because `_extend` was silently dropped at the mapper layer.

## Methods

### `serialize(Register $register, array $extend = [], ?array $schemaStats = null): array`

Serializes a single register entity. Calls `$register->jsonSerialize()` for the base shape, then applies each recognized `_extend` key.

**Parameters**

| Parameter | Type | Description |
|---|---|---|
| `$register` | `Register` | The register entity to serialize. |
| `$extend` | `string[]` | Extension keys (`'schemas'`, `'@self.stats'`). |
| `$schemaStats` | `array<int, array>\|null` | Pre-computed schema object counts (keyed by schema ID). Pass the result of `RegisterService::getSchemaObjectCounts()`. Required when `'@self.stats'` is in `$extend`; ignored otherwise. |

**Returns** `array` — serialized register array with extensions applied.

### `serializeMany(array $registers, array $extend = [], ?array $schemaStatsByRegisterId = null): array`

Serializes multiple register entities. Delegates each to `serialize()`, routing the per-register stats slice via `$schemaStatsByRegisterId[$register->getId()]`.

## Recognized `_extend` keys

| Key | Effect |
|---|---|
| `'schemas'` | Each schema ID in the `schemas` field is replaced by the full schema object (`SchemaMapper::find($id, _multitenancy: false)->jsonSerialize()`). On `DoesNotExistException` the original ID is **kept in its original array position** — not dropped. A warning is logged. |
| `'@self.stats'` | Only effective when `'schemas'` is also in `$extend`. Attaches `stats.objects.total` to each successfully expanded schema object. Defaults to `0` when the ID is absent from `$schemaStats`. Orphan bare IDs are not augmented. |

Unknown keys are silently ignored. No exception, no log entry.

## Usage example (DI consumer)

```php
// Inject via constructor (Nextcloud auto-wires by type):
public function __construct(
    private readonly RegisterService $registerService,
) {}

// In your method:
$registers = $this->registerService->findAllSerialized(
    _extend: ['schemas', '@self.stats'],
);

foreach ($registers as $register) {
    foreach ($register['schemas'] as $schemaOrId) {
        if (is_array($schemaOrId)) {
            // Fully expanded schema object.
            echo $schemaOrId['title'];
        }
        // else: orphan ID — the schema was deleted.
    }
}
```

## Using the service methods directly

`RegisterService` exposes two convenience methods that pre-compute stats and delegate to the serializer:

```php
// Serialize a single register by ID:
$registerArr = $this->registerService->findSerialized(
    id: $id,
    _extend: ['schemas'],
    _multitenancy: false
);

// Serialize all registers with pagination:
$registersArr = $this->registerService->findAllSerialized(
    limit: 25,
    offset: 0,
    _extend: ['schemas', '@self.stats'],
    _multitenancy: false
);
```

The entity-returning `findAll()` / `find()` methods keep their current signatures and entity return types. The `_extend` parameter on those methods is declared for signature compatibility only and has no effect.

## Namespace conventions

`lib/Service/Serializer/` follows the existing subfolder convention in `lib/Service/` (`Archival/`, `Chat/`, `Configuration/`, `Edepot/`, `File/`). Future entity serializers (`SchemaSerializer`, `ObjectSerializer`) should be placed alongside `RegisterSerializer` in this namespace.

## Related

- `RegisterService::findAllSerialized()` / `::findSerialized()` — orchestrate DB queries and delegate to this serializer.
- `RegistersController::index()` — uses `findAllSerialized()` since the refactor; no longer contains inline expansion logic.
- `Register::jsonSerialize()` — unchanged; always returns schemas as an array of IDs. Expansion is a serializer concern.
