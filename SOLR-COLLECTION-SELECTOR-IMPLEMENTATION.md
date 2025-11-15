# Solr Collection Selector Implementation

## Overview
Implemented automatic fetching and display of Solr collections in the LLM Configuration modal's "Vector Search Backend" section, allowing users to select which Solr collection to use for vector storage and search.

## Problem
Previously, when "Solr 9+ Dense Vector" was selected as the vector search backend, the "Solr Collection" dropdown showed "No results" even though Solr was connected and had 16 active collections.

## Solution
Updated the backend to fetch collections from Solr using the existing `GuzzleSolrService->listCollections()` method and pass them to the frontend in the format expected by the dropdown component.

## Changes Made

### 1. Backend: SettingsController.php

Updated `getSolrInfo()` method to fetch and return collections:

```php
if ($solrAvailable) {
    // Get Solr system info
    $stats = $guzzleSolrService->getDashboardStats();
    
    // Try to detect version from Solr admin API
    $solrVersion = '9.x (detection pending)';
    $vectorSupport = false; // Set to false until we implement it
    
    // Get list of collections from Solr
    try {
        $collectionsList = $guzzleSolrService->listCollections();
        // Transform to format expected by frontend
        $collections = array_map(function($collection) {
            return [
                'id' => $collection['name'],
                'name' => $collection['name'],
                'documentCount' => $collection['documentCount'] ?? 0,
                'shards' => $collection['shards'] ?? 0,
                'health' => $collection['health'] ?? 'unknown',
            ];
        }, $collectionsList);
    } catch (\Exception $e) {
        $this->logger->warning('[SettingsController] Failed to list Solr collections', [
            'error' => $e->getMessage(),
        ]);
        $collections = [];
    }
}
```

**File**: `openregister/lib/Controller/SettingsController.php`

### 2. Frontend: LLMConfigModal.vue

#### Updated `loadAvailableBackends()` to populate collection options:

```javascript
// Solr backend (check if Solr is available)
let solrAvailable = false
let solrNote = 'Not connected'
try {
    const solrResponse = await axios.get(generateUrl('/apps/openregister/api/settings/solr-info'))
    if (solrResponse.data.success && solrResponse.data.solr) {
        const solr = solrResponse.data.solr
        solrAvailable = solr.available || false
        
        if (solrAvailable) {
            solrNote = 'Very fast distributed vector search using KNN/HNSW indexing'
            
            // Load collections from Solr
            if (solr.collections && solr.collections.length > 0) {
                this.solrCollectionOptions = solr.collections.map(col => ({
                    id: col.id,
                    name: col.name + ' (' + col.documentCount + ' docs)',
                    rawName: col.name,
                    documentCount: col.documentCount,
                    health: col.health,
                }))
            }
        } else {
            solrNote = solr.error || 'SOLR not connected. Enable in Search Configuration.'
        }
    }
} catch (error) {
    console.error('Failed to fetch Solr info:', error)
    solrNote = 'Failed to check Solr status'
}
```

#### Updated loading of saved collection setting:

```javascript
// Load Solr settings if Solr backend
if (vectorBackend === 'solr') {
    const savedCollection = llmResponse.data.solrVectorCollection
    // Find the collection object in solrCollectionOptions
    if (savedCollection && this.solrCollectionOptions.length > 0) {
        this.solrVectorCollection = this.solrCollectionOptions.find(
            c => c.id === savedCollection || c.rawName === savedCollection
        )
    }
    this.solrVectorField = llmResponse.data.solrVectorField || 'embedding_vector'
}
```

#### Updated save method to store collection name:

```javascript
vectorSearchBackend: this.selectedVectorBackend?.id || 'php',
solrVectorCollection: this.solrVectorCollection?.rawName || this.solrVectorCollection?.id || this.solrVectorCollection,
solrVectorField: this.solrVectorField || 'embedding_vector',
```

**File**: `openregister/src/modals/settings/LLMConfigModal.vue`

## API Response Example

`GET /api/settings/solr-info` now returns:

```json
{
  "success": true,
  "solr": {
    "available": true,
    "version": "9.x (detection pending)",
    "vectorSupport": false,
    "collections": [
      {
        "id": "nc_tst_local_objects",
        "name": "nc_tst_local_objects",
        "documentCount": 15612,
        "shards": 1,
        "health": "healthy"
      },
      {
        "id": "nc_test_local_files",
        "name": "nc_test_local_files",
        "documentCount": 0,
        "shards": 1,
        "health": "healthy"
      },
      ...
    ],
    "error": null
  }
}
```

## UI Display

Collections are shown in the dropdown with their document counts:

- `nc_tst_local_objects (15612 docs)`
- `swc_accept_1_coll_shard1_replica_n1_accept_1 (123580 docs)`
- `nc_test_local_files (0 docs)`
- etc.

This helps users identify which collections contain data and might be suitable for vector storage.

## How It Works

1. **User opens LLM Configuration modal**
2. **Frontend calls** `/api/settings/solr-info` endpoint
3. **Backend**:
   - Checks if `GuzzleSolrService->isAvailable()` returns true
   - Calls `GuzzleSolrService->listCollections()` to fetch all collections
   - Transforms collection data to frontend format
   - Returns collections with metadata (name, document count, health)
4. **Frontend**:
   - Populates `solrCollectionOptions` with collections
   - Displays them in the dropdown with document counts
   - Loads previously saved collection if applicable
   - Saves selected collection name when configuration is saved

## Testing

### Backend Test
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \\
  -H 'Content-Type: application/json' \\
  http://localhost/index.php/apps/openregister/api/settings/solr-info
```

**Expected Result**: JSON response with `collections` array containing all Solr collections.

### UI Test
1. Open Nextcloud OpenRegister settings
2. Go to LLM Configuration section
3. Click on the LLM Configuration modal
4. Scroll to "Vector Search Backend" section
5. Select "Solr 9+ Dense Vector" from the dropdown
6. Verify that:
   - ✅ The "Solr Collection" section appears
   - ✅ The dropdown shows all available collections with document counts
   - ✅ Collections can be selected
   - ✅ "Vector Field Name" input is visible and editable

## Next Steps

- [ ] Implement Solr vector field schema detection
- [ ] Add validation to ensure selected collection has vector field
- [ ] Implement actual vector search using Solr dense vector fields
- [ ] Add collection creation wizard if no suitable collection exists
- [ ] Update `VectorEmbeddingService` to route searches to Solr when selected
- [ ] Add performance metrics comparison between backends

## Related Files

- `openregister/lib/Controller/SettingsController.php` - Backend endpoint
- `openregister/lib/Service/GuzzleSolrService.php` - Collection listing
- `openregister/src/modals/settings/LLMConfigModal.vue` - Frontend UI
- `openregister/appinfo/routes.php` - API route

## Related Documentation

- `SOLR-VECTOR-BACKEND-DETECTION.md` - Solr backend detection implementation
- `VECTOR-SEARCH-BACKENDS.md` - Architecture overview of vector search backends
- `DATABASE-TILE-FEATURE.md` - Database status tile implementation

---

**Last Updated**: 2025-11-12
**Status**: ✅ Collections Loaded Successfully
**API Verified**: Returns 16 collections with metadata

