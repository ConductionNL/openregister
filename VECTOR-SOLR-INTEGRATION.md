# Vector Search with Existing Solr Collections

**Date**: November 13, 2025  
**Status**: ✅ Complete - Ready for Testing

## Overview

Refactored vector search to use **existing Solr collections** instead of a separate vector collection. Vectors are now stored directly in the file and object documents, enabling semantic search to return full document details without additional lookups.

##Architecture

### Before (Separate Vector Collection)
- Vectors stored in a dedicated "vector collection"
- Search returns vector IDs, requires additional queries to get object/file details
- Separate document management for vectors

### After (Integrated Vectors)
- **Files**: Vectors stored in `fileCollection` alongside file chunks
- **Objects**: Vectors stored in `objectCollection` alongside object data
- KNN search returns full documents with all metadata
- Single source of truth for each entity

## How It Works

### Vector Storage

When an entity (file or object) is vectorized:

1. **Generate Embedding**: Create vector representation of the text
2. **Determine Collection**: 
   - Files → `settings['solr']['fileCollection']`
   - Objects → `settings['solr']['objectCollection']`
3. **Atomic Update**: Add vector fields to existing Solr document:
   - `_embedding_`: The dense vector (array of floats)
   - `_embedding_model_`: Model name (e.g., "mistral:7b")
   - `_embedding_dim_`: Vector dimensions (e.g., 4096)
   - `self_updated`: Update timestamp

### Vector Search

When performing semantic search:

1. **Generate Query Embedding**: Convert search query to vector
2. **Determine Collections**: Based on filters or search both
3. **KNN Query**: Use Solr's `{!knn f=_embedding_ topK=N}` syntax
4. **Combine Results**: Merge results from all collections, sort by similarity
5. **Return Full Documents**: Each result includes all Solr fields

## Configuration Structure

### Vector Config
```json
{
  "vectorConfig": {
    "backend": "solr",
    "solrField": "_embedding_"
  }
}
```

No `solrCollection` field needed - uses existing `fileCollection` and `objectCollection` from Solr settings.

**Important**: The `solrField` value is hardcoded to `_embedding_` (a reserved field in the Solr schema) and is not user-configurable through the UI. This ensures consistency with the schema definition.

## Modified Files

### Backend

#### 1. `lib/Service/SettingsService.php`
- **Removed**: `solrCollection` from `vectorConfig`
- **Updated**: Default `solrField` to `_embedding_` (matches schema reserved field)
- **Added**: Cleanup logic to remove deprecated `solrCollection` field

####2. `lib/Service/VectorEmbeddingService.php`

**Removed Methods:**
- `getSolrVectorCollection()` ❌

**New Methods:**
- `getSolrCollectionForEntityType(string $entityType)` ✅
  - Returns `fileCollection` for files
  - Returns `objectCollection` for objects
- `extractEntityId(array $doc, string $entityType)` ✅
  - Extracts the appropriate ID field from Solr documents

**Updated Methods:**
- `storeVectorInSolr()`:
  - Uses `getSolrCollectionForEntityType()` to determine collection
  - Performs **atomic update** instead of creating new document
  - Updates existing document with vector fields
  - Document IDs match existing files/objects:
    - Files: `{fileId}_chunk_{chunkIndex}`
    - Objects: `{uuid}` or `{objectId}`

- `searchVectorsInSolr()`:
  - Searches multiple collections based on `entity_type` filter
  - If no filter: searches both file and object collections
  - Returns full Solr documents as metadata
  - Combines and sorts results by similarity across all collections

### Frontend

#### 3. `src/modals/settings/LLMConfigModal.vue`

**Removed:**
- Solr collection selector dropdown
- Vector field name input (hardcoded to `_embedding_`)
- `solrVectorCollection` data property
- `solrVectorField` data property
- `solrCollectionOptions` data property
- Collection loading logic

**Added:**
- Info box explaining vectors are stored in existing collections
- Clear labeling: "Files → fileCollection, Objects → objectCollection"
- Display of hardcoded vector field: "_embedding_"

**Updated:**
- Vector field always set to `_embedding_` (not user-configurable)
- Simplified Solr configuration section to show only informational content

## Benefits

### 1. **Simpler Architecture**
- No need to manage a separate vector collection
- Fewer collections to maintain and configure
- Single source of truth for each entity

### 2. **Better Performance**
- No additional lookups needed after KNN search
- Full document returned directly from vector search
- Can leverage existing Solr filters and facets

