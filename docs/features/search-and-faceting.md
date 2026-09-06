# Search, Filtering & Faceting

## Overview

OpenRegister provides a comprehensive, backend-agnostic search and filtering system for register objects. The system supports full-text search with relevance ranking, field-level filtering with comparison operators, faceted drill-down navigation, multi-field sorting, and cursor/offset pagination. A single unified API surface (`ObjectService.searchObjectsPaginated()`) operates transparently against PostgreSQL, Apache Solr, or Elasticsearch.

**Tender demand**: 78% of analyzed government tenders require advanced search and filtering capabilities.

## Full-Text Search

Triggered via the `_search` query parameter:

- Searches across all `type: string` properties in the schema's dynamic table
- Always includes metadata fields: `_name`, `_description`, `_summary`
- Case-insensitive matching via `ILIKE` in the database backend
- String properties with `format: date`, `format: date-time`, or `format: time` are excluded from text search
- PostgreSQL `pg_trgm` extension enables fuzzy matching when installed
- Solr and Elasticsearch backends use their native query parsers

```
GET /api/objects/meldingen-register/meldingen?_search=geluidsoverlast
```

### Widening to attached-file body text (`_content_search`)

`_search` alone only matches object metadata and string schema-properties. Adding the opt-in `_content_search=true` flag additionally matches on the extracted body text of an object's attached files (and object-level text chunks), via the chunk store already populated by the text-extraction pipeline:

```
GET /api/objects/meldingen-register/meldingen?_search=geluidsoverlast&_content_search=true
```

- Default `false` (or omitted) — byte-identical to plain `_search` behaviour; no extra query is issued.
- An object matching on both metadata and attached-file text appears exactly once in the response.
- The response envelope never leaks chunk-shaped fields (`chunk_id`, `text_content`, `score`, etc.) — every row is a normal object row.
- On PostgreSQL, chunk matches are `ts_rank`-scored. On backends without a `tsvector` index (e.g. MariaDB), chunk matches are found via an unranked substring scan — the match set is the same, but ordering may differ.

## Field-Level Filtering

Any schema property can be used as a filter parameter with comparison operators:

| Operator suffix | Description | Example |
|----------------|-------------|---------|
| (none) | Exact match | `?status=actief` |
| `[like]` | Pattern match (SQL LIKE) | `?title[like]=overlast%` |
| `[>=]` | Greater than or equal | `?prioriteit[>=]=3` |
| `[<=]` | Less than or equal | `?aanmaakdatum[<=]=2026-01-01` |
| `[>]` | Greater than | `?score[>]=7.5` |
| `[<]` | Less than | `?score[<]=3.0` |
| `[!=]` | Not equal | `?status[!=]=gesloten` |
| `[in]` | In list (comma-separated) | `?status[in]=nieuw,in_behandeling` |
| `[nin]` | Not in list | `?status[nin]=gesloten,afgehandeld` |
| `[exists]` | Field exists/has value | `?locatie[exists]=true` |

System metadata fields (`_name`, `_uuid`, `_owner`, `_created`, `_updated`, `_deleted`, `_schema`, `_register`) are always available as filter targets regardless of schema.

## Faceting

Facets provide aggregated counts per distinct value — enabling "drill-down" navigation in UIs.

### Enabling Facets

