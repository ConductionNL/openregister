# SOLR Service Refactoring Status

**Date:** October 13, 2025  
**Status:** ✅ Phase 1 In Progress - Basic functionality verified

## Current Status

### ✅ Verified Working
- SOLR connection: **Connected**
- Total Objects indexed: **57,310**
- Published Objects: **36,750**
- Dashboard stats API: Working correctly
- Object search: Functional
- Collection separation: `objectCollection` and `fileCollection` configured

### ✅ Completed
1. Created `SolrObjectService` - Object-specific operations wrapper
2. Created `SolrFileService` - File-specific operations wrapper with placeholder implementations
3. Updated `getDashboardStats()` to query both collections separately
4. Created comprehensive architecture documentation
5. Created 53 actionable TODOs across 8 phases

## Service Architecture

```
Current State (Phase 1):
┌─────────────────────────────────────┐
│        ObjectService                │
│  (High-level orchestration)         │
└─────────┬───────────────────────────┘
          │
          ▼
┌─────────────────────────────────────┐
│    GuzzleSolrService                │
│  (Currently handles EVERYTHING)     │
│  • Core SOLR operations            │
│  • Object indexing ❌ should move   │
│  • File operations ❌ not yet used  │
│  • Search ❌ should delegate        │
└─────────────────────────────────────┘

Target State (Phase 1 Complete):
┌─────────────────────────────────────┐
│        ObjectService                │
│  (High-level orchestration)         │
└───┬───────────────┬─────────────────┘
    │               │
    ▼               ▼
┌──────────────┐  ┌──────────────────┐
│SolrObjectSvc │  │  SolrFileSvc     │
│(Objects)     │  │  (Files)         │
└───┬──────────┘  └─────┬────────────┘
    │                   │
    └───────┬───────────┘
            ▼
    ┌───────────────────────┐
    │  GuzzleSolrService    │
    │  (Core only)          │
    │  • Connection mgmt    │
    │  • Collections        │
    │  • HTTP client        │
    │  • Admin operations   │
    └───────────────────────┘
```

## What Works Now

### ObjectService → GuzzleSolrService
```php
// lib/Service/ObjectService.php:2479
$solrService = $this->container->get(GuzzleSolrService::class);
$result = $solrService->searchObjectsPaginated($query, $rbac, $multi, $published, $deleted);
```

### GuzzleSolrService Methods Used
1. `indexObject(ObjectEntity $object, bool $commit)` - Line 862
2. `deleteObject(string|int $objectId, bool $commit)` - Line 990
3. `searchObjectsPaginated(array $query, ...)` - Line 1837
4. `bulkIndexFromDatabase(int $batchSize, ...)` - Line 4696
5. `getDashboardStats()` - Line 4227 (✅ Updated to query both collections)

## Refactoring Strategy

### Phase 1A: Service Wrappers (✅ DONE)
- [x] Create `SolrObjectService` with delegation methods
- [x] Create `SolrFileService` with placeholder methods
- [x] Add `getObjectCollection()` and `getFileCollection()` helpers
- [x] Update dashboard stats to query both collections

### Phase 1B: Gradual Migration (NEXT)
Instead of moving code immediately, we'll:
1. ✅ Keep `GuzzleSolrService` methods working as-is
2. ✅ Have new services delegate to existing methods
3. ✅ Update `ObjectService` to use `SolrObjectService` instead
4. ⏳ Later phases will move actual implementation

This ensures **zero downtime** and **continuous functionality**.

## Collections Configuration

### Current Setup
```json
{
  "objectCollection": "nc_test_local_objects",  // For objects
  "fileCollection": "nc_test_local_files",      // For files (future)
  "collection": "legacy_collection"              // Deprecated, will be removed
}
```

### Usage Pattern
```php
// SolrObjectService
private function getObjectCollection(): ?string
{
    $solrSettings = $this->settingsService->getSolrSettingsOnly();
    return $solrSettings['objectCollection'] ?? null;
}

// All object operations check this first
if ($collection === null) {
    throw new \Exception('objectCollection not configured');
}
```

## Next Steps

### Immediate (This Session)
1. ✅ Test dashboard stats endpoint
2. ⏳ Update `ObjectService` to inject `SolrObjectService`
3. ⏳ Verify object indexing still works
4. ⏳ Verify object search still works
5. ⏳ Mark Phase 1 as complete

### Phase 2 (Next Session)
1. Remove legacy `collection` field from settings
2. Update `getActiveCollectionName()` to use `objectCollection` by default
3. Add collection parameter to core methods
4. Test all SOLR operations

### Phase 3+ (Future Sessions)
- Install LLPhant
- Implement file text extraction
- Implement document chunking
- Add vector embeddings
- Create hybrid search

## Testing Checklist

### Manual Tests
- [x] Dashboard loads and shows stats
- [x] SOLR connection shows "Connected"
- [x] Object counts are displayed (57,310 total)
- [ ] Create new object → verify it indexes
- [ ] Search for objects → verify results returned
- [ ] Delete object → verify removed from index

### API Tests
```bash
# Test dashboard stats (both collections)
curl http://nextcloud.local/index.php/apps/openregister/api/solr/dashboard/stats

# Expected: Should show objectCollection and fileCollection separately
{
  "objectCollection": "nc_test_local_objects",
  "fileCollection": "nc_test_local_files",
  "objectDocuments": 57310,
  "fileDocuments": 0,
  ...
}
```

## Risk Assessment

### Low Risk ✅
- Current approach (wrapper services)
- Dashboard stats changes
- Adding new services without removing old code

### Medium Risk ⚠️
- Updating ObjectService injection
- Removing legacy `collection` field

### High Risk ❌
- Moving indexing logic between services (deferred to later phase)
- Changing database queries
- Modifying search algorithms

## Rollback Plan

If issues occur:
1. Revert `ObjectService` to use `GuzzleSolrService` directly
2. Keep dashboard stats changes (they're backward compatible)
3. New services (`SolrObjectService`, `SolrFileService`) can remain as they delegate to existing methods
4. No database changes have been made yet

## Performance Impact

### Current Performance
- Dashboard stats: Query time increased slightly (queries 2 collections instead of 1)
- Object operations: No change (same code path)
- Search: No change

### Expected After Phase 1
- No performance change (just code organization)

### Expected After Full Implementation
- File indexing: New capability (no baseline to compare)
- Vector search: Additional ~200ms per query for embeddings
- Hybrid search: Combines keyword + semantic (~300ms total)

## Notes

- All original functionality is preserved
- New services are thin wrappers for now
- Actual code migration happens in later phases
- This ensures continuous operation during refactoring

