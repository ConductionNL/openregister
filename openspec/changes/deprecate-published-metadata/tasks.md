# Tasks: Deprecate Published/Depublished Object Metadata

## Phase 1: OpenRegister Core Cleanup

### MagicMapper Column and Metadata Removal
- [ ] Remove `_published` and `_depublished` from `MagicMapper::getBaseMetadataColumns()` (~lines 2159-2170)
- [ ] Remove `'published'` from `$metadataColumns` array in `ensureTableForRegisterSchema()` table creation path (~line 1789)
- [ ] Remove `'published'` from `$metadataColumns` array in `ensureTableForRegisterSchema()` table update path (~line 1841)
- [ ] Remove `'published'` from `$idxMetaFields` index definitions (~line 2808)
- [ ] Remove `'published'` and `'depublished'` from `buildInsertData()` metadata fields list (~lines 3063-3064)
- [ ] Remove `'published'` and `'depublished'` from datetime conversion check in `buildInsertData()` (~line 3072)
- [ ] Remove `'published'` and `'depublished'` from `buildObjectFromRow()` datetime field list (~lines 3287-3288)

### Search and Facet Handlers
- [ ] Remove `'published'` and `'depublished'` from `MariaDbSearchHandler` metadata fields (~lines 62-63) and `DATE_FIELDS` constant (~line 71)
- [ ] Remove `'published'` and `'depublished'` from `MetaDataFacetHandler` column mapping (~line 134) and facet definitions (~lines 1319-1328)
- [ ] Remove `'published'` from `MagicFacetHandler` date field check (~line 951)

### SaveObject Metadata Hydration
- [ ] Remove `objectPublishedField` processing from `SaveObject::hydrateObjectMetadata()`
- [ ] Remove `objectDepublishedField` processing from `SaveObject::hydrateObjectMetadata()`
- [ ] Remove `autoPublish` processing from `SaveObject`
- [ ] Add deprecation warning log when these config keys are encountered in schema configuration
- [ ] Remove published field processing in `setSelfMetadata()` (~line 3299+)

### Search Query Pipeline
- [ ] Remove `'published'` and `'depublished'` from `@self` metadata fields in `SearchQueryHandler` (~lines 173-174)
- [ ] Remove `$params['published']` passing in `SearchQueryHandler` (~line 156)

### Index Service (Solr)
- [ ] Remove `$published` parameter from `IndexService::searchObjects()` method signature
- [ ] Remove `$published` parameter from `ObjectHandler::searchObjects()` and `buildSolrQuery()`
- [ ] Remove `published:true` Solr filter application in `ObjectHandler::buildSolrQuery()` (~line 156-157)
- [ ] Remove `$published` parameter from `SearchBackendInterface::searchObjects()` interface

### Controller Cleanup
- [ ] Update `ObjectsController` docblock comments to remove `published`/`depublished` from metadata filter documentation
- [ ] Update `BulkController` class docblock to remove publish/depublish references
- [ ] Remove any remaining object publish/depublish methods from `BulkController` if present

### Documentation Updates
- [ ] Remove `published`/`depublished` from MultiTenancyTrait documentation comments about object-level bypass

## Phase 2: Database Migration Verification

- [ ] Verify `Version1Date20260313130000` migration handles tables where columns don't exist (idempotent)
- [ ] Test migration on a database with magic tables that have `_published`/`_depublished` columns
- [ ] Test migration on a database with magic tables that do NOT have these columns

## Phase 3: OpenRegister Frontend

- [ ] Remove `objectPublishedField`/`objectDepublishedField`/`autoPublish` config UI from `src/modals/schema/EditSchema.vue`

## Phase 4: OpenCatalogi Backend

- [ ] Remove `isObjectPublished()` from `EventService`; replace published-state checks with RBAC-based logic
- [ ] Remove `@self.published`/`@self.depublished` reads from `ObjectCreatedEventListener`
- [ ] Remove `isObjectEntityPublished()` and `isObjectPublished()` from `ObjectUpdatedEventListener`
- [ ] Remove `@self.published`/`@self.depublished` reads from `ObjectUpdatedEventListener`
- [ ] Remove `'published'` and `'depublished'` from `$universalOrderFields` in `PublicationsController`
- [ ] Update `PublicationService` docblock examples referencing `@self.published` ordering

## Phase 5: OpenCatalogi Frontend

- [ ] Delete `src/modals/object/MassPublishObjects.vue`
- [ ] Delete `src/modals/object/MassDepublishObjects.vue`
- [ ] Delete or repurpose `src/components/PublishedIcon.vue` for RBAC-based visibility
- [ ] Remove `publishObject()`/`depublishObject()` from `src/store/modules/object.js`
- [ ] Remove `published`/`depublished` from `src/entities/publication/publication.ts` and `publication.types.ts`
- [ ] Remove `published`/`depublished` from `src/entities/attachment/attachment.ts` and `attachment.types.ts`

## Phase 6: Softwarecatalogus Frontend

- [ ] Delete `src/modals/object/MassPublishObjects.vue`
- [ ] Delete `src/modals/object/MassDepublishObjects.vue`
- [ ] Delete or repurpose `src/components/PublishedIcon.vue`

## Phase 7: Schema Migration Guide

- [ ] Create migration guide documentation showing how to convert `objectPublishedField`/`objectDepublishedField` schemas to RBAC authorization rules with `$now`
- [ ] Update existing WOO publication schemas in OpenCatalogi to use RBAC rules
- [ ] Test WOO publication visibility with RBAC `$now` rules end-to-end

## Phase 8: Testing

- [ ] Verify RBAC `$now` unit tests exist in `ConditionMatcher` tests (both direct `$now` and `{"$lte": "$now"}` operator format)
- [ ] Verify RBAC `$now` unit tests exist in `MagicRbacHandler` tests
- [ ] Test that deprecated schema config keys (`objectPublishedField`, `objectDepublishedField`, `autoPublish`) produce deprecation warning logs
- [ ] Test that object creation/update works without published metadata
- [ ] Test that search/faceting works without published columns
- [ ] Test Solr indexing without published filter
- [ ] Test OpenCatalogi WOO publication schemas with RBAC `$now` rules
- [ ] Test Softwarecatalogus date-based queries work correctly without published metadata
