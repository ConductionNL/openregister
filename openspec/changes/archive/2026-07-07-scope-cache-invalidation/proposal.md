---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Cache invalidation in OpenRegister is instance-wide ("nuclear") on every write,
so scoped caches never actually serve scoped hits (ADR-009 Rule 4). There is also
a redundant double-cache tier for schemas.

1. **Query cache nuked on every CUD (HIGH).**
   `CacheHandler::clearSchemaRelatedCaches()` (`lib/Service/Object/CacheHandler.php:567-632`)
   calls `$this->queryCache->clear()` (`:580`) — the code's own comment labels it
   `'strategy' => 'nuclear_clear'` (`:590`) — wiping the entire distributed
   query-result cache for the whole instance (all users, registers, schemas) on
   any create/update/delete. Bulk delete across S schemas calls it S times
   (`ObjectService.php:3372-3399`). The query cache effectively behaves as a
   global cache invalidated on every write.

2. **Aggregation cache full-wipe ignoring its own scope args (MED).**
   `AggregationCache::evictForSchema($registerSlug, $schemaSlug)`
   (`lib/Service/Aggregation/AggregationCache.php:270-287`) ignores both params
   (`@SuppressWarnings(PHPMD.UnusedFormalParameter)`) and calls `cache->clear()`
   (`:281`) — every write anywhere resets every dashboard's cached aggregate.

3. **Redundant schema double-cache (MED).**
   `SchemaCacheHandler::getSchema()` (`lib/Service/Schemas/SchemaCacheHandler.php:204-245`)
   maintains a DB-table cache tier whose `getCachedData()` SELECT (`:595-619`) is
   about as costly as `SchemaMapper::find()` — which already has its own
   `findCache` (`SchemaMapper.php:255-350`). Two independently-maintained caches +
   two DB round trips for the same entity; `setCachedData()` (`:636-676`) does
   3 upserts (6 statements) per `cacheSchema()`.

## What Changes

- Key the distributed query cache with a `register:schema` (or finer) prefix so a
  write can issue a **targeted** delete of the affected bucket instead of
  `clear()`. Collapse repeated invalidations within one bulk operation to one
  call per affected bucket.
- Make `AggregationCache` eviction actually scoped: fold a per-(register,schema)
  version counter into the cache key (O(1) eviction, no global wipe), or use a
  backing cache that supports prefix-scoped delete.
- Collapse the schema caching to a single source: either drop the DB-table tier
  in favour of the mapper's `findCache` + local/APCu memory, or make
  `SchemaCacheHandler` the sole cache and stop the mapper caching — not both.

## Impact

- Affected: `lib/Service/Object/CacheHandler.php`,
  `lib/Service/Aggregation/AggregationCache.php`,
  `lib/Service/Schemas/SchemaCacheHandler.php`, `lib/Db/SchemaMapper.php`,
  `lib/Service/ObjectService.php` (bulk-delete invalidation collapse).
- Behavioural: cache hit-rates rise sharply on write-active instances; correctness
  unchanged (invalidation still covers the written bucket). Verify no stale reads
  by testing write-then-read across the affected + an unaffected bucket.
- Risk: scoped invalidation must cover every key that a write can affect — err
  toward invalidating the whole `register:schema` bucket, not narrower, to avoid
  stale reads.
