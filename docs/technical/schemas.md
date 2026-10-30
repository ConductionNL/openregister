---
title: Schema Technical Documentation
sidebar_position: 15
---

# Schema Technical Documentation

This document covers technical details about schema configuration, metadata handling, and register relationships.

## Schema Metadata Configuration

OpenRegister schemas support automatic metadata extraction from object properties. This allows you to configure which fields should populate the object's metadata (like name, description, image, etc.).

### Configuration Options

Metadata fields are configured in the schema's `configuration` object:

```json
{
  "configuration": {
    "objectNameField": "title",
    "objectDescriptionField": "description",
    "objectSummaryField": "summary",
    "objectImageField": "logo",
    "objectSlugField": "name"
  }
}
```

### Available Metadata Fields

| Field | Description | Purpose |
|-------|-------------|---------|
| `objectNameField` | The property to use for object name | Used for display in lists, search results |
| `objectDescriptionField` | The property to use for detailed description | Used in detail views, previews |
| `objectSummaryField` | The property to use for short summary | Used in cards, listings |
| `objectImageField` | The property to use for object image | Used for thumbnails, previews, social sharing |
| `objectSlugField` | The property to use for URL-friendly slug | Used in URLs, permalinks |
| `objectSlugSuffix` | `true` appends a UTC date-time suffix to a slug that is already taken | Default `false` |

### Object slugs

Setting `objectSlugField` switches on managed slugs for the schema. The field takes
the same dot paths and templates as `objectNameField`; the value is slugified
(lower case, `a-z0-9` and hyphens). The reserved value `@schema.name` is the other
choice: the slug becomes `<schema slug>-<object name>`, the name being the value of
`objectNameField` (`convenanten-hello-world`). An object without a name gets no slug
and stays reachable by its uuid; there is no fallback to other fields.

- **Unique per register and schema.** A slug another live object of the same
  register and schema holds is not written: the new object is saved without a slug
  and a warning in `nextcloud.log` names the register, the schema, the slug and both
  objects. With `objectSlugSuffix: true` the object gets `<slug>-<YYYYMMDDHHMMSS>`
  (UTC) instead.
- **Frozen once set.** A later save never changes a slug, also when the source field
  changes. A slug sent by a client (`@self.slug`) is normalised like a generated one (lower case, spaces and `_` become `-`, every other character outside `a-z0-9` and `-`, dots included, is dropped, at most 255 characters; `Zaak_2026-0042` is stored as `zaak-2026-0042`, and a value that normalises to nothing counts as not sent) and follows the same rules; a different
  slug sent for an object that already has one is ignored and logged. There is no
  exception: switching `objectSlugField` applies to objects without a slug, and the
  backfill command has no option that rewrites set slugs.
- **A freed slug moves on.** When the holder is deleted, the next save of an object
  that wanted the slug gives it to that object. If the old holder is restored after
  that, it comes back without a slug (with the suffixed slug when `objectSlugSuffix`
  is on) and a warning names both objects; seed data objects follow the same rule.
- **Lookups.** `GET /api/objects/{register}/{schema}/{slug}` resolves the slug. A
  slug that more than one object holds (possible only for duplicates created before
  this rule) answers `409` with `"error": "ambiguous-identifier"` on the object routes,
  the relations route and the object history route (`…/{id}/audit-trails`, which looks
  the object up within the register and schema of the URL); the matching uuids go to the
  log. The uses and used sub-routes answer the same `409`; the files listing lists the
  files of the object the read check resolved within the register and schema and answers
  `404` for a slug two objects hold, as it does for every refused read. An exact match on an
  object's id or uuid wins over a slug that happens to look the same (a slug `2024` next to
  the object with id 2024, a slug equal to another object's uuid), and a numeric identifier
  is an id only when it is a canonical integer (`2024`; `007` is a slug, not id 7); only when no single row is matched by its id or
  uuid is the identifier ambiguous.
- **Existing objects** get their slugs from
  `occ openregister:backfill-slugs <register> <schema>` (oldest first; existing
  slugs are left alone, duplicates are reported, not changed). After switching the slug
  field, run it again: objects that still have no slug get one from the new field,
  objects that already hold a slug keep it, so links, bookmarks and external references
  keep working. The backfill writes the slug column
  directly: no per-object version or audit entry and no object events, but every run
  that is not a dry run and changes at least one slug writes one audit-trail entry (`slug.backfill.fill`)
  listing the objects that got a slug (a run that changes nothing writes none). The entry lists the first 1,000 changes and the total; the full old-to-new list of a larger run is written to the Nextcloud log at warning level, so it is kept at the default log level (lines starting `[ObjectSlug] Slug backfill changes`, in parts of 1,000). The slugs come from `objectSlugField`, which can be a name: they are copied into the audit trail, which is hash-chained and cannot be redacted afterwards (for an erasure request, the log lines age out with the log rotation). Use
  `--dry-run` to see the plan first.

A schema without `objectSlugField` keeps the old behaviour: no slug unless a client
sends one, and no uniqueness check.

### Object Image Field Configuration

#### ⚠️ IMPORTANT WARNING

