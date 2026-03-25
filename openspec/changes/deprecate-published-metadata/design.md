# Design: Deprecate Published/Depublished Object Metadata

## Overview

Remove the dedicated `published`/`depublished` object metadata system from OpenRegister and downstream apps. The RBAC `$now` dynamic variable (already implemented) replaces this functionality.

## Scope

**In scope**: Object-level published/depublished metadata columns, hydration, search filtering, Solr indexing, and downstream app references.

**Out of scope**: Register/Schema `published`/`depublished` fields (multi-tenancy bypass), File publish/depublish (Nextcloud share management), Configuration publishToGitHub (GitHub export).

## Technical Approach

### 1. MagicMapper Column Definitions

**File**: `lib/Db/MagicMapper.php`

Remove `_published` and `_depublished` from:

- `getBaseMetadataColumns()` (~line 2159-2170): Remove the two column definition entries
- Metadata column lists in `ensureTableForRegisterSchema()` (~lines 1789, 1841): Remove `'published'` from the `$metadataColumns` arrays (appears twice -- table creation and table update paths)
- `buildObjectFromRow()` (~line 3287): Remove `'published'` and `'depublished'` from the datetime field list
- `buildInsertData()` (~line 3063-3064): Remove `'published'` and `'depublished'` from the metadata fields list
- Date field handling in `buildInsertData()` (~line 3072): Remove from the datetime conversion check
- Index definitions: Remove `_published` from `$idxMetaFields` (~line 2808)

### 2. MagicMapper Facet Handlers

**File**: `lib/Db/MagicMapper/MagicFacetHandler.php`

- Remove `'published'` from date field lists (~line 951)

**File**: `lib/Db/ObjectHandlers/MetaDataFacetHandler.php`

- Remove `'published'` and `'depublished'` entries from the metadata-to-column mapping (~line 134)
- Remove the `'published'` and `'depublished'` facet definitions (~lines 1319-1328)

### 3. MariaDB Search Handler

**File**: `lib/Db/ObjectHandlers/MariaDbSearchHandler.php`

- Remove `'published'` and `'depublished'` from the searchable metadata fields list (~line 62-63)
- Remove from `DATE_FIELDS` constant (~line 71)

### 4. SaveObject Metadata Hydration

**File**: `lib/Service/Object/SaveObject.php`

- In `hydrateObjectMetadata()` (~line 884+): Remove processing of `objectPublishedField`, `objectDepublishedField`, and `autoPublish` schema configuration keys
- Remove the published/depublished field processing block (~line 3299+)
- Add deprecation warning log if these config keys are encountered

**File**: `lib/Service/Object/SaveObject/MetadataHydrationHandler.php`

- Already has a note that published/depublished handling is in SaveObject -- no changes needed unless the handler is refactored

### 5. Search Query Pipeline

**File**: `lib/Service/Object/SearchQueryHandler.php`

- Remove `$params['published']` from the method call (~line 156)
- Remove `'published'` and `'depublished'` from the `@self` metadata fields list (~lines 173-174)

**File**: `lib/Service/Object/CrudHandler.php`

- Remove any `published` parameter passing through CRUD operations

### 6. Index Service (Solr)

**File**: `lib/Service/IndexService.php`

- Remove `$published` parameter from `searchObjects()` method signature (~line 164)
- Remove passing `published` to objectHandler (~line 171)

**File**: `lib/Service/Index/ObjectHandler.php`

- Remove `$published` parameter from `searchObjects()` and `buildSolrQuery()` methods
- Remove `published:true` filter application (~line 156-157)

**File**: `lib/Service/Index/SearchBackendInterface.php`

- Remove `$published` parameter from interface method signature (~line 129)

### 7. Multi-Tenancy Trait

**File**: `lib/Db/MultiTenancyTrait.php`

- Remove references to "Published entity bypass" in documentation comments (~lines 231, 239)
- Note: The actual published bypass logic for Register/Schema entities stays -- it uses their own `published`/`depublished` columns, not object metadata

### 8. SearchTrail

**File**: `lib/Db/SearchTrailMapper.php`

- `published_only` field (~line 817): This is a search trail tracking field. Can remain as-is for historical data, or be deprecated in a separate change.

