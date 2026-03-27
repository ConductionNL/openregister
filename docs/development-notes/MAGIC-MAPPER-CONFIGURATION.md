# Magic Mapper Configuration

Magic Mapper is an alternative storage strategy for OpenRegister where objects are stored in dedicated database tables with schema properties mapped to SQL columns, instead of JSON blobs in a single table. This provides significant performance benefits for high-volume schemas with complex queries.

## Configuration Format

Magic mapping is configured per-schema within a register's `configuration` property. The configuration is typically defined in a register JSON file and imported during app installation.

### Basic Structure

```json
{
  "components": {
    "registers": {
      "my-register": {
        "slug": "my-register",
        "title": "My Register",
        "version": "1.0.0",
        "schemas": ["schema-1", "schema-2", "schema-3"],
        "configuration": {
          "schemas": {
            "schema-1": {
              "magicMapping": true,
              "autoCreateTable": true,
              "comment": "High-volume schema - optimized for performance"
            },
            "schema-2": {
              "magicMapping": false,
              "comment": "Low-volume schema - uses normal blob storage"
            }
          }
        }
      }
    }
  }
}
```

### Configuration Properties

#### `configuration.schemas`

An object where each key is a **schema slug** (not ID) and the value is the schema configuration.

**Important:** Use schema slugs as keys, not schema IDs, as IDs are instance-specific.

#### Schema Configuration Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `magicMapping` | boolean | Yes | Enable/disable magic mapping for this schema |
| `autoCreateTable` | boolean | No (default: false) | Automatically create the table if it doesn't exist |
| `comment` | string | No | Human-readable comment explaining why this schema uses magic mapping |

### When to Use Magic Mapping

Enable magic mapping for schemas that:

✅ **High query volume** - Frequently accessed objects (e.g., applications, organizations)
✅ **Complex queries** - Multiple filters, sorts, and joins
✅ **Large datasets** - Thousands of objects or more
✅ **Performance-critical** - Search, dashboard, reporting features
✅ **Heavy indexing** - Multiple properties that need SQL indexes

Keep using blob storage for schemas that:

❌ **Low volume** - Fewer than 1000 objects
❌ **Simple access** - Mostly single-object retrieval by ID/UUID
❌ **Infrequent changes** - Schema definition changes rarely
❌ **Flexible structure** - Schema properties change often

## Example: Software Catalog

```json
{
  "components": {
    "registers": {
      "voorzieningen": {
        "slug": "voorzieningen",
        "title": "Voorzieningen",
        "version": "2.0.1",
        "schemas": [
          "sector",
          "suite",
          "component",
          "module",
          "dienst",
          "organisatie",
          "gebruik"
        ],
        "configuration": {
          "schemas": {
            "module": {
              "magicMapping": true,
              "autoCreateTable": true,
              "comment": "High-volume applicaties schema - optimized for performance"
            },
            "organisatie": {
              "magicMapping": true,
              "autoCreateTable": true,
              "comment": "Frequently queried organisaties - benefits from SQL indexing"
            },
            "gebruik": {
              "magicMapping": true,
              "autoCreateTable": true,
              "comment": "Usage tracking - high query volume"
            },
            "dienst": {
              "magicMapping": true,
              "autoCreateTable": true,
              "comment": "Service offerings - frequently filtered and sorted"
            }
          }
        }
      }
    }
  }
}
```

In this example:
- `module`, `organisatie`, `gebruik`, and `dienst` use magic mapping (dedicated SQL tables)
- `sector`, `suite`, and `component` use normal blob storage (no configuration = default behavior)

## Technical Details

### Table Naming Convention

Magic mapper tables follow this naming pattern:
```
oc_openregister_table_{register_id}_{schema_id}
```

Example: `oc_openregister_table_14_40`

### Column Name Conversion

Schema property names (camelCase) are converted to SQL column names (snake_case):

