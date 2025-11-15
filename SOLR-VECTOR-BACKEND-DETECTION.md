# Solr Vector Backend Detection Implementation

## Overview
Added automatic detection of Solr availability to enable the "Solr 9+ Dense Vector" option in the LLM Configuration modal when Solr is connected and enabled.

## Problem
Previously, the "Vector Search Backend" dropdown in the LLM Configuration modal only showed "PHP Cosine Similarity" as available, even though Solr was connected and actively being used for search operations.

The Solr backend option was hardcoded as `available: false` with a note "Not yet implemented. Coming soon!".

## Solution
Implemented Solr availability detection by:

1. **Backend Endpoint** - Added `getSolrInfo()` method to `SettingsController`
2. **API Route** - Added `/api/settings/solr-info` endpoint
3. **Frontend Detection** - Updated `LLMConfigModal.vue` to fetch and use Solr availability status

## Changes Made

### 1. Backend: SettingsController.php

Added new method `getSolrInfo()`:

```php
/**
 * Get Solr information and vector search capabilities
 *
 * Returns information about Solr availability, version, and vector search support.
 *
 * @NoAdminRequired
 * @NoCSRFRequired
 *
 * @return JSONResponse Solr information
 */
public function getSolrInfo(): JSONResponse
{
    try {
        $solrAvailable = false;
        $solrVersion = 'Unknown';
        $vectorSupport = false;
        $collections = [];
        $errorMessage = null;

        // Check if Solr service is available
        try {
            // Get GuzzleSolrService from container
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $solrAvailable = $guzzleSolrService->isAvailable();
            
            if ($solrAvailable) {
                // Get Solr system info
                $stats = $guzzleSolrService->getDashboardStats();
                
                // TODO: Add actual version detection from Solr admin API
                $solrVersion = '9.x (detection pending)';
                $vectorSupport = false; // Set to false until we implement it
                
                // TODO: Add method to list collections from Solr
                $collections = [];
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        return new JSONResponse([
            'success' => true,
            'solr' => [
                'available' => $solrAvailable,
                'version' => $solrVersion,
                'vectorSupport' => $vectorSupport,
                'collections' => $collections,
                'error' => $errorMessage,
            ],
        ]);
        
    } catch (\Exception $e) {
        $this->logger->error('[SettingsController] Failed to get Solr info', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        return new JSONResponse([
            'success' => false,
            'error' => 'Failed to get Solr information: ' . $e->getMessage(),
        ], 500);
    }
}
```

**File**: `openregister/lib/Controller/SettingsController.php`

### 2. API Route: routes.php

Added new route:

```php
['name' => 'settings#getSolrInfo', 'url' => '/api/settings/solr-info', 'verb' => 'GET'],
```

**File**: `openregister/appinfo/routes.php`

### 3. Frontend: LLMConfigModal.vue

Updated `loadAvailableBackends()` method to detect Solr:

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
            // TODO: Load collections and enable vector support when implemented
        } else {
            solrNote = solr.error || 'SOLR not connected. Enable in Search Configuration.'
        }
    }
} catch (error) {
    console.error('Failed to fetch Solr info:', error)
    solrNote = 'Failed to check Solr status'
}

backends.push({
    id: 'solr',
    name: 'Solr 9+ Dense Vector',
    description: solrAvailable 
        ? 'Very fast distributed vector search (connected ✓)' 
        : 'Very fast distributed vector search (not connected)',
    performance: solrAvailable ? 'very_fast' : null,
    available: solrAvailable,
    performanceNote: solrNote,
})
```

**File**: `openregister/src/modals/settings/LLMConfigModal.vue`

## How It Works

1. **User opens LLM Configuration modal**
2. **Frontend calls** `/api/settings/solr-info` endpoint
3. **Backend checks** if `GuzzleSolrService->isAvailable()` returns true
4. **Frontend updates** the Solr backend option:
   - If Solr is available: Shows as "connected ✓" with 🚀 Very Fast badge
   - If Solr is not available: Shows as "not connected" with no badge and disabled state

## UI Changes

### Before
- Only "PHP Cosine Similarity" showed as available (🐌 Slow)
- "Solr 9+ Dense Vector" showed as "Not yet implemented. Coming soon!"

### After
- "PHP Cosine Similarity" always available (🐌 Slow)
- "MariaDB + pgvector" shown based on database type (unavailable for MariaDB)
- **"Solr 9+ Dense Vector" shown as available (🚀 Very Fast) when Solr is connected**

## Testing

1. Open Nextcloud OpenRegister settings
2. Go to LLM Configuration section
3. Click on the LLM Configuration modal
4. Scroll to "Vector Search Backend" section
5. Open the "Search Method" dropdown
6. Verify that "Solr 9+ Dense Vector" shows as available with ✓ when Solr is enabled

## Next Steps

- [ ] Implement Solr version detection from admin API
- [ ] Add method to list available Solr collections
- [ ] Enable collection selector when Solr is chosen
- [ ] Implement actual vector search using Solr dense vector fields
- [ ] Add field name configuration for vector storage
- [ ] Update `VectorEmbeddingService` to route searches to selected backend

## Related Files

- `openregister/lib/Controller/SettingsController.php` - Backend endpoint
- `openregister/appinfo/routes.php` - API route
- `openregister/src/modals/settings/LLMConfigModal.vue` - Frontend detection
- `openregister/lib/Service/GuzzleSolrService.php` - Solr availability check

## Notes

- The Solr detection uses the existing `GuzzleSolrService->isAvailable()` method
- This method already includes comprehensive checks (connectivity, authentication, collections)
- Results are cached for 1 hour to avoid expensive connectivity tests on every call
- Vector search implementation in Solr is planned for future release

---

**Last Updated**: 2025-11-12
**Status**: ✅ Detection Implemented, ⏳ Vector Search Pending

