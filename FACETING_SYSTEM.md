# Comprehensive Faceting System

This document describes the new Elasticsearch-inspired faceting system implemented for the OpenRegister application. The system provides powerful, flexible faceting capabilities that support both metadata and object field facets with enumerated values and range buckets.

## Overview

The faceting system provides a modern, user-friendly approach to building faceted search interfaces. It supports:

- **Disjunctive faceting** - Facet options don't disappear when selected
- **Multiple facet types** - Terms, date histograms, and numeric ranges
- **Metadata and object field facets** - Both table columns and JSON data
- **Facetable field discovery** - Automatic detection of available faceting options
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

### 5. Facetable Field Discovery
Automatic analysis of available fields and their characteristics to help frontends build dynamic facet interfaces.

## Facetable Field Discovery

The system includes powerful discovery capabilities that analyze your data to determine which fields can be used for faceting and what types of facets are appropriate.

### Discovery API

```php
// Get facetable fields for a specific context
$facetableFields = $objectService->getFacetableFields($baseQuery, $sampleSize);

// Example with context filters
$baseQuery = [
    '@self' => ['register' => 1],
    '_search' => 'customer'
];

$facetableFields = $objectService->getFacetableFields($baseQuery, 100);
```

### Discovery Response Structure

```php
[
    '@self' => [
        'register' => [
            'type' => 'categorical',
            'description' => 'Register that contains the object',
            'facet_types' => ['terms'],
            'has_labels' => true,
            'sample_values' => [
                ['value' => 1, 'label' => 'Publications Register', 'count' => 150],
                ['value' => 2, 'label' => 'Events Register', 'count' => 75]
            ]
        ],
        'created' => [
            'type' => 'date',
            'description' => 'Date and time when the object was created',
            'facet_types' => ['date_histogram', 'range'],
            'intervals' => ['day', 'week', 'month', 'year'],
            'has_labels' => false,
            'date_range' => [
                'min' => '2023-01-01 00:00:00',
                'max' => '2024-12-31 23:59:59'
            ]
        ]
    ],
    'object_fields' => [
        'status' => [
            'type' => 'string',
            'description' => 'Object field: status',
            'facet_types' => ['terms'],
            'cardinality' => 'low',  // ≤50 unique values
            'sample_values' => ['published', 'draft', 'archived'],
            'appearance_rate' => 85  // Count of objects containing this field
        ],
        'priority' => [
            'type' => 'integer',
            'description' => 'Object field: priority',
            'facet_types' => ['range', 'terms'],
            'cardinality' => 'numeric',  // Numeric field type
            'sample_values' => ['1', '2', '3', '4', '5'],
            'appearance_rate' => 72  // Count of objects containing this field
        ]
    ]
]
```

### Field Properties Explained

#### Key Terms

**`appearance_rate`**: The actual count of objects (from the analyzed sample) that contain this field. For example, if 100 objects were analyzed and 85 contained the 'status' field, the appearance_rate would be 85. This is not a percentage but an absolute count.

**`cardinality`**: Indicates the uniqueness characteristics of field values:
- `'low'` - String fields with ≤50 unique values (suitable for terms facets)
- `'numeric'` - Integer, float, or numeric string fields
- `'binary'` - Boolean fields (true/false values only)
- Not set for date fields (they use intervals instead)

### Field Types and Characteristics

#### Metadata Fields (@self)
Predefined fields from the ObjectEntity table:

- **register** - Categorical with labels from register table
- **schema** - Categorical with labels from schema table  
- **uuid** - Identifier field (usually not suitable for faceting)
- **owner** - Categorical user field
- **organisation** - Categorical organisation field
- **application** - Categorical application field
- **created/updated/published/depublished** - Date fields with range support

#### Object Fields
Dynamically discovered from JSON object data:

- **string** - Text fields (low cardinality suitable for terms facets)
- **integer/float** - Numeric fields (suitable for range and terms facets)
- **date** - Date fields (suitable for date_histogram and range facets)
- **boolean** - Binary fields (suitable for terms facets)

### Discovery Configuration

#### Field Analysis Parameters

- **Sample Size** - Number of objects to analyze (default: 100)
- **Appearance Threshold** - Minimum percentage of objects that must contain the field (default: 10%)
- **Cardinality Threshold** - Maximum unique values for terms facets (default: 50)
- **Recursion Depth** - Maximum nesting level to analyze (default: 2)

