# ObjectService Refactoring Plan

## Current State (CRITICAL)
- **5,316 lines** (431% over 1,000 threshold)
- **83 methods** (232% over 25 threshold)
- **30 public methods** (200% over 10 threshold)
- **Complexity: 522** (944% over 50 threshold)
- **50 dependencies** (285% over 13 threshold)
- **27 constructor parameters** (170% over 10 threshold)

## SaveObject/SaveObjects Status
- SaveObject: **3,696 lines** (270% over threshold) - Needs more extraction
- SaveObjects: **2,277 lines** (128% over threshold) - Needs more extraction

## Handler Extraction Strategy

### Phase 1: ObjectService Handlers (In Progress)
Located in: `lib/Service/ObjectService/`

1. ✅ **ValidationHandler** - COMPLETE
   - `handleValidationException()`
   - `validateRequiredFields()`
   - `validateObjectsBySchema()`
   
2. 🔄 **QueryHandler** - IN PROGRESS
   - `find()`, `findSilent()`, `findAll()`, `count()`
   - `findByRelations()`, `searchObjects()`, `searchObjectsPaginated()`
   - `countSearchObjects()`, `buildSearchQuery()`
   - `applyViewsToQuery()`, `isSolrAvailable()`
   - `searchObjectsPaginatedDatabase()`, `searchObjectsPaginatedAsync()`
   - `searchObjectsPaginatedSync()`, `cleanQuery()`
   
3. ⏳ **FacetHandler**
   - `getFacetsForObjects()`, `getFacetableFields()`
   - `getMetadataFacetableFields()`, `getFacetCount()`
   
4. ⏳ **RelationHandler**
   - `findByRelations()`, `extractRelatedData()`
   - `extractAllRelationshipIds()`, `bulkLoadRelationshipsBatched()`
   - `bulkLoadRelationshipsParallel()`, `loadRelationshipChunkOptimized()`
   - `applyInversedByFilter()`
   
5. ⏳ **BulkOperationsHandler**
   - `saveObjects()`, `deleteObjects()`, `publishObjects()`
   - `depublishObjects()`, `publishObjectsBySchema()`
   - `deleteObjectsBySchema()`, `deleteObjectsByRegister()`
   - `filterObjectsForPermissions()`, `filterUuidsForPermissions()`
   
6. ⏳ **MetadataHandler**
   - `getValueFromPath()`, `generateSlugFromValue()`, `createSlugHelper()`
   
7. ⏳ **MigrationHandler**
   - `migrateObjects()`, `mapObjectProperties()`
   
8. ⏳ **MergeHandler**
   - `mergeObjects()`, `transferObjectFiles()`, `deleteObjectFiles()`
   
9. ⏳ **UtilityHandler**
   - `ensureObjectFolderExists()`, `isUuid()`, `normalizeToArray()`
   - `normalizeEntity()`, `getUrlSeparator()`, `calculateAvgPerObject()`
   - `calculateTotalPages()`, `calculateExtendCount()`, `calculateEfficiency()`
   - `getMemoryLimitInBytes()`, `calculateOptimalBatchSize()`
   - `createLightweightObjectEntity()`, `getCachedEntities()`

### Phase 2: SaveObject Additional Handlers
Located in: `lib/Service/Objects/SaveObject/`

1. ⏳ **RelationScanHandler** - Extract from SaveObject
   - `scanForRelations()` (105 lines)
   
2. ⏳ **ImageMetadataHandler** - Extract from SaveObject
   - Complex image field processing from `hydrateObjectMetadata()` (215 lines)
   
3. ⏳ **CascadeHandler** - Extract remaining cascade methods
   - `cascadeObjects()` (132 lines)
   - `handleInverseRelationsWriteBack()` (144 lines)

### Phase 3: SaveObjects Additional Handlers
Located in: `lib/Service/Objects/SaveObjects/`

1. ⏳ **TransformationHandler**
   - `transformObjectsToDatabaseFormatInPlace()` (169 lines)
   
2. ⏳ **ChunkProcessingHandler**
   - `processObjectsChunk()` (287 lines)
   - `prepareObjectsForBulkSave()` (177 lines)
   - `prepareSingleSchemaObjectsOptimized()` (242 lines)

## Target State
- ObjectService: < 1,000 lines, < 25 methods
- SaveObject: < 1,000 lines
- SaveObjects: < 1,000 lines
- All handlers: < 500 lines each

## Progress Tracking
- [ ] Phase 1: ObjectService Handlers (1/9 complete)
- [ ] Phase 2: SaveObject Additional Handlers (0/3 complete)
- [ ] Phase 3: SaveObjects Additional Handlers (0/2 complete)
- [ ] Final PHPQA validation
