## 1. Scoped query-cache invalidation

- [ ] 1.1 Add a `register:schema` (or finer) prefix to distributed query-cache keys.
- [ ] 1.2 In `CacheHandler::clearSchemaRelatedCaches()` (`:567-632`), replace `queryCache->clear()` (`:580`) with a targeted delete of the affected bucket.
- [ ] 1.3 In bulk delete (`ObjectService.php:3372-3399`), collapse invalidation to one call per distinct affected bucket, not per item.

## 2. Scoped aggregation eviction

- [ ] 2.1 Make `AggregationCache::evictForSchema()` (`:270-287`) actually use its args: fold a per-(register,schema) version counter into the cache key, or use a prefix-delete-capable backing cache. Remove the `UnusedFormalParameter` suppression.

## 3. Collapse schema double-cache

- [ ] 3.1 Decide the single schema cache source (mapper `findCache` + local/APCu, OR `SchemaCacheHandler`), and remove the other tier so there is one cache and one invalidation path.

## 4. Verification

- [ ] 4.1 Test: writing an object in schema A invalidates only A's query-cache bucket; a cached read of schema B still hits.
- [ ] 4.2 Test: a bulk delete across 3 schemas issues 3 targeted invalidations, not 3 global clears.
- [ ] 4.3 Test: dashboard aggregate for schema B survives a write to schema A.
- [ ] 4.4 Write-then-read consistency test across affected + unaffected buckets (no stale reads).
- [ ] 4.5 `composer check:strict` passes.

## Acceptance criteria

- No write performs an instance-wide query/aggregation cache clear.
- Invalidation is scoped to the affected `register:schema` bucket.
- Schemas are cached by exactly one tier.
