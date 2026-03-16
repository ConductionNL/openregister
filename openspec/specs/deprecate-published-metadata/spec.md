# Deprecate Published/Depublished Metadata

Replace the dedicated `published`/`depublished` object metadata system with RBAC conditional rules using the `$now` dynamic variable.

**Scope note**: This spec covers object-level published/depublished metadata only. Register/Schema `published`/`depublished` fields (multi-tenancy bypass) and File publish/depublish (Nextcloud share management) are out of scope.

## Requirements

### MUST

- [x] `ConditionMatcher::resolveDynamicValue()` MUST support `$now` variable, resolving to `(new DateTime())->format('c')` (ISO 8601) — **DONE**
- [x] `MagicRbacHandler::resolveDynamicValue()` MUST support `$now` variable, resolving to `(new DateTime())->format('Y-m-d H:i:s')` (SQL datetime format) — **DONE**
- [x] `MagicRbacHandler` MUST resolve `$now` inside operator values (e.g., `{"$lte": "$now"}`) before building SQL expressions — **DONE**
- [x] `ObjectEntity` MUST NOT have `published` or `depublished` properties, getters, setters, or JSON serialization — **DONE**
- [ ] `PublishHandler` class MUST be deleted (if it still exists)
- [x] Object publish/depublish API routes MUST be removed from `routes.php` — **DONE**
- [ ] `BulkController` publish/depublish methods MUST be removed (if they still exist)
- [ ] `SaveObject::hydrateObjectMetadata()` MUST NOT process `objectPublishedField` or `objectDepublishedField` schema configuration
- [ ] `SaveObject` MUST NOT process `autoPublish` schema configuration
- [ ] `MagicSearchHandler` (`MariaDbSearchHandler`) MUST NOT list `published`/`depublished` as searchable metadata or date fields
- [ ] `MagicOrganizationHandler` MUST NOT apply published-based visibility checks for unauthenticated users
- [ ] `MagicMapper::getBaseMetadataColumns()` MUST NOT include `_published` or `_depublished` column definitions
- [ ] `MagicMapper` metadata column lists (table creation, table update, insert data, row extraction) MUST NOT include `published`/`depublished`
- [ ] Magic table index definitions MUST NOT include `_published` index
- [x] A database migration MUST drop `_published` and `_depublished` columns from all existing magic tables — **DONE** (`Version1Date20260313130000`)
- [ ] `MetaDataFacetHandler` MUST NOT define `published`/`depublished` facet metadata
- [ ] `MagicFacetHandler` MUST NOT include `published` in date field handling
- [ ] `SearchQueryHandler` MUST NOT pass `published` parameter or list it as `@self` metadata
- [ ] `IndexService`/`ObjectHandler` (Solr) MUST NOT accept or apply `$published` filter parameter
- [ ] `SearchBackendInterface::searchObjects()` MUST NOT have `$published` parameter
- [ ] OpenCatalogi `MassPublishObjects.vue` and `MassDepublishObjects.vue` modals MUST be deleted
- [ ] OpenCatalogi store actions `publishObject()` and `depublishObject()` MUST be removed
- [ ] OpenCatalogi `ObjectCreatedEventListener` and `ObjectUpdatedEventListener` MUST NOT read `@self.published`/`@self.depublished`
- [ ] OpenCatalogi `PublicationsController` MUST NOT list `published`/`depublished` as universal order fields
- [ ] OpenCatalogi WOO schemas MUST be updated to use RBAC authorization rules with `$now` instead of `objectPublishedField`/`objectDepublishedField`
- [ ] Softwarecatalogus `MassPublishObjects.vue` and `MassDepublishObjects.vue` MUST be deleted

### SHOULD

- [ ] RBAC unit tests SHOULD cover `$now` variable in both `ConditionMatcher` and `MagicRbacHandler`
- [ ] RBAC unit tests SHOULD cover `$now` inside operator expressions (`{"$lte": "$now"}`, `{"$gte": "$now"}`)
- [ ] Date field faceting SHOULD be tested in the Softwarecatalogus to verify date-based queries work correctly
- [ ] Migration SHOULD handle tables where columns don't exist (idempotent)
- [ ] Error messages SHOULD be returned if deprecated schema config keys (`objectPublishedField`, `objectDepublishedField`, `autoPublish`) are encountered in schema configuration

### COULD

- [ ] A `$today` variable COULD be added alongside `$now`, resolving to `Y-m-d` date-only format for day-granularity comparisons
- [ ] Admin documentation COULD include migration examples showing how to convert published-based schemas to RBAC rules

## Acceptance Criteria

