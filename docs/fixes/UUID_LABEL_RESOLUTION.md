# UUID Label Resolution for Facets

## Problem Statement

When faceting on object fields that contain UUIDs (references to other objects), the facet labels were displaying raw UUID strings instead of human-readable names. This made facets unfriendly for frontend users and also prevented proper alphabetical ordering of facet buckets.

### Example Issue

Before the fix, a facet on a 'customer' field would show:
```json
{
  "customer": {
    "buckets": [
      { "value": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "count": 42, "label": "f47ac10b-58cc-4372-a567-0e02b2c3d479" },
      { "value": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "count": 15, "label": "a1b2c3d4-e5f6-7890-abcd-ef1234567890" }
    ]
  }
}
```

This is not user-friendly and makes it impossible to sort by meaningful names.

## Solution

We implemented automatic UUID resolution for all facet types using the existing `ObjectCacheService.getMultipleObjectNames()` method, which provides efficient batch loading and multi-tier caching.

### Changes Made

#### 1. Updated GuzzleSolrService Constructor

**File:** `openregister/lib/Service/GuzzleSolrService.php`

Added `ObjectCacheService` as a dependency:

```php
public function __construct(
    private readonly SettingsService $settingsService,
    private readonly LoggerInterface $logger,
    private readonly IClientService $clientService,
    private readonly IConfig $config,
    private readonly ?SchemaMapper $schemaMapper = null,
    private readonly ?RegisterMapper $registerMapper = null,
    private readonly ?OrganisationService $organisationService = null,
    private readonly ?OrganisationMapper $organisationMapper = null,
    private readonly ?ObjectCacheService $objectCacheService = null,  // NEW
) {
```

#### 2. Enhanced formatTermsFacetData Method

**File:** `openregister/lib/Service/GuzzleSolrService.php` (lines 8633-8690)

Modified the `formatTermsFacetData()` method to:

1. **Detect UUIDs**: Filter bucket values that look like UUIDs (contain hyphens)
2. **Batch resolve**: Use `ObjectCacheService.getMultipleObjectNames()` for efficient batch retrieval
3. **Apply labels**: Use resolved names as labels, fall back to UUIDs if resolution fails
4. **Sort alphabetically**: Sort by resolved labels for user-friendly display

```php
private function formatTermsFacetData(array $rawData): array
{
    $buckets = $rawData['buckets'] ?? [];
    $formattedBuckets = [];
    
    // Extract all values that might be UUIDs for batch lookup
    $values = array_map(function($bucket) { return $bucket['val']; }, $buckets);
    
    // Filter to only UUID-looking values (contains hyphens)
    $potentialUuids = array_filter($values, function($value) {
        return is_string($value) && str_contains($value, '-');
    });
    
    // Resolve UUIDs to names using object cache service
    $resolvedNames = [];
    if (!empty($potentialUuids) && $this->objectCacheService !== null) {
        try {
            $resolvedNames = $this->objectCacheService->getMultipleObjectNames($potentialUuids);
            $this->logger->debug('Resolved UUID labels for terms facet', [
                'uuids_checked' => count($potentialUuids),
                'names_resolved' => count($resolvedNames)
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to resolve UUID labels for terms facet', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Format buckets with resolved labels
    foreach ($buckets as $bucket) {
        $value = $bucket['val'];
        $formattedBuckets[] = [
            'value' => $value,
            'count' => $bucket['count'],
            'label' => $resolvedNames[$value] ?? $value // Use resolved name or fallback to value
        ];
    }
    
    // Sort buckets alphabetically by label (case-insensitive)
    usort($formattedBuckets, function($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });
    
    return [
        'type' => 'terms',
        'total_count' => array_sum(array_column($formattedBuckets, 'count')),
        'buckets' => $formattedBuckets
    ];
}
```

#### 3. Updated Documentation

**File:** `openregister/website/docs/Features/faceting.md`

- Updated overview to mention object UUID resolution
- Enhanced label resolution process section with UUID handling
- Added dedicated "UUID Resolution for Object Field Facets" subsection with examples
- Updated sorting behavior documentation
- Added performance considerations for UUID resolution

## How It Works

### UUID Detection
The system checks if facet values contain hyphens (`str_contains($value, '-')`), which is a simple but effective way to identify potential UUIDs without expensive regex matching.

### Batch Resolution Process

