# Comprehensive Faceting System

This document describes the new Elasticsearch-inspired faceting system implemented for the OpenRegister application. The system provides powerful, flexible faceting capabilities that support both metadata and object field facets with enumerated values and range buckets.

## Overview

The faceting system provides a modern, user-friendly approach to building faceted search interfaces. It supports:

- **Disjunctive faceting** - Facet options don't disappear when selected
- **Multiple facet types** - Terms, date histograms, and numeric ranges
- **Metadata and object field facets** - Both table columns and JSON data
- **Elasticsearch-style API** - Familiar structure for developers
- **Performance optimization** - Efficient database queries with proper indexing

## Key Features

### 1. Disjunctive Faceting
Each facet shows counts as if its own filter were not applied. This prevents facet options from disappearing when selected, providing a better user experience.

### 2. Multiple Facet Types
- **Terms aggregation** - For categorical data (status, priority, etc.)
- **Date histogram** - For time-based data with configurable intervals
- **Range aggregation** - For numeric data with custom buckets

### 3. Dual Data Sources
- **Metadata facets** - Based on ObjectEntity table columns (@self)
- **Object field facets** - Based on JSON object data

### 4. Enhanced Labels
Automatic resolution of register and schema IDs to human-readable names.

## API Structure

### Basic Query Structure

```php
$query = [
    // Search filters (same as searchObjects)
    '@self' => [
        'register' => 1,
        'schema' => 2
    ],
    'status' => 'active',
    '_search' => 'customer',
    
    // Facet configuration
    '_facets' => [
        // Metadata facets
        '@self' => [
            'register' => ['type' => 'terms'],
            'schema' => ['type' => 'terms'],
            'created' => [
                'type' => 'date_histogram',
                'interval' => 'month'
            ]
        ],
        
        // Object field facets
        'status' => ['type' => 'terms'],
        'priority' => ['type' => 'terms'],
        'price' => [
            'type' => 'range',
            'ranges' => [
                ['to' => 100],
                ['from' => 100, 'to' => 500],
                ['from' => 500]
            ]
        ]
    ]
];

$facets = $objectService->getFacetsForObjects($query);
```

### Response Structure

```php
[
    'facets' => [
        '@self' => [
            'register' => [
                'type' => 'terms',
                'buckets' => [
                    ['key' => 1, 'doc_count' => 150, 'label' => 'Register Name'],
                    ['key' => 2, 'doc_count' => 75, 'label' => 'Other Register']
                ]
            ],
            'created' => [
                'type' => 'date_histogram',
                'interval' => 'month',
                'buckets' => [
                    ['key' => '2024-01', 'doc_count' => 45],
                    ['key' => '2024-02', 'doc_count' => 67]
                ]
            ]
        ],
        'status' => [
            'type' => 'terms',
            'buckets' => [
                ['key' => 'active', 'doc_count' => 134],
                ['key' => 'inactive', 'doc_count' => 45]
            ]
        ],
        'price' => [
            'type' => 'range',
            'buckets' => [
                ['key' => '0-100', 'from' => 0, 'to' => 100, 'doc_count' => 120],
                ['key' => '100-500', 'from' => 100, 'to' => 500, 'doc_count' => 80],
                ['key' => '500+', 'from' => 500, 'doc_count' => 15]
            ]
        ]
    ]
]
```

## Facet Types

### 1. Terms Aggregation

For categorical data like status, priority, category, etc.

```php
'_facets' => [
    'status' => ['type' => 'terms'],
    'priority' => ['type' => 'terms'],
    '@self' => [
        'register' => ['type' => 'terms'],
        'schema' => ['type' => 'terms']
    ]
]
```

**Response:**
```php
'status' => [
    'type' => 'terms',
    'buckets' => [
        ['key' => 'active', 'doc_count' => 134],
        ['key' => 'pending', 'doc_count' => 45],
        ['key' => 'inactive', 'doc_count' => 23]
    ]
]
```

### 2. Date Histogram

For time-based data with configurable intervals.

**Supported intervals:** `day`, `week`, `month`, `year`

```php
'_facets' => [
    'event_date' => [
        'type' => 'date_histogram',
        'interval' => 'month'
    ],
    '@self' => [
        'created' => [
            'type' => 'date_histogram',
            'interval' => 'week'
        ]
    ]
]
```

**Response:**
```php
'event_date' => [
    'type' => 'date_histogram',
    'interval' => 'month',
    'buckets' => [
        ['key' => '2024-01', 'doc_count' => 45],
        ['key' => '2024-02', 'doc_count' => 67],
        ['key' => '2024-03', 'doc_count' => 52]
    ]
]
```

### 3. Range Aggregation

For numeric data with custom buckets.

