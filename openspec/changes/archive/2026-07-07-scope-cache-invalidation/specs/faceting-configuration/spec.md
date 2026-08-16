## ADDED Requirements

### Requirement: Cache invalidation is scoped to the written bucket

An object write SHALL NOT clear the entire distributed query-result cache or the
entire aggregation cache. Cache keys SHALL carry a `register:schema` (or finer)
scope so invalidation targets only the affected bucket. Bulk operations SHALL
collapse invalidation to one call per distinct affected bucket.

#### Scenario: Write to one schema does not evict another's cache

- **WHEN** an object in schema A is created, updated, or deleted
- **THEN** only schema A's query-cache and aggregation-cache entries are invalidated
- **AND** a cached list/aggregate for schema B still serves from cache

#### Scenario: Bulk delete invalidates per bucket, not per item

- **WHEN** a bulk delete spans three schemas
- **THEN** invalidation is issued once per affected schema bucket
- **AND** the whole cache is not cleared three times

### Requirement: Schemas are cached by a single tier

A schema SHALL be cached by exactly one caching layer. There SHALL NOT be two
independently-maintained caches each issuing their own DB round trip for the same
schema.

#### Scenario: One schema cache source

- **WHEN** a schema is resolved repeatedly within and across requests
- **THEN** it is served from a single cache tier, not fetched from two separate
  cache tables/round trips
