# Solr Vector Statistics

**Date**: November 13, 2025  
**Status**: ✅ Complete

## Overview

Updated vector statistics to read from Solr collections when Solr is the active vector backend. Previously, statistics always queried the database table `oc_openregister_vectors`, even when vectors were being stored in Solr.

## Problem

User feedback: "We need the cards to display the solr numbers"

**Before:**
- Vectorization could use Solr backend
- Statistics **always** queried database table
- Cards showed database counts (563 vectors) even when Solr was active
- Misleading: showed old database vectors, not current Solr vectors

## Solution

Updated `VectorEmbeddingService->getVectorStats()` to:
1. Check the active vector backend (`vectorConfig.backend`)
2. Route to Solr or database accordingly
3. Return same data structure for consistency

## Implementation

### Modified: `lib/Service/VectorEmbeddingService.php`

#### 1. Updated `getVectorStats()` Method

```php
public function getVectorStats(): array
{
    // Check if we should use Solr for stats
    $backend = $this->getVectorSearchBackend();
    
    if ($backend === 'solr') {
        return $this->getVectorStatsFromSolr();
    }
    
    // Default: get stats from database
    // ... existing database query code ...
}
```

#### 2. Added `getVectorStatsFromSolr()` Method

Queries both file and object collections for vectors:

```php
private function getVectorStatsFromSolr(): array
{
    // Get collection names from settings
    $objectCollection = $settings['solr']['objectCollection'];
    $fileCollection = $settings['solr']['fileCollection'];
    
    // Count vectors in each collection
    $objectStats = $this->countVectorsInCollection($objectCollection, '_embedding_');
    $fileStats = $this->countVectorsInCollection($fileCollection, '_embedding_');
    
    return [
        'total_vectors' => $objectCount + $fileCount,
        'object_vectors' => $objectCount,
        'file_vectors' => $fileCount,
        'by_model' => [...], // Merged from both collections
        'source' => 'solr'
    ];
}
```

#### 3. Added `countVectorsInCollection()` Helper

Uses Solr queries to count documents:

```php
private function countVectorsInCollection(string $collection, string $vectorField): array
{
    // Query: _embedding_:* (documents with embedding field)
    $response = $solrService->get("{$collection}/select", [
        'q' => '_embedding_:*',
        'rows' => 0,  // Just count, don't return docs
        'facet' => 'true',
        'facet.field' => '_embedding_model_'  // Count by model
    ]);
    
    return [
        'count' => $response['response']['numFound'],
        'by_model' => [...]  // Extracted from facets
    ];
}
```

## How It Works

### Solr Query

When backend is Solr, the service queries:

**Object Collection:**
```http
GET /solr/objectCollection/select?q=_embedding_:*&rows=0&facet=true&facet.field=_embedding_model_
```

**File Collection:**
```http
GET /solr/fileCollection/select?q=_embedding_:*&rows=0&facet=true&facet.field=_embedding_model_
```

**Query Breakdown:**
- `q=_embedding_:*` - Find all documents with the `_embedding_` field
- `rows=0` - Don't return documents, just count
- `facet=true` - Enable faceting
- `facet.field=_embedding_model_` - Count by embedding model

### Response Format

Same format regardless of backend:

```json
{
  "total_vectors": 563,
  "object_vectors": 4,
  "file_vectors": 559,
  "by_type": {
    "object": 4,
    "file": 559
  },
  "by_model": {
    "mistral:7b": 563
  },
  "source": "solr"
}
```

The `source` field indicates where the data came from (`"solr"` or `"database"`).

## UI Cards

The statistics cards now correctly display:

```
┌─────────────────────────────────────────────┐
│ TOTAL VECTORS           FILE EMBEDDINGS     │
│                                             │
│       0                        0            │
└─────────────────────────────────────────────┘
│ OBJECT EMBEDDINGS                           │
│                                             │
│       0                                     │
└─────────────────────────────────────────────┘
```

**Initially shows 0** because no vectors are in Solr yet.

After vectorization:
```
┌─────────────────────────────────────────────┐
│ TOTAL VECTORS           FILE EMBEDDINGS     │
│                                             │
│      563                      559           │
└─────────────────────────────────────────────┘
│ OBJECT EMBEDDINGS                           │
│                                             │
│       4                                     │
└─────────────────────────────────────────────┘
```

## Complete Flow

