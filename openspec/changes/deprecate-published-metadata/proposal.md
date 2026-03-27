# Proposal: Deprecate Published/Depublished Object Metadata

## Problem

OpenRegister currently has a dedicated `published`/`depublished` metadata system for objects. This system includes:

- **Database columns**: `_published` and `_depublished` datetime columns on every magic table (dynamic per-register-schema tables with `oc_or_` prefix), plus `published`/`depublished` on the legacy `openregister_objects` table
- **Schema configuration keys**: `objectPublishedField`, `objectDepublishedField`, and `autoPublish` in schema configuration, which auto-hydrate published metadata from object data fields
- **API endpoints**: Dedicated publish/depublish routes for objects (now removed from routes.php but controllers may still have methods)
- **Search/query filtering**: `MagicSearchHandler` and `MagicOrganizationHandler` apply published-based WHERE clauses and visibility checks
- **Solr indexing**: Index backend filters by published status
- **Frontend components**: `MassPublishObjects.vue`, `MassDepublishObjects.vue`, `PublishedIcon.vue` in OpenCatalogi and Softwarecatalogus
- **Cross-app dependencies**: OpenCatalogi's `EventService`, `ObjectCreatedEventListener`, `ObjectUpdatedEventListener`, and `PublicationsController` all read `@self.published`/`@self.depublished` from object metadata

## Why Deprecate

The RBAC conditional rules system now supports a `$now` dynamic variable (already implemented in both `ConditionMatcher` and `MagicRbacHandler`). This makes the dedicated published/depublished metadata redundant:

1. **Redundancy**: Publication control can be expressed as an RBAC authorization rule like `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`. This is more flexible than a binary published/depublished toggle.

2. **Separation of concerns**: Published/depublished conflates two different things -- visibility control (which is an authorization concern) and publication lifecycle timestamps (which are data fields). RBAC rules properly separate these.

3. **Maintenance burden**: Every magic table gets two extra columns (`_published`, `_depublished`) plus indexes, regardless of whether the schema uses publication control. The hydration logic in `SaveObject`, search filtering in `MagicSearchHandler`, and organization bypass in `MagicOrganizationHandler` all add complexity.

4. **Consistency**: Register and Schema entities also have `published`/`depublished` fields, but those serve a different purpose (multi-tenancy bypass). Having the same field names with different semantics at different levels is confusing.

## What Has Already Been Done

Some deprecation work is already in progress:

- `$now` dynamic variable is **implemented** in `ConditionMatcher::resolveDynamicValue()` (ISO 8601 format) and `MagicRbacHandler::resolveDynamicValue()` (SQL datetime format)
- `ObjectEntity` **no longer has** `published`/`depublished` properties (fields were removed)
- Database migration `Version1Date20260313130000` **exists** to drop `_published`/`_depublished` columns from magic tables and the objects table
- Object-level publish/depublish API routes **removed** from `routes.php`

## What Still Needs to Be Done

- `MagicMapper::getBaseMetadataColumns()` still defines `_published` and `_depublished` column specs (lines ~2159-2170)
- `MagicMapper` metadata field lists still include `published`/`depublished` in multiple locations (table creation, column counting, metadata extraction, index definitions)
- `MagicSearchHandler` (`MariaDbSearchHandler`) still lists `published`/`depublished` as date fields and metadata fields
- `MetaDataFacetHandler` still defines published/depublished facet metadata
- `SaveObject::hydrateObjectMetadata()` still processes `objectPublishedField`/`objectDepublishedField`/`autoPublish` schema configuration
- `MagicOrganizationHandler` may still apply published-based visibility checks
- `SearchQueryHandler` still passes `published` parameter through the query pipeline
- `IndexService`/`ObjectHandler` (Solr) still accept and apply `$published` filter parameter
- `SearchTrailMapper` still tracks `published_only` flag
- OpenCatalogi listeners and services still read `@self.published`/`@self.depublished` from object data
- OpenCatalogi `PublicationsController` still lists `published`/`depublished` as universal order fields
- Frontend components (`MassPublishObjects.vue`, `MassDepublishObjects.vue`, `PublishedIcon.vue`) still exist in OpenCatalogi and Softwarecatalogus
- Frontend stores still have `publishObject()`/`depublishObject()` actions

## Impact

### OpenRegister (primary)
- ~15 PHP files need modification
- 1 migration already exists
- MagicMapper column definitions, metadata lists, and index definitions
- SaveObject metadata hydration
- Search/query pipeline (SearchQueryHandler, MagicSearchHandler, IndexService)
- MetaDataFacetHandler facet definitions

### OpenCatalogi (significant)
- EventService published-state checking
- ObjectCreatedEventListener and ObjectUpdatedEventListener metadata reading
- PublicationsController universal order fields
- Frontend: MassPublishObjects.vue, MassDepublishObjects.vue, PublishedIcon.vue
- Store actions for publish/depublish
- WOO publication schemas need RBAC rule migration

### Softwarecatalogus (moderate)
- Frontend: MassPublishObjects.vue, MassDepublishObjects.vue, PublishedIcon.vue
- Store plugins that reference published state

### Pipelinq (minimal)
- Only references in specs/docs, no code dependencies

## Migration Strategy

1. **Phase 1 - Code removal in OpenRegister**: Remove published/depublished from MagicMapper column definitions, metadata lists, SaveObject hydration, search filtering, and Solr indexing
2. **Phase 2 - Cross-app updates**: Update OpenCatalogi and Softwarecatalogus to use RBAC-based authorization rules with `$now` instead of published metadata
3. **Phase 3 - Schema migration**: Update existing WOO/publication schemas to use authorization rules with `$now` instead of `objectPublishedField`/`objectDepublishedField`
4. **Phase 4 - Frontend cleanup**: Remove MassPublish/MassDepublish components and replace with RBAC-based UI

Note: Register and Schema publish/depublish endpoints and fields are **out of scope** -- those serve the multi-tenancy bypass system and are a separate concern. File publish/depublish endpoints are also out of scope as they control Nextcloud file sharing, not object metadata.
