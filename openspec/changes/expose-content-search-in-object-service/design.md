# Design: expose-content-search-in-object-service

## Context

`ObjectService::searchObjectsPaginated()` is OpenRegister's canonical multi-schema search entry-point — every consumer that wants "objects matching X" goes through it. It delegates to `QueryHandler` → `MagicMapper` → `SearchBackendInterface` and today only matches on metadata + string schema-properties (`_search` param translates to `ILIKE`/`tsvector` over indexed columns; details in `zoeken-filteren` spec).

`ChunkMapper::searchByKeyword($query, $limit, $filters)` is the existing keyword-search entry-point over extracted file body text (populated by `TextExtractionService` + per-format handlers, indexed via the PostgreSQL `to_tsvector('simple', text_content)` GIN shipped in the merged `hybrid-document-search` change). It returns chunk-shaped hits with `source_type` + `source_id` — ready to be joined back to the owning object.

Today, `ChunkMapper::searchByKeyword()` is only reachable through `FileSearchController` (admin-only, chunk-shaped response). No path exists through `searchObjectsPaginated` — so any consumer that wants "search my objects, including their attached files' body text" cannot get there without re-implementing the chunk→object join in application code. The downstream consumer that surfaces this pain today: OpenCatalogi's WOO-517 [`add-document-content-search`](https://codeberg.org/Conduction/opencatalogi/pulls/136) is HARD-blocked on this wire landing.

## Goals

- Add a **thin wire** from `ObjectService::searchObjectsPaginated()` to `ChunkMapper::searchByKeyword()` behind an opt-in `_content_search` flag.
- Preserve today's behaviour byte-identical when the flag is absent or false — no drift for any current consumer.
- Return **object rows**, not chunks — the response envelope stays what `searchObjectsPaginated` returns today.
- Deduplicate on object id when an object matches on both metadata AND chunk-body text.
- Expose the flag at the `SearchBackendInterface` seam so PostgreSQL and MariaDB (and future ES) backends can implement or explicitly fall back.

## Non-Goals

- New extraction pipeline. Reuse `TextExtractionService`.
- New chunk store. Reuse `openregister_chunks`.
- New index. Reuse the PostgreSQL `to_tsvector('simple', text_content)` GIN shipped by `hybrid-document-search`.
- New migration. Zero schema change.
- MariaDB `FULLTEXT` / trigram parity for chunks. MariaDB falls back to `LIKE` on `text_content` — behaviour preserved, ranking degraded. Equal-quality MariaDB ranking is a follow-up.
- Chunk-shaped response fields (snippet, score, chunk id) surfaced to the caller. Explicitly forbidden by `ZKN-CONTENT-003`.
- Elasticsearch backend implementation. Interface exposes the flag; ES impl lands with (if) the ES backend.

## Decisions

**D1 — Flag lives on the query, not the method signature.** Instead of adding `bool $_content_search = false` as an 8th positional parameter, accept it as a `_content_search` key inside `$query`. Rationale: `searchObjectsPaginated` already has 7 parameters and the existing pattern for orthogonal search options (`_extend`, `_fields`, `_search`, `_order`, `_facets`, …) is query-key-based. Named-param would force every caller who wanted to add other future flags to grow the signature further. **Alternative rejected:** named parameter — cleaner call site but breaks the established convention and forces cascading signature changes downstream.

**D2 — Chunk fan-out AFTER the metadata query, not in a UNION SQL statement.** The metadata search path (`MagicMapper::searchAcrossMultipleTables`) is complex and per-schema. Trying to inline a UNION with `openregister_chunks` at the SQL level would multiply query complexity and lose backend abstraction. Instead: run the metadata query as today, run `ChunkMapper::searchByKeyword()` as a second query, merge in application code, dedupe on object id. Cost: one extra roundtrip. **Alternative rejected:** UNION SQL — tighter but couples the two search paths at the storage layer and breaks the `SearchBackendInterface` contract.

