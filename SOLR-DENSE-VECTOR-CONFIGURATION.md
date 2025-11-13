# Solr Dense Vector Search Configuration

## Overview
To use Solr as a vector search backend, the Solr collection schema must be configured with a **dense vector field type** that supports KNN (K-Nearest Neighbors) search. This guide explains how to configure your Solr collection schema for vector storage and search.

## Requirements

### Solr Version
- **Minimum**: Solr 9.0+  
- **Recommended**: Solr 9.4+ (improved vector search performance)
- Dense vector search (`DenseVectorField`) was introduced in Solr 9.0

### Check Your Solr Version
```bash
# Find your Solr container
docker ps | findstr solr

# Check version (replace container name)
docker exec <solr-container-name> bin/solr version
```

## Solr Schema Configuration

### 1. Add Dense Vector Field Type

Add this field type definition to your `managed-schema.xml` or `schema.xml`:

```xml
<!-- Dense Vector Field Type for Embeddings -->
<fieldType name="knn_vector" class="solr.DenseVectorField" vectorDimension="1536" similarityFunction="cosine"/>
```

**Parameters**:
- `vectorDimension`: Must match your embedding model dimensions
  - OpenAI `text-embedding-ada-002`: 1536
  - OpenAI `text-embedding-3-small`: 1536  
  - OpenAI `text-embedding-3-large`: 3072
  - Ollama default (nomic-embed-text): 768
  - **Important**: Set this to match your embedding model!

- `similarityFunction`: Distance metric for similarity calculation
  - `cosine` - Cosine similarity (recommended for text embeddings)
  - `dot_product` - Dot product similarity
  - `euclidean` - Euclidean distance

### 2. Add Vector Field to Schema

Add the actual field that will store vectors:

```xml
<!-- Vector Embedding Field -->
<field name="embedding_vector" type="knn_vector" indexed="true" stored="true"/>
```

**Note**: The field name `embedding_vector` matches the default in our implementation, but you can configure this via the LLM Configuration modal.

### 3. Add Supporting Fields

Our implementation also requires these supporting fields:

```xml
<!-- Entity identification -->
<field name="entity_type_s" type="string" indexed="true" stored="true"/>
<field name="entity_id_s" type="string" indexed="true" stored="true"/>

<!-- Chunk information -->
<field name="chunk_index_i" type="pint" indexed="true" stored="true"/>
<field name="total_chunks_i" type="pint" indexed="true" stored="true"/>
<field name="chunk_text_txt" type="text_general" indexed="true" stored="true"/>

<!-- Model information -->
<field name="embedding_model_s" type="string" indexed="true" stored="true"/>
<field name="embedding_dimensions_i" type="pint" indexed="true" stored="true"/>

<!-- Timestamps -->
<field name="created_at_dt" type="pdate" indexed="true" stored="true"/>
<field name="updated_at_dt" type="pdate" indexed="true" stored="true"/>

<!-- Dynamic field for metadata -->
<dynamicField name="metadata_*_s" type="string" indexed="true" stored="true"/>
```

## Complete Schema Example

