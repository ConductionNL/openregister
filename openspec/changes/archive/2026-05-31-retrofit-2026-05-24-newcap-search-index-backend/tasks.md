# Tasks — retrofit search-index document-pipeline (half b)

Reverse-spec retrofit for the **document-pipeline half** of the search-index backend cluster. Extends the `search-index` capability created by PR #1765 with 5 new REQs. Annotations are docblock-only; no runtime behaviour changes.

The numbered tasks below correspond to the 5 new requirements in `specs/search-index/spec.md`. Each `@spec` tag annotated on a method points to one of these task IDs.

---

## Task 6: Annotate DocumentBuilder::createDocument with the metadata + payload + _text contract

Maps to REQ-6: *DocumentBuilder produces a flat Solr document with metadata, scalar payload, and a `_text` blob fallback.*

- [x] `lib/Service/Index/DocumentBuilder.php::createDocument()` — annotate with `@spec`

---

## Task 7: Annotate DocumentBuilder value-conversion and truncation methods

Maps to REQ-7: *DocumentBuilder converts values by field type and truncates oversize strings.*

- [x] `lib/Service/Index/DocumentBuilder.php::convertValueForSolr()` — annotate with `@spec`
- [x] `lib/Service/Index/DocumentBuilder.php::truncateFieldValue()` — annotate with `@spec`
- [x] `lib/Service/Index/DocumentBuilder.php::shouldTruncateField()` — annotate with `@spec`
- [x] `lib/Service/Index/DocumentBuilder.php::mapFieldToSolrType()` — annotate with `@spec` (reserved-field skip rule referenced under REQ-7's payload-skip contract)

---

## Task 8: Annotate SolrDocumentIndexer's CRUD pipeline

Maps to REQ-8: *SolrDocumentIndexer routes every CRUD operation through the active collection's `/update` endpoint.*

- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::bulkIndexObjects()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::indexDocuments()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::deleteObject()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::deleteByQuery()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::commit()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::clearIndex()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::optimize()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php::getDocumentCount()` — annotate with `@spec`
- [x] `lib/Service/IndexService.php::commit()` — annotate with `@spec` (facade route to ObjectHandler→backend commit)
- [x] `lib/Service/IndexService.php::optimize()` — annotate with `@spec` (facade route to backend optimize)

---

## Task 9: Annotate ObjectHandler's search/commit/reindex methods

Maps to REQ-9: *ObjectHandler::searchObjects builds a Solr query with OpenRegister's start/rows/q shape and converts the response.*

- [x] `lib/Service/Index/ObjectHandler.php::searchObjects()` — annotate with `@spec`
- [x] `lib/Service/Index/ObjectHandler.php::buildSolrQuery()` (private) — annotate with `@spec`
- [x] `lib/Service/Index/ObjectHandler.php::convertToOpenRegisterFormat()` (private) — annotate with `@spec`
- [x] `lib/Service/Index/ObjectHandler.php::commit()` — annotate with `@spec`
- [x] `lib/Service/Index/ObjectHandler.php::reindexAll()` — annotate with `@spec`
- [x] `lib/Service/IndexService.php::searchObjects()` — annotate with `@spec` (facade route)

---

## Task 10: Annotate SolrQueryExecutor's paginated-search path

Maps to REQ-10: *SolrQueryExecutor::searchPaginated translates OpenRegister pagination into Solr and returns the paginated envelope.*

- [x] `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php::searchPaginated()` — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php::translateSortField()` (private) — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php::convertToPaginatedFormat()` (private) — annotate with `@spec`
- [x] `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php::inspectIndex()` — annotate with `@spec` (uses the same `search()` primitive with a stripped query envelope)

---

## Deferred to setup half (next reverse-spec PR)

These methods are intentionally **NOT** annotated in this change. They belong to the setup-half follow-up.

- `SolrCollectionManager`: `collectionExists` (covered by PR #1765 REQ-1), `getActiveCollectionName`, `createCollection`, `deleteCollection`, `listCollections`, `listConfigSets`, `createConfigSet`, `deleteConfigSet`.
- `SolrSchemaManager` (entire file): `getFieldTypes`, `addFieldType`, `getFields`, `addOrUpdateField`, `deleteField`, `getSchema`.
- `SolrHttpClient` (entire file): `get`, `post`, `buildSolrBaseUrl`, `getEndpointUrl`, `getTenantSpecificCollectionName`, `initializeConfig`, `initializeHttpClient`, `isConfigured`, `getConfig`, `getHttpClient`.
- `SetupHandler` step internals beyond the orchestration covered in PR #1765 REQ-5: `uploadConfigSet`, `createCollectionWithRetry`, `ensureTenantConfigSet`, `forceConfigSetPropagation`, `configureSchemaFields`, `addOrUpdateSchemaFieldWithTracking`, `validateSetup`, `verifySolrConnectivity`, `configSetExists`, `ensureTenantCollectionExists`, `trackStep`, `buildSolrUrl`, `getApiCallsFromResult`, `initializeAllSteps`, `getTenantCollectionName`, `getTenantId`, `getTenantConfigSetName`, `allComponentsSuccessful`.
- `ConfigurationHandler` URL/HTTP plumbing: `buildSolrBaseUrl`, `getEndpointUrl`, `isSolrConfigured`, `initializeHttpClient`, `initializeConfig`, `getHttpClient`, `getSolrConfig`, `getPortStatus`, `getCoreStatus`.
- `SchemaHandler` mirror/conflict-resolution: `mirrorSchemas`, `analyzeAndResolveFieldConflicts`, `getMostPermissiveType`, `generateSolrFieldsFromSchema`, `generateSolrFieldName`, `determineSolrFieldType`, `isMultiValued`, `ensureCoreMetadataFields`, `getCollectionFieldStatus`, `createMissingFields`, `fixMismatchedFields`, `ensureVectorFieldType`.
- `BulkIndexer` internals beyond `bulkIndexFromDatabase` (covered by PR #1765 REQ-3): `bulkIndexObjects` (TODO wrapper), `countSearchableObjects`, `fetchSearchableObjects`, `getSearchableSchemaIds`.

Roughly 60+ additional methods are deferred to the setup half. A follow-up GitHub issue will scope the setup half as `retrofit-2026-05-XX-search-index-setup`.