#### Field Filtering

The discovery system automatically filters out:
- System fields (starting with @ or _)
- Nested objects and arrays of objects
- High cardinality string fields (>50 unique values)
- Fields appearing in <10% of objects
- Fields with inconsistent types (<70% type consistency)

### API Integration

#### Discovery Parameter

Add `_facetable=true` to any search endpoint to include facetable field information:

```
GET /api/objects?_facetable=true&limit=0
```

Response includes additional `facetable` property:

```php
[
    'results' => [],
    'total' => 0,
    'facetable' => [
        '@self' => [...],
        'object_fields' => [...]
    ]
]
```

#### Dynamic Facet Configuration

Use discovery results to build facet configurations:

```javascript
// Frontend example: Build facet config from discovery
const buildFacetConfig = (facetableFields) => {
    const config = { _facets: { '@self': {}, ...{} } };
    
    // Add metadata facets
    Object.entries(facetableFields['@self']).forEach(([field, info]) => {
        if (info.facet_types.includes('terms')) {
            config._facets['@self'][field] = { type: 'terms' };
        } else if (info.facet_types.includes('date_histogram')) {
            config._facets['@self'][field] = { 
                type: 'date_histogram', 
                interval: 'month' 
            };
        }
    });
    
    // Add object field facets
    Object.entries(facetableFields.object_fields).forEach(([field, info]) => {
        if (info.facet_types.includes('terms')) {
            config._facets[field] = { type: 'terms' };
        } else if (info.facet_types.includes('range')) {
            config._facets[field] = { 
                type: 'range',
                ranges: generateRanges(info.sample_values)
            };
        }
    });
    
    return config;
};
```

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

### Performance Impact

Based on real-world testing, the faceting system has the following performance characteristics:

- **Regular queries** - Baseline response time
- **With `_facets`** - Adds approximately **~10ms** to response time
- **With `_facetable=true`** - Adds approximately **~15ms** to response time
- **Combined `_facets` + `_facetable`** - Adds approximately **~25ms** total

These measurements are for typical datasets and may vary based on:
- Database size and complexity
- Number of facet fields requested
- Sample size for facetable discovery
- Server hardware and database optimization

### Optimizations

1. **Database-level aggregations** - Uses SQL GROUP BY for efficiency
2. **Indexed fields** - Metadata facets use indexed table columns
3. **Disjunctive queries** - Optimized to exclude only the relevant filter
4. **Count optimization** - Uses COUNT(*) instead of selecting all data
5. **Sample-based analysis** - Facetable discovery analyzes subset of data for performance

### Best Practices

1. **Use metadata facets when possible** - They perform better than JSON field facets
2. **Limit range buckets** - Too many ranges can impact performance
3. **Consider caching** - Facet results can be cached for frequently accessed data
4. **Index JSON fields** - Consider adding indexes for frequently faceted JSON fields
5. **Use `_facetable` sparingly** - Only request facetable discovery when building dynamic interfaces
6. **Optimize sample size** - Balance accuracy vs performance for facetable discovery (default: 100 objects)
7. **Cache facetable results** - Store discovery results for repeated interface building

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

### Dynamic Facet Discovery