Here's a complete example of a `managed-schema.xml` configured for vector search:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<schema name="openregister-vectors" version="1.6">
  
  <!-- Field Types -->
  <fieldType name="string" class="solr.StrField" sortMissingLast="true" docValues="true"/>
  <fieldType name="pint" class="solr.IntPointField" docValues="true"/>
  <fieldType name="pdate" class="solr.DatePointField" docValues="true"/>
  <fieldType name="text_general" class="solr.TextField" positionIncrementGap="100">
    <analyzer type="index">
      <tokenizer class="solr.StandardTokenizerFactory"/>
      <filter class="solr.LowerCaseFilterFactory"/>
    </analyzer>
    <analyzer type="query">
      <tokenizer class="solr.StandardTokenizerFactory"/>
      <filter class="solr.LowerCaseFilterFactory"/>
    </analyzer>
  </fieldType>
  
  <!-- Dense Vector Field Type -->
  <fieldType name="knn_vector" class="solr.DenseVectorField" 
             vectorDimension="1536" 
             similarityFunction="cosine"/>
  
  <!-- Required Fields -->
  <field name="id" type="string" indexed="true" stored="true" required="true" multiValued="false"/>
  <field name="_version_" type="plong" indexed="false" stored="false"/>
  
  <!-- Vector Field -->
  <field name="embedding_vector" type="knn_vector" indexed="true" stored="true"/>
  
  <!-- Entity Fields -->
  <field name="entity_type_s" type="string" indexed="true" stored="true"/>
  <field name="entity_id_s" type="string" indexed="true" stored="true"/>
  
  <!-- Chunk Fields -->
  <field name="chunk_index_i" type="pint" indexed="true" stored="true"/>
  <field name="total_chunks_i" type="pint" indexed="true" stored="true"/>
  <field name="chunk_text_txt" type="text_general" indexed="true" stored="true"/>
  
  <!-- Model Fields -->
  <field name="embedding_model_s" type="string" indexed="true" stored="true"/>
  <field name="embedding_dimensions_i" type="pint" indexed="true" stored="true"/>
  
  <!-- Timestamp Fields -->
  <field name="created_at_dt" type="pdate" indexed="true" stored="true"/>
  <field name="updated_at_dt" type="pdate" indexed="true" stored="true"/>
  
  <!-- Dynamic Fields -->
  <dynamicField name="metadata_*_s" type="string" indexed="true" stored="true"/>
  <dynamicField name="*_s" type="string" indexed="true" stored="true"/>
  <dynamicField name="*_i" type="pint" indexed="true" stored="true"/>
  <dynamicField name="*_txt" type="text_general" indexed="true" stored="true"/>
  
  <!-- Unique Key -->
  <uniqueKey>id</uniqueKey>
</schema>
```

## How to Apply Schema Changes

### Method 1: Schema API (Recommended for managed-schema)

```bash
# 1. Add the dense vector field type
curl -X POST -H 'Content-type:application/json' \\
  http://localhost:8983/solr/YOUR_COLLECTION/schema \\
  -d '{
    "add-field-type": {
      "name": "knn_vector",
      "class": "solr.DenseVectorField",
      "vectorDimension": 1536,
      "similarityFunction": "cosine"
    }
  }'

# 2. Add the vector field
curl -X POST -H 'Content-type:application/json' \\
  http://localhost:8983/solr/YOUR_COLLECTION/schema \\
  -d '{
    "add-field": {
      "name": "embedding_vector",
      "type": "knn_vector",
      "indexed": true,
      "stored": true
    }
  }'

# 3. Add supporting fields
curl -X POST -H 'Content-type:application/json' \\
  http://localhost:8983/solr/YOUR_COLLECTION/schema \\
  -d '{
    "add-field": [
      {"name": "entity_type_s", "type": "string", "indexed": true, "stored": true},
      {"name": "entity_id_s", "type": "string", "indexed": true, "stored": true},
      {"name": "chunk_index_i", "type": "pint", "indexed": true, "stored": true},
      {"name": "total_chunks_i", "type": "pint", "indexed": true, "stored": true},
      {"name": "chunk_text_txt", "type": "text_general", "indexed": true, "stored": true},
      {"name": "embedding_model_s", "type": "string", "indexed": true, "stored": true},
      {"name": "embedding_dimensions_i", "type": "pint", "indexed": true, "stored": true},
      {"name": "created_at_dt", "type": "pdate", "indexed": true, "stored": true},
      {"name": "updated_at_dt", "type": "pdate", "indexed": true, "stored": true}
    ]
  }'