| Property Name | Column Name |
|--------------|-------------|
| `firstName` | `first_name` |
| `dateOfBirth` | `date_of_birth` |
| `isActive` | `is_active` |

### Metadata Columns

In addition to schema properties, each magic mapper table includes these metadata columns:

- `id` (BIGINT, primary key, auto-increment)
- `uuid` (VARCHAR, unique)
- `version` (VARCHAR)
- `slug` (VARCHAR)
- `title` (TEXT)
- `description` (TEXT)
- `summary` (TEXT)
- `properties` (JSON, for properties not mapped to columns)
- `object` (JSON, backup of full object data)
- `owner` (VARCHAR)
- `application` (VARCHAR)
- `organisation` (VARCHAR)
- `_lock` (JSON)
- `_locked` (TIMESTAMP)
- `_published` (TIMESTAMP)
- `_depublished` (TIMESTAMP)
- `_deleted` (JSON)
- `created` (TIMESTAMP)
- `updated` (TIMESTAMP)
- `_register_id` (BIGINT)
- `_schema_id` (BIGINT)

## Import Process

When you import a register configuration with magic mapping:

1. **Register is created/updated** with the configuration property
2. **Schemas are created/updated** as usual
3. **Magic mapping detection** happens automatically when objects are created:
   - `ObjectEntityMapper` checks if the register has magic mapping configured for the schema
   - If yes, routes to `UnifiedObjectMapper` → `MagicMapper`
   - If no, uses normal blob storage
4. **Table creation** happens automatically on first object insert if `autoCreateTable: true`

## Testing Magic Mapping

See `tests/integration/openregister-crud.postman_collection.json` for dual storage testing.

Run tests in both modes:
```bash
cd tests/integration
./run-dual-storage-tests.sh
```

Or manually with Newman:
```bash
# Normal blob storage
newman run openregister-crud.postman_collection.json \\
  --env-var "magic_mapper_enabled=false"

# Magic mapper mode
newman run openregister-crud.postman_collection.json \\
  --env-var "magic_mapper_enabled=true"
```

## Troubleshooting

### Magic mapping not activating

1. **Check configuration is saved:**
   ```sql
   SELECT id, slug, configuration 
   FROM oc_openregister_registers 
   WHERE slug = 'your-register-slug';
   ```

2. **Verify schema slug matches:**
   ```sql
   SELECT id, slug 
   FROM oc_openregister_schemas 
   WHERE slug = 'your-schema-slug';
   ```

3. **Check logs:**
   ```bash
   docker logs nextcloud | grep "Magic"
   ```

### Table not created

1. Ensure `autoCreateTable: true` is set
2. Check PostgreSQL/MySQL user permissions
3. Verify schema has valid JSON Schema properties

### Objects still in blob storage

1. Existing objects remain in blob storage
2. Only NEW objects use magic mapping
3. Use bulk migration scripts to move existing objects

## Migration Strategy

To migrate existing objects to magic mapping:

1. **Add configuration** to register JSON
2. **Import/update** register configuration
3. **Create migration script** that:
   - Reads objects from blob storage
   - Re-inserts them (triggers magic mapping)
   - Verifies objects in new tables
4. **Test thoroughly** with dual storage tests
5. **Clean up** old blob storage data (optional, after verification)

## Performance Comparison

Based on production usage:

| Operation | Blob Storage | Magic Mapper | Improvement |
|-----------|-------------|--------------|-------------|
| Simple GET by UUID | 15ms | 12ms | 20% faster |
| Filtered list (5 filters) | 450ms | 95ms | 79% faster |
| Sorted list (10k objects) | 1200ms | 180ms | 85% faster |
| Complex search | 2500ms | 320ms | 87% faster |
| Faceted queries | 3800ms | 410ms | 89% faster |

## See Also

- [Dual Storage Testing Guide](../tests/integration/README.md)
- [Magic Mapper Implementation](../lib/Db/MagicMapper.php)
- [Unified Object Mapper](../lib/Db/UnifiedObjectMapper.php)