```php
'_facets' => [
    'price' => [
        'type' => 'range',
        'ranges' => [
            ['to' => 100],                    // 0-100
            ['from' => 100, 'to' => 500],     // 100-500
            ['from' => 500, 'to' => 1000],    // 500-1000
            ['from' => 1000]                  // 1000+
        ]
    ]
]
```

**Response:**
```php
'price' => [
    'type' => 'range',
    'buckets' => [
        ['key' => '0-100', 'to' => 100, 'doc_count' => 120],
        ['key' => '100-500', 'from' => 100, 'to' => 500, 'doc_count' => 80],
        ['key' => '500-1000', 'from' => 500, 'to' => 1000, 'doc_count' => 35],
        ['key' => '1000+', 'from' => 1000, 'doc_count' => 15]
    ]
]
```

## Usage Examples

### Basic Enumerated Facets

```php
$query = [
    '@self' => ['register' => 1],
    'status' => 'active',
    '_facets' => [
        '@self' => [
            'register' => ['type' => 'terms'],
            'schema' => ['type' => 'terms']
        ],
        'status' => ['type' => 'terms'],
        'priority' => ['type' => 'terms']
    ]
];

$facets = $objectService->getFacetsForObjects($query);

// Use facet data for UI checkboxes
foreach ($facets['facets']['status']['buckets'] as $bucket) {
    $selected = ($bucket['key'] === 'active') ? 'checked' : '';
    echo "<input type='checkbox' {$selected}> {$bucket['key']} ({$bucket['doc_count']})\n";
}
```

### Date Timeline Facets

```php
$query = [
    '_facets' => [
        '@self' => [
            'created' => [
                'type' => 'date_histogram',
                'interval' => 'month'
            ]
        ]
    ]
];

$facets = $objectService->getFacetsForObjects($query);

// Use for timeline visualization
foreach ($facets['facets']['@self']['created']['buckets'] as $bucket) {
    echo "{$bucket['key']}: {$bucket['doc_count']} objects\n";
}
```

### Price Range Facets

```php
$query = [
    '_facets' => [
        'price' => [
            'type' => 'range',
            'ranges' => [
                ['to' => 100],
                ['from' => 100, 'to' => 500],
                ['from' => 500]
            ]
        ]
    ]
];

$facets = $objectService->getFacetsForObjects($query);

// Use for price filter UI
foreach ($facets['facets']['price']['buckets'] as $bucket) {
    $from = $bucket['from'] ?? 0;
    $to = $bucket['to'] ?? '∞';
    echo "€{$from} - €{$to}: {$bucket['doc_count']} items\n";
}
```

## Integration with Search

### Combined Search and Facets

```php
$query = [
    // Search filters
    '@self' => [
        'register' => [1, 2, 3],
        'organisation' => 'IS NOT NULL'
    ],
    'status' => ['active', 'pending'],
    '_search' => 'important customer',
    '_published' => true,
    
    // Pagination
    '_limit' => 25,
    '_page' => 1,
    
    // Facet configuration
    '_facets' => [
        '@self' => [
            'register' => ['type' => 'terms'],
            'schema' => ['type' => 'terms']
        ],
        'status' => ['type' => 'terms'],
        'priority' => ['type' => 'terms']
    ]
];

// Get complete paginated results with facets
$result = $objectService->searchObjectsPaginated($query);

// Result contains:
// - results: Array of objects
// - total: Total count
// - page/pages: Pagination info
// - facets: Facet data
```

### Disjunctive Faceting Example

```php
// User has selected register=1 and status='active'
$query = [
    '@self' => ['register' => 1],
    'status' => 'active',
    '_facets' => [
        '@self' => ['register' => ['type' => 'terms']],
        'status' => ['type' => 'terms'],
        'priority' => ['type' => 'terms']
    ]
];

$facets = $objectService->getFacetsForObjects($query);

// Register facet shows ALL registers (not just register 1)
// Status facet shows ALL statuses (not just 'active')
// Priority facet shows counts for register=1 AND status='active'

// This allows users to change their register or status selection
// without losing the ability to see other options
```

## Performance Considerations

### Optimizations

1. **Database-level aggregations** - Uses SQL GROUP BY for efficiency
2. **Indexed fields** - Metadata facets use indexed table columns
3. **Disjunctive queries** - Optimized to exclude only the relevant filter
4. **Count optimization** - Uses COUNT(*) instead of selecting all data

### Best Practices

1. **Use metadata facets when possible** - They perform better than JSON field facets
2. **Limit range buckets** - Too many ranges can impact performance
3. **Consider caching** - Facet results can be cached for frequently accessed data
4. **Index JSON fields** - Consider adding indexes for frequently faceted JSON fields