**When configuring `objectImageField` to point to a file property, the file MUST be publicly accessible.**

#### Why This Matters

The object's image metadata (`@self.image`) is used in:
- **API responses** for public consumption
- **Social media sharing** (Open Graph, Twitter Cards)
- **Frontend displays** where the image needs to be accessible
- **Search engine indexing**
- **Third-party integrations**

If the file is not publicly shared, these features will not work correctly.

#### Understanding autoPublish Settings

⚠️ **IMPORTANT**: There are TWO different 'autoPublish' settings with different purposes. Do not confuse them!

##### 1. Property-Level autoPublish (File Properties)

Controls whether files uploaded to a specific property get published:

```json
{
  "properties": {
    "productImage": {
      "type": "file",
      "autoPublish": true  // ← Files uploaded here are published
    },
    "internalDoc": {
      "type": "file",
      "autoPublish": false  // ← Files uploaded here stay private
    }
  }
}
```

**Purpose**: Gives you control over which file properties should have their files publicly accessible.

**Location**: Inside individual file property definitions

**Applies to**: Only files uploaded to that specific property

##### 2. Schema-Level autoPublish (Object Publishing)

Controls whether the entire object gets published:

```json
{
  "configuration": {
    "autoPublish": true  // ← The OBJECT itself is published
  }
}
```

**Purpose**: Controls object visibility (has nothing to do with file sharing)

**Location**: In the schema's 'configuration' object

**Applies to**: The object entity itself, not its files

**Note**: This is completely separate from file publishing!

#### Auto-Publishing Behavior for objectImageField

When a file property is configured as 'objectImageField', **the file is ALWAYS published**, regardless of the property's 'autoPublish' setting.

**Why?** Object metadata must be publicly accessible for:
- Social media previews
- API consumers
- Search engines
- Third-party integrations

**What happens:**

```json
{
  "properties": {
    "logo": {
      "type": "file",
      "autoPublish": false  // ← User preference: keep private
    }
  },
  "configuration": {
    "objectImageField": "logo"  // ← FORCES publication!
  }
}
```

**Result**: The logo file will be published anyway, and you'll see this warning in logs:

```
File configured as objectImageField is not published. Auto-publishing file.
```

**Recommendation**: Always set 'autoPublish: true' on file properties used as 'objectImageField' to make the behavior explicit and avoid confusion.

### Quick Reference Table

| Setting Location | Property Name | What It Controls | Example |
|-----------------|---------------|------------------|---------|
| Property definition | 'autoPublish' | Whether files in THIS property get published | 'logo: \{type: "file", autoPublish: true\}' |
| Schema configuration | 'autoPublish' | Whether the OBJECT gets published | 'configuration: \{autoPublish: true\}' |
| Schema configuration | 'objectImageField' | Forces file publication for metadata | 'configuration: \{objectImageField: "logo"\}' |

**Key Takeaways:**
- ✅ Property 'autoPublish' = controls file sharing per property
- ✅ Schema 'autoPublish' = controls object publication (unrelated to files)
- ⚠️ 'objectImageField' = overrides property 'autoPublish' and forces publication
- 💡 Best practice: Set 'autoPublish: true' on properties used as 'objectImageField'

### Best Practices

#### ✅ Recommended Approach

**Use `autoPublish` property configuration** to ensure files are published on upload:

```json
{
  "slug": "products",
  "title": "Products Schema",
  "properties": {
    "name": {
      "type": "string"
    },
    "logo": {
      "type": "file",
      "allowedTypes": ["image/png", "image/jpeg"],
      "autoPublish": true  // ✅ File will be published on upload
    }
  },
  "configuration": {
    "objectImageField": "logo"  // ✅ File is already published
  }
}
```

#### ❌ What to Avoid

**Don't use private file properties as objectImageField:**

```json
{
  "properties": {
    "internalDocument": {
      "type": "file",
      "autoPublish": false  // ❌ Property says: keep private
    }
  },
  "configuration": {
    "objectImageField": "internalDocument"  // ❌ But metadata needs public access!
  }
}
```

**What happens:** The file WILL be published anyway (with a warning logged), defeating your 'autoPublish: false' setting.

**Why this is bad:**
- Confusing behavior (says private, becomes public)
- Unexpected file publication
- Security risk if you think files are private

**The fix:** Don't point 'objectImageField' to properties with sensitive files. Use separate properties:

```json
{
  "properties": {
    "publicLogo": {
      "type": "file",
      "autoPublish": true  // ✅ Explicitly public
    },
    "internalDocuments": {
      "type": "file",
      "autoPublish": false  // ✅ Stays private (not used as image)
    }
  },
  "configuration": {
    "objectImageField": "publicLogo"  // ✅ Points to public property
  }
}
```

### Array File Properties

When `objectImageField` points to an **array of files**, the **first file** in the array is used as the object's image:

```json
{
  "properties": {
    "photos": {
      "type": "array",
      "items": {
        "type": "file",
        "allowedTypes": ["image/png", "image/jpeg"],
        "autoPublish": true  // ✅ All photos will be published
      }
    }
  },
  "configuration": {
    "objectImageField": "photos"  // ✅ First photo used as image
  }
}
```