```javascript
// React component that discovers and builds facets dynamically
const DynamicFacetInterface = ({ baseQuery }) => {
  const [facetableFields, setFacetableFields] = useState(null);
  const [facetData, setFacetData] = useState(null);
  const [filters, setFilters] = useState({});

  useEffect(() => {
    // Discover available facetable fields
    const discoverFacets = async () => {
      const response = await fetch('/api/objects?_facetable=true&limit=0', {
        method: 'POST',
        body: JSON.stringify(baseQuery)
      });
      const data = await response.json();
      setFacetableFields(data.facetable);
      
      // Build initial facet configuration
      const facetConfig = buildFacetConfig(data.facetable);
      
      // Get actual facet data
      const facetResponse = await fetch('/api/objects', {
        method: 'POST',
        body: JSON.stringify({ ...baseQuery, ...facetConfig })
      });
      const facetData = await facetResponse.json();
      setFacetData(facetData.facets);
    };

    discoverFacets();
  }, [baseQuery]);

  const buildFacetConfig = (facetableFields) => {
    const config = { _facets: { '@self': {} } };
    
    // Add metadata facets
    Object.entries(facetableFields['@self'] || {}).forEach(([field, info]) => {
      if (info.facet_types.includes('terms')) {
        config._facets['@self'][field] = { type: 'terms' };
      } else if (info.facet_types.includes('date_histogram')) {
        config._facets['@self'][field] = { 
          type: 'date_histogram', 
          interval: 'month' 
        };
      }
    });
    
    // Add object field facets
    Object.entries(facetableFields.object_fields || {}).forEach(([field, info]) => {
      if (info.facet_types.includes('terms')) {
        config._facets[field] = { type: 'terms' };
      }
    });
    
    return config;
  };

  if (!facetableFields || !facetData) {
    return <div>Loading facets...</div>;
  }

  return (
    <div className="dynamic-facets">
      <h2>Available Filters</h2>
      
      {/* Metadata facets */}
      {Object.entries(facetData['@self'] || {}).map(([field, facet]) => (
        <FacetFilter 
          key={`@self.${field}`}
          field={`@self.${field}`}
          facet={facet}
          fieldInfo={facetableFields['@self'][field]}
          onFilterChange={handleFilterChange}
        />
      ))}
      
      {/* Object field facets */}
      {Object.entries(facetData).filter(([key]) => key !== '@self').map(([field, facet]) => (
        <FacetFilter 
          key={field}
          field={field}
          facet={facet}
          fieldInfo={facetableFields.object_fields[field]}
          onFilterChange={handleFilterChange}
        />
      ))}
    </div>
  );
};
```

### Enhanced Facet Component

