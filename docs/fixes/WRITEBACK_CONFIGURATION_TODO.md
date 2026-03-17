# WriteBack Configuration Refactoring TODO

## Overview

The current implementation stores `writeBack`, `removeAfterWriteBack`, and `inversedBy` properties in the `items` property of array-type schema properties. However, these configuration values should be moved to a dedicated `configuration` property for better organization and consistency.

## Current Issue

Looking at the `EditSchema.vue` component, it's clear that these configuration options are not available in the UI for array properties, and the current schema structure is inconsistent.

## Files Modified with TODO Comments

### 1. `lib/Service/ObjectService.php`
- **Line 823**: Added TODO comment for pre-validation cascading
- **Line 2438**: Added TODO comment in `handlePreValidationCascading` method docblock
- **Line 2469**: Added TODO comment for inversedBy properties filtering

### 2. `lib/Service/ObjectHandlers/SaveObject.php`
- **Line 415**: Added TODO comment in `cascadeObjects` method docblock
- **Line 433**: Added TODO comment for cascade objects logic
- **Line 450**: Added TODO comment for array properties logic
- **Line 708**: Added TODO comment in `handleInverseRelationsWriteBack` method docblock
- **Line 718**: Added TODO comment for writeBack properties filtering

### 3. `lib/Service/ObjectHandlers/RenderObject.php`
- **Line 777**: Added TODO comment in `getInversedProperties` method docblock
- **Line 785**: Added TODO comment for inversedBy properties filtering

### 4. `lib/Service/ObjectHandlers/ValidateObject.php`
- **Line 268**: Added TODO comment in `transformPropertyForOpenRegister` method docblock
- **Line 269**: Added TODO comment for inversedBy relationships handling
- **Line 342**: Added TODO comment for array items inversedBy handling

### 5. `lib/Db/Schema.php`
- **Line 340**: Added TODO comment for inversedBy properties normalization
- **Line 465**: Added TODO comment in `normalizeInversedByProperties` method docblock
- **Line 473**: Added TODO comment for regular object properties handling
- **Line 482**: Added TODO comment for array items handling

## Properties to Move

The following properties should be moved from `items` to `configuration`:

1. **`writeBack`** - Controls whether the property should be processed by write-back logic
2. **`removeAfterWriteBack`** - Controls whether the property should be removed from source after write-back
3. **`inversedBy`** - Specifies the property name on the target object for inverse relationships

## Current Structure (to be changed)
```json
{
  "properties": {
    "deelnemers": {
      "type": "array",
      "items": {
        "writeBack": true,
        "removeAfterWriteBack": true,
        "inversedBy": "deelnames"
      }
    }
  }
}
```

## Target Structure (after refactoring)
```json
{
  "properties": {
    "deelnemers": {
      "type": "array",
      "configuration": {
        "writeBack": true,
        "removeAfterWriteBack": true,
        "inversedBy": "deelnames"
      },
      "items": {
        "type": "object",
        "$ref": "#/components/schemas/organisatie"
      }
    }
  }
}
```

## Impact

This refactoring will:
1. Make the schema structure more consistent
2. Enable proper UI configuration in `EditSchema.vue`
3. Separate configuration from data structure
4. Improve code maintainability

## Next Steps

1. Update the frontend `EditSchema.vue` component to support configuration properties
2. Update all backend handlers to read from `configuration` instead of `items`
3. Create migration script for existing schemas
4. Update documentation
5. Add comprehensive tests for the new structure 