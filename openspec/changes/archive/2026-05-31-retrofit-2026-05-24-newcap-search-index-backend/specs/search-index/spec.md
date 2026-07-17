## ADDED Requirements

### Requirement: DocumentBuilder produces a flat Solr document with metadata, scalar payload, and a `_text` blob fallback

`DocumentBuilder::createDocument(ObjectEntity $object)` SHALL build a Solr-ready associative array containing (a) the seven core metadata fields `id`, `object_id`, `uuid`, `schema`, `register`, `created`, `updated` populated from `ObjectEntity` getters, (b) every key/value pair from `ObjectEntity::getObject()` (skipping `null` values) with each value passed through `convertValueForSolr(value: $value, fieldType: 'auto')`, and (c) a `_text` field containing `json_encode($objectData)` so that the raw object body remains full-text searchable even when individual fields are not separately indexed. The `id` field MUST be the object's `uuid` as a string (NOT the database row id), enabling deterministic re-indexing. `created` and `updated` MUST be formatted with `format('Y-m-d\TH:i:s\Z')` and a `null` `DateTime` MUST resolve to `null` (via the safe-call operator), not to an empty string.

#### Rationale

`id` as UUID guarantees that re-indexing the same object overwrites the prior document (Solr uses `id` as the document key). The `_text` fallback is what makes free-text search work for fields that the schema mirror skipped because they were too dynamic to type. Filtering `null` upstream avoids Solr rejecting the document with "missing required field"-style errors when the object happens to have a `null` payload value.

#### Scenario: createDocument populates the seven core metadata fields from getters

- **GIVEN** an `ObjectEntity` with `id=42`, `uuid='abc-123'`, `schema=7`, `register=3`, `created=DateTime('2026-05-24 10:00:00')`, `updated=DateTime('2026-05-25 09:00:00')`, `object=['title' => 'X']`
- **WHEN** `DocumentBuilder::createDocument($object)` runs
- **THEN** the result MUST contain `id: 'abc-123'`, `object_id: 42`, `uuid: 'abc-123'`, `schema: 7`, `register: 3`
- **AND** `created: '2026-05-24T10:00:00Z'` and `updated: '2026-05-25T09:00:00Z'`
- **AND** `_text` MUST equal `json_encode(['title' => 'X'])`

#### Scenario: createDocument skips null payload values

- **GIVEN** an `ObjectEntity` whose `getObject()` returns `['present' => 'foo', 'absent' => null]`
- **WHEN** `createDocument()` is called
- **THEN** the result MUST contain key `present` with the converted value
- **AND** the result MUST NOT contain key `absent`

#### Scenario: createDocument tolerates a null `getUpdated()`

- **GIVEN** an `ObjectEntity` whose `getUpdated()` returns `null`
- **WHEN** `createDocument()` is called
- **THEN** the result key `updated` MUST be `null` (the `?->format()` safe-call resolves to null)
- **AND** no exception MUST be thrown

---

### Requirement: DocumentBuilder converts values by field type and truncates oversize strings

`DocumentBuilder::convertValueForSolr($value, string $fieldType)` SHALL coerce values into Solr-friendly representations based on the lowercased field type: `integer`/`int` casts numeric values to `(int)` and returns `null` (with debug log) for non-numeric input; `float`/`double`/`number` casts numeric to `(float)` and returns `null` for non-numeric; `boolean`/`bool` casts via `(bool)`; `date`/`datetime` formats a `\DateTime` instance as `'Y-m-d\TH:i:s\Z'` and parses strings via `DateTime::createFromFormat('Y-m-d H:i:s', $value)` (passing the original string through unchanged if the parse fails); `array` wraps scalars in a single-element array; any other type (including `auto`) falls through to `(string) $value`. A `null` input MUST always return `null` regardless of declared `$fieldType`.

`DocumentBuilder::truncateFieldValue($value, string $fieldName='')` SHALL enforce Solr's 32 766-byte limit for indexed string fields: non-string inputs MUST be returned unchanged; strings up to 32 766 bytes MUST be returned unchanged; strings exceeding the limit MUST be truncated via `mb_strcut($value, 0, 32766 - 100, 'UTF-8')` with the suffix `'...[TRUNCATED]'` appended, AND an `info` log MUST be emitted with `original_bytes`, `truncated_bytes`, and `truncation_point: 32666`. `shouldTruncateField($fieldName, $fieldDefinition)` MUST return `true` when (a) the field's `type` or `format` is `file`/`binary`/`data-url`/`base64`/`image`/`document`, (b) the lowercased field name is one of `logo`, `image`, `icon`, `thumbnail`, `content`, `body`, `description`, or (c) the field name contains the substring `'base64'`.

