# OpenRegister Platform Capabilities

This document tracks which capabilities are available on each supported
database platform.

## Aggregation API

| Capability                        | postgres | mysql | sqlite | php-fallback |
|-----------------------------------|----------|-------|--------|--------------|
| Native time-bucket SQL            | ✓        | ✓     | ✓      | –            |
| COUNT / SUM / AVG / MIN / MAX     | ✓        | ✓     | ✓      | ✓ (COUNT only fallback) |
| Ad-hoc cache (60 s TTL)           | ✓        | ✓     | ✓      | ✓            |
| Cache invalidation on object events | ✓      | ✓     | ✓      | ✓            |
| ISO-8601-UTC bucket key format    | ✓        | ✓     | ✓      | ✓            |

### Native fast path details

- **PostgreSQL**: `date_trunc('gap', "field")::text` — double-quoted identifiers.
- **MySQL / MariaDB**: `DATE_FORMAT(\`field\`, '<format>')` — backtick-quoted identifiers.
  Minute format is `%i` (not `%M` which is full month name in MySQL).
- **SQLite**: `strftime('<format>', "field")` — double-quoted identifiers.
  Minute format is `%M` (opposite of MySQL).

### Ad-hoc cache

`AggregationRunner::runAdhoc()` implements a read-through distributed cache
keyed on `(registerSlug, schemaSlug, sha1(query.toArray()), filterHash, rbacHash)`.

TTL: 60 s (class constant `AggregationCache::TTL`).

Invalidation: `AggregationCacheInvalidationListener` fires on every
`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` and calls
`AggregationCache::evictForSchema()` which flushes the entire
`openregister_aggregations` cache namespace (coarse but bounded by TTL).

See [`docs/technical/aggregation-api.md`](../docs/technical/aggregation-api.md)
for the full cache semantics and performance notes.

## Search / Faceting

| Capability      | Solr | Elasticsearch | Database-direct |
|-----------------|------|---------------|-----------------|
| Full-text search | ✓   | ✓             | ✓ (limited)     |
| Faceting        | ✓    | ✓             | ✓               |
| Semantic search | ✓    | ✓             | –               |
