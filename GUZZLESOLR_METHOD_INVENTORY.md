# GuzzleSolrService Method Inventory & Categorization

**Total Methods:** 168
**Current File Size:** 11,728 lines
**Target:** Migrate to IndexService with specialized handlers

## Handler Categories

### 1. AdminHandler (Collection & Config Management) - 28 methods

**Collection Management:**
- Line 772: `collectionExists(string $collectionName): bool`
- Line 800: `ensureTenantCollection(): array | bool`
- Line 851: `getActiveCollectionName(): ?string`
- Line 893: `getTenantCollectionName(): ?string`
- Line 916: `createCollection(...)`
- Line 1025: `deleteCollection(?string $collectionName = null): array`
- Line 10704: `listCollections(): array`
- Line 11043: `copyCollection(string $sourceCollection, string $targetCollection, bool $copyData=false): array`

**ConfigSet Management:**
- Line 10816: `listConfigSets(): array`
- Line 10893: `createConfigSet(string $name, string $baseConfigSet='_default'): array`
- Line 10970: `deleteConfigSet(string $name): array`

**Connection & Health:**
- Line 422: `isAvailable(bool $forceRefresh=false): bool`
- Line 651: `testConnection(bool $includeCollectionTests=true): array`
- Line 743: `testConnectivityOnly(): array`
- Line 758: `testFullOperationalReadiness(): array`
- Line 4584: `testZookeeperConnection(): array`
- Line 4657: `testSolrConnectivity(): array`
- Line 4773: `testSolrCollection(): array`
- Line 4919: `testSolrQuery(): array`
- Line 5707: `testConnectionForDashboard(): array`
- Line 11695: `getCollectionHealth(array $allActive): string`
- Line 11716: `getCollectionStatus(array $allActive): string`

**Stats & Dashboard:**
- Line 1347: `getDocumentCount(): int`
- Line 5322: `getDashboardStats(): array`
- Line 5613: `getCollectionStats(string $collectionName): array`
- Line 5645: `getDocumentCountForCollection(string $collectionName): int`
- Line 5686: `getStats(): array`
- Line 11227: `getFileIndexStats(): array`

**Configuration:**
- Line 272: `getTenantSpecificCollectionName(string $baseCollectionName): string`
- Line 285: `buildSolrBaseUrl(): string`

### 2. IndexingHandler (Document Indexing) - 32 methods

**Single Object Indexing:**
- Line 1116: `indexObject(ObjectEntity $object, bool $commit=false): bool`
- Line 1277: `deleteObject(string|int $objectId, bool $commit=false): bool`
- Line 3460: `commit(): bool`
- Line 3517: `deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): array|bool`
- Line 5011: `clearIndex(?string $collectionName=null): array`
- Line 5264: `optimize(): bool`

**Bulk Indexing:**
- Line 3137: `bulkIndexObjects(array $objects, bool $commit=true): array`
- Line 3159: `bulkIndex(array $documents, bool $commit=false): bool`
- Line 5919: `bulkIndexFromDatabase(int $batchSize = 1000, int $maxObjects = 0, array $solrFieldTypes = [], array $schemaIds = []): array`
- Line 6091: `bulkIndexFromDatabaseParallel(int $batchSize = 1000, int $maxObjects = 0, int $parallelBatches = 4, array $solrFieldTypes = [], array $schemaIds = []): array`
- Line 6445: `bulkIndexFromDatabaseHyperFast(int $batchSize = 5000, int $maxObjects = 10000): array`
- Line 7164: `bulkIndexFromDatabaseOptimized(int $batchSize = 1000, int $maxObjects = 0, array $solrFieldTypes = [], array $schemaIds = []): array`
- Line 7563: `reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array`

**Batch Processing:**
- Line 6238: `processBatchDirectly($objectMapper, array $job, array $schemaIds = []): array`
- Line 6354: `processBatchAsync($objectMapper, array $job): \React\Promise\PromiseInterface`

