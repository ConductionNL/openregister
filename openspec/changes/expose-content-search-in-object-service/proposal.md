---
kind: code
depends_on: []
chain: []
---

## Why

`openregister_chunks.text_content` is already populated + PostgreSQL-tsvector-GIN-indexed by the merged [`hybrid-document-search`](../archive/) change, and `ChunkMapper::searchByKeyword()` runs `ts_rank` scoring over it. But that method is currently only reachable through `FileSearchController` (admin-only, chunk-shaped response) — no query path exists through `ObjectService::searchObjectsPaginated()`, so any consumer that wants "search my objects, including their attached files' body text" cannot get there without re-implementing the join.

Concrete downstream consumer: OpenCatalogi's public full-text search endpoint (`GET /apps/opencatalogi/api/search`, shipped by WOO-506's [`add-public-fulltext-search`](https://github.com/ConductionNL/opencatalogi/tree/development/openspec/changes/archive/2026-07-16-add-public-fulltext-search)) needs to widen its match surface to include document body text for WOO-517 (Codeberg PR opencatalogi#136 `add-document-content-search`, pre-migration, not migrated to GitHub). OpenCatalogi's follow-up change is a HARD dependency on this OR-side wire landing first — its proposal frontmatter carries `depends_on: openregister:expose-content-search-in-object-service`.

Solution is a thin wire, not a new stack:

1. Add an opt-in `_content_search` boolean flag to `ObjectService::searchObjectsPaginated()` (default `false`).
2. When set, additionally call `ChunkMapper::searchByKeyword($query['_search'])` scoped by the same `_register` / `_schemas` the caller passed.
3. Map each chunk hit back to its owning object (`source_type='file'` → owning object via existing file→object join; `source_type='object'` → `source_id` is the object id directly), UNION with the metadata-match result set, deduplicate on object id.
4. Return the same envelope shape `searchObjectsPaginated` already returns — object rows, not chunk payloads. No raw chunk id, snippet, or score field leaks.

## What Changes

- Extend `ObjectService::searchObjectsPaginated()` (and the delegating `QueryHandler`/`MagicMapper` path) with a `_content_search: bool` query key. Absent or false → byte-identical to today's behaviour. True → chunk-search fan-out per above.
- Expose the flag consistently at the `SearchBackendInterface` seam so PostgreSQL, MariaDB, and (future) Elasticsearch backends can implement it or explicitly fall back (MariaDB has no `tsvector` — falls back to `LIKE` on `text_content` without `ts_rank`; behaviour preserved, ranking degraded).
- Deduplicate on object id when a document matches on BOTH metadata AND chunk-body text; result envelope stays flat, no per-source grouping.
- No change to `FileSearchController` — that admin endpoint keeps its chunk-shaped response contract. This change adds a **second consumer surface** for the same underlying `ChunkMapper::searchByKeyword()` method.
- No new migration; no new table; no new column. Reuses `openregister_chunks` + its existing tsvector GIN.

**Out of scope (own follow-ups):**

- MariaDB `FULLTEXT` / trigram index parity for `text_content` — MariaDB deploys get unranked `LIKE` results from this change. Equal-quality ranking on MariaDB is a separate storage-layer change.
- Snippet/highlight exposure (`_snippet` field on rows) — natural next ask; deliberately out of scope here.
- Elasticsearch backend support — the flag is exposed at the interface seam, but implementation is left to when/if the ES backend lands.

## Cross-refs

- **Chunk store shipped by:** [`hybrid-document-search`](../archive/2026-07-06-hybrid-document-search/) (already merged) — `openregister_chunks` table, `text_content` column, PostgreSQL `to_tsvector('simple', text_content)` GIN via migration `Version1Date20260706101000`.
- **Existing keyword-search method (unchanged):** `lib/Db/ChunkMapper.php::searchByKeyword()` — `ts_rank`-scored, returns hits with `source_type` + `source_id`.
- **Downstream OpenCatalogi consumer:** Codeberg PR opencatalogi#136 `add-document-content-search` (WOO-517, pre-migration, not migrated to GitHub). Its `depends_on` frontmatter names this change; impl of that change is gated on this one landing.
- **Jira parent:** WOO-517 (Conduction Atlassian). This OR-side work is the prerequisite subtask referenced in that ticket's DoD.
