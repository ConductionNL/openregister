# Automatic Facets

Open Register provides a powerful automatic faceting system that enables dynamic filtering and navigation through your data. The system automatically discovers facetable fields and provides intelligent faceting options based on your actual data content.

## Overview

The automatic faceting system offers:

- **Dynamic Field Discovery** - Automatically detects which fields can be used for faceting
- **Intelligent Type Detection** - Determines appropriate facet types based on data analysis
- **Context-Aware Filtering** - Respects current search context when discovering facets
- **Multiple Facet Types** - Supports terms, date histograms, and numeric ranges
- **Database-Level Performance** - All analysis happens efficiently at the database level

## Key Features

### 1. Facetable Field Discovery

The system analyzes your data to automatically discover which fields are suitable for faceting:

```php
// Discover facetable fields for a specific context
$facetableFields = $objectService->getFacetableFields([
    '@self' => ['register' => 1],
    '_search' => 'customer'
], 100);
```

### 2. Intelligent Facet Configuration

Based on field analysis, the system suggests appropriate facet types:

- **String fields** with low cardinality → Terms facets
- **Numeric fields** → Range and terms facets  
- **Date fields** → Date histogram and range facets
- **Boolean fields** → Terms facets

### 3. Context-Aware Analysis

Discovery respects your current search filters to show relevant faceting options for the filtered dataset.

## API Usage

### Discovery Endpoint

Add `_facetable=true` to any search request to include facetable field information:

```
GET /api/objects?_facetable=true&limit=0
```

Response includes facetable field metadata:

```json
{
  'results': [],
  'total': 0,
  'facetable': {
    '@self': {
      'register': {
        'type': 'categorical',
        'description': 'Register that contains the object',
        'facet_types': ['terms'],
        'has_labels': true,
        'sample_values': [
          {'value': 1, 'label': 'Publications Register', 'count': 150}
        ]
      }
    },
    'object_fields': {
      'status': {
        'type': 'string',
        'description': 'Object field: status',
        'facet_types': ['terms'],
        'cardinality': 'low',  // ≤50 unique values
        'sample_values': ['published', 'draft', 'archived'],
        'appearance_rate': 85  // Count of objects containing this field
      }
    }
  }
}
```

### Using Discovered Fields

Build facet configurations dynamically from discovery results:

```php
// Get facetable fields
$facetableFields = $objectService->getFacetableFields($baseQuery);

// Build facet configuration
$facetConfig = ['_facets' => ['@self' => []]];

foreach ($facetableFields['@self'] as $field => $info) {
    if (in_array('terms', $info['facet_types'])) {
        $facetConfig['_facets']['@self'][$field] = ['type' => 'terms'];
    }
}

foreach ($facetableFields['object_fields'] as $field => $info) {
    if (in_array('terms', $info['facet_types'])) {
        $facetConfig['_facets'][$field] = ['type' => 'terms'];
    }
}

// Get facets with the discovered configuration
$facets = $objectService->getFacetsForObjects(array_merge($baseQuery, $facetConfig));
```

## Key Terms Explained

### `appearance_rate`
The actual count of objects (from the analyzed sample) that contain this field. This is **not a percentage** but an absolute count.

**Example**: If 100 objects were analyzed and 85 contained the 'status' field, the `appearance_rate` would be 85.

### `cardinality`
Indicates the uniqueness characteristics of field values:

- **`'low'`** - String fields with ≤50 unique values (suitable for terms facets)
- **`'numeric'`** - Integer, float, or numeric string fields  
- **`'binary'`** - Boolean fields (true/false values only)
- **Not set** - Date fields (they use intervals instead)

## Field Types and Analysis

### Metadata Fields (@self)

Predefined fields from the database table:

| Field | Type | Description | Facet Types |
|-------|------|-------------|-------------|
| register | categorical | Register containing the object | terms |
| schema | categorical | Schema defining the object | terms |
| owner | categorical | User who owns the object | terms |
| organisation | categorical | Organisation associated with object | terms |
| created | date | Creation timestamp | date_histogram, range |
| updated | date | Last update timestamp | date_histogram, range |
| published | date | Publication timestamp | date_histogram, range |

### Object Fields

Dynamically discovered from JSON object data:

