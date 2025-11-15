# Vector Field Name Hardcoded

**Date**: November 13, 2025  
**Status**: ✅ Complete

## Overview

Removed the user-configurable vector field name input from the LLM Configuration modal. The field name is now hardcoded to `_embedding_`, which is a **reserved field** in the Solr schema and should not be changed by users.

## Rationale

1. **Schema Consistency**: `_embedding_` is defined as a reserved field in `SolrSchemaService::RESERVED_FIELDS`
2. **System Configuration**: This is a system-level setting that must match the Solr schema definition
3. **User Safety**: Users changing this value could break vector search if the field doesn't exist or isn't configured correctly
4. **Standardization**: All vectors across the system use the same field name for consistency

## Changes

### Frontend: `src/modals/settings/LLMConfigModal.vue`

**Removed:**
- Vector field name text input
- `solrVectorField` data property
- Logic to load/save custom field name

**Added:**
- Informational text showing the hardcoded field name: "Vector field: _embedding_"

**Updated:**
- `saveConfiguration()` now always sends `solrField: '_embedding_'` with a comment

### Backend: No Changes Needed

The backend already defaults to `_embedding_` if no value is provided, so no backend changes were necessary:

```php
// lib/Service/SettingsService.php
'solrField' => $llmData['vectorConfig']['solrField'] ?? '_embedding_',

// lib/Service/VectorEmbeddingService.php
return $settings['llm']['vectorConfig']['solrField'] ?? '_embedding_';
```

## UI Changes

### Before
```
┌─────────────────────────────────────────────┐
│ Solr Configuration                          │
├─────────────────────────────────────────────┤
│ Vectors will be stored in your existing    │
│ object and file collections                 │
│ Files → fileCollection, Objects →           │
│ objectCollection                            │
│                                             │
│ Vector Field Name                           │
│ ┌─────────────────────────────────────────┐ │
│ │ _embedding_                             │ │
│ └─────────────────────────────────────────┘ │
│ Field name in Solr schema for storing      │
│ dense vectors (default: _embedding_)       │
└─────────────────────────────────────────────┘
```

### After
```
┌─────────────────────────────────────────────┐
│ Solr Configuration                          │
├─────────────────────────────────────────────┤
│ Vectors will be stored in your existing    │
│ object and file collections                 │
│ Files → fileCollection, Objects →           │
│ objectCollection                            │
│ Vector field: _embedding_                   │
└─────────────────────────────────────────────┘
```

## Reserved Fields in Solr Schema

From `SolrSchemaService.php`:

```php
private const RESERVED_FIELDS = [
    'id', 'uuid', 'self_tenant', '_text_', '_version_', '_root_', '_nest_path_',
    '_embedding_',          // Dense vector field (KNN searchable)
    '_embedding_model_',    // Model name used for embedding
    '_embedding_dim_',      // Embedding dimensions
    '_confidence_',         // Confidence score
    '_classification_'      // Classification result
];
```

These fields are system-level and should not be prefixed with the app prefix (`or_`).

## Configuration Storage

The configuration is still stored in the database with the `solrField` property, but it's always set to `_embedding_`:

```json
{
  "llm": {
    "vectorConfig": {
      "backend": "solr",
      "solrField": "_embedding_"
    }
  }
}
```

This provides consistency and allows for future system-wide changes if needed, while preventing users from breaking the integration.

## Benefits

1. **Prevents User Errors**: Users can't accidentally break vector search by entering an invalid field name
2. **Consistency**: All vectors use the same field across the entire system
3. **Simplicity**: One less configuration option for users to understand
4. **Schema Alignment**: Ensures field name always matches the schema definition

## Migration

No migration needed:
- Existing configurations with custom `solrField` values will continue to work
- New/updated configurations will use `_embedding_`
- The field name is a soft default - the backend will still accept other values if needed for special cases

## Testing

After rebuilding the frontend:

1. ✅ Open LLM Configuration modal
2. ✅ Select "Solr 9+ Dense Vector" as backend
3. ✅ Verify no input field for vector field name
4. ✅ Verify info box shows "Vector field: _embedding_"
5. ✅ Save configuration
6. ✅ Reopen modal - verify Solr backend still selected
7. ✅ Check API response: `vectorConfig.solrField` should be `"_embedding_"`

## Related Files

- `openregister/lib/Service/SolrSchemaService.php` - Defines reserved fields
- `openregister/lib/Service/VectorEmbeddingService.php` - Uses the field for storage/search
- `openregister/src/modals/settings/LLMConfigModal.vue` - UI changes
- `openregister/VECTOR-SOLR-INTEGRATION.md` - Updated documentation

## Last Updated

November 13, 2025

