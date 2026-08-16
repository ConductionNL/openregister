# Plan: Property-Level RBAC

## Problem Statement

Currently, RBAC is applied at the object level. However, we need finer control where specific properties have different access rules than the object itself.

**Use Case**: The `interneAantekening` property on the `gebruik` schema should only be readable and writable by users belonging to that organisation, while the rest of the object can be read by anyone with the `gebruik-beheerder` group.

## Proposed Solution

Add an `authorization` property to schema property definitions, reusing the same conditional RBAC structure we have at the schema level.

### Schema Property Authorization Structure

```json
{
  "properties": {
    "interneAantekening": {
      "type": "string",
      "title": "Interne Aantekening",
      "authorization": {
        "read": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ],
        "update": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ]
      }
    },
    "naam": {
      "type": "string",
      "title": "Naam"
    }
  }
}
```

This means:
- `interneAantekening`: Only readable/writable if user's active organisation matches the object's `_organisation`
- `naam`: No property-level auth, follows object-level RBAC

## Implementation Components

### 1. Schema Model Updates

**File**: `lib/Db/Schema.php`

- Add validation for property-level `authorization` in `validateProperties()` or new method
- Reuse existing `validateAuthorizationRule()` logic
- Add helper method: `hasPropertyAuthorization(): bool` - returns true if ANY property has non-empty authorization

### 2. Property RBAC Handler

**New File**: `lib/Db/MagicMapper/PropertyRbacHandler.php` (or extend existing)

Responsibilities:
- Check if user can read a specific property on an object
- Check if user can update a specific property on an object
- Reuse dynamic variable resolution (`$organisation`, `$userId`) from MagicRbacHandler
- Reuse match condition evaluation logic

Key methods:
```php
public function canReadProperty(Schema $schema, string $property, array $object): bool
public function canUpdateProperty(Schema $schema, string $property, array $object): bool
public function filterReadableProperties(Schema $schema, array $object): array
public function filterWritableProperties(Schema $schema, array $object, array $incomingData): array
```

### 3. Incoming Data - ValidationHandler

**File**: `lib/Service/Object/ValidationHandler.php` (or similar)

Before saving an object:
1. Check if schema has any properties with authorization
2. For each property being modified that has authorization:
   - Evaluate the `update` rules against the object and user context
   - If user cannot update: either strip the property or throw an error

**Decision needed**: Should unauthorized property updates be:
- A) Silently stripped (forgiving)
- B) Throw a validation error (strict)

Recommendation: **Option A** for updates (strip silently), but log a warning. For creates, include all properties since the object doesn't exist yet to match against.

### 4. Outgoing Data - RenderHandler

**File**: `lib/Service/Object/RenderHandler.php`

When rendering objects:
1. Check if schema has any properties with authorization (`hasPropertyAuthorization()`)
2. If yes, for each property with authorization:
   - Evaluate the `read` rules against the object and user context
   - If user cannot read: strip the property from output

### 5. Performance Optimization - ObjectService

**File**: `lib/Service/ObjectService.php`

Current check for complex rendering:
```php
$hasComplexRendering = empty($extend) === false
    || empty($query['_fields'] ?? null) === false
    || empty($query['_filter'] ?? null) === false
    || empty($query['_unset'] ?? null) === false;
```

Add property authorization check:
```php
$hasPropertyAuth = $schema->hasPropertyAuthorization();
$needsRendering = $hasComplexRendering || $hasPropertyAuth;
```

This ensures property filtering happens even without explicit `_extend` etc.

### 6. Nested Objects (_extend)

When extending objects via `_extend`:
1. Load the related object's schema
2. Apply property-level RBAC to the extended object
3. Store filtered extended objects in `@self.objects`

**Important**: The user context remains the same, but each extended object is evaluated against its own schema's property authorization AND its own `_organisation` value.

## Data Flow

### Read Flow
```
Request → ObjectService → RenderHandler
                              ↓
                    Check schema.hasPropertyAuthorization()
                              ↓
                    If yes: PropertyRbacHandler.filterReadableProperties()
                              ↓
                    Return filtered object
```

### Write Flow
```
Request → ValidationHandler → PropertyRbacHandler.filterWritableProperties()
                                        ↓
                              Strip unauthorized property changes
                                        ↓
                              Continue with save
```

## Edge Cases

1. **Object creation**: On create, there's no existing object to match against. Options:
   - Allow all properties on create (authorization only applies to read/update)
   - Use the incoming data itself for matching (risky - user controls the match data)
   - Require explicit `create` authorization rule

   **Recommendation**: Allow all properties on create, property auth primarily for read/update.

2. **Admin users**: Admin users should bypass property-level RBAC (same as object-level)

3. **Null organisation**: If object has no `_organisation` and rule matches against `$organisation`:
   - Current behavior: condition not met
   - Should be consistent with object-level RBAC

4. **Caching**: Property authorization evaluation per-object could be expensive for large result sets. Consider:
   - Caching schema property auth check results
   - Batch evaluation where possible

## Files to Modify/Create

1. `lib/Db/Schema.php` - Add property authorization validation and `hasPropertyAuthorization()`
2. `lib/Db/MagicMapper/PropertyRbacHandler.php` - **NEW** - Property-level RBAC evaluation
3. `lib/Service/Object/RenderHandler.php` - Apply property filtering on output
4. `lib/Service/Object/ValidationHandler.php` or `SaveObject.php` - Apply property filtering on input
5. `lib/Service/Object/QueryHandler.php` - Add property auth check to rendering decision
6. `website/docs/features/property-authorization.md` - **NEW** - Documentation

## Decisions Made

1. **Unauthorized property updates**: Throw validation errors (strict mode)
2. **Object creation**: Property authorization rules apply on create, EXCEPT for organisation matching (since there's no existing object to match against)
3. **Implementation level**: PHP-level filtering only (RenderHandler for output, ValidationHandler for input)
4. **Handler**: Create new `PropertyRbacHandler.php` since it applies to both magic mapper AND blob objects

## Recommended Implementation Order

1. Schema model updates (validation, hasPropertyAuthorization)
2. PropertyRbacHandler with basic evaluation logic
3. RenderHandler integration (outgoing data)
4. ValidationHandler integration (incoming data)
5. ObjectService/QueryHandler performance optimization
6. Nested object handling
7. Documentation
8. Testing