Facets are configured per schema property:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "facetable": true
    },
    "publicatiedatum": {
      "type": "string",
      "format": "date",
      "facetable": {
        "aggregated": false,
        "title": "Publication Date",
        "type": "date_histogram",
        "options": { "interval": "year" }
      }
    }
  }
}
```

### Facet Types

| Type | Description |
|------|-------------|
| `terms` | Distinct value counts (default for string/enum properties) |
| `date_histogram` | Bucketed counts by interval (day, month, quarter, year) |
| `date_range` | Pre-defined date range buckets |
| `range` | Numeric range buckets |

### Facet Computation

- Facets are computed on the **full filtered dataset**, independent of pagination — so facet counts always reflect the entire result set, not just the current page
- `aggregated: true` merges counts across schemas in a cross-schema query
- `aggregated: false` keeps facets per-schema (default for date histogram)
- Boolean `facetable: true` is backward-compatible shorthand for `{ "aggregated": true }`
- Facet results are cached at multiple layers: in-memory, APCu/distributed, and database-persistent for sub-200ms response times on large datasets

### Requesting Facets

```
GET /api/objects/meldingen-register/meldingen?_facets=status,publicatiedatum
```

Response includes both the paginated object list and a `facets` key with aggregation results.

## Sorting

```
GET /api/objects/meldingen-register/meldingen?_order[aanmaakdatum]=desc&_order[titel]=asc
```

Multiple sort fields are supported. Metadata fields (`_created`, `_updated`, `_name`) are always sortable.

## Pagination

Two pagination modes:

| Mode | Parameter | Description |
|------|-----------|-------------|
| Offset | `?_start=0&_limit=25` | Classic page-based; efficient for small result sets |
| Cursor | `?_after=<cursor>` | Stable cursor for large result sets or infinite scroll |

Default page size is 25. Maximum page size is configurable per register.

## Search Backends

| Backend | Full-Text | Fuzzy | Facets | Notes |
|---------|-----------|-------|--------|-------|
| PostgreSQL | ILIKE | pg_trgm (optional) | SQL GROUP BY | Default; no additional setup |
| Apache Solr | Native query parser | Trigram/phonetic | Native faceting | Higher performance for large datasets |
| Elasticsearch | Native query parser | Fuzzy queries | Aggregations | Best for complex faceting |

The backend is selected per register via the `Source` configuration. The `SearchBackendInterface` ensures all backends expose the same query API.

## Cross-Schema Search

Objects across all schemas in a register (or across all registers) can be searched in a single query:

```
GET /api/objects?_search=vergunning&_register=omgevingsdienst-register
```

Cross-schema facets with `aggregated: true` merge value counts across schemas.

## Saved Views

Named saved searches can be stored as View objects, allowing frequently-used filter combinations to be bookmarked and shared:

```
POST /api/views
{
  "name": "Openstaande meldingen",
  "register": "meldingen-register",
  "schema": "meldingen",
  "filters": { "status": "nieuw", "_order[aanmaakdatum]": "desc" }
}
```

## API

```
GET /api/objects/{register}/{schema}    Search objects with any combination of parameters
GET /api/objects/{register}             Cross-schema search within a register
GET /api/objects                        Cross-register search (global)
GET /api/views                          List saved views
POST /api/views                         Create a saved view
GET /api/views/{id}                     Execute a saved view
```

## Nextcloud Unified Search

OpenRegister exposes a single, fleet-wide Nextcloud **unified search** provider
(the top-bar magnifier) over register objects, implemented by
`OCA\OpenRegister\Search\ObjectsProvider` (id `openregister_objects`). This is
the one place register objects surface in unified search for the entire fleet —
consuming apps (Pipelinq, Procest, …) do **not** register their own search
provider. They participate by claiming `(register, schema)` pairs through the
deep-link registry, which supplies each result's URL, icon, and display label.

Key behaviours:

- **Central access control** — the provider delegates to
  `ObjectService::searchObjectsPaginated(query, _rbac: true, _multitenancy: true)`
  and applies no second access filter of its own. Results contain only objects
  the searching user may read: RBAC-granted objects plus objects readable
  through the published predicate. Soft-deleted objects never appear, and tenant
  isolation is enforced. The provider can only narrow this set further (it never
  widens it).
- **`searchable` flag governs unified-search exposure** — the schema-level
  `searchable` boolean (default `true`, editable via the schemas API and schema
  edit modal) now controls whether a schema's objects appear in Nextcloud
  unified search. Setting `searchable = false` removes a schema from the
  magnifier; the exclusion is applied inside the search query (not by
  post-filtering a page). An explicit `schema` filter that targets a
  non-searchable schema returns an empty result set (opt-out wins).
- **Per-app labeling** — each result entry is labeled
  `{App} · {Register} · {Schema}` and carries the owning app's rounded icon for
  claimed pairs; unclaimed pairs keep the `Open Register · …` label and the
  OpenRegister icon.
- **Excerpts** — the result subline ends with an excerpt around the first match
  of the search term, falling back to the object's `summary`/`description`.
  Excerpts come from the rendered object the user may read, so field-level
  security applies to excerpt content.
- **Pagination** — results paginate with a cursor (integer offset), 25 per page,
  so "load more" works for registers with thousands of objects.

Apps declare their result URLs, icons, and display names via the boot-time
deep-link registry (`DeepLinkRegistrationEvent`); the registry's optional
`displayName` is what labels an app's unified-search results.

## Search Trail Recording

OpenRegister records a **search trail** for paginated searches so the dashboard's
"Popular Search Terms" widget and "Searches" KPI have data to display. Each trail
entry captures the search term, the result count on the returned page, the total
matching results, the response time, and the execution backend (database vs.
SOLR/index). Recording happens in `ObjectService::searchObjectsPaginated()`, so it
covers both backends transparently.

What gets recorded is governed by two retention settings, combined into an
**effective mode**:

- **`searchTrailsEnabled`** (boolean master switch) — when `false`, nothing is
  recorded regardless of the mode below. When the setting cannot be read,
  recording fails safe to enabled.
- **`searchTrailRecordingMode`** (`all` | `_search` | `none`, default `_search`) —
  configurable from the **Retention** admin page without a code deploy:
  - `_search` (default) — record only free-text searches (non-empty `_search`);
    plain list and pagination calls record nothing.
  - `all` — record every paginated search call.
  - `none` — record nothing.

Recording is best-effort: a write failure logs a warning and the search returns
normally, so analytics recording never degrades search availability. The settings
round-trip through `GET`/`PATCH /api/settings/retention`.

**Retention.** A third setting on the same page, `searchTrailRetention`
(milliseconds, default `2592000000`, 30 days), says how long a trail entry is
kept. The hourly `LogCleanUpTask` background job enforces it: entries are
recorded without an expiry, so the job first stamps `expires` = `created` plus
the retention on entries that have none, then deletes every entry whose expiry
has passed. The same job tombstones expired audit trail rows; the two sweeps are
independent, so a failure in one never skips the other. A value of `0` or lower
keeps entries indefinitely. `POST /api/search-trails/cleanup` runs the same
deletion on demand.

**Standards**: BIO (audit logging), AVG/GDPR Article 30 (processing register context).

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — facet configuration lives on schema properties
- [Object Storage & Lifecycle](object-storage.md) — objects being searched
- [Access Control (RBAC)](access-control.md) — RBAC filters are applied transparently to all searches
- [OpenAPI & GraphQL APIs](api-generation.md) — GraphQL queries support equivalent filtering
