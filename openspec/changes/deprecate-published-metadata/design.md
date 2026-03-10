## Context

OpenRegister objects have two parallel visibility systems:

1. **Published/depublished metadata** — `published` and `depublished` DateTime fields on `ObjectEntity`, with dedicated API endpoints, hardcoded query filters, and Solr indexing logic
2. **RBAC conditional rules** — Schema-level authorization with group matching and operator-based conditions (`$gt`, `$lte`, `$in`, etc.)

The RBAC system already supports all the operators needed for date-based visibility, but lacks a `$now` dynamic variable to express "field <= current time". Adding `$now` makes RBAC a complete replacement for the published/depublished system.

Key classes involved:
- `ObjectEntity` — holds `published`/`depublished` fields
- `PublishHandler` — publish/depublish logic with audit trail
- `MagicSearchHandler` — hardcoded published filter for search queries
- `MagicOrganizationHandler` — hardcoded published filter for unauthenticated access
- `MagicRbacHandler` — RBAC query-level enforcement with `resolveDynamicValue()`
- `ConditionMatcher` — RBAC object-level condition matching with `resolveDynamicValue()`
- `OperatorEvaluator` — operator comparison (`$gt`, `$lte`, etc.)
- `SaveObject` — `hydrateObjectMetadata()` reads `objectPublishedField`/`objectDepublishedField`
- `MagicMapper` — magic table schema with `_published`/`_depublished` columns

## Goals / Non-Goals

**Goals:**
- Add `$now` dynamic variable to RBAC so date-based visibility can be expressed as authorization rules
- Remove all published/depublished infrastructure (fields, endpoints, filters, frontend)
- Update OpenCatalogi WOO schemas to use RBAC instead of publication config
- Disable Solr published-only indexing

**Non-Goals:**
- Redesigning the RBAC system itself
- Adding new RBAC operators (existing `$lte`, `$gte` etc. are sufficient)
- Implementing a full Solr replacement strategy (separate concern)
- Migrating existing published/depublished data to RBAC rules (major version — clean break)

## Decisions

### 1. Add `$now` as a dynamic variable resolving to ISO 8601 datetime

Both `ConditionMatcher::resolveDynamicValue()` and `MagicRbacHandler::resolveDynamicValue()` gain support for `$now`. It resolves to `(new DateTime())->format('c')` (ISO 8601 format with timezone).

**Rationale:** ISO 8601 sorts lexicographically, so string-based `$lte`/`$gte` comparisons work correctly for dates stored in this format. This is already the format used throughout OpenRegister for datetime fields.

**Example RBAC rule replacing published/depublished:**
```json
{
  "authorization": {
    "read": [
      "admin",
      {
        "group": "public",
        "match": {
          "publicatieDatum": {"$lte": "$now"},
          "einddatum": {"$gte": "$now"}
        }
      }
    ]
  }
}
```

If `einddatum` is optional (object stays visible indefinitely), use `$exists`:
```json
{
  "group": "public",
  "match": {
    "publicatieDatum": {"$lte": "$now"}
  }
}
```

### 2. Also support `$now` in operator values within `MagicRbacHandler`

The `MagicRbacHandler` translates RBAC conditions to SQL WHERE clauses. When an operator value is `$now` (e.g., `{"$lte": "$now"}`), it must resolve the value before building the SQL expression.

**Implementation:** In the operator resolution path of `MagicRbacHandler`, check if the operator value is a string matching `$now` and replace it with `(new DateTime())->format('Y-m-d H:i:s')` (MySQL datetime format for SQL comparisons).

### 3. Hard removal of published/depublished (no deprecation period)

Since this is a major version release, remove everything in one go:

**Backend removals:**
- `ObjectEntity`: Remove `published`/`depublished` properties, getters/setters, constructor registration, JSON serialization
- `PublishHandler`: Delete entire class
- `ObjectsController`: Remove `publish()`/`depublish()` actions
- `BulkController`: Remove `publish()`/`depublish()`/`publishSchema()` actions
- `routes.php`: Remove all publish/depublish routes
- `SaveObject::hydrateObjectMetadata()`: Remove `objectPublishedField`/`objectDepublishedField` logic
- `SaveObject`: Remove `autoPublish` schema config handling
- `MagicSearchHandler`: Remove `applyPublishedFilter()` and all published-based WHERE clauses
- `MagicOrganizationHandler`: Remove published-based visibility checks for unauthenticated users
- `MagicMapper`: Remove `_published`/`_depublished` from column definitions, remove index creation
- `ObjectEntityMapper`: Remove `applyPublishedFilter()` method

**Frontend removals:**
- `MassPublishObjects.vue`: Delete
- `MassDepublishObjects.vue`: Delete
- `object.js` store: Remove `publishObject()`/`depublishObject()` actions
- `object.types.ts`: Remove `published`/`depublished` from type definitions

**Migration:**
- Add database migration to drop `_published` and `_depublished` columns from all magic tables

### 4. Disable Solr published-only indexing

Remove the published filter from Solr indexing. All objects are indexed regardless of any field value. Solr visibility should be handled by search-time RBAC filtering (existing or future work).

### 5. Update OpenCatalogi WOO schemas

Replace `objectPublishedField`/`objectDepublishedField` in WOO register/schema configurations with RBAC authorization rules:

```json
{
  "authorization": {
    "read": [
      "admin",
      {
        "group": "public",
        "match": {
          "publicatieDatum": {"$lte": "$now"}
        }
      }
    ],
    "create": ["admin"],
    "update": ["admin"],
    "delete": ["admin"]
  }
}
```

## Risks / Trade-offs

- **Apps relying on publish endpoints break** → Major version, documented in release notes, migration guide provided
- **Date comparison accuracy** → ISO 8601 string comparison works for same-timezone dates; mixed timezones could cause edge cases → Document that dates should be stored in UTC
- **Performance of `$now` in queries** → Each query evaluates `NOW()` at query time, same as the current hardcoded published filter — no performance difference
- **OpenCatalogi schema updates** → Must be coordinated with OpenCatalogi release; document required schema configuration changes
- **Existing data with published/depublished dates** → Data in object fields (e.g., `publicatieDatum`) is untouched; only the metadata columns are removed. RBAC rules reference the object data fields directly
