# SOLR Field Dialog - Collection Support Fix

## Issue Reported

User noticed that the "SOLR Field Configuration" dialog only shows missing fields for the **Object Collection**, not the **File Collection**. 

**Expected Behavior:**
- Show missing fields from BOTH object and file collections
- Display them in a single table with a "Collection" column showing which collection each field belongs to

## Changes Made

### Backend Updates

#### 1. Updated `SettingsController::getSolrFields()` 
**File:** `lib/Controller/SettingsController.php`

**Changes:**
- Now calls both `getObjectCollectionFieldStatus()` and `getFileCollectionFieldStatus()`
- Combines missing fields from both collections
- Adds `collection` and `collectionLabel` properties to each field
- Returns structured data with separate sections for each collection

**New Response Format:**
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
        "config": {...},
        "collection": "files",
        "collectionLabel": "File Collection"
      },
      {
        "name": "self_tenant",
        "type": "string",
        "config": {...},
        "collection": "objects",
        "collectionLabel": "Object Collection"
      }
    ],
    "extra": [...],
    "object_collection": {
      "missing": 131,
      "extra": 50
    },
    "file_collection": {
      "missing": 63,
      "extra": 13
    }
  },
  "object_collection_status": {...},
  "file_collection_status": {...}
}
```

#### 2. Fixed `SolrSchemaService` Collection Query Methods
**File:** `lib/Service/SolrSchemaService.php`

**Changes:**
- Fixed `getObjectCollectionFieldStatus()` to properly query the object collection
- Fixed `getFileCollectionFieldStatus()` to properly query the file collection
- Added `getCurrentCollectionFields()` helper method to fetch current fields from a specific collection
- Returns missing fields with full configuration details
- Returns collection name in response

**Key Fixes:**
- Removed non-existent `getSolrFields()` method call
- Properly retrieves collection names from settings (`objectCollection`, `fileCollection`)
- Queries correct SOLR collection via `GuzzleSolrService`
- Returns missing fields as associative array with field configurations

---

## Current Status

### ✅ Completed
1. Backend logic updated to return fields from both collections
2. Fields now include `collection` and `collectionLabel` properties
3. Separate statistics for each collection included
4. Fixed collection querying logic

### 🔄 In Progress
- Testing the updated endpoint
- Need to verify field retrieval works correctly

### 🔜 Next Steps
1. **Verify endpoint works** - Ensure `/api/solr/fields` returns data correctly
2. **Update Frontend (if needed)** - May need to update UI to display "Collection" column
3. **Test with actual missing fields** - Ensure both object and file collection fields appear

---

## Frontend Updates Needed (TBD)

The frontend may need updates to:
1. **Add "Collection" column** to the missing fields table
2. **Show collection statistics** (e.g., "Object Collection: 131 missing, File Collection: 63 missing")
3. **Color-code or group** fields by collection for easier reading
4. **Update "Create Missing Fields" button** to handle both collections

**Location to update:** The component that renders `settingsStore.fieldsInfo` or `settingsStore.fieldComparison`

---

## Testing

### Manual Test
```bash
# Test the endpoint
curl -u 'admin:admin' \
  http://localhost/index.php/apps/openregister/api/solr/fields \
  | jq '.comparison'
```

### Expected Output Structure
```json
{
  "total_differences": 194,
  "missing_count": 131,
  "extra_count": 63,
  "missing": [
    {
      "name": "field_name_here",
      "type": "string|pint|plong|...",
      "config": {
        "type": "...",
        "indexed": true/false,
        "stored": true/false,
        "multiValued": true/false,
        "docValues": true/false
      },
      "collection": "objects|files",
      "collectionLabel": "Object Collection|File Collection"
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
```

---

## API Changes

### Before
`GET /api/solr/fields` - Only returned object collection fields

### After
`GET /api/solr/fields` - Returns fields from **both** collections with collection identifier

**Breaking Change:** ❌ No  
**Backwards Compatible:** ✅ Yes (response structure extended, not changed)

---

## Benefits

1. ✅ **Complete visibility** - See all missing fields across all collections
2. ✅ **Better organization** - Fields grouped by collection
3. ✅ **Actionable insights** - Know exactly which collection needs which fields
4. ✅ **Consistent schema** - Ensure both collections have required fields

---

## Files Modified

1. ✅ `lib/Controller/SettingsController.php` - Updated `getSolrFields()`
2. ✅ `lib/Service/SolrSchemaService.php` - Fixed collection field status methods
3. 🔄 Frontend (TBD) - May need UI updates to display collection column

---

## Next Actions

1. **Test endpoint** - Verify it returns data without errors
2. **Check UI** - See if existing UI handles new data structure
3. **Add Collection column** (if needed) - Update frontend table
4. **Document UI changes** - If frontend updates are needed

---

**Date:** 2025-10-13  
**Status:** Backend complete, testing in progress  
**Reported by:** User observation that only object fields were showing