**Important Notes:**
- Only the **first file** is used for the object image
- All files in the array are **still stored and accessible**
- Consider the first file as the 'primary' or 'featured' image
- Order matters - the first uploaded file becomes the image

### URL Structure

The object image uses the file's **downloadUrl**, not accessUrl. This ensures:
- Direct download capability
- Compatibility with image proxies
- Better caching behavior
- Social media previews work correctly

Example URL format:
```
https://your-domain.com/index.php/s/abc123xyz/download
```

### Implementation Details

#### Technical Flow

1. **Object Created** with file attached
2. **File Stored** in Nextcloud Files
3. **Metadata Hydration**:
   - Check if `objectImageField` is configured
   - Locate the file (ID or file object)
   - Check if file is published
   - **Auto-publish** if not published (with warning logged)
   - Extract `downloadUrl`
   - Set `@self.image` to downloadUrl

4. **API Response** includes:
   ```json
   {
     "id": "uuid-123",
     "name": "Product Name",
     "logo": {
       "id": 456,
       "downloadUrl": "https://domain.com/s/abc/download",
       "accessUrl": "https://domain.com/preview?id=456"
     },
     "@self": {
       "image": "https://domain.com/s/abc/download"
     }
   }
   ```

#### Code Location

The auto-publishing logic is implemented in:
- `/lib/Service/ObjectHandlers/SaveObject.php` - `hydrateObjectMetadata()` method
- `/lib/Service/ObjectService.php` - `hydrateObjectMetadataFromData()` method

### Troubleshooting

#### Image Not Appearing

**Problem**: `@self.image` is `null` in API responses

**Solutions**:
1. Check that `objectImageField` matches the property name exactly
2. Verify the file property has `autoPublish: true`
3. Check logs for auto-publishing warnings
4. Ensure the property actually contains a file

#### Performance Concerns

**Problem**: Slow object saves when using `objectImageField` with files

**Solution**: The file is loaded and checked during metadata hydration. This is cached. If performance is critical:
- Use `autoPublish: true` to avoid auto-publishing checks
- Consider using string URLs instead of file properties for external images
- Use background jobs for bulk imports

#### Security Concerns

**Problem**: Don't want to auto-publish sensitive files

**Solution**: **DO NOT** configure sensitive file properties as `objectImageField`. Use separate fields:
- Public logo → `logo` property with `autoPublish: true` → configured as `objectImageField`
- Private documents → `documents` property without `autoPublish` → NOT configured as `objectImageField`

## Schema and Register Relationship

In OpenRegister, **schemas** and **registers** have an independent, many-to-many relationship. This architectural decision enables maximum flexibility and reusability.

### Key Principles

#### 1. Schemas Are Independent Entities

Schemas exist independently of registers and can be:
- Created without being assigned to any register
- Shared across multiple registers
- Modified without affecting unrelated registers
- Deleted independently (with proper cascade handling for objects)

#### 2. Schemas Do NOT Cascade Delete

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

#### 3. Register-Schema Association

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

### Use Cases

#### Single Schema, Multiple Registers

A "Person" schema can be reused across different registers:

```
Schema: "person"
├── Register: "employees"      (HR department)
├── Register: "customers"      (Sales department)
└── Register: "contractors"    (Operations department)
```

Each register contains different person objects, but they all follow the same schema structure.

#### Schema Evolution

Schemas can evolve independently:

1. Update schema definition
2. Affects all registers using that schema
3. Provides consistent data structure across the application

### Deletion Behavior

#### Register Deletion

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

#### Schema Deletion

```
DELETE /api/schemas/{id}
```

**Deletes:**
- ✅ The schema definition
- ✅ All objects using that schema (across ALL registers)
- ✅ Schema metadata

**Use with caution** - this affects all registers using the schema!

### Best Practices

#### 1. Schema Naming

Use descriptive, generic names that reflect the data structure, not the specific use case:

✅ Good: `person`, `document`, `transaction`  
❌ Bad: `hr-employee`, `sales-customer`

#### 2. Schema Versioning

For breaking changes, create a new schema instead of modifying:

```
person-v1  →  person-v2
```

This allows gradual migration without breaking existing data.

#### 3. Cleanup Strategy

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

#### 4. Schema Discovery

Find which registers use a specific schema:

```
GET /api/objects?schema={schemaId}
```

Group results by register to see usage patterns.

### Database Structure

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

### Migration Considerations

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

### Testing Implications

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

### Summary

| Action | Affects Schemas? | Affects Objects? |
|--------|------------------|------------------|
| Delete Register | ❌ No | ✅ Yes (in that register) |
| Delete Schema | ✅ Yes (definition) | ✅ Yes (ALL using it) |
| Update Schema | ✅ Yes (definition) | 🔄 Structure changed |

**Remember:** Schemas are shared resources - treat them with care!

## Related Documentation

- [Schemas](../Features/schemas.md) - User-facing schema documentation
- [Schema API](../api/schemas.md) - API reference for schemas