| Type | Characteristics | Suitable Facets | Example |
|------|----------------|-----------------|---------|
| string | Low cardinality (<50 unique values) | terms | status, category, type |
| integer | Numeric values | range, terms | priority, score, count |
| float | Decimal values | range | price, rating, percentage |
| date | Date/datetime strings | date_histogram, range | event_date, deadline |
| boolean | True/false values | terms | is_featured, active |

### Field Filtering

The system automatically filters out unsuitable fields:

- **High cardinality strings** (>50 unique values) - Too many options for terms facets
- **Rare fields** (<10% appearance rate) - Not common enough to be useful
- **System fields** (starting with @ or _) - Internal use only
- **Inconsistent types** (<70% type consistency) - Mixed data types
- **Complex nested objects** - Not suitable for simple faceting

## Configuration Options

### Sample Size

Control how many objects to analyze for field discovery:

```php
$facetableFields = $objectService->getFacetableFields($baseQuery, 200);
```

**Recommendations:**
- Small datasets (<1000 objects): Use 100-200 samples
- Medium datasets (1000-10000 objects): Use 100-500 samples  
- Large datasets (>10000 objects): Use 100-1000 samples

### Appearance Threshold

Fields must appear in at least 10% of analyzed objects to be considered facetable. This ensures facets are useful for the majority of your data.

### Cardinality Limits

- **Terms facets**: Maximum 50 unique values
- **Range facets**: No limit (automatically generates appropriate ranges)
- **Date histograms**: No limit (uses configurable intervals)

## Frontend Integration

### React/Vue Example

```javascript
const FacetDiscovery = ({ baseQuery }) => {
  const [facetableFields, setFacetableFields] = useState(null);
  const [activeFacets, setActiveFacets] = useState({});

  useEffect(() => {
    // Discover available facets
    fetch('/api/objects?_facetable=true&limit=0', {
      method: 'POST',
      body: JSON.stringify(baseQuery)
    })
    .then(response => response.json())
    .then(data => setFacetableFields(data.facetable));
  }, [baseQuery]);

  const buildFacetInterface = () => {
    if (!facetableFields) return null;

    return (
      <div className='facet-discovery'>
        <h3>Available Filters</h3>
        
        {/* Metadata facets */}
        {Object.entries(facetableFields['@self']).map(([field, info]) => (
          <FacetOption 
            key={field}
            field={`@self.${field}`}
            info={info}
            onToggle={handleFacetToggle}
          />
        ))}
        
        {/* Object field facets */}
        {Object.entries(facetableFields.object_fields).map(([field, info]) => (
          <FacetOption 
            key={field}
            field={field}
            info={info}
            onToggle={handleFacetToggle}
          />
        ))}
      </div>
    );
  };

  return buildFacetInterface();
};

const FacetOption = ({ field, info, onToggle }) => (
  <div className='facet-option'>
    <label>
      <input 
        type='checkbox'
        onChange={() => onToggle(field, info.facet_types[0])}
      />
      {info.description}
      <small>({info.type}, {info.appearance_rate} objects)</small>
    </label>
    
    {info.sample_values && (
      <div className='sample-values'>
        Sample: {info.sample_values.slice(0, 3).join(', ')}
      </div>
    )}
  </div>
);
```

## Performance Considerations

### Performance Impact

Real-world performance testing shows the following response time impacts:

- **Regular API calls** - Baseline response time
- **With faceting (`_facets`)** - Adds approximately **~10ms**
- **With discovery (`_facetable=true`)** - Adds approximately **~15ms**
- **Combined faceting + discovery** - Adds approximately **~25ms** total

These measurements are based on typical datasets and may vary depending on:
- Database size and object complexity
- Number of facet fields being analyzed
- Sample size used for discovery (default: 100 objects)
- Server hardware and database configuration

### Database Optimization

- **Indexed fields**: Metadata facets use indexed table columns for fast performance
- **JSON analysis**: Object field discovery uses efficient JSON functions
- **Sample-based analysis**: Analyzes subset of data for large datasets
- **Cached results**: Discovery results can be cached for frequently accessed configurations

### Best Practices

