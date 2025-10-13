# SOLR Field Dialog - Current Status

## Issue
User reported that the "SOLR Field Configuration" dialog only shows missing fields for the **Object Collection**, not both object and file collections.

## Changes Made
1. ✅ Updated `SettingsController::getSolrFields()` to query both collections
2. ✅ Modified `SolrSchemaService::getObjectCollectionFieldStatus()` 
3. ✅ Modified `SolrSchemaService::getFileCollectionFieldStatus()`
4. ✅ Added `SolrSchemaService::getCurrentCollectionFields()` helper method

## Current Status
🔄 **IN PROGRESS** - Backend changes complete but encountering 500 errors during testing

## Next Steps
1. Debug the 500 error in `getCurrentCollectionFields()` method
2. Test endpoint returns data correctly
3. Update frontend UI to display collection column (if needed)

## Files Modified
- `lib/Controller/SettingsController.php`
- `lib/Service/SolrSchemaService.php`
- `docs/SOLR_FIELD_DIALOG_COLLECTIONS_FIX.md` (documentation)

## Known Issues
- HTTP 500 error when calling `/api/solr/fields`
- Needs debugging to identify root cause

---

**Note:** Pausing this fix to prioritize file text processing TODOs. Will return to debug this later.

**Date:** 2025-10-13

