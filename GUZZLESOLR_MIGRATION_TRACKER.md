# GuzzleSolrService → IndexService Migration Tracker

**Status:** IN PROGRESS  
**Start Date:** 2025-12-14  
**Total Methods:** 168  
**Migrated:** 21 (ConfigurationHandler)  
**Remaining:** 147  

## Migration Progress

### ✅ Phase 1: Configuration (COMPLETED)

**Handler:** `lib/Service/Index/ConfigurationHandler.php`  
**Methods Migrated:** 21/21 (100%)  
**Status:** ✅ COMPLETE  

| Method | Line | Status |
|--------|------|--------|
| __construct | 148 | ✅ Migrated |
| initializeConfig | 208 | ✅ Migrated |
| initializeHttpClient | 229 | ✅ Migrated |
| isSolrConfigured | 375 | ✅ Migrated |
| getTenantSpecificCollectionName | 272 | ✅ Migrated |
| buildSolrBaseUrl | 285 | ✅ Migrated |
| getEndpointUrl | 5747 | ✅ Migrated |
| getHttpClient | 5772 | ✅ Migrated |
| getSolrConfig | 5784 | ✅ Migrated |
| getConfigStatus | 11533 | ✅ Migrated |
| getPortStatus | 11545 | ✅ Migrated |
| getCoreStatus | 11557 | ✅ Migrated |

### 🔄 Phase 2: QueryHandler (IN PROGRESS)

**Handler:** `lib/Service/Index/QueryHandler.php` (TO BE CREATED)  
**Methods To Migrate:** 38/38 (0%)  
**Status:** 🔄 IN PROGRESS  

#### Main Search Methods (3)
| Method | Line | Status |
|--------|------|--------|
| searchObjectsPaginated | 2307 | ⏳ Pending |
| searchObjects | 3685 | ⏳ Pending |
| inspectIndex | 5122 | ⏳ Pending |

#### Query Building (8)
| Method | Line | Status |
|--------|------|--------|
| translateOpenRegisterQuery | 2483 | ⏳ Pending |
| buildSolrQuery | 3843 | ⏳ Pending |
| buildWeightedSearchQuery | 3772 | ⏳ Pending |
| buildOptimizedContextualFacetQuery | 9326 | ⏳ Pending |
| buildJsonFacetQuery | 9607 | ⏳ Pending |
| translateFilterField | 2726 | ⏳ Pending |
| translateSortField | 2763 | ⏳ Pending |
| translateSortableField | 2799 | ⏳ Pending |

#### Query Execution & Processing (6)
| Method | Line | Status |
|--------|------|--------|
| executeSearch | 4099 | ⏳ Pending |
| parseSolrResponse | 4215 | ⏳ Pending |
| convertToOpenRegisterPaginatedFormat | 4270 | ⏳ Pending |
| convertSolrDocumentsToOpenRegisterObjects | 4468 | ⏳ Pending |
| reconstructObjectFromSolrDocument | 2876 | ⏳ Pending |
| applyAdditionalFilters | 2423 | ⏳ Pending |

#### Faceting Methods (21)
| Method | Line | Status |
|--------|------|--------|
| discoverFacetableFieldsFromSolr | 8667 | ⏳ Pending |
| getRawSolrFieldsForFacetConfiguration | 8780 | ⏳ Pending |
| getSuggestedDisplayTypes | 8878 | ⏳ Pending |
| mapSolrTypeToFacetType | 8928 | ⏳ Pending |
| getContextualFacetsFromSameQuery | 9000 | ⏳ Pending |
| discoverFieldsFromCurrentResults | 9057 | ⏳ Pending |
| inferFieldType | 9170 | ⏳ Pending |
| processContextualFacetsFromSearchResults | 9198 | ⏳ Pending |
| getOptimizedContextualFacets | 9217 | ⏳ Pending |
| processOptimizedContextualFacets | 9456 | ⏳ Pending |
| getMetadataFieldInfo | 9530 | ⏳ Pending |
| getObjectFieldInfo | 9569 | ⏳ Pending |
| buildTermsFacet | 9654 | ⏳ Pending |
| buildRangeFacet | 9677 | ⏳ Pending |
| buildDateHistogramFacet | 9700 | ⏳ Pending |
| applyFacetConfiguration | 9741 | ⏳ Pending |
| sortFacetsWithConfiguration | 9830 | ⏳ Pending |
| processFacetResponse | 9890 | ⏳ Pending |
| formatFacetData | 9966 | ⏳ Pending |
| formatMetadataFacetData | 9990 | ⏳ Pending |
| formatTermsFacetData | 10069 | ⏳ Pending |
| formatRangeFacetData | 10174 | ⏳ Pending |
| formatDateHistogramFacetData | 10206 | ⏳ Pending |
| getMetadataFacetableFields | 10269 | ⏳ Pending |