**Document Creation:**
- Line 1389: `createSolrDocument(ObjectEntity $object, array $solrFieldTypes=[]): array`
- Line 1508: `createSchemaAwareDocument(ObjectEntity $object, Schema $schema, $register=null, array $solrFieldTypes=[]): array`
- Line 2157: `createLegacySolrDocument(ObjectEntity $object): array`

**Document Flattening & Conversion:**
- Line 1798: `flattenRelationsForSolr($relations): array`
- Line 1878: `extractArraysFromRelations(array $relations): array`
- Line 1943: `extractIndexableArrayValues(array $arrayValue, string $fieldName): array`
- Line 1984: `extractIdFromObject(array $object): ?string`
- Line 2010: `flattenFilesForSolr($files): array`
- Line 2050: `mapFieldToSolrType(string $fieldName, string $_fieldType, $_fieldValue): ?string`
- Line 2071: `convertValueForSolr($value, string $fieldType)`
- Line 2930: `extractTextContent(ObjectEntity $object, array $objectData): string`
- Line 2957: `extractTextFromArray(array $data, array &$textParts): void`
- Line 2980: `extractDynamicFields(array $objectData, string $prefix = ''): array`
- Line 8584: `truncateFieldValue($value, string $fieldName=''): mixed`
- Line 8629: `shouldTruncateField(string $fieldName, array $fieldDefinition=[]): bool`

**Value Extraction:**
- Line 3058: `getUriValue(ObjectEntity $object): string|null`
- Line 3077: `getVersionValue(ObjectEntity $object): string|null`
- Line 3096: `getSizeValue(ObjectEntity $object): ?int`
- Line 3115: `getFolderValue(ObjectEntity $object): string|null`

**File Indexing:**
- Line 11136: `indexFiles(array $fileIds, ?string $collectionName=null): array`

### 3. QueryHandler (Search & Query) - 38 methods

**Main Search:**
- Line 2307: `searchObjectsPaginated(array $query=[], bool $_rbac=true, bool $_multitenancy=true, bool $published=false, bool $deleted=false): array`
- Line 3685: `searchObjects(array $searchParams): array`
- Line 5122: `inspectIndex(string $query='*:*', int $start=0, int $rows=20, string $fields=''): array`

**Query Building:**
- Line 2483: `translateOpenRegisterQuery(array $query): array`
- Line 3843: `buildSolrQuery(array $query): array`
- Line 3772: `buildWeightedSearchQuery(string $searchTerm): string`
- Line 9326: `buildOptimizedContextualFacetQuery(array $filterQueries=[]): array`
- Line 9607: `buildJsonFacetQuery(array $facetableFields): array`

**Query Translation:**
- Line 2726: `translateFilterField(string $field): string`
- Line 2763: `translateSortField(array|string $order): string`
- Line 2799: `translateSortableField(string $field): string`

**Filters & Security:**
- Line 2423: `applyAdditionalFilters(array &$solrQuery, bool $_rbac, bool $_multitenancy, bool $_published, bool $_deleted): void`
- Line 2454: `getActiveOrganisationUuid(): ?string`

**Query Execution:**
- Line 4099: `executeSearch(array $solrQuery, string $collectionName, array $_extend=[]): array`

**Response Processing:**
- Line 4215: `parseSolrResponse(array $responseData, array $_extend=[]): array`
- Line 4270: `convertToOpenRegisterPaginatedFormat(array $searchResults, array $originalQuery, array $solrQuery=null): array`
- Line 4468: `convertSolrDocumentsToOpenRegisterObjects(array $solrDocuments=[], $_extend=[]): array`
- Line 2876: `reconstructObjectFromSolrDocument(array $doc): ?ObjectEntity`