### 3. **Consistency**
- Vectors always in sync with their entities
- Deleting a file/object automatically removes its vector
- Updating an entity can update its vector atomically

### 4. **Rich Results**
- Search results include ALL document fields
- Can display file metadata, object properties, etc.
- Supports existing application logic for displaying files/objects

## Schema Requirements

The Solr schema must include these fields (already defined in `SolrSchemaService`):

```json
{
  "_embedding_": "knn_vector",          // Dense vector field
  "_embedding_model_": "string",        // Model name
  "_embedding_dim_": "pint"             // Vector dimensions
}
```

These are defined in `RESERVED_FIELDS` and should be added to both file and object collections.

## Document Structure Examples

### File Document with Vector
```json
{
  "id": "12345_chunk_0",
  "file_id": 12345,
  "file_path": "/path/to/file.pdf",
  "file_name": "document.pdf",
  "chunk_index": 0,
  "chunk_total": 5,
  "chunk_text": "This is the content...",
  "_embedding_": [0.123, 0.456, 0.789, ...],
  "_embedding_model_": "mistral:7b",
  "_embedding_dim_": 4096,
  "self_updated": "2025-11-13T10:30:00Z"
}
```

### Object Document with Vector
```json
{
  "id": "uuid-1234-5678",
  "self_uuid": "uuid-1234-5678",
  "self_name": "Customer Record",
  "self_schema_slug": "customer",
  "self_description": "Customer details...",
  "_embedding_": [0.321, 0.654, 0.987, ...],
  "_embedding_model_": "mistral:7b",
  "_embedding_dim_": 4096,
  "self_updated": "2025-11-13T10:30:00Z"
}
```

## Atomic Updates

Solr atomic updates use special syntax to modify existing documents:

```php
$updateDocument = [
    'id' => $documentId,
    '_embedding_' => ['set' => $embeddingVector],
    '_embedding_model_' => ['set' => $modelName],
    '_embedding_dim_' => ['set' => $dimensions],
    'self_updated' => ['set' => $timestamp]
];
```

This updates only the specified fields without affecting other document fields.

## Testing Checklist

### Setup
- ✅ File collection configured in Solr settings
- ✅ Object collection configured in Solr settings
- ✅ Embedding fields added to both collections
- ✅ Solr backend selected in LLM configuration

### Vectorization
- [ ] Vectorize a file - check Solr document has `_embedding_` field
- [ ] Vectorize an object - check Solr document has `_embedding_` field
- [ ] Verify existing file/object fields are preserved
- [ ] Check logs for "atomic_update" operation

### Search
- [ ] Semantic search returns files with full metadata
- [ ] Semantic search returns objects with full metadata
- [ ] Filter search to files only
- [ ] Filter search to objects only
- [ ] Verify search results include similarity scores

### Integration
- [ ] Existing file display logic works with search results
- [ ] Existing object display logic works with search results
- [ ] Can navigate to files/objects from search results

## Rollback

If issues occur, change vector backend to PHP:
1. Open LLM Configuration modal
2. Set Vector Search Backend to "PHP Cosine Similarity"
3. Save configuration

This will use the database table for vectors instead of Solr.

## Related Documentation

- `VECTOR-CONFIG-REFACTOR.md` - Vector config structure changes
- `SolrSchemaService.php` - Schema field definitions
- `SOLR-DENSE-VECTOR-CONFIGURATION.md` - Solr schema setup
- `VECTOR-SEARCH-BACKENDS.md` - Backend options overview

## Migration Notes

### Existing Deployments

If you already have vectors in a separate collection:
1. Old vectors will remain in the separate collection (no automatic migration)
2. New vectors will be added to file/object collections
3. Search will use the new collections only
4. Re-vectorize files/objects to populate new structure
5. Old vector collection can be deleted once re-vectorization is complete

### Configuration Update

The configuration will automatically migrate:
- `solrVectorCollection` field is removed on next read
- `solrField` defaults to `_embedding_` if missing
- No manual migration needed

## Performance Considerations

### Pros
- **Faster**: No additional lookups after vector search
- **Fewer Network Calls**: One query returns everything
- **Better Caching**: Solr can cache full documents

### Cons
- **Larger Documents**: Each document now includes a large vector array
- **Storage**: Vectors stored redundantly if documents are replicated

### Optimization Tips
1. Use `stored=false` for `_embedding_` field if you don't need to retrieve vectors
2. Configure appropriate memory for Solr to handle larger documents
3. Consider increasing `maxDoc` settings if you have many documents

## Last Updated

November 13, 2025