### 9. SchemaMapper Published Parameter

**Files**: `lib/Db/SchemaMapper.php`, `lib/Db/RegisterMapper.php`

- The `$published` parameter in these mappers refers to Register/Schema published bypass, **not** object published metadata. These are **out of scope** and should remain.

### 10. ObjectsController and BulkController

**File**: `lib/Controller/ObjectsController.php`

- Remove `published`/`depublished` from metadata filter documentation comments (~lines 873, 1256)

**File**: `lib/Controller/BulkController.php`

- Object publish/depublish routes are already removed from routes.php
- Remove any remaining publish/depublish methods if they still exist
- Update class docblock (~line 7)

### 11. Database Migration

**File**: `lib/Migration/Version1Date20260313130000.php`

- Already exists and correctly drops `_published`/`_depublished` columns from magic tables and `published`/`depublished` from the objects table, plus related indexes

### 12. Frontend (OpenRegister)

**File**: `src/modals/schema/EditSchema.vue`

- Remove any UI for `objectPublishedField`, `objectDepublishedField`, `autoPublish` schema config

### 13. OpenCatalogi Cross-App Impact

**Files that need updating**:

| File | Change |
|---|---|
| `lib/Service/EventService.php` | Remove `isObjectPublished()` method; replace published-state checks with RBAC-based authorization |
| `lib/Listener/ObjectCreatedEventListener.php` | Remove `$objectData['@self']['published']` and `depublished` reads (~lines 150-151) |
| `lib/Listener/ObjectUpdatedEventListener.php` | Remove `isObjectEntityPublished()`, `isObjectPublished()` methods; remove `@self` published/depublished reads (~lines 188-263) |
| `lib/Controller/PublicationsController.php` | Remove `'published'`, `'depublished'` from `$universalOrderFields` (~line 352) |
| `lib/Service/PublicationService.php` | Update ordering examples in docblocks that reference `@self.published` |
| `src/modals/object/MassPublishObjects.vue` | Delete |
| `src/modals/object/MassDepublishObjects.vue` | Delete |
| `src/components/PublishedIcon.vue` | Delete or repurpose for RBAC-based visibility indication |
| `src/store/modules/object.js` | Remove `publishObject()`/`depublishObject()` store actions |
| `src/entities/publication/publication.ts` | Remove `published`/`depublished` fields |
| `src/entities/attachment/attachment.ts` | Remove `published`/`depublished` fields |

### 14. Softwarecatalogus Cross-App Impact

| File | Change |
|---|---|
| `src/modals/object/MassPublishObjects.vue` | Delete |
| `src/modals/object/MassDepublishObjects.vue` | Delete |
| `src/components/PublishedIcon.vue` | Delete or repurpose |

## Backward Compatibility

### Schema Configuration

Schemas with `objectPublishedField`, `objectDepublishedField`, or `autoPublish` in their configuration will:
- Have the config keys **ignored** (no error, no processing)
- Log a **deprecation warning** suggesting migration to RBAC rules with `$now`
- Continue to function otherwise (the data fields they reference still exist as regular object properties)

### API Responses

- Object JSON responses will no longer include `@self.published` or `@self.depublished` keys
- The `_order[@self.published]` query parameter will stop working (should return an error or be ignored)
- The `published_only` search parameter will be ignored

### Data Migration

- Existing `_published`/`_depublished` column data is **dropped** by the migration
- For schemas that relied on published-state for visibility, administrators must create RBAC authorization rules using `$now` to replicate the behavior
- Example migration from old to new:
  - Old: `objectPublishedField: "publicatieDatum"` + `autoPublish: true`
  - New: Schema authorization: `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`

## RBAC `$now` Variable (Already Implemented)

The replacement mechanism is fully functional:

- `ConditionMatcher::resolveDynamicValue()` resolves `$now` to `(new DateTime())->format('c')` (ISO 8601) for in-memory evaluation
- `MagicRbacHandler::resolveDynamicValue()` resolves `$now` to `(new DateTime())->format('Y-m-d H:i:s')` (SQL datetime) for query-level filtering
- Both support `$now` inside operator expressions: `{"$lte": "$now"}`, `{"$gte": "$now"}`
- Works recursively in nested operator arrays
