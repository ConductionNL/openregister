---
title: Solr Development Troubleshooting
sidebar_position: 30
---

# Solr Development Troubleshooting

This document covers common Solr development issues, fixes, and troubleshooting guides.

## SOLR Filter AND Logic Fix

### Problem

When sending multiple filter parameters to SOLR search, filters were using OR logic instead of AND logic. This caused MORE results with more filters (union) instead of FEWER results (intersection/drilling down).

**Example:**
```
?status=active&category=featured
```

**Expected:** Items that are BOTH active AND featured (fewer results)
**Actual:** Items that are active OR featured (more results) ❌

### Root Cause

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

### Solution

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

### How Filtering Works Now

#### Single Value Filters (AND Logic)
```
?status=active&category=featured
```
Creates:
```
fq=status:active
fq=category:featured
```
Result: active AND featured (fewer results) ✅

#### Array Value Filters (OR Logic within field)
```
?status[]=active&status[]=pending
```
Creates:
```
fq=(status:active OR status:pending)
```
Result: active OR pending (more results for that field) ✅

#### Mixed Filters
```
?status[]=active&status[]=pending&category=featured
```
Creates:
```
fq=(status:active OR status:pending)
fq=category:featured
```
Result: (active OR pending) AND featured ✅

### Testing

After deploying, test with multiple filters:

1. **Single filter:** Should return subset
2. **Two filters:** Should return smaller subset (drill down)
3. **Three filters:** Should return even smaller subset (drill down further)

More filters = fewer results = drilling down ✅

### Debug Logging

Added extensive debug logging to diagnose filter issues:
- Logs incoming query structure
- Logs each filter being built
- Logs final filter queries sent to SOLR
- Check logs if filtering still doesn't work as expected

**Date Fixed:** 2024-10-14

## UUID Label Resolution for Facets

### Problem Statement

When faceting on object fields that contain UUIDs (references to other objects), the facet labels were displaying raw UUID strings instead of human-readable names. This made facets unfriendly for frontend users and also prevented proper alphabetical ordering of facet buckets.

### Solution

We implemented automatic UUID resolution for all facet types using the existing `ObjectCacheService.getMultipleObjectNames()` method, which provides efficient batch loading and multi-tier caching.

#### Changes Made

1. **Updated GuzzleSolrService Constructor** - Added `ObjectCacheService` as a dependency
2. **Enhanced formatTermsFacetData Method** - Modified to detect UUIDs, batch resolve them, and sort alphabetically by resolved names

#### How It Works

1. **UUID Detection**: Checks if facet values contain hyphens (simple but effective UUID detection)
2. **Batch Resolution**: Uses `ObjectCacheService.getMultipleObjectNames()` for efficient batch retrieval
3. **Cache Hierarchy**: Checks in-memory cache → distributed cache → database
4. **Name Extraction**: Searches common name fields (naam, name, title, contractNummer, achternaam)
5. **Alphabetical Sorting**: Sorts by resolved labels (case-insensitive A-Z)

#### Example Result

**Before UUID Resolution:**
```json
{
  "customer": {
    "buckets": [
      { "value": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "count": 42, "label": "f47ac10b-58cc-4372-a567-0e02b2c3d479" }
    ]
  }
}
```

**After UUID Resolution:**
```json
{
  "customer": {
    "buckets": [
      { "value": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "count": 42, "label": "Acme Corporation" }
    ]
  }
}
```

#### Performance Impact

- **UUID detection**: Simple string check - negligible cost
- **Batch loading**: Single query for all UUIDs instead of N queries
- **Cache hits**: Most UUIDs resolved from cache without database access
- **Lazy evaluation**: Only processes values that look like UUIDs

## SOLR Field Dialog - Collection Support

### Issue

The "SOLR Field Configuration" dialog only showed missing fields for the **Object Collection**, not the **File Collection**.

### Solution

Updated the backend to query both collections and return fields with collection identifiers.