```javascript
// Enhanced facet component with discovery information
const FacetFilter = ({ field, facet, fieldInfo, onFilterChange }) => {
  return (
    <div className="facet-filter">
      <h3>
        {fieldInfo?.description || field}
        <span className="facet-info">
          ({fieldInfo?.type}, {fieldInfo?.appearance_rate} objects)
        </span>
      </h3>
      
      {facet.type === 'terms' && (
        <div className="checkbox-list">
          {facet.buckets.map(bucket => (
            <label key={bucket.key}>
              <input 
                type="checkbox" 
                onChange={() => onFilterChange(field, bucket.key)}
              />
              {bucket.label || bucket.key} ({bucket.results})
            </label>
          ))}
        </div>
      )}
      
      {facet.type === 'range' && (
        <div className="range-list">
          {facet.buckets.map(bucket => (
            <button 
              key={bucket.key}
              onClick={() => onFilterChange(field, bucket)}
            >
              {bucket.key}: {bucket.results} items
            </button>
          ))}
        </div>
      )}
      
      {facet.type === 'date_histogram' && (
        <div className="timeline">
          <div className="interval-selector">
            {fieldInfo?.intervals?.map(interval => (
              <button 
                key={interval}
                onClick={() => changeInterval(field, interval)}
              >
                {interval}
              </button>
            ))}
          </div>
          {facet.buckets.map(bucket => (
            <div key={bucket.key} className="timeline-item">
              <span>{bucket.key}</span>
              <span>{bucket.results}</span>
            </div>
          ))}
        </div>
      )}
      
      {fieldInfo?.sample_values && (
        <div className="sample-values">
          <small>Sample values: {fieldInfo.sample_values.slice(0, 3).join(', ')}</small>
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
        
        // Add facetable field discovery if requested
        if ($request->get('_facetable') === 'true') {
            $baseQuery = $query;
            unset($baseQuery['_facets'], $baseQuery['_limit'], $baseQuery['_page']);
            
            $result['facetable'] = $this->objectService->getFacetableFields(
                $baseQuery, 
                (int) $request->get('_sample_size', 100)
            );
        }
        
        return new JsonResponse($result);
    }
    
    public function getFacetableFields(Request $request): JsonResponse
    {
        $baseQuery = [
            '@self' => [
                'register' => $request->get('register'),
                'schema' => $request->get('schema')
            ],
            '_search' => $request->get('q')
        ];
        
        $sampleSize = (int) $request->get('sample_size', 100);
        
        $facetableFields = $this->objectService->getFacetableFields($baseQuery, $sampleSize);
        
        return new JsonResponse([
            'facetable' => $facetableFields,
            'sample_size' => $sampleSize,
            'base_query' => $baseQuery
        ]);
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
        
        $activeCount = $this->findBucketByKey($statusBuckets, 'active')['results'];
        $inactiveCount = $this->findBucketByKey($statusBuckets, 'inactive')['results'];
        
        $this->assertEquals(10, $activeCount);
        $this->assertEquals(5, $inactiveCount);
    }

    public function testFacetableFieldDiscovery(): void
    {
        // Create test objects with various field types
        $this->createTestObjects([
            'status' => 'active',
            'priority' => 1,
            'created_date' => '2024-01-15',
            'is_featured' => true
        ], 5);
        
        $this->createTestObjects([
            'status' => 'inactive', 
            'priority' => 2,
            'created_date' => '2024-02-20',
            'is_featured' => false
        ], 3);

        $baseQuery = ['@self' => ['register' => 1]];
        $facetableFields = $this->objectService->getFacetableFields($baseQuery, 50);
        
        // Check structure
        $this->assertArrayHasKey('@self', $facetableFields);
        $this->assertArrayHasKey('object_fields', $facetableFields);
        
        // Check metadata fields
        $this->assertArrayHasKey('register', $facetableFields['@self']);
        $this->assertEquals('categorical', $facetableFields['@self']['register']['type']);
        $this->assertContains('terms', $facetableFields['@self']['register']['facet_types']);
        
        // Check object fields
        $this->assertArrayHasKey('status', $facetableFields['object_fields']);
        $this->assertEquals('string', $facetableFields['object_fields']['status']['type']);
        $this->assertContains('terms', $facetableFields['object_fields']['status']['facet_types']);
        
        $this->assertArrayHasKey('priority', $facetableFields['object_fields']);
        $this->assertEquals('integer', $facetableFields['object_fields']['priority']['type']);
        $this->assertContains('range', $facetableFields['object_fields']['priority']['facet_types']);
        
        $this->assertArrayHasKey('is_featured', $facetableFields['object_fields']);
        $this->assertEquals('boolean', $facetableFields['object_fields']['is_featured']['type']);
        $this->assertContains('terms', $facetableFields['object_fields']['is_featured']['facet_types']);
    }

    public function testFacetableFieldFiltering(): void
    {
        // Create objects with high cardinality field (should be filtered out)
        for ($i = 0; $i < 100; $i++) {
            $this->createTestObjects([
                'unique_id' => 'id_' . $i,  // High cardinality
                'category' => 'cat_' . ($i % 3)  // Low cardinality
            ], 1);
        }

        $facetableFields = $this->objectService->getFacetableFields([], 100);
        
        // High cardinality field should be filtered out
        $this->assertArrayNotHasKey('unique_id', $facetableFields['object_fields']);
        
        // Low cardinality field should be included
        $this->assertArrayHasKey('category', $facetableFields['object_fields']);
        $this->assertEquals('low', $facetableFields['object_fields']['category']['cardinality']);
    }

    public function testFacetableFieldAppearanceThreshold(): void
    {
        // Create objects where some fields appear in <10% of objects
        $this->createTestObjects(['common_field' => 'value1'], 50);  // 100% appearance
        $this->createTestObjects(['rare_field' => 'value2'], 2);     // 4% appearance
        
        $facetableFields = $this->objectService->getFacetableFields([], 50);
        
        // Common field should be included
        $this->assertArrayHasKey('common_field', $facetableFields['object_fields']);
        
        // Rare field should be filtered out (below 10% threshold)
        $this->assertArrayNotHasKey('rare_field', $facetableFields['object_fields']);
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
- **Dynamic discovery** - Automatic detection of facetable fields helps build intelligent interfaces
- **Database-oriented** - All analysis happens at the database level for optimal performance

### Facetable Discovery Benefits

The facetable field discovery system provides several key advantages:

1. **Dynamic Interface Building** - Frontends can automatically discover and build facet interfaces without hardcoding field lists
2. **Data-Driven Configuration** - Facet types and options are determined by analyzing actual data
3. **Context Awareness** - Discovery respects current filters to show relevant faceting options
4. **Performance Optimization** - Database-level analysis ensures efficient field discovery
5. **Type Intelligence** - Automatic detection of field types enables appropriate facet configurations

### Usage Recommendations

1. **Use `_facetable=true`** for initial interface discovery
2. **Cache discovery results** for frequently accessed configurations
3. **Combine with regular faceting** for complete search interfaces
4. **Leverage sample data** to show users what to expect
5. **Respect appearance rates** to focus on commonly used fields

The system is designed to grow with your application's needs while maintaining excellent performance and user experience. The addition of facetable discovery makes it even easier to build intelligent, data-driven search interfaces that adapt to your content automatically. 