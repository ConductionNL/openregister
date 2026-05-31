## Why

Phase 4c sub-cluster `search-index-backend-orchestration` was scoped by the architect as 85 methods across 13 files with an estimate of 5 REQs, but flagged as **TOO BIG** and requiring a 2-way split:

- **(a) setup half** — configSet/collection lifecycle, schema mirroring, SetupHandler internals, SolrHttpClient transport, SolrSchemaManager.
- **(b) document-pipeline half** — `DocumentBuilder` value-conversion + truncation, `SolrDocumentIndexer` full CRUD (`bulkIndexObjects`, `indexDocuments`, `deleteObject`, `deleteByQuery`, `commit`, `clearIndex`, `optimize`, `getDocumentCount`), `ObjectHandler` search/commit/reindex, `SolrQueryExecutor::searchPaginated`, and the IndexService facade methods that route to them.

This change covers **only the document-pipeline half (b)**. The setup half (a) is deferred to a follow-up reverse-spec PR.

### Existing-capability check — EXTEND, not mint

A new capability `search-index-backend` was originally proposed by the architect, but a prior reverse-spec PR (#1765, `retrofit-2026-05-24-search-index`, currently OPEN against `development`) **already creates the canonical `search-index` capability** with 5 REQs and 35 annotated methods across 12 of the same 13 files. The architect's plan pre-dated PR #1765's creation.

Honest assessment after reading PR #1765's `openspec/specs/search-index/spec.md` (216 lines, 5 REQs):

- **PR #1765 covers** the facade-routing contract (REQ-1), core-metadata + flatten-relations + SchemaMapper-stub (REQ-2), `BulkIndexer::bulkIndexFromDatabase` (REQ-3), `ConfigurationHandler` constructor + tenant identity (REQ-4), and the `SetupHandler::setupSolr` six-step orchestration (REQ-5).
- **PR #1765 does NOT cover** the per-document conversion/truncation rules, the Solr indexer's per-operation HTTP shapes, `ObjectHandler` (file not in PR #1765 at all), `SolrQueryExecutor::searchPaginated` pagination math, or the IndexService facade methods beyond the 6 already specced.

This change therefore **extends `search-index`** with 5 NEW requirements that fill the document-pipeline gap. Mode is `ADDED` (additive deltas on an existing capability whose `spec.md` is created by PR #1765).

### Merge-order dependency

PR #1765 MUST merge first to land `openspec/specs/search-index/spec.md`. This change appends REQ-6..REQ-10 to that file. If PR #1765 is rebased or its REQ ordering changes, this delta will need a corresponding rebase.

## What Changes

- **Capability**: extends `search-index` (canonical home created by PR #1765).
- **Specs**: 5 new requirements added under the existing `search-index` capability.
  - REQ-6: `DocumentBuilder::createDocument` produces a flat metadata+payload Solr document with `_text` json-blob fallback.
  - REQ-7: `DocumentBuilder` converts values by declared field type and truncates large strings to Solr's 32 KiB byte limit.
  - REQ-8: `SolrDocumentIndexer` performs every per-operation update against the active collection's `/update?commit={true|false}` endpoint and short-circuits when no active collection is set.
  - REQ-9: `ObjectHandler::searchObjects` builds a Solr query with the OpenRegister start/rows/q convention, applies `-deleted:true` filter by default, and converts the result to `{results, total, start}` format.
  - REQ-10: `SolrQueryExecutor::searchPaginated` translates OpenRegister `_limit`/`_offset`/`_order`/`_fields` into Solr `rows`/`start`/`sort`/`fl` and returns a paginated envelope with `pages = ceil(numFound / limit)`.
- **Code**: docblock-only edits (no runtime behaviour changes).

### Methods annotated this run

50 methods across 9 files (see `tasks.md` for the per-file breakdown).

### Deferred to next half (setup)

- `SolrCollectionManager`: `createCollection`, `deleteCollection`, `listCollections`, `listConfigSets`, `createConfigSet`, `deleteConfigSet`, `getActiveCollectionName`.
- `SolrSchemaManager` entirely (file is in the cluster but not yet specced anywhere): `getFieldTypes`, `addFieldType`, `getFields`, `addOrUpdateField`, `deleteField`, `getSchema`.
- `SolrHttpClient`: `get`, `post`, `buildSolrBaseUrl`, `getEndpointUrl`, `getTenantSpecificCollectionName`, `initializeConfig`, `initializeHttpClient`.
- `SetupHandler` step internals: `uploadConfigSet`, `createCollectionWithRetry`, `ensureTenantConfigSet`, `configureSchemaFields` step-by-step, `forceConfigSetPropagation` retry/backoff math.
- `ConfigurationHandler::buildSolrBaseUrl` + `getEndpointUrl` + `initializeHttpClient`.
- `SchemaHandler::mirrorSchemas` + `analyzeAndResolveFieldConflicts` + `getMostPermissiveType` + `generateSolrFieldsFromSchema` + `determineSolrFieldType`.

A follow-up issue (linked at PR time) will scope the setup half as `retrofit-2026-05-XX-search-index-setup`.

## Impact

- **Affected specs**: `search-index` (extends; depends on PR #1765 to create the file).
- **Affected code**: docblock-only — adds `@spec` tags resolving to this change's `tasks.md`.
- **Risk**: none — annotation retrofit. `php -l` clean on all touched files.
- **Backwards-compat**: pure addition; no existing behaviour spec'd.
