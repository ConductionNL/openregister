## ADDED Requirements

### Requirement: `_content_search` opt-in flag widens `searchObjectsPaginated` to include document body text (ZKN-CONTENT-001)

`ObjectService::searchObjectsPaginated()` MUST accept an optional boolean `_content_search` flag (either as a named parameter or as a `_content_search` key inside the `$query` array). When absent or `false`, the method's behaviour MUST be byte-identical to the pre-change baseline (metadata + string properties only, no chunk fan-out) — no envelope drift, no row-order drift, no `total` drift, no additional query fan-out MUST occur.

When `_content_search=true`, the method MUST additionally invoke `ChunkMapper::searchByKeyword($query['_search'])` — scoped by the same `_register` / `_schemas` the caller passed — and include the objects whose file chunks match the query, subject to the mapping and deduplication rules in `ZKN-CONTENT-002` and the response-shape rule in `ZKN-CONTENT-003`.

The flag MUST be exposed at the `SearchBackendInterface` seam so alternate backends can implement it or explicitly fall back. On backends without native `tsvector` (e.g. MariaDB), the backend MAY fall back to unranked `LIKE` on `openregister_chunks.text_content` — behaviour is preserved, ranking degrades. On PostgreSQL, the existing `ts_rank`-scored `ChunkMapper::searchByKeyword()` path MUST be used.

#### Scenario: default omits chunk fan-out

- GIVEN a call to `ObjectService::searchObjectsPaginated($query)` with no `_content_search` key,
- WHEN the response is compared to the pre-change baseline for the same `$query`,
- THEN the response envelope, row order, and `total` MUST be byte-identical to that baseline,
- AND no query MUST be issued against `openregister_chunks`.

#### Scenario: opt-in triggers chunk fan-out

- GIVEN a call to `searchObjectsPaginated($query)` with `_content_search=true` and `_search=<phrase>`,
- WHEN backend query logs are inspected,
- THEN exactly one additional query MUST be issued against `openregister_chunks` (or its backend-specific equivalent),
- AND that query MUST be scoped by the same `_register` / `_schemas` present in `$query`.

#### Scenario: MariaDB fallback preserves match set, degrades ranking

- GIVEN a MariaDB backend without a `tsvector` index on `openregister_chunks.text_content`,
- WHEN `searchObjectsPaginated(..., _content_search=true)` runs,
- THEN the response MUST include every object whose chunk `text_content` contains the query as a substring,
- AND the response ordering MAY differ from the PostgreSQL `ts_rank` ordering,
- AND the response MUST NOT error or return HTTP 500.

### Requirement: Chunk hits are mapped back to their owning object (ZKN-CONTENT-002)

When `_content_search=true` returns chunk hits, `searchObjectsPaginated` MUST map each chunk to its owning object before merging into the result set:

- Chunks with `source_type = 'object'` MUST map to the object identified by `source_id` directly.
- Chunks with `source_type = 'file'` MUST map to the object that owns the file, resolved via the existing file→object join OpenRegister already uses for other file-relative queries.
- Chunks whose owning object cannot be resolved (deleted, cross-tenant, etc.) MUST be silently skipped — no error surfaced to the caller.

The mapped-object set MUST be unioned with the metadata-match set produced by the pre-change search path and deduplicated on object id. An object that matches on BOTH surfaces MUST appear exactly once in the response.

#### Scenario: chunk hit maps to owning object

- GIVEN a chunk in `openregister_chunks` with `source_type='file'` and `source_id=42`, where file 42 is attached to object `obj-X`,
- WHEN `searchObjectsPaginated(..., _content_search=true, _search=<phrase>)` matches that chunk,
- THEN `obj-X` MUST appear in the response's result rows.

#### Scenario: object matching both surfaces appears once

- GIVEN an object whose `_name` metadata AND a linked chunk's `text_content` both contain the query,
- WHEN `searchObjectsPaginated(..., _content_search=true, _search=<query>)` runs,
- THEN the object MUST appear exactly once in the response's result rows,
- AND the `total` field MUST count it once.

#### Scenario: chunk with unresolvable owning object is silently skipped

- GIVEN a chunk whose `source_id` references a deleted or cross-tenant object,
- WHEN `searchObjectsPaginated(..., _content_search=true)` runs,
- THEN the chunk MUST NOT surface any row in the response,
- AND the call MUST NOT return an error.

### Requirement: Response envelope stays object-shaped — no raw chunk payloads (ZKN-CONTENT-003)

Chunk hits routed through `_content_search=true` MUST NOT leak chunk-shaped fields into the `searchObjectsPaginated` response. Specifically, the returned rows MUST NOT contain:

- A `chunk_id`, `chunk`, or `source_id` field (unless that field is already part of the object schema for other reasons).
- A `text_content` field, snippet, or excerpt derived from the chunk.
- A `ts_rank` / `score` field or any ranking numerical exposed as a row property.

The row shape MUST be exactly what `searchObjectsPaginated` returns for a metadata-only match today — the object as `ObjectEntity` (or its `jsonSerialize()` form), with the caller's existing `_extend` / `_fields` selection semantics applied uniformly.

#### Scenario: no chunk fields in response rows

- GIVEN a `_content_search=true` call whose sole matches are chunk-only (no metadata match),
- WHEN the response rows are inspected,
- THEN no row MUST contain a `chunk_id`, `text_content`, `snippet`, or `ts_rank` / `score` field,
- AND every row MUST be shape-identical to the rows produced by a metadata-only match on the same schemas.

#### Scenario: `_extend` / `_fields` selection applies uniformly

- GIVEN two calls with identical query except one is a metadata match and the other is a chunk-only match,
- AND both calls pass the same `_extend` / `_fields` selection,
- WHEN both responses' first rows are compared,
- THEN the rows MUST have the same set of top-level keys and the same nested shape under `@self`.
