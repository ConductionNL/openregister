## Why

The `published` and `depublished` metadata fields on objects were the original mechanism for controlling object visibility — unauthenticated users could only see objects where `published <= NOW()` and `depublished IS NULL OR depublished > NOW()`. This logic is now hardcoded in multiple places: `MagicSearchHandler`, `MagicOrganizationHandler`, `PublishHandler`, and the Solr indexing filter.

With the RBAC system now supporting conditional rules (operators like `$gt`, `$lte`, group-based matching), publication visibility is just an authorization concern: "public group can read objects where `publicatieDatum <= now`". The only missing piece is a `$now` dynamic variable in the RBAC condition matcher.

Having both systems creates confusion: developers must understand the interaction between RBAC rules and the separate published/depublished filters. It also prevents flexible publication logic — for example, some schemas may want publication based on a different field name, or may want depublication based on `einddatum` with different semantics. RBAC handles all of this naturally.

Since we're releasing a new major version, this is a clean break — no backwards compatibility needed.

## What Changes

- **Add `$now` dynamic variable** to `ConditionMatcher.resolveDynamicValue()` and `MagicRbacHandler.resolveDynamicValue()` — resolves to current ISO 8601 datetime, enabling date-based RBAC conditions
- **Remove `published` and `depublished` fields** from `ObjectEntity` (columns, getters/setters, JSON serialization)
- **Remove `PublishHandler`** and all publish/depublish API endpoints (single + bulk)
- **Remove `_published`/`_depublished` magic table columns** and their indexes
- **Remove hardcoded published filters** from `MagicSearchHandler` and `MagicOrganizationHandler` — RBAC replaces these
- **Remove `objectPublishedField`/`objectDepublishedField` schema configuration** from `SaveObject.hydrateObjectMetadata()`
- **Remove auto-publish schema configuration** (`autoPublish`)
- **Remove frontend publish/depublish modals** (`MassPublishObjects.vue`, `MassDepublishObjects.vue`) and store actions
- **Disable Solr published-only indexing filter** — index all objects regardless of publication state
- **Update WOO schemas in OpenCatalogi** — replace `objectPublishedField`/`objectDepublishedField` config with RBAC authorization rules using `$now`
- **Add migration** to drop `_published`/`_depublished` columns from magic tables

## Capabilities

### New Capabilities
- `rbac-now-variable`: Support for `$now` dynamic variable in RBAC match conditions, resolving to the current datetime for time-based authorization rules

### Modified Capabilities
- `rbac-scopes`: RBAC now fully replaces published/depublished as the visibility mechanism for unauthenticated and authenticated users

### Removed Capabilities
- `publish-depublish`: The `published`/`depublished` metadata fields, publish/depublish API endpoints, bulk publish operations, auto-publish schema config, and all frontend publish/depublish UI

## Impact

- **BREAKING: API endpoints removed** — `POST /api/objects/{register}/{schema}/{id}/publish`, `POST /api/objects/{register}/{schema}/{id}/depublish`, `POST /api/bulk/{register}/{schema}/publish`, `POST /api/bulk/{register}/{schema}/depublish`, `POST /api/bulk/{register}/{schema}/publish-schema`
- **BREAKING: Schema configuration removed** — `objectPublishedField`, `objectDepublishedField`, `autoPublish` no longer recognized
- **BREAKING: Object response format** — `published` and `depublished` fields no longer present in object JSON
- **Database** — `_published` and `_depublished` columns dropped from all magic tables via migration
- **Dependent apps** — OpenCatalogi WOO schemas must be updated to use RBAC rules with `$now` instead of `objectPublishedField`/`objectDepublishedField`
- **Solr** — Disabled for now; all objects indexed regardless (Solr integration to be revisited separately)
- **Date field faceting** — Must be properly tested in Softwarecatalogus since date-based RBAC queries replace the old publication filter