1. **Extract potential UUIDs** from all facet buckets
2. **Call ObjectCacheService.getMultipleObjectNames()** which:
   - Checks in-memory cache first
   - Checks distributed cache (Redis/Memcached) second  
   - Queries database only for cache misses
   - Searches organisations table first (priority)
   - Searches objects table for remaining UUIDs
   - Extracts names from common fields: naam, name, title, contractNummer, achternaam
3. **Apply resolved names** as labels in facet buckets
4. **Sort alphabetically** by resolved labels (case-insensitive)

### Cache Hierarchy

```
Request for UUID names
       ↓
[In-Memory Cache]
       ↓ (miss)
[Distributed Cache] (Redis/Memcached)
       ↓ (miss)
[Database Query]
       ↓
Store in caches → Return to caller
```

## Example Result

### Before UUID Resolution
```json
{
  "customer": {
    "buckets": [
      { "value": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "count": 42, "label": "f47ac10b-58cc-4372-a567-0e02b2c3d479" },
      { "value": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "count": 15, "label": "a1b2c3d4-e5f6-7890-abcd-ef1234567890" }
    ]
  }
}
```

### After UUID Resolution (Alphabetically Sorted)
```json
{
  "customer": {
    "buckets": [
      { "value": "f47ac10b-58cc-4372-a567-0e02b2c3d479", "count": 42, "label": "Acme Corporation" },
      { "value": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "count": 15, "label": "Beta Industries" }
    ]
  }
}
```

## Performance Impact

### Minimal Overhead
- **UUID detection**: Simple string check (`str_contains`) - negligible cost
- **Batch loading**: Single query for all UUIDs instead of N queries
- **Cache hits**: Most UUIDs resolved from cache without database access
- **Lazy evaluation**: Only processes values that look like UUIDs

### Real-World Performance
For a facet with 50 UUID values:
- **Without caching**: 50 database queries (N+1 problem)
- **With caching** (cache miss): 1 batch query + cache storage
- **With caching** (cache hit): 0 database queries

## Benefits

### For Frontend Users
- ✅ **Human-readable labels** instead of UUIDs
- ✅ **Alphabetically sorted** facets for easy navigation  
- ✅ **Consistent ordering** across all facet types
- ✅ **Better UX** - users can find options by name

### For Developers
- ✅ **Automatic** - no frontend code changes needed
- ✅ **Efficient** - batch loading with multi-tier caching
- ✅ **Robust** - graceful fallback to UUIDs if resolution fails
- ✅ **Consistent** - same resolution logic across all facet types

### For System Performance
- ✅ **Reduced queries** - batch loading instead of N+1
- ✅ **Cache efficiency** - shared cache across all users
- ✅ **Scalable** - performance doesn't degrade with more facets

## Compatibility

- ✅ **Backward compatible** - existing code continues to work
- ✅ **Graceful degradation** - falls back to UUIDs if ObjectCacheService unavailable
- ✅ **No breaking changes** - facet structure remains the same
- ✅ **Framework agnostic** - works with any frontend consuming the API

## Testing Recommendations

### Manual Testing
1. Create facets on object fields containing UUIDs
2. Verify labels show object names instead of UUIDs
3. Verify alphabetical sorting by resolved names
4. Verify graceful fallback for unresolvable UUIDs

### Performance Testing
1. Test facets with 10, 50, 100 unique UUIDs
2. Measure response time with cold cache
3. Measure response time with warm cache
4. Verify single batch query for UUID resolution

### Edge Cases
1. Non-UUID values mixed with UUIDs - should handle both
2. Invalid/deleted UUIDs - should fallback to UUID string
3. ObjectCacheService unavailable - should fallback gracefully
4. Empty name fields - should use UUID as fallback

## Related Files

- `openregister/lib/Service/GuzzleSolrService.php` - Main implementation
- `openregister/lib/Service/ObjectCacheService.php` - UUID resolution service
- `openregister/website/docs/Features/faceting.md` - Updated documentation
- `openregister/docs/fixes/UUID_LABEL_RESOLUTION.md` - This document

## See Also

- [Faceting System Documentation](../website/docs/Features/faceting.md)
- [Solr AND Filter Fix](./SOLR_AND_FILTER_FIX.md)
- [Smart Deduplication System](../SMART_DEDUPLICATION_SYSTEM.md)

