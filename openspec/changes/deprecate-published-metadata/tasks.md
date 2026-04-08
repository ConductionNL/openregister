# Tasks: Deprecate Published/Depublished Object Metadata

## Phase 1: OpenRegister Core Cleanup (COMPLETED - already done prior to this change)

### MagicMapper Column and Metadata Removal
- [x] Remove `_published` and `_depublished` from `MagicMapper::getBaseMetadataColumns()` (already removed)
- [x] Remove `'published'` from `$metadataColumns` arrays in `ensureTableForRegisterSchema()` (already removed)
- [x] Remove `'published'` from `$idxMetaFields` index definitions (already removed)
- [x] Remove `'published'` and `'depublished'` from `buildInsertData()` metadata fields list (already removed)
- [x] Remove `'published'` and `'depublished'` from datetime conversion check (already removed)
- [x] Remove `'published'` and `'depublished'` from `buildObjectFromRow()` datetime field list (already removed)

### Search and Facet Handlers
- [x] Remove `'published'` and `'depublished'` from `MariaDbSearchHandler` (already removed)
- [x] Remove `'published'` and `'depublished'` from `MetaDataFacetHandler` (already removed)
- [x] Remove `'published'` from `MagicFacetHandler` (already removed)

### SaveObject Metadata Hydration
- [x] Remove `objectPublishedField` processing from `SaveObject::hydrateObjectMetadata()` (already removed)
- [x] Remove `objectDepublishedField` processing (already removed)
- [x] Remove `autoPublish` processing from SaveObject (already removed)
- [x] Add deprecation warning log when these config keys are encountered in schema configuration (#1132)
- [x] Remove published field processing in `setSelfMetadata()` (already removed)

### Search Query Pipeline
- [x] Remove `'published'` and `'depublished'` from `@self` metadata fields in `SearchQueryHandler` (already removed)
- [x] Remove `$params['published']` passing in `SearchQueryHandler` (already removed)

### Index Service (Solr)
- [x] Remove `$published` parameter from `IndexService::searchObjects()` (already removed)
- [x] Remove `$published` parameter from `ObjectHandler::searchObjects()` and `buildSolrQuery()` (already removed)
- [x] Remove `published:true` Solr filter (already removed)
- [x] Remove `$published` parameter from `SearchBackendInterface::searchObjects()` (already removed)

### Controller Cleanup
- [x] Update `ObjectsController` docblock comments (already removed)
- [x] Update `BulkController` class docblock (already removed)
- [x] Remove object publish/depublish methods from `BulkController` (already removed)

### Documentation Updates
- [x] Remove `published`/`depublished` from MultiTenancyTrait documentation about object-level bypass (#1132)

### Import Service
- [x] Remove `addPublishedDateToObjects()` from `ImportService` (#1128)
- [x] Add deprecation warning when `$publish=true` is passed to import methods (#1128)

## Phase 2: Database Migration Verification (COMPLETED)

- [x] Verify `Version1Date20260313130000` migration handles tables where columns don't exist (idempotent) (#1133)
- [x] Test migration on a database with magic tables that have `_published`/`_depublished` columns (#1133)
- [x] Test migration on a database with magic tables that do NOT have these columns (#1133)

## Phase 3: OpenRegister Frontend (COMPLETED)

- [x] Remove `@self.published`/`@self.depublished` from copy object modals (#1129)
- [x] Remove published object stats from all frontend views (#1130)
- [x] Remove auto-publish toggle from ImportRegister modal (#1131)
- [x] Remove published CSS classes from schema modals (#1130)
- [x] Remove published from type definitions and mock data (#1130)

## Phase 4: OpenCatalogi Backend (OUT OF SCOPE - separate repo)

- [ ] Remove `isObjectPublished()` from `EventService`; replace published-state checks with RBAC-based logic
- [ ] Remove `@self.published`/`@self.depublished` reads from `ObjectCreatedEventListener`
- [ ] Remove `isObjectEntityPublished()` and `isObjectPublished()` from `ObjectUpdatedEventListener`
- [ ] Remove `@self.published`/`@self.depublished` reads from `ObjectUpdatedEventListener`
- [ ] Remove `'published'` and `'depublished'` from `$universalOrderFields` in `PublicationsController`
- [ ] Update `PublicationService` docblock examples referencing `@self.published` ordering

## Phase 5: OpenCatalogi Frontend (OUT OF SCOPE - separate repo)

- [ ] Delete `src/modals/object/MassPublishObjects.vue`
- [ ] Delete `src/modals/object/MassDepublishObjects.vue`
- [ ] Delete or repurpose `src/components/PublishedIcon.vue` for RBAC-based visibility
- [ ] Remove `publishObject()`/`depublishObject()` from `src/store/modules/object.js`
- [ ] Remove `published`/`depublished` from publication and attachment entities

## Phase 6: Softwarecatalogus Frontend (OUT OF SCOPE - separate repo)

- [ ] Delete `src/modals/object/MassPublishObjects.vue`
- [ ] Delete `src/modals/object/MassDepublishObjects.vue`
- [ ] Delete or repurpose `src/components/PublishedIcon.vue`

## Phase 7: Schema Migration Guide (OUT OF SCOPE - documentation change)

- [ ] Create migration guide documentation
- [ ] Update existing WOO publication schemas in OpenCatalogi to use RBAC rules
- [ ] Test WOO publication visibility with RBAC `$now` rules end-to-end

## Phase 8: Testing (COMPLETED for OpenRegister scope)

- [x] Test that deprecated schema config keys produce deprecation warning logs (#1133)
- [x] Test that ImportService $publish parameter is deprecated (#1133)
- [x] Test migration idempotency (#1133)
- [ ] Test OpenCatalogi WOO publication schemas with RBAC `$now` rules (separate repo)
- [ ] Test Softwarecatalogus date-based queries (separate repo)
