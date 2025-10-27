# Solr Search & Ordering Testing Guide

## Overview
This document provides comprehensive testing procedures for Solr search functionality, including case-insensitive search, metadata ordering, and facet UUID resolution.

## Changes Implemented

### 1. Case-Insensitive Search (✅ FIXED)
**File:** `openregister/lib/Service/GuzzleSolrService.php`
**Method:** `buildWeightedSearchQuery()` (line ~3138)

**Change:**
```php
// Added line 3145:
$cleanTerm = mb_strtolower($cleanTerm);
```

**Why:** Ensures search terms are lowercase regardless of user input, making searches case-insensitive even if Solr schema doesn't have lowercase filters configured.

### 2. Metadata Ordering (✅ VERIFIED CORRECT)
**File:** `openregister/lib/Service/GuzzleSolrService.php`
**Methods:** 
- `translateFilterField()` (line ~2314)
- `translateSortField()` (line ~2347)

**Functionality:**
- `@self.name` → `self_name` (sortable string field)
- `@self.published` → `self_published` (sortable date field)
- Direction handling: `asc` / `desc`

**Indexed Fields:**
- `self_name`: Line 1300, 1838 (string, sortable)
- `self_published`: Line 1313, 1854 (date in ISO 8601 format, sortable)

### 3. Facet UUID Resolution (✅ IMPLEMENTED, ⏳ TESTING)
**File:** `openregister/lib/Service/GuzzleSolrService.php`
**Method:** `formatTermsFacetData()` (line ~8784)

**Features:**
- Lazy-loads ObjectCacheService from container
- Batch-resolves UUIDs to names
- Sorts facets alphabetically by resolved labels
- Graceful fallback to UUID if resolution fails

## Test Suite

### Test 1: Case-Insensitive Search

**Purpose:** Verify search works regardless of input case

**Test Cases:**

```bash
# Base URL for all tests
BASE_URL="http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications"

# Test 1a: Lowercase search
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_search=software&_limit=5" \
  -H "Content-Type: application/json" | jq '.total'

# Test 1b: Uppercase search (should return same count as 1a)
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_search=SOFTWARE&_limit=5" \
  -H "Content-Type: application/json" | jq '.total'

# Test 1c: Mixed case search (should return same count)
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_search=SoFtWaRe&_limit=5" \
  -H "Content-Type: application/json" | jq '.total'
```

**Expected Result:** All three tests should return the same total count.

**Verification:**
```bash
# Run all three and compare
echo "Lowercase:" && docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' "${BASE_URL}?_source=index&_search=software&_limit=1" -H "Content-Type: application/json" | jq '.total' && \
echo "Uppercase:" && docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' "${BASE_URL}?_source=index&_search=SOFTWARE&_limit=1" -H "Content-Type: application/json" | jq '.total' && \
echo "Mixed:" && docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' "${BASE_URL}?_source=index&_search=SoFtWaRe&_limit=1" -H "Content-Type: application/json" | jq '.total'
```

---

### Test 2: Ordering by @self.name (Ascending)

**Purpose:** Verify alphabetical ordering by name field

**Test Command:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&@self[schema]=27&_page=1&_order[@self.name]=asc&_limit=5" \
  -H "Content-Type: application/json" | jq '.results[] | {name: .["@self"].name}'
```

**Expected Result:** Names should be in alphabetical order (A→Z)

**Verification:**
```bash
# Get names and verify order
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_order[@self.name]=asc&_limit=10" \
  -H "Content-Type: application/json" | jq -r '.results[] | .["@self"].name' | sort -c && echo "✓ Names are in ascending order" || echo "✗ Names are NOT in ascending order"
```

---

### Test 3: Ordering by @self.name (Descending)

**Purpose:** Verify reverse alphabetical ordering

**Test Command:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&@self[schema]=27&_page=1&_order[@self.name]=desc&_limit=5" \
  -H "Content-Type: application/json" | jq '.results[] | {name: .["@self"].name}'
```

**Expected Result:** Names should be in reverse alphabetical order (Z→A)

**Verification:**
```bash
# Get names and verify reverse order
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_order[@self.name]=desc&_limit=10" \
  -H "Content-Type: application/json" | jq -r '.results[] | .["@self"].name' | sort -r -c && echo "✓ Names are in descending order" || echo "✗ Names are NOT in descending order"
```

---

### Test 4: Ordering by @self.published (Ascending)

**Purpose:** Verify chronological ordering by published date

**Test Command:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&@self[schema]=27&_page=1&_order[@self.published]=asc&_limit=5" \
  -H "Content-Type: application/json" | jq '.results[] | {name: .["@self"].name, published: .["@self"].published}'
