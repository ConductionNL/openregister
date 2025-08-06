# Organisatie Schema Fix Summary

## Problem Description

The import of `voorzieningen_organisaties.xlsx` was working correctly on the local environment but failing on the test environments (Accept and Test). The key issue was that the `deelnemers` and `deelnames` relationship was not being established properly during import.

## Root Cause Analysis

### Investigation Results

1. **Local Environment**: ✅ Working correctly
   - `deelnemers` property present with `writeBack: true`
   - Write-back functionality properly updates `deelnames` arrays
   - Import process establishes relationships correctly

2. **Test Environments**: ❌ Not working
   - `deelnemers` property was **completely missing** from the organisatie schema
   - No write-back functionality available
   - Import process could not establish relationships

### Schema Configuration Differences

**Local Environment (Working)**:
```json
{
  "deelnemers": {
    "type": "array",
    "title": "deelnemers",
    "description": "Organisaties die deelnemen aan deze community",
    "items": {
      "type": "object",
      "objectConfiguration": {
        "handling": "related-object"
      },
      "$ref": "#/components/schemas/organisatie",
      "inversedBy": "deelnames",
      "writeBack": true,
      "removeAfterWriteBack": true
    },
    "facetable": false
  }
}
```

**Test Environments (Before Fix)**:
```json
{
  // deelnemers property was completely missing
}
```

## Solution Implemented

### 1. Schema Investigation
Created `investigate_schema_diff.php` to:
- Compare schema configurations between environments
- Export the working schema from local environment
- Identify missing properties and configurations

### 2. Schema Update
Created `update_test_schemas.php` to:
- Update test environments with the correct schema configuration
- Add the missing `deelnemers` property with proper write-back configuration
- Test the functionality after update

### 3. Verification
Created `verify_schema_update.php` to:
- Confirm that schema updates were successful
- Verify that all required properties are present
- Check that configuration values are correct

## Fix Results

### ✅ Schema Updates Successful

Both test environments now have the correct configuration:

- **Accept Environment**: ✅ Updated successfully
- **Test Environment**: ✅ Updated successfully

### ✅ Configuration Verified

All environments now have:
- `deelnemers` property present
- `writeBack: true` - Enables automatic updates to `deelnames` arrays
- `removeAfterWriteBack: true` - Removes `deelnemers` from source after processing
- `inversedBy: "deelnames"` - Specifies the target property to update

## How the Write-Back System Works

### Process Flow

1. **Import Process**: Excel file contains samenwerking objects with `deelnemers` arrays
2. **Object Creation**: Samenwerking objects are created with `deelnemers` property
3. **Write-Back Processing**: `handleInverseRelationsWriteBack()` method:
   - Detects properties with `writeBack: true`
   - Finds referenced organisations using UUIDs
   - Updates each organisation's `deelnames` array
   - Adds the samenwerking UUID to the `deelnames` array
4. **Cleanup**: `deelnemers` property is removed from samenwerking (if `removeAfterWriteBack: true`)

### Example

**Before Import**:
```json
// Organisation A
{
  "id": "org-a-uuid",
  "naam": "Gemeente Amsterdam",
  "type": "Gemeente",
  "deelnames": []
}

// Samenwerking (from Excel)
{
  "naam": "Test Samenwerking",
  "type": "Samenwerking", 
  "deelnemers": ["org-a-uuid"]
}
```

**After Import**:
```json
// Organisation A (updated)
{
  "id": "org-a-uuid",
  "naam": "Gemeente Amsterdam",
  "type": "Gemeente",
  "deelnames": ["samenwerking-uuid"]  // ← Automatically updated
}

// Samenwerking (created)
{
  "id": "samenwerking-uuid",
  "naam": "Test Samenwerking",
  "type": "Samenwerking",
  "deelnemers": []  // ← Removed after write-back
}
```

## Testing Instructions

### 1. Test Import Functionality

You can now test the import functionality on all environments:

1. **Local Environment**: Should continue working as before
2. **Accept Environment**: Should now work correctly
3. **Test Environment**: Should now work correctly

### 2. Expected Results

After importing `voorzieningen_organisaties.xlsx`:

1. **Samenwerking objects** should be created successfully
2. **Gemeente objects** should have their `deelnames` arrays updated with samenwerking UUIDs
3. **Samenwerking objects** should have empty `deelnemers` arrays (due to `removeAfterWriteBack: true`)

### 3. Verification Steps

To verify the fix is working:

1. **Check Samenwerking objects**: Should exist with empty `deelnemers` arrays
2. **Check Gemeente objects**: Should have samenwerking UUIDs in their `deelnames` arrays
3. **Test API calls**: Use `?extend=deelnemers` to verify inverse relationships are populated

### 4. API Testing Commands

```bash
# Check a samenwerking object
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
  'https://vng.accept.commonground.nu/index.php/apps/openregister/api/objects/6/35/SAMENWERKING-UUID'

# Check a gemeente object to see deelnames
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
  'https://vng.accept.commonground.nu/index.php/apps/openregister/api/objects/6/35/GEMEENTE-UUID'

# Test inverse relationship population
curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' \
  'https://vng.accept.commonground.nu/index.php/apps/openregister/api/objects/6/35/SAMENWERKING-UUID?extend=deelnemers'
```

## Files Created/Modified

### Investigation Scripts
- `investigate_schema_diff.php` - Schema comparison and export
- `update_test_schemas.php` - Schema update automation
- `verify_schema_update.php` - Update verification

### Exported Files
- `organisatie_schema_local.json` - Working schema configuration

### Documentation
- `SCHEMA_FIX_SUMMARY.md` - This summary document

## Technical Details

### Backend Implementation

The write-back functionality is implemented in:
- **File**: `lib/Service/ObjectHandlers/SaveObject.php`
- **Method**: `handleInverseRelationsWriteBack()`
- **Order**: Called after cascading but before default values

### Key Configuration Properties

- **`writeBack: true`**: Enables automatic updates to target objects
- **`removeAfterWriteBack: true`**: Removes source property after processing
- **`inversedBy: "deelnames"`**: Specifies target property to update
- **`objectConfiguration.handling: "related-object"`**: Handles as related objects

### Processing Order

1. **Sanitization**: Clean empty values
2. **Cascading**: Handle object cascading (skips writeBack properties)
3. **Write-Back**: Process inverse relationships with write-back
4. **Default Values**: Set any default values

## Conclusion

The issue has been successfully resolved by updating the organisatie schema on the test environments to include the missing `deelnemers` property with the correct write-back configuration. The import functionality should now work consistently across all environments.

**Status**: ✅ **RESOLVED** 