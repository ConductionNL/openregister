# Design: referential-integrity-on-delete

## Architecture Overview

Referential integrity on delete integrates into the existing `DeleteObject` service. Before any soft-delete mutation, a `ReferentialIntegrityService` builds a deletion graph by walking schema relations with `onDelete` configuration. If the graph contains a RESTRICT blocker, the deletion is aborted with a 409 response. Otherwise, cascades, nullifications, and default-sets are applied in a single transaction-like flow.

```
Controller          DeleteObject         ReferentialIntegrityService       ObjectEntityMapper
   │                     │                          │                          │
   ├─ DELETE object ────►│                          │                          │
   │                     ├─ canDelete(object) ─────►│                          │
   │                     │                          ├─ buildRelationIndex()    │
   │                     │                          │  (cached per-request)    │
   │                     │                          ├─ walkDeletionGraph()     │
   │                     │                          │  ├─ find dependents      │
   │                     │                          │  ├─ check onDelete       │
   │                     │                          │  ├─ recurse CASCADE      │
   │                     │                          │  └─ detect RESTRICT      │
   │                     │◄── DeletionAnalysis ─────┤                          │
   │                     │                          │                          │
   │                     ├─ [if blocked] ──────────►│                          │
   │◄── 409 Conflict ───┤                          │                          │
   │                     │                          │                          │
   │                     ├─ [if deletable] ────────►│                          │
   │                     │                          ├─ applyCascades() ───────►│ soft-delete
   │                     │                          ├─ applySetNull() ────────►│ update
   │                     │                          ├─ applySetDefault() ─────►│ update
   │                     │◄─────────────────────────┤                          │
   │                     ├─ soft-delete source ────►│                          │ soft-delete
   │◄── 200 OK ─────────┤                          │                          │
```

**canDelete API flow:**
```
Controller          ReferentialIntegrityService
   │                          │
   ├─ GET .../can-delete ────►│
   │                          ├─ buildRelationIndex()
   │                          ├─ walkDeletionGraph()
   │◄── DeletionAnalysis ────┤
   ├─ 200 {deletable, ...}   │
```

## Schema Property Extension: onDelete

The `onDelete` field is added to relation property definitions within a schema's `properties` JSON. No database migration needed — it's stored inside the existing properties JSON column.

### Property Configuration

```json
{
  "properties": {
    "person": {
      "type": "string",
      "$ref": "person-schema",
      "inversedBy": "contactDetails",
      "onDelete": "CASCADE"
    },
    "serviceType": {
      "type": "string",
      "$ref": "service-type-schema",
      "onDelete": "RESTRICT"
    },
    "coupon": {
      "type": "string",
      "$ref": "coupon-schema",
      "onDelete": "SET_NULL"
    },
    "assignee": {
      "type": "string",
      "$ref": "person-schema",
      "onDelete": "SET_DEFAULT",
      "default": "unassigned-uuid"
    },
    "logs": {
      "type": "array",
      "items": { "$ref": "log-schema" },
      "onDelete": "NO_ACTION"
    }
  }
}
```

**Important**: `onDelete` is configured on the **dependent** schema's property (the schema that holds the `$ref`). This means: "when the object I'm referencing is deleted, do X to me."

### Validation Rules

Schema validation MUST check:
1. `onDelete` is only valid on properties with `$ref` (or items with `$ref`)
2. Value must be one of: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION`
3. `SET_NULL` on a `required` property is invalid (falls back to RESTRICT at runtime, but should warn at configuration time)
4. `SET_DEFAULT` without a `default` value is valid (falls back to SET_NULL → RESTRICT chain at runtime)

## DeletionAnalysis Value Object

```php
class DeletionAnalysis
{
    public function __construct(
        public readonly bool $deletable,
        public readonly array $cascadeTargets,   // [{uuid, schema, property, chain}]
        public readonly array $nullifyTargets,   // [{uuid, schema, property}]
        public readonly array $defaultTargets,   // [{uuid, schema, property, defaultValue}]
        public readonly array $blockers,         // [{uuid, schema, property, action, chain}]
        public readonly array $chainPaths,       // Full graph paths for debugging
    ) {}

    public function toArray(): array { /* ... */ }
}
```

## ReferentialIntegrityService

### Relation Index

On first call per request, build a reverse index from schema definitions:

```php
// Schema slug → array of {sourceSchema, property, onDelete, isArray}
private array $relationIndex = [];