```

**Expected Result:** Published dates should be in chronological order (oldest→newest)

**Verification:**
```bash
# Get published dates and verify order
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_order[@self.published]=asc&_limit=10" \
  -H "Content-Type: application/json" | jq -r '.results[] | .["@self"].published // "null"' | grep -v null | sort -c && echo "✓ Dates are in ascending order" || echo "✗ Dates are NOT in ascending order"
```

---

### Test 5: Ordering by @self.published (Descending)

**Purpose:** Verify reverse chronological ordering

**Test Command:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&@self[schema]=27&_page=1&_order[@self.published]=desc&_limit=5" \
  -H "Content-Type: application/json" | jq '.results[] | {name: .["@self"].name, published: .["@self"].published}'
```

**Expected Result:** Published dates should be in reverse chronological order (newest→oldest)

**Verification:**
```bash
# Get published dates and verify reverse order
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_order[@self.published]=desc&_limit=10" \
  -H "Content-Type: application/json" | jq -r '.results[] | .["@self"].published // "null"' | grep -v null | sort -r -c && echo "✓ Dates are in descending order" || echo "✗ Dates are NOT in descending order"
```

---

### Test 6: Facet UUID Resolution

**Purpose:** Verify UUIDs in facet buckets are resolved to names

**Test Command:**
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_limit=0&_facets=extend" \
  -H "Content-Type: application/json" | jq '.facets.object_fields.referentieComponenten.data.buckets[0]'
```

**Expected Result:**
```json
{
  "value": "01c26b42-e047-4322-95ba-46d53a1696c0",
  "count": 2,
  "label": "Component Name Here"  // ← Should be resolved name, NOT UUID
}
```

**Debug Command:**
```bash
# Check logs for UUID resolution
docker logs master-nextcloud-1 2>&1 | grep -E "(FORMAT TERMS FACET|ObjectCacheService|Resolved)" | tail -50
```

**Key Log Messages to Look For:**
- `=== FORMAT TERMS FACET DATA CALLED ===`
- `Total values: X`
- `Potential UUIDs: Y`
- `=== ATTEMPTING TO LOAD ObjectCacheService ===`
- `ObjectCacheService loaded: YES`
- `Calling getMultipleObjectNames() with Z UUIDs`
- `Resolved N names`

---

## Automated Test Script

Save as `test_solr.sh`:

```bash
#!/bin/bash

# Solr Test Suite
# Tests case-insensitive search, ordering, and facet UUID resolution

BASE_URL="http://master-nextcloud-1/index.php/apps/opencatalogi/api/publications"
EXEC_PREFIX="docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin'"

echo "================================"
echo "SOLR FUNCTIONALITY TEST SUITE"
echo "================================"
echo ""

# Test 1: Case-Insensitive Search
echo "TEST 1: Case-Insensitive Search"
echo "--------------------------------"
echo -n "Lowercase 'software': "
LOWER=$(${EXEC_PREFIX} "${BASE_URL}?_source=index&_search=software&_limit=1" -H "Content-Type: application/json" | jq -r '.total // 0')
echo "${LOWER} results"

echo -n "Uppercase 'SOFTWARE': "
UPPER=$(${EXEC_PREFIX} "${BASE_URL}?_source=index&_search=SOFTWARE&_limit=1" -H "Content-Type: application/json" | jq -r '.total // 0')
echo "${UPPER} results"

echo -n "Mixed 'SoFtWaRe': "
MIXED=$(${EXEC_PREFIX} "${BASE_URL}?_source=index&_search=SoFtWaRe&_limit=1" -H "Content-Type: application/json" | jq -r '.total // 0')
echo "${MIXED} results"

if [ "$LOWER" -eq "$UPPER" ] && [ "$LOWER" -eq "$MIXED" ]; then
    echo "✓ PASS: Case-insensitive search working"
else
    echo "✗ FAIL: Results differ (${LOWER} vs ${UPPER} vs ${MIXED})"
fi
echo ""

# Test 2: Ordering by name (ascending)
echo "TEST 2: Ordering by @self.name (ASC)"
echo "------------------------------------"
${EXEC_PREFIX} "${BASE_URL}?_source=index&_order[@self.name]=asc&_limit=5" -H "Content-Type: application/json" | \
  jq -r '.results[]? | .["@self"].name' | head -5 | nl
echo ""

# Test 3: Ordering by name (descending)
echo "TEST 3: Ordering by @self.name (DESC)"
echo "-------------------------------------"
${EXEC_PREFIX} "${BASE_URL}?_source=index&_order[@self.name]=desc&_limit=5" -H "Content-Type: application/json" | \
  jq -r '.results[]? | .["@self"].name' | head -5 | nl