### 1. Initial State (Solr Backend Active, No Vectors)
- User sees: **0 Total Vectors, 0 Object, 0 File**
- Old database vectors: 563 (ignored)
- Solr documents: No `_embedding_` fields yet

### 2. User Clicks "Vectorize All Objects"
- `VectorEmbeddingService->storeVector()` called for each object
- Routes to `storeVectorInSolr()` (checks `backend === 'solr'`)
- Performs atomic update on existing Solr documents
- Adds `_embedding_`, `_embedding_model_`, `_embedding_dim_` fields

### 3. Page Refreshes, Statistics Updated
- `getVectorStats()` called
- Routes to `getVectorStatsFromSolr()` (checks `backend === 'solr'`)
- Queries Solr collections for documents with `_embedding_` field
- Returns counts

### 4. Cards Display Solr Numbers
- **Total Vectors**: 4 (from Solr)
- **Object Embeddings**: 4 (from objectCollection in Solr)
- **File Embeddings**: 0 (from fileCollection in Solr)

### 5. User Clicks "Vectorize All Files"
- Same process for files
- Updates file chunk documents in fileCollection
- Statistics refresh shows: 563 total (4 objects + 559 files)

## Testing

### 1. Verify Initial State
```bash
# Check Solr backend is active
curl http://nextcloud.local/index.php/apps/openregister/api/settings/llm

# Should show:
# "vectorConfig": { "backend": "solr", "solrField": "_embedding_" }

# Check stats show 0
curl http://nextcloud.local/index.php/apps/openregister/api/solr/vector-stats

# Should show:
# "total_vectors": 0, "source": "solr"
```

### 2. Vectorize One Object
1. Open LLM Configuration page
2. Click Actions → "Vectorize All Objects"
3. Wait for completion
4. Refresh page
5. **Verify**: Object Embeddings card shows count > 0

### 3. Check Solr Document
```bash
# Query Solr for an object document
curl "http://solr:8983/solr/objectCollection/select?q=id:{objectUuid}&fl=*"

# Should include:
# "_embedding_": [0.123, 0.456, ...],
# "_embedding_model_": "mistral:7b",
# "_embedding_dim_": 4096
```

### 4. Vectorize Files
1. Click Actions → "Vectorize All Files"
2. Wait for completion
3. Refresh page
4. **Verify**: File Embeddings card shows count

### 5. Check Collection Counts Match
```bash
# Count in objectCollection
curl "http://solr:8983/solr/objectCollection/select?q=_embedding_:*&rows=0"
# Check response.numFound

# Count in fileCollection  
curl "http://solr:8983/solr/fileCollection/select?q=_embedding_:*&rows=0"
# Check response.numFound

# Should match UI cards
```

## Fallback Behavior

If Solr query fails:
- Returns zeros with `source: "solr_error"`
- Logs error for debugging
- UI shows 0 vectors (not cached database counts)

If Solr is unavailable:
- Returns zeros with `source: "solr_unavailable"`
- User knows to check Solr configuration

## Database Vectors

The old database vectors (563) remain in `oc_openregister_vectors`:
- **Not deleted** (kept as backup)
- **Not used** for statistics when Solr is active
- **Not used** for search when Solr is active
- Can be manually deleted later if desired

## Benefits

1. **Accurate Statistics**: Cards show actual Solr vector counts
2. **Clear Migration Path**: Start at 0, watch progress as vectorization runs
3. **Consistency**: Stats backend matches storage backend
4. **Performance**: Solr facet queries are fast (no full table scan)
5. **Model Breakdown**: Facets provide counts by embedding model

## API Endpoints

### Get Vector Stats
```http
GET /apps/openregister/api/solr/vector-stats
```

**Response when Solr active:**
```json
{
  "success": true,
  "stats": {
    "total_vectors": 0,
    "object_vectors": 0,
    "file_vectors": 0,
    "by_model": {},
    "source": "solr"
  }
}
```

### Get LLM Configuration
```http
GET /apps/openregister/api/settings/llm
```

**Response:**
```json
{
  "vectorConfig": {
    "backend": "solr",
    "solrField": "_embedding_"
  }
}
```

## Related Documentation

- `VECTOR-SOLR-INTEGRATION.md` - How vectors are stored in Solr
- `SOLR-DENSE-VECTOR-CONFIGURATION.md` - Solr schema setup
- `VECTOR-SEARCH-BACKENDS.md` - Backend options overview

## Last Updated

November 13, 2025