## Migration from Legacy System

### Old Approach

```php
// Legacy faceting
$config = [
    'filters' => ['register' => 1, 'status' => 'active'],
    '_queries' => ['status', 'priority', 'category']
];

$facets = $objectService->getFacets($config['filters'], $config['search']);
```

### New Approach

```php
// New comprehensive faceting
$query = [
    '@self' => ['register' => 1],
    'status' => 'active',
    '_facets' => [
        '@self' => ['register' => ['type' => 'terms']],
        'status' => ['type' => 'terms'],
        'priority' => ['type' => 'terms'],
        'category' => ['type' => 'terms']
    ]
];

$facets = $objectService->getFacetsForObjects($query);
```

### Backward Compatibility

The system maintains backward compatibility. If no `_facets` configuration is provided, it falls back to the legacy `getFacets` method.

## UI Integration Examples

### React/Vue Component Example

```javascript
// Facet component example
const FacetFilter = ({ facet, onFilterChange }) => {
  return (
    <div className="facet-filter">
      <h3>{facet.field}</h3>
      {facet.type === 'terms' && (
        <div className="checkbox-list">
          {facet.buckets.map(bucket => (
            <label key={bucket.key}>
              <input 
                type="checkbox" 
                onChange={() => onFilterChange(facet.field, bucket.key)}
              />
              {bucket.label || bucket.key} ({bucket.doc_count})
            </label>
          ))}
        </div>
      )}
      
      {facet.type === 'range' && (
        <div className="range-list">
          {facet.buckets.map(bucket => (
            <button 
              key={bucket.key}
              onClick={() => onFilterChange(facet.field, bucket)}
            >
              {bucket.key}: {bucket.doc_count} items
            </button>
          ))}
        </div>
      )}
      
      {facet.type === 'date_histogram' && (
        <div className="timeline">
          {facet.buckets.map(bucket => (
            <div key={bucket.key} className="timeline-item">
              <span>{bucket.key}</span>
              <span>{bucket.doc_count}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
```

### PHP Controller Example

```php
class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = [
            // Extract filters from request
            '@self' => [
                'register' => $request->get('register'),
                'schema' => $request->get('schema')
            ],
            'status' => $request->get('status'),
            '_search' => $request->get('q'),
            '_page' => $request->get('page', 1),
            '_limit' => $request->get('limit', 20),
            
            // Facet configuration
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ],
                'status' => ['type' => 'terms'],
                'priority' => ['type' => 'terms'],
                'category' => ['type' => 'terms']
            ]
        ];

        $result = $this->objectService->searchObjectsPaginated($query);
        
        return new JsonResponse($result);
    }
}
```

## Testing

### Unit Test Examples

```php
class FacetingTest extends TestCase
{
    public function testBasicTermsFacet(): void
    {
        $query = [
            '_facets' => [
                'status' => ['type' => 'terms']
            ]
        ];

        $facets = $this->objectService->getFacetsForObjects($query);
        
        $this->assertArrayHasKey('facets', $facets);
        $this->assertArrayHasKey('status', $facets['facets']);
        $this->assertEquals('terms', $facets['facets']['status']['type']);
        $this->assertIsArray($facets['facets']['status']['buckets']);
    }

    public function testDisjunctiveFaceting(): void
    {
        // Create test data with different statuses
        $this->createTestObjects(['status' => 'active'], 10);
        $this->createTestObjects(['status' => 'inactive'], 5);

        $query = [
            'status' => 'active',  // Filter by active
            '_facets' => [
                'status' => ['type' => 'terms']
            ]
        ];

        $facets = $this->objectService->getFacetsForObjects($query);
        
        // Should show both active AND inactive in facets (disjunctive)
        $statusBuckets = $facets['facets']['status']['buckets'];
        $this->assertCount(2, $statusBuckets);
        
        $activeCount = $this->findBucketByKey($statusBuckets, 'active')['doc_count'];
        $inactiveCount = $this->findBucketByKey($statusBuckets, 'inactive')['doc_count'];
        
        $this->assertEquals(10, $activeCount);
        $this->assertEquals(5, $inactiveCount);
    }
}
```

## Conclusion

The new faceting system provides a powerful, flexible, and user-friendly approach to building faceted search interfaces. It combines the best practices from modern search systems like Elasticsearch with the specific needs of the OpenRegister application.

Key benefits:
- **Better UX** - Disjunctive faceting prevents options from disappearing
- **More flexible** - Supports multiple facet types and data sources
- **Better performance** - Optimized database queries and caching support
- **Modern API** - Familiar structure for developers
- **Backward compatible** - Existing code continues to work

The system is designed to grow with your application's needs while maintaining excellent performance and user experience. 