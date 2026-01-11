# Issue #001: Magic Mapper Cross-Table Search Performance Optimization

**Status:** 📋 Open  
**Priority:** 🟡 Medium  
**Effort:** ⏱️ 2-4 hours  
**Created:** 2026-01-05  
**Target:** Reduce cross-table search from ~380ms to <300ms

---

## 📊 Current Performance

| Scenario | Current Performance | Target |
|----------|-------------------|--------|
| Single table search | ~340ms | ~250ms |
| Cross-table (2 schemas) | ~385ms | ~280ms |
| Cross-table (5 schemas) | ~385ms | ~290ms |

**Observation:** Multi-table overhead is only ~45ms, which is already quite good!

## 🎯 Problem Statement

Cross-table searches via Magic Mapper are functional but could be optimized for better user experience. The current implementation searches each table sequentially, which works well but leaves room for improvement.

Example query:
```http
GET /api/objects?schemas[]=9&schemas[]=10&_search=open&_limit=30
```

Current flow:
1. Loop through each schema sequentially
2. Execute search query per table
3. Merge results
4. Sort by relevance

Total time: ~385ms for 2 tables

## ⚡ Proposed Optimizations

### Option A: GIN Indexes for pg_trgm (RECOMMENDED - Quick Win)

**Impact:** 🟢 High (100-200ms improvement)  
**Effort:** 🟡 Medium (15-30 minutes)  
**Risk:** 🟢 Low

Add GIN indexes on text columns used for fuzzy search:

```sql
-- Example for each magic mapper table
CREATE INDEX idx_naam_trgm ON oc_openregister_table_2_9 
USING GIN (naam gin_trgm_ops);

CREATE INDEX idx_beschrijving_trgm ON oc_openregister_table_2_9 
USING GIN (beschrijving gin_trgm_ops);
```

**Implementation:**
- Update `MagicMapper::createTableIndexes()` to add GIN indexes for text columns
- Detect column type from schema properties
- Only add GIN index for `string` and `text` types

**Files to modify:**
- `openregister/lib/Db/MagicMapper.php` (around line 1694-1730)

---

### Option B: Lower Default Limit (Quick Win)

**Impact:** 🟡 Medium (20-50ms improvement)  
**Effort:** 🟢 Low (5 minutes)  
**Risk:** 🟢 None

Change default `_limit` from 100 to 20 for API responses.

**Reasoning:**
- Most users only look at first page of results
- Reduces data transfer and serialization time
- Users can still request higher limits explicitly

**Files to modify:**
- `openregister/lib/Controller/ObjectsController.php` (default limit in queries)
- `openregister/lib/Db/MagicMapper.php` (default limit in search methods)

---

### Option C: UNION ALL Query Optimization (Advanced)

**Impact:** 🟡 Medium (50-100ms improvement)  
**Effort:** 🔴 High (2-3 hours)  
**Risk:** 🟡 Medium

Replace sequential table queries with a single UNION ALL SQL query:

```sql
SELECT *, '9' AS _schema_id FROM oc_openregister_table_2_9 WHERE naam ILIKE '%search%'
UNION ALL
SELECT *, '10' AS _schema_id FROM oc_openregister_table_2_10 WHERE naam ILIKE '%search%'
ORDER BY _search_score DESC
LIMIT 30;
```

**Advantages:**
- Single database round-trip instead of N trips
- PostgreSQL can optimize the combined query
- Reduces PHP overhead

**Challenges:**
- Complex SQL generation
- Different schemas might have different columns
- Harder to debug
- Limited to simple queries (no aggregations/facets)

**Implementation approach:**
1. Add `searchAcrossMultipleTablesWithUnion()` method
2. Build SELECT for each table with schema metadata
3. Combine with UNION ALL
4. Apply global ORDER BY and LIMIT
5. Convert rows to ObjectEntity objects
6. Fallback to sequential for complex queries

**Files to modify:**
- `openregister/lib/Db/MagicMapper.php` (add UNION method)

**Partial implementation started but not completed** - see git history around 2026-01-05.

---

### Option D: Result Caching (Future Enhancement)

**Impact:** 🟢 Very High (near-instant for cached queries)  
**Effort:** 🟡 Medium (1-2 hours)  
**Risk:** 🟡 Medium (cache invalidation complexity)

Implement Redis/APCu caching for search results:

```php
$cacheKey = 'magic_search_' . md5(json_encode($query));
if ($cached = $cache->get($cacheKey)) {
    return $cached;
}
// ... perform search ...
$cache->set($cacheKey, $results, 60); // 60 seconds TTL
```

**Considerations:**
- Cache invalidation when data changes
- Memory usage
- TTL configuration
- Cache key strategy

**Not recommended for initial implementation** - wait until there's actual user demand.

---

## 🎯 Recommended Implementation Order

1. **Start with Option A (GIN Indexes)** ✅
   - Highest impact, reasonable effort
   - No code changes to search logic
   - Low risk, easy to rollback

2. **Then Option B (Lower Limit)** ✅
   - Trivial to implement
   - Immediate benefit
   - No risk

3. **Measure Results** 📊
   - Run performance tests
   - If <300ms achieved: DONE! ✨
   - If not: Consider Option C

4. **Option C (UNION ALL) only if needed** ⚠️
   - Complex implementation
   - Higher risk
   - Only if Options A+B insufficient

5. **Option D (Caching) - Future** 🔮
   - Only if sub-100ms needed
   - Requires proper cache infrastructure

---

## 📝 Testing Strategy

After implementation, test with:

```bash
# Single table
time curl "http://localhost/apps/openregister/api/objects/2/9?_search=open&_limit=30"

# Multi-table (2 schemas)
time curl "http://localhost/apps/openregister/api/objects?schemas[]=9&schemas[]=10&_search=open&_limit=30"

# Multi-table (5 schemas)
time curl "http://localhost/apps/openregister/api/objects?schemas[]=9&schemas[]=10&schemas[]=11&schemas[]=12&schemas[]=13&_search=open&_limit=30"
```

**Success criteria:**
- Single table: <250ms
- Cross-table (2): <280ms
- Cross-table (5): <300ms

---

## 📚 References

- PostgreSQL GIN indexes: https://www.postgresql.org/docs/current/gin.html
- pg_trgm documentation: https://www.postgresql.org/docs/current/pgtrgm.html
- Nextcloud database best practices: https://docs.nextcloud.com/server/latest/developer_manual/basics/storage/database.html

---

## 🔄 Status Updates

| Date | Status | Notes |
|------|--------|-------|
| 2026-01-05 | Created | Initial analysis completed, Options A-D documented |

---

## 💬 Discussion

Add comments and findings here as work progresses.


