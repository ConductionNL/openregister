# Facets UUID Resolution Implementation Summary

## Overview
This document summarizes the implementation of UUID-to-name resolution in Solr facet responses and outlines testing procedures for related Solr functionality.

## Changes Implemented

### 1. GuzzleSolrService UUID Resolution
**File:** `openregister/lib/Service/GuzzleSolrService.php`

#### A. Lazy-Loading ObjectCacheService
- **Problem:** ObjectCacheService was not injected into GuzzleSolrService, causing circular dependency issues
- **Solution:** Implemented lazy-loading pattern to get ObjectCacheService from container only when needed

**New Properties:**
```php
private ?ObjectCacheService $objectCacheService = null;
private bool $objectCacheServiceAttempted = false;
```

**New Method:**
```php
private function getObjectCacheService(): ?ObjectCacheService
```
- Loads ObjectCacheService from Nextcloud container on first call
- Caches the result to avoid repeated container lookups
- Handles exceptions gracefully if service is unavailable

#### B. Enhanced formatTermsFacetData() Method
**Location:** Lines 8784-8908

**Functionality:**
1. **UUID Detection:** Filters facet values to identify potential UUIDs (contains hyphens)
2. **Batch Resolution:** Uses `ObjectCacheService->getMultipleObjectNames()` to resolve all UUIDs in one call
3. **Label Assignment:** Replaces UUID values with resolved names in facet bucket labels
4. **Alphabetical Sorting:** Sorts facets by label (case-insensitive) after resolution
5. **Graceful Fallback:** If resolution fails or service unavailable, uses UUID as label

**Debug Logging Added:**
- Bucket count and samples
- UUID detection results  
- ObjectCacheService load status
- Resolution results (count and samples)
- Before/after sorting samples

### 2. Application.php - No Changes Required
**File:** `openregister/lib/AppInfo/Application.php`

- ObjectCacheService registration already handles circular dependency via try-catch
- GuzzleSolrService now lazy-loads ObjectCacheService, avoiding injection issues

## Testing Required

### Test 1: UUID Resolution in Facets
**Status:** IN PROGRESS

**Test Endpoint:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications?_source=index&_limit=0&_facets=extend" \
  -H "Content-Type: application/json"
```

**Expected Result:**
```json
{
  "facets": {
    "object_fields": {
      "referentieComponenten": {
        "data": {
          "buckets": [
            {
              "value": "01c26b42-e047-4322-95ba-46d53a1696c0",
              "count": 2,
              "label": "Component Name Here"  // ← Should be resolved name, not UUID
            }
          ]
        }
      }
    }
  }
}
```

**Debug Logs to Check:**
```bash
docker logs master-nextcloud-1 2>&1 | grep -E "(FORMAT TERMS FACET|ObjectCacheService|Resolved)" | tail -50
```

### Test 2: Case-Insensitive Search
**Status:** PENDING

**Problem:** Reports indicate _search parameter may be case-sensitive

**Test Cases:**
```bash
# Test 1: Lowercase search
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/openregister/api/objects?_search=test&_source=index"

# Test 2: Uppercase search (should return same results as Test 1)
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/openregister/api/objects?_search=TEST&_source=index"

# Test 3: Mixed case search (should return same results)
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/openregister/api/objects?_search=TeSt&_source=index"
```

**Files to Check:**
- `GuzzleSolrService->buildWeightedSearchQuery()` (line ~3087)
- `GuzzleSolrService->cleanSearchTerm()` (line ~3125)

**Fix Location:**
Ensure Solr query uses lowercase matching or wildcards appropriately.

### Test 3: Metadata Ordering (@self.name)
**Status:** PENDING

**Problem:** Ordering on metadata fields doesn't work correctly

**Test Cases:**
```bash
# Ascending order by name
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications?_source=index&@self[schema]=27&_page=1&_order[@self.name]=asc"

# Descending order by name
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications?_source=index&@self[schema]=27&_page=1&_order[@self.name]=desc"
```

**Files to Check:**
- `GuzzleSolrService->translateSortField()` (line ~2296)
- `GuzzleSolrService->buildSolrQuery()` (line ~3145)

**Expected Solr Field:**
- `@self.name` should translate to `self_name` in Solr query
- Verify field exists in Solr schema and is sortable

### Test 4: Metadata Ordering (@self.published)
**Status:** PENDING

**Test Cases:**
```bash
# Ascending order by published date
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications?_source=index&@self[schema]=27&_page=1&_order[@self.published]=asc"

# Descending order by published date  
curl -s -u 'admin:admin' "http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications?_source=index&@self[schema]=27&_page=1&_order[@self.published]=desc"
```

**Expected Solr Field:**
- `@self.published` should translate to `self_published` in Solr
- Verify date/boolean type handling in sort

## Debug Commands

### View Container Logs
```bash
# All logs
docker logs master-nextcloud-1 2>&1 | tail -100

# Facet-specific logs
docker logs master-nextcloud-1 2>&1 | grep "FORMAT TERMS FACET"

# UUID resolution logs
docker logs master-nextcloud-1 2>&1 | grep "ObjectCacheService"

# Solr query logs
docker logs master-nextcloud-1 2>&1 | grep "SOLR"
```

### Test ObjectCacheService Directly
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://master-nextcloud-1/index.php/apps/openregister/api/names" \
  -H "Content-Type: application/json"
```

### Check Solr Index
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://master-nextcloud-1/index.php/apps/openregister/api/objects?_source=index&_limit=1" \
  -H "Content-Type: application/json"
```

## Next Steps

1. **Complete UUID Resolution Testing**
   - Run test endpoint
   - Check logs for UUID detection and resolution
   - Verify labels show names instead of UUIDs
   - Confirm alphabetical sorting by resolved names

2. **Fix Case-Insensitive Search**
   - Test current behavior with different cases
   - Update `buildWeightedSearchQuery()` to force lowercase
   - Add `.lowercase()` to Solr query fields if needed

3. **Fix Metadata Ordering**
   - Test current order behavior
   - Verify field mapping in `translateSortField()`
   - Check Solr schema for sortable fields
   - Add/update field mappings as needed

4. **Remove Debug Statements**
   - Once testing complete, remove all `error_log()` calls
   - Keep PSR logger debug statements for production debugging

## Files Modified

- `openregister/lib/Service/GuzzleSolrService.php`
  - Added lazy-loading for ObjectCacheService
  - Enhanced formatTermsFacetData() with UUID resolution
  - Added comprehensive debug logging

- `openregister/lib/AppInfo/Application.php`
  - Updated comments to clarify lazy-loading approach
  - No functional changes (circular dependency already handled)

## Related Documentation

- NamesController: `/openregister/lib/Controller/NamesController.php`
- ObjectCacheService: `/openregister/lib/Service/ObjectCacheService.php`
- Facet Service: `/openregister/lib/Service/FacetService.php`

## Performance Considerations

- **Batch Resolution:** All UUIDs in facets resolved in single call to ObjectCacheService
- **Caching:** ObjectCacheService uses distributed cache (Redis/Memcache) + in-memory cache
- **Lazy Loading:** ObjectCacheService only loaded when facets contain UUIDs
- **Expected Performance:** Sub-10ms for cached names, <100ms for uncached batch resolution