1. **Use appropriate sample sizes** - Balance accuracy with performance (50-200 for most cases)
2. **Cache discovery results** - Store results for repeated use, especially for interface building
3. **Prefer metadata facets** - They perform better than object field facets
4. **Filter by context** - Use base queries to focus discovery on relevant data
5. **Monitor field cardinality** - High cardinality fields may impact performance
6. **Request discovery strategically** - Only use `_facetable=true` when building dynamic interfaces
7. **Consider lazy loading** - Load facetable information separately from initial search results

## Use Cases

### Dynamic Search Interfaces

Build search interfaces that adapt to your data automatically:

```php
// Discover what facets are available for publications
$facetableFields = $objectService->getFacetableFields([
    '@self' => ['register' => $publicationsRegister->getId()]
]);

// Build interface showing only relevant facets for publications
foreach ($facetableFields['object_fields'] as $field => $info) {
    if ($info['appearance_rate'] > 50) { // Only show common fields
        $recommendedFacets[$field] = $info;
    }
}
```

### Data Exploration

Help users discover patterns in their data:

```php
// Show what fields are available for analysis
$facetableFields = $objectService->getFacetableFields([
    '_search' => 'customer complaints'
]);

// Suggest facets that might reveal insights
$insightFacets = array_filter($facetableFields['object_fields'], function($info) {
    return in_array($info['type'], ['date', 'categorical']) && 
           $info['appearance_rate'] > 25;
});
```

### Schema Validation

Understand your data structure and quality:

```php
// Analyze field consistency across objects
$facetableFields = $objectService->getFacetableFields([], 1000);

foreach ($facetableFields['object_fields'] as $field => $info) {
    $sampleSize = 100; // Adjust based on your actual sample size
    if ($info['appearance_rate'] < ($sampleSize * 0.8)) {
        $missingPercentage = (($sampleSize - $info['appearance_rate']) / $sampleSize) * 100;
        echo "Field '{$field}' is missing from " . round($missingPercentage) . "% of objects\n";
    }
}
```

## Advanced Features

### Custom Field Analysis

The system provides detailed analysis for each discovered field:

- **Appearance rate**: Count of objects containing the field (from analyzed sample)
- **Cardinality**: Uniqueness classification (low/numeric/binary)
- **Type consistency**: How consistently the field type is used (≥70% required)
- **Sample values**: Representative values from the field
- **Date ranges**: Min/max dates for date fields

### Nested Field Support

The system can analyze nested object fields up to 2 levels deep:

```json
{
  'address.city': {
    'type': 'string',
    'facet_types': ['terms'],
    'appearance_rate': 85
  },
  'contact.email': {
    'type': 'string', 
    'facet_types': ['terms'],
    'appearance_rate': 95
  }
}
```

### Multi-Register Analysis

Discover facets across multiple registers:

```php
$facetableFields = $objectService->getFacetableFields([
    '@self' => ['register' => [1, 2, 3]]
]);
```

## Troubleshooting

### No Fields Discovered

If no object fields are discovered:

1. **Check sample size** - Increase the sample size parameter
2. **Verify data structure** - Ensure objects contain JSON data in the 'object' column
3. **Review appearance threshold** - Fields must appear in >10% of objects
4. **Check field cardinality** - High cardinality fields are filtered out

### Poor Performance

If discovery is slow:

1. **Reduce sample size** - Use smaller samples for large datasets
2. **Add database indexes** - Index frequently queried JSON fields
3. **Use base query filters** - Narrow the analysis scope
4. **Cache results** - Store discovery results for reuse

### Unexpected Results

If discovery results seem incorrect:

1. **Check data quality** - Inconsistent field types may cause filtering
2. **Review base query** - Ensure filters are working as expected
3. **Verify field names** - Case sensitivity and special characters matter
4. **Analyze sample data** - Check if sample is representative

## Related Documentation

- [FACETING_SYSTEM.md](../../FACETING_SYSTEM.md) - Complete faceting system documentation
- [Advanced Search](advanced-search.md) - Property-based search queries
- [Content Search](content-search.md) - Full-text search capabilities
- [Schema Validation](schema-validation.md) - Object structure validation

## Conclusion

The automatic faceting system makes it easy to build intelligent, data-driven search interfaces that adapt to your content automatically. By analyzing your actual data, it provides relevant faceting options that help users navigate and discover information efficiently.

The combination of metadata and object field facets, along with intelligent type detection and performance optimization, makes this system suitable for both simple and complex data exploration scenarios. 