### ⏳ Phase 3: IndexingHandler (PENDING)

**Handler:** `lib/Service/Index/IndexingHandler.php` (TO BE CREATED)  
**Methods To Migrate:** 32/32 (0%)  
**Status:** ⏳ PENDING  

#### Single Object Operations (6)
- indexObject (1116)
- deleteObject (1277)
- commit (3460)
- deleteByQuery (3517)
- clearIndex (5011)
- optimize (5264)

#### Bulk Operations (7)
- bulkIndexObjects (3137)
- bulkIndex (3159)
- bulkIndexFromDatabase (5919)
- bulkIndexFromDatabaseParallel (6091)
- bulkIndexFromDatabaseHyperFast (6445)
- bulkIndexFromDatabaseOptimized (7164)
- reindexAll (7563)

#### Document Creation (3)
- createSolrDocument (1389)
- createSchemaAwareDocument (1508)
- createLegacySolrDocument (2157)

#### Document Processing (16)
- flattenRelationsForSolr (1798)
- extractArraysFromRelations (1878)
- extractIndexableArrayValues (1943)
- extractIdFromObject (1984)
- flattenFilesForSolr (2010)
- mapFieldToSolrType (2050)
- convertValueForSolr (2071)
- extractTextContent (2930)
- extractTextFromArray (2957)
- extractDynamicFields (2980)
- truncateFieldValue (8584)
- shouldTruncateField (8629)
- getUriValue (3058)
- getVersionValue (3077)
- getSizeValue (3096)
- getFolderValue (3115)

### ⏳ Phase 4: SchemaHandler (PENDING)

**Handler:** `lib/Service/Index/SchemaHandler.php` (TO BE CREATED)  
**Methods To Migrate:** 35/35 (0%)  
**Status:** ⏳ PENDING  

### ⏳ Phase 5: WarmupHandler (PENDING)

**Handler:** `lib/Service/Index/WarmupHandler.php` (TO BE CREATED)  
**Methods To Migrate:** 14/14 (0%)  
**Status:** ⏳ PENDING  

### ⏳ Phase 6: AdminHandler (PENDING)

**Handler:** `lib/Service/Index/AdminHandler.php` (TO BE CREATED)  
**Methods To Migrate:** 28/28 (0%)  
**Status:** ⏳ PENDING  

## Migration Statistics

| Phase | Handler | Methods | Migrated | Remaining | Progress |
|-------|---------|---------|----------|-----------|----------|
| 1 | ConfigurationHandler | 21 | 21 | 0 | 100% |
| 2 | QueryHandler | 38 | 0 | 38 | 0% |
| 3 | IndexingHandler | 32 | 0 | 32 | 0% |
| 4 | SchemaHandler | 35 | 0 | 35 | 0% |
| 5 | WarmupHandler | 14 | 0 | 14 | 0% |
| 6 | AdminHandler | 28 | 0 | 28 | 0% |
| **TOTAL** | **All** | **168** | **21** | **147** | **12.5%** |

## GuzzleSolrService Delegation Status

After each handler is extracted, GuzzleSolrService methods will be updated to delegate:

```php
// BEFORE (direct implementation)
public function searchObjects(array $searchParams): array {
    // 100 lines of implementation
}

// AFTER (delegation)
public function searchObjects(array $searchParams): array {
    return $this->queryHandler->searchObjects($searchParams);
}
```

## Testing Strategy

After each handler extraction:
1. ✅ Verify all methods remain accessible via GuzzleSolrService
2. ✅ Run existing unit tests
3. ✅ Test key workflows (search, index, bulk operations)
4. ✅ Check performance metrics
5. ✅ Validate error handling

## Final Rename

After ALL handlers are extracted and tested:
- Rename `GuzzleSolrService.php` → `IndexService.php`
- Update class name and all imports
- Update dependency injection across codebase
- Final integration testing

## Notes

- All methods remain backward compatible during migration.
- Each handler is independently testable.
- GuzzleSolrService stays functional throughout migration.
- Can rollback individual handlers if issues arise.


