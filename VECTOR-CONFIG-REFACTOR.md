# Vector Config Refactoring

**Date**: November 13, 2025  
**Status**: ✅ Complete

## Overview

Refactored the vector search configuration from root-level properties to a nested `vectorConfig` object in the LLM settings, following the same pattern as `openaiConfig`, `ollamaConfig`, and `fireworksConfig`.

## Changes

### Before (Root-level properties)
```json
{
  "enabled": true,
  "embeddingProvider": "ollama",
  "chatProvider": "ollama",
  "vectorSearchBackend": "solr",
  "solrVectorCollection": "swc_vectors",
  "solrVectorField": "embedding_vector",
  "openaiConfig": { ... },
  "ollamaConfig": { ... },
  "fireworksConfig": { ... }
}
```

### After (Nested vectorConfig)
```json
{
  "enabled": true,
  "embeddingProvider": "ollama",
  "chatProvider": "ollama",
  "vectorConfig": {
    "backend": "solr",
    "solrCollection": "swc_vectors",
    "solrField": "embedding_vector"
  },
  "openaiConfig": { ... },
  "ollamaConfig": { ... },
  "fireworksConfig": { ... }
}
```

## Modified Files

### Backend

#### 1. `lib/Service/SettingsService.php`

**`getLLMSettingsOnly()` method:**
- Updated default configuration to include `vectorConfig` object
- Added backward compatibility checks for `vectorConfig` and its nested fields
- Ensures all vector config fields have default values

**`updateLLMSettingsOnly()` method:**
- Updated to save `vectorConfig` object instead of root-level properties
- Maintains PATCH behavior with existing config fallbacks

#### 2. `lib/Service/VectorEmbeddingService.php`

**Updated three private methods:**
- `getVectorSearchBackend()`: Now reads from `settings['llm']['vectorConfig']['backend']`
- `getSolrVectorCollection()`: Now reads from `settings['llm']['vectorConfig']['solrCollection']`
- `getSolrVectorField()`: Now reads from `settings['llm']['vectorConfig']['solrField']`

### Frontend

#### 3. `src/modals/settings/LLMConfigModal.vue`

**`saveConfiguration()` method:**
- Changed from sending root-level properties to sending nested `vectorConfig` object:
  ```javascript
  vectorConfig: {
    backend: this.selectedVectorBackend?.id || 'php',
    solrCollection: this.solrVectorCollection?.rawName || ...,
    solrField: this.solrVectorField || 'embedding_vector',
  }
  ```

**`loadAvailableBackends()` method:**
- Updated to read vector backend from `llmResponse.data.vectorConfig?.backend`
- Updated Solr collection reading from `llmResponse.data.vectorConfig?.solrCollection`
- Updated Solr field reading from `llmResponse.data.vectorConfig?.solrField`

#### 4. `src/views/settings/sections/LlmConfiguration.vue`

**`loadDatabaseInfo()` method:**
- Updated to read vector backend from `llmResponse.data.vectorConfig?.backend`
- Correctly displays Solr status in the Vector Storage tile

## Benefits

1. **Better Organization**: Groups all vector-related configuration in one object
2. **Consistency**: Follows the same pattern as provider configs (openaiConfig, ollamaConfig, fireworksConfig)
3. **Maintainability**: Easier to understand and extend vector configuration
4. **Backward Compatibility**: Includes fallbacks for missing config fields

## Configuration Structure

### Vector Config Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `backend` | string | `'php'` | Vector search backend: 'php', 'database', or 'solr' |
| `solrCollection` | string\|null | `null` | Solr collection name for vector storage |
| `solrField` | string | `'embedding_vector'` | Solr field name for dense vectors |

## Testing

After applying these changes:

1. **Check API response:**
   ```bash
   curl http://nextcloud.local/index.php/apps/openregister/api/settings/llm
   ```
   Should return `vectorConfig` object with nested properties.

2. **Test LLM Configuration Modal:**
   - Open LLM Configuration modal
   - Select Solr as vector backend
   - Choose a collection
   - Save configuration
   - Reopen modal to verify settings persist

3. **Test Vector Storage Tile:**
   - Navigate to LLM Configuration settings page
   - Verify Vector Storage tile shows correct backend status
   - When Solr is active, should show: "Solr Dense Vector (active ✓)"

## Migration

No explicit migration needed. The backward compatibility checks in `getLLMSettingsOnly()` automatically handle existing configurations:
- Missing `vectorConfig` → Creates with default values
- Missing nested fields → Adds defaults for each field

## Related Documentation

- `VECTOR-SEARCH-BACKENDS.md` - Overview of vector search backend options
- `SOLR-DENSE-VECTOR-CONFIGURATION.md` - Solr configuration details
- `VECTOR-STORAGE-IMPLEMENTATION-COMPLETE.md` - Complete vector storage implementation

## Last Updated

November 13, 2025

