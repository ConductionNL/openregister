# Deprecate Published/Depublished Metadata

Replace the dedicated `published`/`depublished` object metadata system with RBAC conditional rules using a new `$now` dynamic variable.

## Requirements

### MUST

- [ ] `ConditionMatcher::resolveDynamicValue()` MUST support `$now` variable, resolving to `(new DateTime())->format('c')` (ISO 8601)
- [ ] `MagicRbacHandler::resolveDynamicValue()` MUST support `$now` variable, resolving to `(new DateTime())->format('Y-m-d H:i:s')` (SQL datetime format)
- [ ] `MagicRbacHandler` MUST resolve `$now` inside operator values (e.g., `{"$lte": "$now"}`) before building SQL expressions
- [ ] `ObjectEntity` MUST NOT have `published` or `depublished` properties, getters, setters, or JSON serialization
- [ ] `PublishHandler` class MUST be deleted
- [ ] All publish/depublish API routes MUST be removed from `routes.php`:
  - `POST /api/objects/{register}/{schema}/{id}/publish`
  - `POST /api/objects/{register}/{schema}/{id}/depublish`
  - `POST /api/bulk/{register}/{schema}/publish`
  - `POST /api/bulk/{register}/{schema}/depublish`
  - `POST /api/bulk/{register}/{schema}/publish-schema`
- [ ] `ObjectsController::publish()` and `ObjectsController::depublish()` MUST be removed
- [ ] `BulkController::publish()`, `BulkController::depublish()`, `BulkController::publishSchema()` MUST be removed
- [ ] `SaveObject::hydrateObjectMetadata()` MUST NOT process `objectPublishedField` or `objectDepublishedField` schema configuration
- [ ] `SaveObject` MUST NOT process `autoPublish` schema configuration
- [ ] `MagicSearchHandler` MUST NOT apply any published-based WHERE clauses or filters
- [ ] `MagicOrganizationHandler` MUST NOT apply published-based visibility checks for unauthenticated users
- [ ] Magic table column definitions MUST NOT include `_published` or `_depublished`
- [ ] Magic table index definitions MUST NOT include `_published` index
- [ ] A database migration MUST drop `_published` and `_depublished` columns from all existing magic tables
- [ ] `ObjectEntityMapper::applyPublishedFilter()` MUST be removed
- [ ] Frontend `MassPublishObjects.vue` and `MassDepublishObjects.vue` modals MUST be deleted
- [ ] Store actions `publishObject()` and `depublishObject()` MUST be removed from `object.js`
- [ ] `published` and `depublished` MUST be removed from `object.types.ts`
- [ ] Solr indexing MUST NOT filter by published status — all objects are indexed
- [ ] OpenCatalogi WOO schemas MUST be updated to use RBAC authorization rules with `$now` instead of `objectPublishedField`/`objectDepublishedField`

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
