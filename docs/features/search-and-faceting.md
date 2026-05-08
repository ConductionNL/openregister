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

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — facet configuration lives on schema properties
- [Object Storage & Lifecycle](object-storage.md) — objects being searched
- [Access Control (RBAC)](access-control.md) — RBAC filters are applied transparently to all searches
- [OpenAPI & GraphQL APIs](api-generation.md) — GraphQL queries support equivalent filtering