**Faceting:**
- Line 8667: `discoverFacetableFieldsFromSolr(): array`
- Line 8780: `getRawSolrFieldsForFacetConfiguration(): array`
- Line 8878: `getSuggestedDisplayTypes(array $field): array`
- Line 8928: `mapSolrTypeToFacetType(string $solrType): string`
- Line 9057: `discoverFieldsFromCurrentResults(array $solrResponse): array`
- Line 9170: `inferFieldType(array $values): string`
- Line 9198: `processContextualFacetsFromSearchResults(array $_searchFacets): array`
- Line 9217: `getOptimizedContextualFacets(array $filters=[]): array`
- Line 9456: `processOptimizedContextualFacets(array $facetData): array`
- Line 9530: `getMetadataFieldInfo(string $fieldKey): array`
- Line 9569: `getObjectFieldInfo(string $fieldName): array`
- Line 9654: `buildTermsFacet(string $fieldName, int $limit=1000): array`
- Line 9677: `buildRangeFacet(string $fieldName): array`
- Line 9700: `buildDateHistogramFacet(string $fieldName): array`
- Line 9741: `applyFacetConfiguration(array $facetData, string $fieldName): array`
- Line 9830: `sortFacetsWithConfiguration(array $facets): array`
- Line 9890: `processFacetResponse(array $facetData, array $facetableFields): array`
- Line 9966: `formatFacetData(array $rawFacetData, string $facetType): array`
- Line 9990: `formatMetadataFacetData(array $rawData, string $fieldName, string $facetType): array`
- Line 10069: `formatTermsFacetData(array $rawData): array`
- Line 10174: `formatRangeFacetData(array $rawData): array`
- Line 10206: `formatDateHistogramFacetData(array $rawData): array`
- Line 10269: `getMetadataFacetableFields(): array`
- Line 9000: `getContextualFacetsFromSameQuery(array $solrQuery, array $_originalQuery): array`

### 4. SchemaHandler (Field & Schema Management) - 35 methods

**Field Configuration:**
- Line 6729: `getSolrFieldTypes(bool $forceRefresh = false): array`
- Line 7049: `validateFieldForSolr(string $fieldName, $fieldValue, array $solrFieldTypes): bool`
- Line 7103: `isValueCompatibleWithSolrType($value, string $solrFieldType): bool`
- Line 7296: `fixMismatchedFields(array $mismatchedFields, bool $dryRun=false): array`
- Line 7503: `deleteFieldFromSolr(string $fieldName): array`
- Line 7821: `createMissingFields(array $expectedFields=[], bool $_dryRun=false): array`
- Line 8060: `prepareSolrFieldConfig(string $fieldName, array $fieldConfig): array`

**Field Discovery & Extraction:**
- Line 8099: `getFieldsConfiguration(): array`
- Line 8213: `extractFields(array $schema): array`
- Line 8248: `extractSchemaDynamicFields(array $schema): array`
- Line 8278: `extractFieldTypes(array $schema): array`
- Line 8308: `extractCoreInfo(array $schema, string $collectionName): array`
- Line 8331: `generateEnvironmentNotes(array $schema): array`
- Line 10374: `getObjectFieldInfoFromSchema(string $fieldName)`

**Metadata Field Resolution:**
- Line 10408: `resolveRegisterLabels(array $ids): array`
- Line 10448: `resolveSchemaLabels(array $ids): array`
- Line 10488: `resolveOrganisationLabels(array $ids): array`
- Line 10531: `resolveRegisterToId($registerValue, ?\OCA\OpenRegister\Db\Register $register=null): int`
- Line 10585: `resolveSchemaToId($schemaValue, ?\OCA\OpenRegister\Db\Schema $schema=null): int`
- Line 10647: `resolveMetadataValueToId(string $fieldType, string|int $value): int`

**Testing & Validation:**
- Line 6589: `testSchemaAwareMapping($objectMapper, $schemaMapper): array`

**Helper Methods:**
- Line 2050: `mapFieldToSolrType(string $fieldName, string $_fieldType, $_fieldValue): ?string`
- Line 3029: `isAssociativeArray(array $array): bool`
- Line 3043: `escapeSolrValue(string $value): string`
- Line 3818: `cleanSearchTerm(string $term): string`
- Line 11354: `getObjectDataOrDefault($objectData): array`
- Line 11372: `formatDateTimeField(?\DateTime $dateTime): ?string`
- Line 11390: `encodeJsonField($data): string|false|null`
- Line 11408: `formatQueryStructureValue($v): string`
- Line 11426: `getBasePrefix(string $prefix): string`
- Line 11444: `getSolrTypeFromArray(array $solrType): string`
- Line 11462: `getSelfRelationsType(array $document): string`
- Line 11491: `getSelfRelationsCount(array $document): int`
- Line 11514: `getSelfObjectJson(ObjectEntity $object): string`
- Line 11591: `getNumericType($value): string`
- Line 11617: `getFieldNameFromFacetKey(string $facetKey): string`
- Line 11637: `getFacetConfigKey(bool $isMetadataField, string $facetKey, string $fieldName): string`
- Line 11655: `getFacetKeys(array $data): array`
- Line 11673: `getObjectFacetKeys(array $data): array`