#### Rationale

Solr will silently drop or 400 on documents whose values don't match the field type — coercing in PHP keeps the indexer's "successful-index" semantics honest. Returning `null` rather than an empty string for non-numeric→numeric conversions lets the document-build step skip the field entirely (see REQ-6's null-skip scenario). The 32 KiB limit is Solr's actual byte cap for indexed string fields; the 100-byte safety margin prevents the truncation marker itself from pushing the value back over the limit on multi-byte UTF-8 boundaries. The `shouldTruncateField()` heuristic targets the file/image/base64 patterns that, in practice, are the only ones that hit the limit in the OpenRegister datasets.

#### Scenario: integer conversion accepts numeric strings, skips non-numeric

- **GIVEN** `convertValueForSolr(value: '42', fieldType: 'integer')`
- **THEN** the result MUST be `42` (int)
- **AND** `convertValueForSolr(value: 'forty-two', fieldType: 'integer')` MUST return `null`
- **AND** a `debug` log SHOULD be emitted for the non-numeric case

#### Scenario: datetime conversion parses Y-m-d H:i:s and falls through on mismatch

- **GIVEN** `convertValueForSolr(value: '2026-05-24 10:00:00', fieldType: 'datetime')`
- **THEN** the result MUST be `'2026-05-24T10:00:00Z'`
- **AND** `convertValueForSolr(value: '24/05/2026', fieldType: 'datetime')` MUST return `'24/05/2026'` unchanged

#### Scenario: truncateFieldValue caps at 32666 bytes with UTF-8-safe cut

- **GIVEN** a string `$big` of length 50 000 bytes (all ASCII)
- **WHEN** `truncateFieldValue($big, 'description')` is called
- **THEN** the result length MUST be at most `32666 + strlen('...[TRUNCATED]')`
- **AND** the result MUST end with `'...[TRUNCATED]'`
- **AND** an `info` log MUST be emitted with `truncation_point: 32666`

#### Scenario: shouldTruncateField fires on image/base64 patterns

- **GIVEN** `fieldName='logo'`, `fieldDefinition=[]`
- **THEN** `shouldTruncateField('logo', [])` MUST return `true`
- **AND** `shouldTruncateField('photo_base64', [])` MUST return `true`
- **AND** `shouldTruncateField('user_avatar', ['format' => 'image'])` MUST return `true`
- **AND** `shouldTruncateField('first_name', [])` MUST return `false`

---

### Requirement: SolrDocumentIndexer routes every CRUD operation through the active collection's `/update` endpoint

Every write-path method on `SolrDocumentIndexer` (`indexObject`, `bulkIndexObjects`, `indexDocuments`, `deleteObject`, `deleteByQuery`, `commit`, `clearIndex`, `optimize`) SHALL first resolve the active collection via `SolrCollectionManager::getActiveCollectionName()`, and SHALL short-circuit (returning `false`, or the array shape `{success: false, ...}` where the method returns an array) with a `warning` log when no active collection is set. The URL pattern for write operations MUST be `{httpClient->getEndpointUrl(collection)}/update?commit={true|false}` (with the literal strings `'true'` or `'false'`, NOT booleans). `commit()` MUST POST an empty body to `/update?commit=true`. `optimize()` MUST POST an empty body to `/update?optimize=true`. `deleteByQuery($query)` MUST POST the body `{delete: {query: $query}}` and, when `$returnDetails === true`, return `{success, query, result}` instead of a bare boolean. `deleteObject($objectId)` MUST POST `{delete: {query: 'id:' . $objectId}}` (NOT a delete-by-id command, because OpenRegister allows numeric and UUID ids interchangeably and querying by `id:` works for both). `getDocumentCount()` MUST GET `/select?q=*:*&rows=0&wt=json` and return `$data['response']['numFound']` (or `0` on the response shape miss).

Per-operation exceptions MUST be caught: write methods log at `error` level and return the failure shape; the surrounding pipeline (`BulkIndexer`, `IndexService`) is NOT responsible for re-throwing.

#### Rationale

Centralising the URL pattern and the no-collection short-circuit in the indexer keeps every method's no-op semantics consistent — callers don't need to check `isAvailable()` before each operation. Using `id:` query for deletes (rather than `<id>...</id>` delete-by-id syntax) avoids the integer-vs-UUID id-type mismatch that historically broke deletes in OpenRegister-on-Solr. The string-typed `commit=true|false` query parameter is what Solr's HTTP API accepts; booleans coerce to `1`/`0` and Solr treats them as commit=false.

#### Scenario: indexObject short-circuits with warning when no active collection

- **GIVEN** `SolrCollectionManager::getActiveCollectionName()` returns `null`
- **WHEN** `SolrDocumentIndexer::indexObject($object, true)` is called
- **THEN** the method MUST return `false`
- **AND** a `warning` log MUST be emitted with message `[SolrDocumentIndexer] No active collection for indexing`
- **AND** `SolrHttpClient::post()` MUST NOT be called

#### Scenario: deleteObject uses an `id:` delete-by-query, not delete-by-id

- **GIVEN** an active collection `'openregister'` and an `objectId` of `42`
- **WHEN** `SolrDocumentIndexer::deleteObject(42, false)` is called
- **THEN** the URL MUST be `{endpoint}/update?commit=false`
- **AND** the POST body MUST be `['delete' => ['query' => 'id:42']]`

#### Scenario: deleteByQuery returns details only when `returnDetails=true`

- **GIVEN** an active collection and a successful POST
- **WHEN** `deleteByQuery('register:5', false, false)` is called
- **THEN** the return MUST be the bare boolean `true`
- **WHEN** `deleteByQuery('register:5', false, true)` is called
- **THEN** the return MUST be the array `['success' => true, 'query' => 'register:5', 'result' => <post response>]`

#### Scenario: getDocumentCount returns 0 on no active collection without HTTP call

- **GIVEN** `getActiveCollectionName()` returns `null`
- **WHEN** `getDocumentCount()` is called
- **THEN** the return MUST be `0`
- **AND** no HTTP call MUST be issued

---

### Requirement: ObjectHandler::searchObjects builds a Solr query with OpenRegister's start/rows/q shape and converts the response to {results, total, start}

`ObjectHandler::searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false)` SHALL: (1) build a Solr query via the private `buildSolrQuery()` with defaults `q: '*:*'`, `start: 0`, `rows: 10` (note: a different default to `SolrQueryExecutor`'s `rows: 30`); (2) when `$deleted === false`, append `-deleted:true` to the `fq` filter array; (3) call `SearchBackendInterface::search($solrQuery)`; (4) convert the response to `{results: <docs>, total: <numFound>, start: <start>}` via `convertToOpenRegisterFormat()`. The `_rbac` and `_multitenancy` flags MUST be accepted but currently produce no additional filters (TODO markers in the code MUST be preserved as documented stubs, not silent omissions).

`ObjectHandler::commit()` SHALL delegate to `SearchBackendInterface::commit()`, log `'Successfully committed to Solr'` at `info` level when the backend returns `true`, and on exception MUST log at `error` and re-throw (NOT swallow).

`ObjectHandler::reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null)` SHALL delegate the full call to `SearchBackendInterface::reindexAll($maxObjects, $batchSize, $collectionName)`, log start at `info`, and on exception MUST log at `error` and return `['success' => false, 'error' => $exceptionMessage]` (NOT throw).

#### Rationale

The two different default `rows` values (10 in `ObjectHandler`, 30 in `SolrQueryExecutor::buildSolrQuery`) is intentional drift between the legacy "small-list dashboard" path and the newer paginated-search path; both are observed behaviours that must remain compatible with their respective callers. The `_rbac`/`_multitenancy` TODOs are explicit hooks for an upcoming change spec and must remain visible as stubs — silently dropping the flags would make it harder to add the filtering later. `commit()` re-throws because committing is invoked synchronously from save-pipeline paths that need to surface the failure; `reindexAll()` swallows because it runs from a long-running background context where re-throwing would crash the cron.

#### Scenario: searchObjects defaults to q=*:*, start=0, rows=10

- **GIVEN** an empty query `[]` with `deleted=false`
- **WHEN** `ObjectHandler::searchObjects([])` runs
- **THEN** the Solr query passed to `SearchBackendInterface::search()` MUST contain `q: '*:*'`, `start: 0`, `rows: 10`
- **AND** `fq` MUST contain `'-deleted:true'`

#### Scenario: searchObjects returns the OpenRegister envelope shape

- **GIVEN** the backend returns `['response' => ['docs' => [{id:1}], 'numFound' => 100, 'start' => 20]]`
- **WHEN** `searchObjects()` runs
- **THEN** the return MUST be `['results' => [{id:1}], 'total' => 100, 'start' => 20]`
- **AND** keys NOT in the envelope (e.g., `responseHeader`, `facet_counts`) MUST NOT be propagated

#### Scenario: commit re-throws on backend failure

- **GIVEN** `SearchBackendInterface::commit()` throws `Exception('connection refused')`
- **WHEN** `ObjectHandler::commit()` is called
- **THEN** an `error` log MUST be emitted
- **AND** the exception MUST be re-thrown unchanged

#### Scenario: reindexAll swallows backend exceptions into a result array

- **GIVEN** `SearchBackendInterface::reindexAll(...)` throws `Exception('timeout')`
- **WHEN** `ObjectHandler::reindexAll()` is called
- **THEN** the return MUST be `['success' => false, 'error' => 'timeout']`
- **AND** the exception MUST NOT propagate

---

### Requirement: SolrQueryExecutor::searchPaginated translates OpenRegister pagination into Solr and returns a {results, total, limit, offset, page, pages} envelope

`SolrQueryExecutor::searchPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false)` SHALL translate OpenRegister query keys into Solr keys using the following rules in `buildSolrQuery()`: `q := $query['_search'] ?? '*:*'`; `start := (int)($query['_offset'] ?? $query['_start'] ?? 0)` (offset wins over start when both are present); `rows := (int)($query['_limit'] ?? $query['_rows'] ?? 30)`; if `_order` is set, `sort := translateSortField($query['_order'])` which accepts either a string (passed through unchanged) or an associative `{field => direction}` map joined with `', '` and direction lowercased to `'asc'`/`'desc'`; if `_fields` is set, `fl := $query['_fields']` and if it is an array, it MUST be `implode(',', ...)`-joined. When `$deleted === false`, the filter `'-deleted:true'` MUST be appended to `fq`. The request MUST set `wt: 'json'` before the call to `search()`.

The result MUST be converted via `convertToPaginatedFormat($solrResult, $query)` to the envelope `{results: <docs>, total: <numFound>, limit: <_limit | 30>, offset: <response.start | 0>, page: <_page | 1>, pages: <ceil(numFound / limit)>}` where `pages = 0` when `limit <= 0` (no division-by-zero panic).

#### Rationale

The `_offset > _start` precedence reflects OpenRegister's API evolution — newer callers send `_offset`, legacy callers send `_start`, and both are accepted. `_order` as a string passes through because Solr already accepts `"field asc, other desc"` natively; the map form exists so PHP callers don't have to format the sort string themselves. `pages = 0` on `limit <= 0` is the observed behaviour (the early-return guard around `ceil(numFound / limit)`) and is what frontends like `zoeken-filteren` rely on to decide whether to render a paginator.

#### Scenario: _offset wins over _start when both are present

- **GIVEN** `$query = ['_offset' => 50, '_start' => 10, '_limit' => 25]`
- **WHEN** `searchPaginated($query)` runs
- **THEN** the Solr request MUST have `start: 50`, `rows: 25`
- **AND** the resulting envelope MUST have `limit: 25`

#### Scenario: _order as associative map joins direction-lowered pairs

- **GIVEN** `$query = ['_order' => ['title' => 'ASC', 'created' => 'desc']]`
- **WHEN** the internal `translateSortField()` runs
- **THEN** the Solr `sort` MUST be `'title asc, created desc'`
- **AND** a string `_order` MUST pass through unchanged

#### Scenario: _fields as array becomes comma-joined fl

- **GIVEN** `$query = ['_fields' => ['id', 'title', 'summary']]`
- **WHEN** the Solr query is built
- **THEN** `fl` MUST equal the string `'id,title,summary'`

#### Scenario: convertToPaginatedFormat returns pages=0 on limit<=0

- **GIVEN** a Solr response with `numFound: 500` and `$query = ['_limit' => 0]`
- **WHEN** the paginated envelope is built
- **THEN** the result MUST contain `pages: 0` (no division-by-zero)
- **AND** `total: 500`, `limit: 0`, `page: 1` (default)
