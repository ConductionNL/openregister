# Retrofit: backend coverage — Service/Index (reverse-spec + @spec exclude)

## Why

The Bucket-Wide coverage scan (`/tmp/or-scan/bw-svc-index.json`, 2026-05-25) flagged 127 uncovered methods across 18 files under `lib/Service/Index/` — the residual search-index backend plumbing (the Solr and Elasticsearch concrete backends, their HTTP/collection/schema/query primitives, the `SearchBackendInterface` contract, and the document/file/schema handlers). The `search-index` capability already exists (REQ-1..5, reverse-spec'd by `retrofit-2026-05-24-search-index`) and covers the high-level facade behaviors. This change closes the gate-16 coverage gap on the remaining backend methods.

Per ADR-003 (the two-tool approach, just merged), each method ends with EITHER an `@spec` pointer to a real behavior OR an `@spec exclude <reason>` for boilerplate. The overwhelming majority of these 127 methods are backend plumbing: Guzzle HTTP verb wrappers, facade delegations to extracted primitives, interface method declarations, and simplified stubs (`reindexAll`/`warmupIndex`/`fixMismatchedFields` that defer to `IndexService` or return empty). Those are excluded with reasons. A small set of genuinely novel value-transformation and schema-mirroring behaviors — not already in REQ-1..5 — are reverse-spec'd into three new REQs.

## What Changes

- Extend the existing `search-index` capability spec with **three new REQs** (REQ-6, REQ-7, REQ-8):
  - **REQ-6** — `DocumentBuilder` SOLR value coercion, type-compatibility validation, byte-limit truncation, dot-notation array reconstruction, and register/schema ID resolution.
  - **REQ-7** — `SchemaHandler` cross-schema field-type conflict resolution (most-permissive type) and `knn_vector` dense-vector field-type provisioning.
  - **REQ-8** — `FileHandler` file-chunk indexing from the database `ChunkMapper` into the backend file collection.
- Annotate a subset of methods against existing REQs where they already implement spec'd behavior (`ConfigurationHandler` URL/status helpers → REQ-4).
- `@spec exclude <reason>` the boilerplate majority (HTTP plumbing, facade delegations, interface declarations, stubs).
- No production code changes — docblock-only edits.

## Counts

- **Reverse-spec'd / annotated to real behavior: 25** — 8 DocumentBuilder (REQ-6), 5 SchemaHandler (REQ-7), 6 FileHandler (REQ-8), 5 ConfigurationHandler annotated against existing REQ-4, and 1 `SolrSchemaManager::addOrUpdateField` annotated against existing REQ-2.
- **Excluded (boilerplate): 102** — HTTP-client verb wrappers, facade delegations, interface contract declarations, simplified stubs.
- **New REQs: 3** (REQ-6, REQ-7, REQ-8).

## Impact

- **Specs**: `search-index` grows from 5 to 8 REQs.
- **Code**: docblock-only `@spec`/`@spec exclude` edits across 18 files; no runtime behavior changes.
- **Risk**: none — annotation retrofit.