### 5. WarmupHandler (Cache & Warmup) - 14 methods

**Warmup Operations:**
- Line 6789: `warmupIndex(array $schemas=[], int $maxObjects=0, string $mode='serial', bool $collectErrors=false, int $batchSize=1000, array $schemaIds=[]): array`
- Line 8385: `predictWarmupMemoryUsage(int $maxObjects, array $schemaIds=[]): array`
- Line 8474: `generateMemoryReport(int $initialUsage, int $finalUsage, int $initialPeak, int $finalPeak, array $prediction): array`

**Memory Management:**
- Line 8528: `parseMemoryLimit(string $memoryLimit): int`
- Line 8559: `formatBytes(int|float $bytes): string`
- Line 11572: `calculatePredictionAccuracy($estimated, $actual): float`

**Object Fetching:**
- Line 5797: `countSearchableObjects(\OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper, array $schemaIds = []): int`
- Line 5842: `fetchSearchableObjects(\OCA\OpenRegister\Db\ObjectEntityMapper $objectMapper, int $limit, int $offset, array $schemaIds=[]): array`

**Cache Operations:**
- Line 174: `getObjectCacheService(): ?ObjectCacheService`
- Line 509: `getCachedAvailability(string $cacheKey): ?bool`
- Line 559: `setCachedAvailability(string $cacheKey, bool $isAvailable, int $ttl=3600): void`
- Line 599: `clearCachedAvailability(?string $cacheKey=null): void`
- Line 7149: `clearCache(): void`

### 6. ConfigurationHandler (Internal Config) - 21 methods

**Initialization:**
- Line 148: `__construct(...)`
- Line 208: `initializeConfig(): void`
- Line 229: `initializeHttpClient(): void`
- Line 375: `isSolrConfigured(): bool`

**Configuration Access:**
- Line 5747: `getEndpointUrl(?string $collection=null): string`
- Line 5772: `getHttpClient(): GuzzleClient`
- Line 5784: `getSolrConfig(): array`
- Line 11533: `getConfigStatus(string $key): string`
- Line 11545: `getPortStatus(): string`
- Line 11557: `getCoreStatus(): string`

## Summary Statistics

| Handler | Methods | Percentage |
|---------|---------|------------|
| AdminHandler | 28 | 16.7% |
| IndexingHandler | 32 | 19.0% |
| QueryHandler | 38 | 22.6% |
| SchemaHandler | 35 | 20.8% |
| WarmupHandler | 14 | 8.3% |
| ConfigurationHandler | 21 | 12.5% |
| **TOTAL** | **168** | **100%** |

## Migration Strategy

### Phase 1: Create Backend Abstraction
- Create `SearchBackendInterface`
- Create `SolrBackend` implementing interface
- Move configuration methods first

### Phase 2: Extract Handlers (In Order)
1. **ConfigurationHandler** (21 methods) - Foundation
2. **AdminHandler** (28 methods) - Collection management
3. **SchemaHandler** (35 methods) - Field management
4. **IndexingHandler** (32 methods) - Document operations
5. **QueryHandler** (38 methods) - Search operations
6. **WarmupHandler** (14 methods) - Cache & warmup

### Phase 3: Rename Service
- Rename `GuzzleSolrService` → `IndexService`
- Update all imports and dependency injection
- Verify all 168 methods still accessible

## Notes

- All methods remain accessible via delegation during migration.
- Each handler extraction is a separate, testable migration.
- Service maintains backward compatibility throughout.
- Final service will be ~500 lines (thin facade).
- Each handler will be 1,000-2,500 lines (focused responsibility).

