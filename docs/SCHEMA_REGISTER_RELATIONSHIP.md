# Schema and Register Relationship

## Overview

In OpenRegister, **schemas** and **registers** have an independent, many-to-many relationship. This architectural decision enables maximum flexibility and reusability.

## Key Principles

### 1. Schemas Are Independent Entities

Schemas exist independently of registers and can be:
- Created without being assigned to any register
- Shared across multiple registers
- Modified without affecting unrelated registers
- Deleted independently (with proper cascade handling for objects)

### 2. Schemas Do NOT Cascade Delete

**Important:** When a register is deleted, its associated schemas are **NOT** automatically deleted.

This is **by design** because:
- **Reusability**: Schemas can be used by multiple registers
- **Data Integrity**: Deleting a register should not break other registers using the same schema
- **Explicit Management**: Schema lifecycle is managed independently for safety

```php
// Example: Deleting a register
DELETE /api/registers/{id}

// Result:
// ✅ Register is deleted
// ✅ Objects in that register are deleted
// ❌ Schemas are NOT deleted (they may be used elsewhere)
```

### 3. Register-Schema Association

Schemas are associated with registers through:

1. **Schema Creation**:
   ```json
   POST /api/schemas
   {
     "register": 123,  // Optional: Primary register
     "slug": "person",
     "title": "Person Schema"
   }
   ```

2. **Object Creation**:
   ```json
   POST /api/objects/{register}/{schema}
   {
     "name": "John Doe"
   }
   ```

The schema used in an object determines its structure, regardless of which register it's stored in.

## Use Cases

### Single Schema, Multiple Registers

A "Person" schema can be reused across different registers:

```
Schema: "person"
├── Register: "employees"      (HR department)
├── Register: "customers"      (Sales department)
└── Register: "contractors"    (Operations department)
```

Each register contains different person objects, but they all follow the same schema structure.

### Schema Evolution

Schemas can evolve independently:

1. Update schema definition
2. Affects all registers using that schema
3. Provides consistent data structure across the application

## Deletion Behavior

### Register Deletion

```
DELETE /api/registers/{id}
```

**Deletes:**
- ✅ The register record
- ✅ All objects in that register
- ✅ Register-specific metadata

**Does NOT Delete:**
- ❌ Schemas (independent lifecycle)
- ❌ Objects in other registers

### Schema Deletion

```
DELETE /api/schemas/{id}
```

**Deletes:**
- ✅ The schema definition
- ✅ All objects using that schema (across ALL registers)
- ✅ Schema metadata

**Use with caution** - this affects all registers using the schema!

## Best Practices

### 1. Schema Naming

Use descriptive, generic names that reflect the data structure, not the specific use case:

✅ Good: `person`, `document`, `transaction`  
❌ Bad: `hr-employee`, `sales-customer`

### 2. Schema Versioning

For breaking changes, create a new schema instead of modifying:

```
person-v1  →  person-v2
```

This allows gradual migration without breaking existing data.

### 3. Cleanup Strategy

When deleting registers in tests or cleanup scripts:

```php
// 1. Delete objects
DELETE /api/objects/{register}/{schema}/{uuid}

// 2. Delete register
DELETE /api/registers/{id}

// 3. Optionally delete schemas (if not used elsewhere)
DELETE /api/schemas/{id}
```

**Always check** if a schema is used by other registers before deleting it!

### 4. Schema Discovery

Find which registers use a specific schema:

```
GET /api/objects?schema={schemaId}
```

Group results by register to see usage patterns.

## Database Structure

```
oc_openregister_schemas
├── id (primary key)
├── slug (unique)
├── register (nullable foreign key - primary register reference)
└── ...

oc_openregister_objects  
├── id (primary key)
├── register (foreign key → CASCADES on delete)
├── schema (foreign key → CASCADES on delete)
└── ...
```

**Note:** The `register` field in `schemas` table is **nullable** and serves as a **hint** for the primary register, but does not enforce exclusivity.

## API Endpoints

### Schema Management

```bash
# Create schema
POST /api/schemas
{
  "register": 123,      # Optional primary register
  "slug": "person",
  "title": "Person",
  "properties": { ... }
}

# List schemas
GET /api/schemas
GET /api/schemas?register={id}
GET /api/schemas?slug={slug}

# Get schema
GET /api/schemas/{id}

# Update schema
PUT /api/schemas/{id}

# Delete schema (affects ALL registers!)
DELETE /api/schemas/{id}
```

### Register Management

```bash
# Create register
POST /api/registers
{
  "slug": "employees",
  "title": "Employees"
}

# List schemas in a register
GET /api/registers/{id}/schemas

# Delete register (schemas remain)
DELETE /api/registers/{id}
```

## Migration Considerations

When migrating data between registers:

1. **Same Schema**: Direct move possible
   ```
   PATCH /api/objects/{register}/{schema}/{uuid}
   { "register": newRegisterId }
   ```

2. **Different Schema**: Data transformation required
   - Export from old schema
   - Transform data structure
   - Import to new schema

## Testing Implications

Integration tests must explicitly clean up both registers AND schemas:

```php
protected function tearDown(): void
{
    // 1. Delete objects
    foreach ($this->createdObjects as $obj) {
        $this->client->delete("/api/objects/{$obj['register']}/{$obj['schema']}/{$obj['uuid']}");
    }
    
    // 2. Delete register
    $this->client->delete("/api/registers/{$this->registerId}");
    
    // 3. Delete schemas (if test-specific)
    foreach ($this->createdSchemas as $schemaId) {
        $this->client->delete("/api/schemas/{$schemaId}");
    }
}
```

## Summary

| Action | Affects Schemas? | Affects Objects? |
|--------|------------------|------------------|
| Delete Register | ❌ No | ✅ Yes (in that register) |
| Delete Schema | ✅ Yes (definition) | ✅ Yes (ALL using it) |
| Update Schema | ✅ Yes (definition) | 🔄 Structure changed |

**Remember:** Schemas are shared resources - treat them with care!

