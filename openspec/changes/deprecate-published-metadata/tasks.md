## Tasks

### Phase 1: Add `$now` dynamic variable

- [ ] Add `$now` support to `ConditionMatcher::resolveDynamicValue()` — resolve to `(new DateTime())->format('c')`
- [ ] Add `$now` support to `MagicRbacHandler::resolveDynamicValue()` — resolve to `(new DateTime())->format('Y-m-d H:i:s')`
- [ ] Update `MagicRbacHandler` operator value resolution to resolve `$now` inside `{"$lte": "$now"}` style expressions
- [ ] Add unit tests for `$now` in `ConditionMatcher` (past date match, future date deny)
- [ ] Add unit tests for `$now` in RBAC comprehensive test suite (query-level filtering)

### Phase 2: Remove published/depublished backend

- [ ] Remove `published`/`depublished` properties from `ObjectEntity` (field declarations, constructor registration, `getObjectArray()` serialization)
- [ ] Delete `PublishHandler` class (`lib/Service/Object/PublishHandler.php`)
- [ ] Remove `publish()`/`depublish()` from `ObjectsController`
- [ ] Remove `publish()`/`depublish()`/`publishSchema()` from `BulkController`
- [ ] Remove publish/depublish routes from `appinfo/routes.php`
- [ ] Remove `objectPublishedField`/`objectDepublishedField` processing from `SaveObject::hydrateObjectMetadata()`
- [ ] Remove `autoPublish` handling from `SaveObject`
- [ ] Remove `applyPublishedFilter()` from `ObjectEntityMapper`
- [ ] Remove published-based WHERE clauses from `MagicSearchHandler`
- [ ] Remove published-based visibility checks from `MagicOrganizationHandler`
- [ ] Remove `_published`/`_depublished` from magic table column definitions in `MagicMapper`
- [ ] Remove `_published` index creation from `MagicMapper`
- [ ] Remove `isPublished()` method and any references throughout the codebase

### Phase 3: Database migration

- [ ] Create migration to drop `_published` and `_depublished` columns from all existing magic tables
- [ ] Make migration idempotent (check column existence before dropping)

### Phase 4: Remove frontend

- [ ] Delete `src/modals/object/MassPublishObjects.vue`
- [ ] Delete `src/modals/object/MassDepublishObjects.vue`
- [ ] Remove `publishObject()`/`depublishObject()` from `src/store/modules/object.js`
- [ ] Remove `published`/`depublished` from `src/entities/object/object.types.ts`
- [ ] Remove any publish/depublish buttons or menu items from object list/detail views

### Phase 5: Disable Solr published filter

- [ ] Remove published-only indexing filter from Solr integration
- [ ] Verify all objects are indexed regardless of any field value

### Phase 6: Update OpenCatalogi WOO schemas

- [ ] Update WOO register/schema configurations to use RBAC authorization with `$now` rules
- [ ] Remove `objectPublishedField`/`objectDepublishedField` from schema configs
- [ ] Test WOO publication visibility with the new RBAC rules

### Phase 7: Testing

- [ ] Test date field faceting in Softwarecatalogus with date-based RBAC queries
- [ ] Verify unauthenticated access works correctly with RBAC `$now` rules (replaces old published filter)
- [ ] Verify admin users bypass `$now` rules
- [ ] Verify 404 on all removed endpoints