**D3 — Skip chunks whose owning object cannot be resolved silently.** A chunk may reference a deleted, cross-tenant, or otherwise-invisible object. Log at DEBUG level; do NOT error, do NOT surface the chunk. Rationale: caller asked "give me matching objects" — an unresolvable owning object is by definition "no matching object to give". **Alternative rejected:** error out — would fail-closed on stale chunks and block the caller for a data-integrity issue the caller can't fix.

**D4 — Dedup key = object id (`$obj->getId()`), not `@self.id` string.** Fast, monomorphic integer set on the union. Ordering is preserved from the metadata-match arm first, then any chunk-only matches appended in `ts_rank` order (or `LIKE`-scan order on MariaDB). **Alternative considered:** merge-sort by relevance across both arms — better ranking but requires a comparable scoring axis between metadata-match (ILIKE / tsvector) and chunk-match (`ts_rank`). Deferred to a follow-up if needed.

**D5 — Interface flag is universal, backend fallback is per-backend.** `SearchBackendInterface` grows the `_content_search` awareness (docblock + optional method), so a consumer always gets a defined response regardless of backend. On MariaDB: fall back to `LIKE '%<query>%'` on `openregister_chunks.text_content`. On future ES: implement natively when available. **Alternative rejected:** backend-specific method that PostgreSQL implements and MariaDB doesn't — breaks the "single unified API surface" invariant `zoeken-filteren` already holds.

## Risks / Trade-offs

- **[Risk] Extra query cost on `_content_search=true`** → Mitigation: opt-in only; existing consumers see zero change. Downstream (opencatalogi WOO-517) will only enable it under `?_content=true`.
- **[Risk] MariaDB fallback returns unranked results — order differs from PostgreSQL** → Mitigation: documented in the spec (`ZKN-CONTENT-001` scenario). MariaDB parity is an explicit follow-up.
- **[Risk] Chunk store not populated for a freshly-uploaded file (extraction jobs are async)** → Mitigation: existing consumer contract of `TextExtractionService` — background job runs on file write. Caller retries or waits. No change to that contract here.
- **[Risk] Dedup on `getId()` misses when the two arms return different projections of the same row (e.g. `_extend` on one but not the other)** → Mitigation: dedup happens BEFORE `_extend` / `_fields` selection is applied. Both arms produce raw `ObjectEntity` first; selection is uniform.
- **[Trade-off] Two round-trips instead of a UNION** → Accepted: cleaner backend abstraction, easier to test, easier to backend-swap. Cost is ~1 extra query for opt-in path only.

## Migration Plan

Pure code addition. No database migration, no config change, no data transformation.

- **Deploy** — plain code push. Opt-in default preserves all pre-change behaviour for every current consumer.
- **Rollback** — remove or ignore the `_content_search` key in `ObjectService::searchObjectsPaginated()`; endpoint reverts to today's behaviour instantly. Chunk store data left in place (owned by the extraction pipeline, still valid).
- **Coexistence** — OpenCatalogi WOO-517 (`add-document-content-search`, [#136](https://codeberg.org/Conduction/opencatalogi/pulls/136)) is HARD-blocked on this change merging first. Its own proposal.md carries `depends_on: openregister:expose-content-search-in-object-service` — spec-name matches this change slug, so Hydra's dependency-block will lift automatically once this PR merges to `development`.

## Open Questions

- **Final flag name** — `_content_search` in this proposal is a working name. If bikeshed lands on `_include_content` / `_content` / etc., the OpenCatalogi consumer will translate its `_content=true` query param to whatever wire OR agrees on. Working name is locked in the spec deltas here; changing it later means a follow-up PR that updates the spec + the OC-side consumer.
- **Dedup ordering** — the current design puts metadata-match rows first and chunk-only rows after. If downstream consumers want relevance-interleaved, a follow-up can add a `_content_search_merge=relevance` option — out of scope here.