// Example index:
// "person-schema" => [
//   {sourceSchema: "contact-detail", property: "person", onDelete: "CASCADE", isArray: false},
//   {sourceSchema: "service", property: "manager", onDelete: "RESTRICT", isArray: false},
//   {sourceSchema: "project", property: "contributors", onDelete: "SET_NULL", isArray: true},
// ]
```

This tells us: "when a person-schema object is deleted, these schemas care."

**Optimization**: Only schemas with at least one `onDelete` property (other than NO_ACTION) appear in this index. Schemas with no onDelete config are completely skipped.

### Graph Walking Algorithm

```php
public function walkDeletionGraph(
    ObjectEntity $object,
    array &$visited = [],
    array $chain = []
): DeletionAnalysis {
    // 1. Cycle detection
    if (in_array($object->getUuid(), $visited)) {
        return DeletionAnalysis::empty(deletable: true);
    }
    $visited[] = $object->getUuid();

    // 2. Look up who depends on this object's schema
    $dependents = $this->relationIndex[$object->getSchema()] ?? [];
    if (empty($dependents)) {
        return DeletionAnalysis::empty(deletable: true);
    }

    $cascadeTargets = [];
    $nullifyTargets = [];
    $defaultTargets = [];
    $blockers = [];

    // 3. For each dependent schema with onDelete config
    foreach ($dependents as $dep) {
        // Find actual objects that reference this object
        $referencingObjects = $this->findReferencingObjects(
            $dep['sourceSchema'], $dep['property'], $object->getUuid()
        );

        foreach ($referencingObjects as $refObj) {
            $currentChain = [...$chain, "{$object->getUuid()} → {$refObj->getUuid()} ({$dep['onDelete']})"];

            switch ($dep['onDelete']) {
                case 'RESTRICT':
                    $blockers[] = [
                        'objectUuid' => $refObj->getUuid(),
                        'schema' => $dep['sourceSchema'],
                        'property' => $dep['property'],
                        'action' => 'RESTRICT',
                        'chain' => $currentChain,
                    ];
                    break;

                case 'CASCADE':
                    $cascadeTargets[] = [
                        'objectUuid' => $refObj->getUuid(),
                        'schema' => $dep['sourceSchema'],
                        'property' => $dep['property'],
                        'chain' => $currentChain,
                    ];
                    // Recurse: what happens if we cascade-delete this object?
                    $subAnalysis = $this->walkDeletionGraph($refObj, $visited, $currentChain);
                    if (!$subAnalysis->deletable) {
                        // A deeper RESTRICT blocks the entire chain
                        $blockers = array_merge($blockers, $subAnalysis->blockers);
                    }
                    $cascadeTargets = array_merge($cascadeTargets, $subAnalysis->cascadeTargets);
                    $nullifyTargets = array_merge($nullifyTargets, $subAnalysis->nullifyTargets);
                    $defaultTargets = array_merge($defaultTargets, $subAnalysis->defaultTargets);
                    break;

                case 'SET_NULL':
                    if ($this->isRequiredProperty($dep['sourceSchema'], $dep['property'])) {
                        // Falls back to RESTRICT
                        $blockers[] = [
                            'objectUuid' => $refObj->getUuid(),
                            'schema' => $dep['sourceSchema'],
                            'property' => $dep['property'],
                            'action' => 'RESTRICT',
                            'chain' => [...$currentChain, '(SET_NULL on required → RESTRICT)'],
                        ];
                    } else {
                        $nullifyTargets[] = [
                            'objectUuid' => $refObj->getUuid(),
                            'schema' => $dep['sourceSchema'],
                            'property' => $dep['property'],
                        ];
                    }
                    break;

                case 'SET_DEFAULT':
                    $defaultValue = $this->getDefaultValue($dep['sourceSchema'], $dep['property']);
                    if ($defaultValue === null) {
                        // Falls back to SET_NULL → RESTRICT chain
                        if ($this->isRequiredProperty($dep['sourceSchema'], $dep['property'])) {
                            $blockers[] = [
                                'objectUuid' => $refObj->getUuid(),
                                'schema' => $dep['sourceSchema'],
                                'property' => $dep['property'],
                                'action' => 'RESTRICT',
                                'chain' => [...$currentChain, '(SET_DEFAULT no default + required → RESTRICT)'],
                            ];
                        } else {
                            $nullifyTargets[] = [
                                'objectUuid' => $refObj->getUuid(),
                                'schema' => $dep['sourceSchema'],
                                'property' => $dep['property'],
                            ];
                        }
                    } else {
                        $defaultTargets[] = [
                            'objectUuid' => $refObj->getUuid(),
                            'schema' => $dep['sourceSchema'],
                            'property' => $dep['property'],
                            'defaultValue' => $defaultValue,
                        ];
                    }
                    break;

                case 'NO_ACTION':
                default:
                    // Do nothing
                    break;
            }
        }
    }

    return new DeletionAnalysis(
        deletable: empty($blockers),
        cascadeTargets: $cascadeTargets,
        nullifyTargets: $nullifyTargets,
        defaultTargets: $defaultTargets,
        blockers: $blockers,
        chainPaths: $chain,
    );
}
```

### Execution Order

When deletion is approved (no blockers), actions execute in this order:
1. **SET_NULL** — clear references first (these objects survive)
2. **SET_DEFAULT** — set defaults (these objects survive)
3. **CASCADE** — delete dependent objects (deepest first, bottom-up)
4. **Delete source** — soft-delete the original object

Bottom-up cascade ordering prevents intermediate states where a parent is deleted but children still reference it.

## API Endpoint: can-delete

```
GET /api/objects/{register}/{schema}/{id}/can-delete
```

Response (deletable):
```json
{
  "deletable": true,
  "cascadeTargets": [
    {"objectUuid": "uuid-1", "schema": "contact-detail", "property": "person"}
  ],
  "nullifyTargets": [],
  "defaultTargets": [],
  "blockers": []
}
```

Response (blocked):
```json
{
  "deletable": false,
  "cascadeTargets": [],
  "nullifyTargets": [],
  "defaultTargets": [],
  "blockers": [
    {
      "objectUuid": "uuid-2",
      "objectTitle": "Web Service",
      "schema": "service",
      "property": "serviceType",
      "action": "RESTRICT",
      "chain": ["st-uuid → uuid-2 (RESTRICT)"]
    }
  ]
}
```

## DeleteObject Integration

The existing `DeleteObject` service is modified to call `ReferentialIntegrityService` before performing the soft-delete:

```php
// In DeleteObject::deleteObject()