```

### Method 2: Manual Configuration (for schema.xml)

1. Stop Solr
2. Edit `schema.xml` in your collection's `conf/` directory
3. Add the field type and fields shown above
4. Restart Solr
5. Reload the collection:
   ```bash
   curl "http://localhost:8983/solr/admin/collections?action=RELOAD&name=YOUR_COLLECTION"
   ```

### Method 3: Automatic Configuration via OCC Command ✅ **NEW**

OpenRegister now automatically configures the Solr schema when you run:

```bash
# From your Nextcloud container
docker exec -u 33 master-nextcloud-1 php occ openregister:solr:manage setup
```

**What this does:**
- Adds `knn_vector` field type with correct dimensions (4096 for mistral:7b by default)
- Configures `_embedding_` field in both object and file collections
- Adds supporting metadata fields (`_embedding_model_`, `_embedding_dim_`)
- Uses cosine similarity function (best for text embeddings)
- Automatically runs when Solr schema mirroring is triggered

**Collections Configured:**
- Object collection (from `solr.objectCollection` setting)
- File collection (from `solr.fileCollection` setting)

**When It Runs:**
- During initial Solr setup (`openregister:solr:manage setup`)
- When mirroring schemas to Solr
- When saving LLM settings with Solr as vector backend

**Configuration:**
- Dimensions: 4096 (for mistral:7b, adjustable in `VectorEmbeddingService`)
- Similarity: cosine (best for text embeddings)
- Field name: `_embedding_` (reserved field, not user-configurable)

## Verify Schema Configuration

Check if the vector field is properly configured:

```bash
curl "http://localhost:8983/solr/YOUR_COLLECTION/schema/fields/embedding_vector"
```

Expected response:
```json
{
  "field": {
    "name": "embedding_vector",
    "type": "knn_vector",
    "indexed": true,
    "stored": true
  }
}
```

## Testing Vector Search

Once configured, you can test KNN search:

```bash
curl -X POST "http://localhost:8983/solr/YOUR_COLLECTION/select" \\
  -H 'Content-Type: application/json' \\
  -d '{
    "query": "{!knn f=embedding_vector topK=10}[0.1, 0.2, 0.3, ...]",
    "limit": 10,
    "fields": ["id", "entity_type_s", "entity_id_s", "score"]
  }'
```

Replace `[0.1, 0.2, 0.3, ...]` with your actual query embedding vector.

## Common Issues

### Issue: "undefined field embedding_vector"
**Solution**: The vector field hasn't been added to the schema. Apply Method 1 or 2 above.

### Issue: "Cannot create field 'embedding_vector' of type 'knn_vector'"
**Solution**: The field type hasn't been defined. Add the `knn_vector` field type first.

### Issue: "Vector dimension mismatch"
**Solution**: Ensure `vectorDimension` in schema matches your embedding model's output dimension.

### Issue: Solr version < 9.0
**Solution**: Upgrade to Solr 9.0+ or use PHP/Database backend instead.

## Performance Considerations

### HNSW Algorithm
Dense vector fields in Solr 9+ use HNSW (Hierarchical Navigable Small World) algorithm for efficient nearest neighbor search.

### Indexing Performance
- Vector indexing is slower than traditional fields
- Consider batch indexing for large datasets
- Monitor Solr heap usage (vectors consume significant memory)

### Query Performance
- KNN queries are very fast (millisecond range)
- 100-1000x faster than PHP cosine similarity
- Performance scales well with collection size

### Memory Requirements
Calculate memory needs:
```
Memory per document ≈ (vectorDimension × 4 bytes) + overhead
Example: 1536 dimensions × 4 bytes = 6.14 KB per vector
100,000 vectors ≈ 614 MB memory
```

## Integration with OpenRegister

### 1. Configure LLM Settings

1. Go to **Settings** → **OpenRegister** → **LLM Configuration**
2. Click **Configure LLM**
3. Under **Vector Search Backend**, select **"Solr 9+ Dense Vector"**
4. Select your configured collection from the dropdown
5. Set **Vector Field Name** to `embedding_vector` (or your custom name)
6. Click **Save Configuration**

### 2. Test Integration

The system will automatically:
- Store new embeddings in Solr using the dense vector field
- Use KNN queries for semantic search
- Route all vector operations to Solr

Monitor logs for confirmation:
```bash
docker logs -f <nextcloud-container> | grep VectorEmbeddingService
```

You should see:
```
[VectorEmbeddingService] Routing vector storage: backend=solr
[VectorEmbeddingService] Vector stored successfully in Solr
[VectorEmbeddingService] Searching vectors in Solr using KNN
```

## Next Steps

1. **Configure Schema**: Apply the schema changes to your selected Solr collection
2. **Test Storage**: Create a test object with text to generate and store embeddings
3. **Test Search**: Perform a semantic search to verify KNN queries work
4. **Monitor Performance**: Compare search times vs PHP backend (should be 100-1000x faster)
5. **Migrate Existing Vectors**: If you have vectors in the database, consider migrating them to Solr

## Migration from Database to Solr

If you already have vectors stored in the database, you can migrate them:

1. Configure Solr schema as described above
2. Change backend to "Solr" in LLM Configuration
3. Re-run vectorization for your objects/files
   - The system will generate new embeddings and store them in Solr
4. Old database vectors will remain but won't be used for search

---

**Last Updated**: 2025-11-12  
**Compatible with**: Solr 9.0+  
**OpenRegister Version**: 0.2.7+  