#### Changes Made

1. **Updated `SettingsController::getSolrFields()`** - Now calls both `getObjectCollectionFieldStatus()` and `getFileCollectionFieldStatus()`
2. **Fixed `SolrSchemaService` Collection Query Methods** - Properly queries both object and file collections
3. **Added Collection Properties** - Each field now includes `collection` and `collectionLabel` properties

#### New Response Format

```json
{
  "success": true,
  "comparison": {
    "total_differences": 194,
    "missing_count": 131,
    "extra_count": 63,
    "missing": [
      {
        "name": "file_id",
        "type": "plong",
        "collection": "files",
        "collectionLabel": "File Collection"
      },
      {
        "name": "self_tenant",
        "type": "string",
        "collection": "objects",
        "collectionLabel": "Object Collection"
      }
    ],
    "object_collection": {
      "missing": 131,
      "extra": 50
    },
    "file_collection": {
      "missing": 63,
      "extra": 13
    }
  }
}
```

#### Benefits

- ✅ Complete visibility - See all missing fields across all collections
- ✅ Better organization - Fields grouped by collection
- ✅ Actionable insights - Know exactly which collection needs which fields
- ✅ Consistent schema - Ensure both collections have required fields

**Date:** 2025-10-13

## SOLR Service Refactoring Status

### Current Status

**Phase 1 In Progress** - Basic functionality verified

#### Verified Working
- SOLR connection: **Connected**
- Total Objects indexed: **57,310**
- Published Objects: **36,750**
- Dashboard stats API: Working correctly
- Object search: Functional
- Collection separation: `objectCollection` and `fileCollection` configured

#### Completed
1. Created `SolrObjectService` - Object-specific operations wrapper
2. Created `SolrFileService` - File-specific operations wrapper with placeholder implementations
3. Updated `getDashboardStats()` to query both collections separately
4. Created comprehensive architecture documentation

### Service Architecture

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
│  • Object indexing                 │
│  • File operations                 │
│  • Search                          │
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

### Refactoring Strategy

#### Phase 1A: Service Wrappers (✅ DONE)
- [x] Create `SolrObjectService` with delegation methods
- [x] Create `SolrFileService` with placeholder methods
- [x] Add `getObjectCollection()` and `getFileCollection()` helpers
- [x] Update dashboard stats to query both collections

#### Phase 1B: Gradual Migration (NEXT)
Instead of moving code immediately:
1. ✅ Keep `GuzzleSolrService` methods working as-is
2. ✅ Have new services delegate to existing methods
3. ✅ Update `ObjectService` to use `SolrObjectService` instead
4. ⏳ Later phases will move actual implementation

This ensures **zero downtime** and **continuous functionality**.

### Collections Configuration

```json
{
  "objectCollection": "nc_test_local_objects",  // For objects
  "fileCollection": "nc_test_local_files",      // For files (future)
  "collection": "legacy_collection"              // Deprecated, will be removed
}
```

### Risk Assessment

#### Low Risk ✅
- Current approach (wrapper services)
- Dashboard stats changes
- Adding new services without removing old code

#### Medium Risk ⚠️
- Updating ObjectService injection
- Removing legacy `collection` field

#### High Risk ❌
- Moving indexing logic between services (deferred to later phase)
- Changing database queries
- Modifying search algorithms

### Rollback Plan

If issues occur:
1. Revert `ObjectService` to use `GuzzleSolrService` directly
2. Keep dashboard stats changes (they're backward compatible)
3. New services (`SolrObjectService`, `SolrFileService`) can remain as they delegate to existing methods
4. No database changes have been made yet

**Date:** October 13, 2025

## Related Documentation

- [Solr Setup and Configuration](../technical/solr-setup-configuration.md) - Complete Solr setup guide
- [Solr Dashboard Management](../technical/solr-dashboard-management.md) - Dashboard management documentation
- [Faceting System](../Features/faceting.md) - Faceting documentation with UUID resolution details

