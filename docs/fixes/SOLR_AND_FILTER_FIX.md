# SOLR Filter AND Logic Fix

## Problem

When sending multiple filter parameters to SOLR search, filters were using OR logic instead of AND logic. This caused MORE results with more filters (union) instead of FEWER results (intersection/drilling down).

**Example:**
```
?status=active&category=featured
```

**Expected:** Items that are BOTH active AND featured (fewer results)
**Actual:** Items that are active OR featured (more results) ❌

## Root Cause

There was a **key mismatch** in the `buildSolrQuery()` method:

```php
// Line 2163 - WRONG KEY
$solrQuery['filters'] = $filterQueries;
```

But `executeSearch()` was looking for:
```php
// Line 3259 - DIFFERENT KEY
if ($key === 'fq' && is_array($value)) {
```

**Result:** Filters were never being passed to SOLR at all! No filtering was happening, so results weren't being narrowed down.

## Solution

Changed line 2165 to use the correct key:

```php
// **CRITICAL FIX**: Use 'fq' key (not 'filters') so executeSearch() can find them
// Multiple fq parameters are ANDed together by SOLR (drilling down)
$solrQuery['fq'] = $filterQueries;
```

Now filters are properly passed as multiple `fq` parameters to SOLR:
```
fq=status:active&fq=category:featured
```

SOLR automatically ANDs multiple `fq` parameters together.

## How Filtering Works Now

### Single Value Filters (AND Logic)
```
?status=active&category=featured
```
Creates:
```
fq=status:active
fq=category:featured
```
Result: active AND featured (fewer results) ✅

### Array Value Filters (OR Logic within field)
```
?status[]=active&status[]=pending
```
Creates:
```
fq=(status:active OR status:pending)
```
Result: active OR pending (more results for that field) ✅

### Mixed Filters
```
?status[]=active&status[]=pending&category=featured
```
Creates:
```
fq=(status:active OR status:pending)
fq=category:featured
```
Result: (active OR pending) AND featured ✅

## Testing

After deploying, test with multiple filters:

1. **Single filter:** Should return subset
2. **Two filters:** Should return smaller subset (drill down)
3. **Three filters:** Should return even smaller subset (drill down further)

More filters = fewer results = drilling down ✅

## Debug Logging

Added extensive debug logging to diagnose filter issues:
- Logs incoming query structure
- Logs each filter being built
- Logs final filter queries sent to SOLR
- Check logs if filtering still doesn't work as expected

## Date

Fixed: 2024-10-14