echo ""

# Test 4: Ordering by published (ascending)
echo "TEST 4: Ordering by @self.published (ASC)"
echo "-----------------------------------------"
${EXEC_PREFIX} "${BASE_URL}?_source=index&_order[@self.published]=asc&_limit=5" -H "Content-Type: application/json" | \
  jq -r '.results[]? | "\(.["@self"].name): \(.["@self"].published // "null")"' | head -5 | nl
echo ""

# Test 5: Ordering by published (descending)
echo "TEST 5: Ordering by @self.published (DESC)"
echo "------------------------------------------"
${EXEC_PREFIX} "${BASE_URL}?_source=index&_order[@self.published]=desc&_limit=5" -H "Content-Type: application/json" | \
  jq -r '.results[]? | "\(.["@self"].name): \(.["@self"].published // "null")"' | head -5 | nl
echo ""

# Test 6: Facet UUID Resolution
echo "TEST 6: Facet UUID Resolution"
echo "-----------------------------"
echo "First facet bucket:"
${EXEC_PREFIX} "${BASE_URL}?_source=index&_limit=0&_facets=extend" -H "Content-Type: application/json" | \
  jq '.facets.object_fields | to_entries[0] | "\(.key): \(.value.data.buckets[0])"'
echo ""

echo "Check logs with:"
echo "docker logs master-nextcloud-1 2>&1 | grep -E '(FORMAT TERMS FACET|ObjectCacheService)' | tail -20"
echo ""

echo "================================"
echo "TEST SUITE COMPLETE"
echo "================================"
```

**Make executable and run:**
```bash
chmod +x test_solr.sh
./test_solr.sh
```

---

## Troubleshooting

### Issue: Search Returns HTML
**Symptom:** API returns HTML instead of JSON
**Cause:** Authentication failure or app not enabled
**Solution:**
```bash
# Check app status
docker exec -u 33 master-nextcloud-1 php occ app:list | grep -E "(opencatalogi|openregister)"

# Enable if needed
docker exec -u 33 master-nextcloud-1 php occ app:enable opencatalogi
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

### Issue: No Search Results
**Symptom:** `total: 0` for all searches
**Cause:** Solr index may be empty
**Solution:**
```bash
# Check Solr configuration
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://master-nextcloud-1/index.php/apps/openregister/api/objects?_source=index&_limit=1" \
  -H "Content-Type: application/json"

# Rebuild index if needed (through Nextcloud admin UI)
```

### Issue: Ordering Not Working
**Symptom:** Results appear in random order
**Possible Causes:**
1. **Field not indexed as sortable** - Check Solr schema
2. **NULL values** - Solr may sort nulls differently
3. **Field type mismatch** - String vs. number sorting

**Debug:**
```bash
# Check what's actually being sent to Solr
docker logs master-nextcloud-1 2>&1 | grep "sort=" | tail -5
```

### Issue: UUIDs Still Showing in Facets
**Symptom:** Facet labels show UUIDs instead of names
**Possible Causes:**
1. ObjectCacheService not loading
2. Objects not found in database
3. Names not cached

**Debug:**
```bash
# Check ObjectCacheService logs
docker logs master-nextcloud-1 2>&1 | grep "ObjectCacheService" | tail -20

# Test ObjectCacheService directly
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://master-nextcloud-1/index.php/apps/openregister/api/names/01c26b42-e047-4322-95ba-46d53a1696c0" \
  -H "Content-Type: application/json"
```

---

## Performance Benchmarks

**Expected Performance:**
- Case-insensitive search: No performance impact (lowercasing is O(n) on search term length)
- Ordering: Minimal impact (<5ms additional) if fields are properly indexed
- Facet UUID resolution:
  - Cached: <10ms for 100 UUIDs
  - Uncached: <100ms for 100 UUIDs (batch DB query)

**Monitoring:**
```bash
# Watch execution times
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "${BASE_URL}?_source=index&_search=test&_limit=10" \
  -H "Content-Type: application/json" -w "\nTime: %{time_total}s\n"
```

---

## Related Files

- **GuzzleSolrService.php** - Main Solr integration
- **ObjectCacheService.php** - UUID-to-name resolution
- **NamesController.php** - Names API endpoint
- **FACETS_UUID_RESOLUTION_IMPLEMENTATION.md** - Detailed UUID resolution docs

---

## Next Steps

1. Run test suite
2. Verify all tests pass
3. Remove debug `error_log()` statements from production code
4. Update user documentation with new search capabilities
5. Consider adding integration tests to CI/CD pipeline