1. **`$now` works in RBAC conditions**: A schema with authorization `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}` correctly allows public read access to objects where `publicatieDatum` is in the past, and denies access to objects where it is in the future
2. **No publish endpoints**: Requests to any former publish/depublish endpoint return 404
3. **No published metadata**: Object JSON responses do not contain `published` or `depublished` keys
4. **Unauthenticated access via RBAC**: Unauthenticated users can read objects that match RBAC public rules (replacing the old published filter)
5. **WOO schemas work**: OpenCatalogi WOO publications are visible/hidden based on RBAC rules using `publicatieDatum` and `$now`
6. **Magic tables clean**: No `_published` or `_depublished` columns exist in magic tables after migration

## Test Scenarios

### `$now` dynamic variable

| Scenario | Input | Expected |
|---|---|---|
| Object with past publicatieDatum, RBAC rule `$lte: $now` | `publicatieDatum: "2024-01-01"` | Public read allowed |
| Object with future publicatieDatum, RBAC rule `$lte: $now` | `publicatieDatum: "2099-01-01"` | Public read denied |
| Object with past einddatum, RBAC rule `$gte: $now` | `einddatum: "2024-01-01"` | Public read denied (expired) |
| Object with future einddatum, RBAC rule `$gte: $now` | `einddatum: "2099-01-01"` | Public read allowed |
| Combined publicatieDatum + einddatum window | past publicatieDatum, future einddatum | Public read allowed |
| Combined publicatieDatum + einddatum expired | past publicatieDatum, past einddatum | Public read denied |
| Admin user ignores `$now` rules | Any dates | Admin always has access |

### Endpoint removal

| Scenario | Expected |
|---|---|
| POST to `/api/objects/{r}/{s}/{id}/publish` | 404 Not Found |
| POST to `/api/objects/{r}/{s}/{id}/depublish` | 404 Not Found |
| POST to `/api/bulk/{r}/{s}/publish` | 404 Not Found |

### Schema config removal

| Scenario | Expected |
|---|---|
| Schema with `objectPublishedField` config | Config ignored (or warning logged) |
| Schema with `autoPublish: true` config | Config ignored (or warning logged) |

### Current Implementation Status

**Partially implemented.** Several items are done (marked with [x] above), but many remain:

**Implemented (DONE):**
- `$now` dynamic variable in `ConditionMatcher::resolveDynamicValue()` and `MagicRbacHandler::resolveDynamicValue()`
- `$now` resolution inside operator values (e.g., `{"$lte": "$now"}`)
- `ObjectEntity` no longer has `published`/`depublished` properties
- Object publish/depublish API routes removed from `routes.php`
- Database migration `Version1Date20260313130000` drops `_published` and `_depublished` columns from magic tables

**Not yet implemented (still remaining):**
- `PublishHandler` class deletion (already confirmed deleted -- class not found in codebase)
- `BulkController` publish/depublish method removal
- `SaveObject::hydrateObjectMetadata()` still references `objectPublishedField`/`objectDepublishedField` (found in `SaveObject.php` line ~899 as comment, and in `MetadataHydrationHandler.php`)
- `MagicSearchHandler`/`MariaDbSearchHandler` still lists `published`/`depublished` as searchable metadata
- `MagicOrganizationHandler` still applies published-based visibility for unauthenticated users
- `MagicMapper::getBaseMetadataColumns()` still includes `_published`/`_depublished`
- Magic table index definitions still include `_published` index
- `MetaDataFacetHandler` still defines `published`/`depublished` facet metadata
- `MagicFacetHandler` still includes `published` in date field handling
- `SearchQueryHandler` still passes `published` parameter
- `IndexService`/`ObjectHandler` (Solr) still accepts `$published` filter
- `SearchBackendInterface::searchObjects()` still has `$published` parameter
- OpenCatalogi UI components (`MassPublishObjects.vue`, `MassDepublishObjects.vue`) not yet deleted
- OpenCatalogi store actions, event listeners, and controller references not yet cleaned up
- Softwarecatalogus UI components not yet deleted
- `lib/Db/Schema.php` still has `autoPublish` in `boolFields` (line ~1476)
- `lib/Service/Object/SaveObject/FilePropertyHandler.php` still uses `autoPublish` for file publishing (lines ~480-485, ~773) -- note: this is file-level autoPublish, which may be intentionally kept (out of scope per spec)

### Standards & References
- RBAC (Role-Based Access Control) with dynamic date conditions
- ISO 8601 datetime format for `$now` resolution
- HTTP 404 for removed endpoints
- Database migration best practices (idempotent column drops)

### Specificity Assessment
- **Specific enough to implement?** Yes -- the checklist format with explicit file paths and method names makes this very actionable.
- **Missing/ambiguous:**
  - The spec notes file publish/depublish is out of scope, but `FilePropertyHandler.php` uses `autoPublish` for file sharing -- this needs clarification on whether it stays or goes
  - No specification for how existing objects with `published`/`depublished` data should be migrated (just drop columns, or convert to RBAC rules?)
- **Open questions:**
  - Should a data migration convert existing `published`/`depublished` values to RBAC authorization rules on affected objects?
  - Should the `autoPublish` in Schema configuration trigger a deprecation warning or be silently ignored?