// 1. Build analysis (pre-flight)
$analysis = $this->referentialIntegrityService->canDelete($objectEntity);

// 2. If blocked, return 409
if (!$analysis->deletable) {
    throw new ReferentialIntegrityException($analysis);
    // Controller catches this and returns HTTP 409 with blocker details
}

// 3. Apply mutations
$this->referentialIntegrityService->applyDeletionActions($analysis, $userId);

// 4. Soft-delete the source object (existing logic)
$objectEntity->setDeleted([...]);
```

## File Structure

```
openregister/lib/
  Service/
    Object/
      ReferentialIntegrityService.php    # New — graph walking, index building, action execution
      DeleteObject.php                   # Modified — integrate canDelete() before soft-delete
  Dto/
    DeletionAnalysis.php                 # New — value object for analysis results
  Exception/
    ReferentialIntegrityException.php    # New — thrown when deletion is blocked
  Controller/
    ObjectsController.php               # Modified — add can-delete endpoint, catch 409
  Db/
    Schema.php                           # Modified — onDelete validation in property definitions
```

## Security Considerations

- `canDelete()` requires the same permissions as delete (read access to related schemas is implicit)
- The `can-delete` API endpoint requires delete permission on the object
- CASCADE deletes are performed as the requesting user — all cascaded objects must be deletable by that user
- Graph walking has a maximum depth limit (default 10) to prevent pathological schema configurations from causing stack overflows

## Performance Considerations

- Relation index is built once per request from schema definitions (no object queries)
- Object lookups only happen for schemas that appear in the relation index
- Batch deletes reuse the same relation index
- Already-deleted objects are skipped (no redundant processing)
- The `findReferencingObjects` query uses the existing `relations` JSON column or object properties for efficient lookup

## Trade-offs

| Alternative | Why not |
|---|---|
| Separate relation configuration table | Over-engineering — `onDelete` is a property-level concern and belongs in the schema property definition |
| Database-level foreign keys | OpenRegister uses JSON storage (blob + magic tables) — DB-level FK constraints don't apply to JSON relations |
| Async cascade via background jobs | Dangerous — user expects immediate consistency. A partially-cascaded state is worse than blocking |
| Trigger-based approach (Nextcloud events) | Events are fire-and-forget; we need synchronous analysis before mutation |
| Separate "relation" entity | Adds complexity. The relation is already defined by the `$ref` property — adding `onDelete` to it is natural |
