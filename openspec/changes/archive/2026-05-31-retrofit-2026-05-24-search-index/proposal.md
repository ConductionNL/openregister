# Retrofit: Search Index (reverse-spec)

## Why

OpenRegister ships a sizeable search-index layer under `lib/Service/Index/` (and its `Backends/Solr/` subtree, plus the top-level facade `lib/Service/IndexService.php`) that powers full-text search, faceting, bulk indexing, schema mirroring, and the multi-tenant SolrCloud setup flow. The Bucket 2a coverage scan (2026-05-24, `/tmp/or-scan/rspec-cluster-search-index.json`) surfaced 35 public/private methods across 13 files with no `@spec` annotation. The capability has **zero** spec coverage — `openspec/specs/search-index/spec.md` does not exist.

This ghost change reverse-engineers the new `search-index` capability spec from observed code, captures five REQs covering the major behaviors (backend abstraction, schema-to-index field mapping, bulk indexing pipeline, configuration loading, setup orchestration), and annotates the backing methods with `@spec` pointers to this change's tasks. No production code changes.

## What changes

- Add new canonical capability spec `openspec/specs/search-index/spec.md` (status: implemented, retrofit: true). Five REQs, all extracted from observed behavior.
- Add ghost change `openspec/changes/retrofit-2026-05-24-search-index/` (proposal, spec delta, tasks).
- Annotate methods across `IndexService.php`, `BulkIndexer.php`, `ConfigurationHandler.php`, `SetupHandler.php`, `DocumentBuilder.php`, `SchemaHandler.php`, `SchemaMapper.php`, and the Solr backend helpers with `@spec` pointers.

## Impact

- **Specs**: new capability `search-index` (was: missing).
- **Code**: docblock-only edits; no runtime behavior changes.
- **Risk**: none — annotation retrofit. Notes section in spec flags drifts observed in the triage (`SchemaMapper` is a stub; `BulkIndexer::bulkIndexObjects` is a TODO wrapper).
