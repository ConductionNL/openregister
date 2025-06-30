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
  "results": [],
  "total": 0,
  "facets": {
    "@self": {
      "register": {
        "type": "categorical",
        "description": "Register that contains the object",
        "facet_types": ["terms"],
        "has_labels": true,
        "sample_values": [
          {"value": "1", "label": "Publications", "count": 45},
          {"value": "2", "label": "Events", "count": 32}
        ]
      },
      "created": {
        "type": "date_histogram", 
        "interval": "month",
        "buckets": [
          {"key": "2024-01", "results": 15},
          {"key": "2024-02", "results": 28}
        ]
      }
    },
    "status": {
      "type": "terms",
      "buckets": [
        {"key": "active", "results": 67},
        {"key": "inactive", "results": 10}
      ]
    },
    "priority": {
      "type": "terms", 
      "buckets": [
        {"key": "high", "results": 23},
        {"key": "medium", "results": 45},
        {"key": "low", "results": 9}
      ]
    }
  },
  "facetable": {
    "@self": {
      "register": {
        "type": "categorical",
        "description": "Register that contains the object",
        "facet_types": ["terms"],
        "has_labels": true,
        "sample_values": [
          {"value": "1", "label": "Publications", "count": 45},
          {"value": "2", "label": "Events", "count": 32}
        ]
      }
    },
    "object_fields": {
      "status": {
        "type": "string",
        "description": "Object field: status", 
        "facet_types": ["terms"],
        "cardinality": "low",
        "sample_values": ["active", "inactive", "pending"],
        "appearance_rate": 77
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
            field={'@self.' + field}
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

### Asynchronous Performance Optimization

For applications requiring both faceting and discovery, the system provides asynchronous methods that run operations concurrently instead of sequentially:

```php
// Async method - runs operations concurrently (~15ms total)
$promise = $objectService->searchObjectsPaginatedAsync($query);
$results = React\Async\await($promise);

// Sync convenience method - same performance as async
$results = $objectService->searchObjectsPaginatedSync($query);

// Traditional method - sequential execution (~25ms total)
$results = $objectService->searchObjectsPaginated($query);
```

**Performance improvement**: Up to 40% faster when using both `_facets` and `_facetable=true`.

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

## Metadata Faceting

Metadata facets allow you to aggregate and filter by database table columns (metadata) rather than JSON object field data. These are accessed via the '@self' key in facet configurations and typically perform better than object field facets since they use indexed database columns.

### Basic Metadata Facet Structure

Metadata facets are configured under the '@self' key:

```json
{
  '_facets': {
    '@self': {
      'fieldname': {
        'type': 'facet_type',
        'options': 'value'
      }
    }
  }
}
```

### Metadata Facet Types

#### Terms Facets
Returns unique values and their counts for categorical data.

**Example - Register Terms Facet:**
```json
{
  '_facets': {
    '@self': {
      'register': {
        'type': 'terms'
      }
    }
  }
}
```

**URL Example:**
```
/api/objects?_facets[@self][register][type]=terms
```

#### Date Histogram Facets
Groups date fields by time intervals with customizable periods.

**Example - Publications by Year:**
```json
{
  '_facets': {
    '@self': {
      'published': {
        'type': 'date_histogram',
        'interval': 'year'
      }
    }
  }
}
```

**URL Example:**
```
/api/publications?_facets[@self][published][type]=date_histogram&_facets[@self][published][interval]=year
```

**Available Intervals:**
- 'day' - Daily grouping (YYYY-MM-DD)
- 'week' - Weekly grouping (YYYY-WW)  
- 'month' - Monthly grouping (YYYY-MM)
- 'year' - Yearly grouping (YYYY)

#### Range Facets
Creates custom numeric or date ranges with specified boundaries.

**Example - Creation Date Ranges:**
```json
{
  '_facets': {
    '@self': {
      'created': {
        'type': 'range',
        'ranges': [
          {'to': '2023-01-01', 'key': 'Before 2023'},
          {'from': '2023-01-01', 'to': '2024-01-01', 'key': '2023'},
          {'from': '2024-01-01', 'key': '2024 and later'}
        ]
      }
    }
  }
}
```

### Metadata Fields Reference

| Field | Type | Description | Facet Types | Notes |
|-------|------|-------------|-------------|--------|
| 'register' | Integer | Register ID | terms, range | References register table |
| 'schema' | Integer | Schema ID | terms, range | References schema table |
| 'uuid' | String | Unique identifier | terms | Usually not suitable for faceting |
| 'owner' | String | Owner user ID | terms | User who owns the object |
| 'organisation' | String | Organisation name | terms | Organisation context |
| 'application' | String | Application name | terms | Application context |
| 'created' | DateTime | Creation timestamp | date_histogram, range | ISO 8601 format |
| 'updated' | DateTime | Last update timestamp | date_histogram, range | ISO 8601 format |
| 'published' | DateTime | Publication date | date_histogram, range | When object was published |
| 'depublished' | DateTime | Depublication date | date_histogram, range | When object was unpublished |

### Practical Metadata Examples

#### Example 1: Publications by Year with Register Filter
```json
{
  '@self': {
    'register': 1
  },
  '_facets': {
    '@self': {
      'published': {
        'type': 'date_histogram',
        'interval': 'year'
      }
    }
  }
}
```

#### Example 2: Multiple Metadata Facets
```json
{
  '_facets': {
    '@self': {
      'register': {
        'type': 'terms'
      },
      'schema': {
        'type': 'terms'  
      },
      'created': {
        'type': 'date_histogram',
        'interval': 'month'
      }
    }
  }
}
```

#### Example 3: Organisation Activity Ranges
```json
{
  '_facets': {
    '@self': {
      'organisation': {
        'type': 'terms'
      },
      'updated': {
        'type': 'range',
        'ranges': [
          {'from': '2024-01-01', 'key': 'Recent'},
          {'to': '2024-01-01', 'key': 'Older'}
        ]
      }
    }
  }
}
```

### URL Encoding Examples

**Simple Terms Facet:**
```
/api/objects?_facets[@self][register][type]=terms
```

**Date Histogram (URL Encoded):**
```
/api/objects?_facets%5B@self%5D%5Bpublished%5D%5Btype%5D=date_histogram&_facets%5B@self%5D%5Bpublished%5D%5Binterval%5D=year
```

**Multiple Facets:**
```
/api/objects?_facets[@self][register][type]=terms&_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month
```

### Metadata Facet Performance

- Metadata facets are indexed and perform better than object field facets
- Date histogram facets on 'created' and 'updated' are optimized
- Terms facets work best on low-cardinality fields like 'register', 'schema', 'organisation'
- Avoid terms facets on high-cardinality fields like 'uuid'
- Range facets allow custom grouping without performance penalties

### Metadata Response Format

Metadata facets return results under the '@self' key:

```json
{
  'facets': {
    '@self': {
      'published': {
        'type': 'date_histogram',
        'interval': 'year',
        'buckets': [
          {'key': '2023', 'results': 15},
          {'key': '2024', 'results': 28}
        ]
      }
    }
  }
}
```

## Related Documentation

- [FACETING_SYSTEM.md](../../FACETING_SYSTEM.md) - Complete faceting system documentation
- [Advanced Search](advanced-search.md) - Property-based search queries
- [Content Search](content-search.md) - Full-text search capabilities
- [Schema Validation](schema-validation.md) - Object structure validation

## Conclusion

The automatic faceting system makes it easy to build intelligent, data-driven search interfaces that adapt to your content automatically. By analyzing your actual data, it provides relevant faceting options that help users navigate and discover information efficiently.

The combination of metadata and object field facets, along with intelligent type detection and performance optimization, makes this system suitable for both simple and complex data exploration scenarios.

## Frontend Integration Guide

### Controller Fix (IMPORTANT)

**Issue Resolved**: The original problem was that the `ObjectsController::index()` method was using legacy methods that don't support facets. 

**Root Cause**: The controller was calling:
- `$objectService->findAll($config)` - doesn't handle facets
- `$objectService->count($config)` - doesn't handle facets
- Manual pagination with `$this->paginate()` - doesn't include facets

**Solution Implemented**:

1. **New buildSearchQuery() method**: Properly extracts and preserves facet parameters (`_facets`, `_facetable`) from the request
2. **Updated index() method**: Now uses `searchObjectsPaginated()` which handles facets, facetable field discovery, and pagination in one call
3. **Debug logging**: Added temporary logging to help troubleshoot facet parameter flow
4. **Fallback support**: Includes error handling that falls back to the legacy method if needed

The controller now properly processes URLs like:
```
/api/objects/4/22?_limit=20&_page=1&_facetable=true&_facets[@self][register][type]=terms&_facets[@self][schema][type]=terms&_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month
```

**Testing**: Use `window.facetTests.testExactUserURL()` in the browser console to test the exact URL that was previously failing.

### Store Integration

The OpenRegister object store has been enhanced with comprehensive facet support. The store automatically requests facets when fetching object lists and provides reactive data for building dynamic facet interfaces.

#### New Store State

```javascript
// Added to object store state
facets: {}, // Current facet results
facetableFields: {}, // Available facetable fields for dynamic UI
activeFacets: {}, // Currently active/selected facets
facetsLoading: false, // Loading state for facets
```

#### Key Store Methods

**getFacetableFields(options = {})**
Discovers what fields can be used for faceting based on the current register/schema context:

```javascript
// Get facetable fields for current context
await objectStore.getFacetableFields()

// With custom options
await objectStore.getFacetableFields({ 
  register: 1, 
  schema: 2 
})
```

**getFacets(facetConfig = null, options = {})**
Gets actual facet counts for the current search context:

```javascript
// Get facets with default configuration
await objectStore.getFacets()

// With custom facet configuration
await objectStore.getFacets({
  _facets: {
    '@self': {
      register: { type: 'terms' },
      created: { type: 'date_histogram', interval: 'month' }
    },
    status: { type: 'terms' }
  }
})
```

**updateActiveFacet(field, facetType, enabled)**
Updates active facets and refreshes data:

```javascript
// Enable a facet
await objectStore.updateActiveFacet('@self.register', 'terms', true)

// Disable a facet
await objectStore.updateActiveFacet('status', 'terms', false)
```

**Enhanced refreshObjectList(options = {})**
The main object list method now automatically includes facets:

```javascript
// Automatically includes facets and facetable fields
await objectStore.refreshObjectList()

// Disable facet inclusion for performance
await objectStore.refreshObjectList({ includeFacets: false })
```

#### Store Getters

**availableMetadataFacets**
Returns metadata fields that can be used for faceting:

```javascript
// Access available metadata facets
const metadataFacets = objectStore.availableMetadataFacets
// Example: { register: { type: 'categorical', facet_types: ['terms'] }, ... }
```

**availableObjectFieldFacets**
Returns object fields that can be used for faceting:

```javascript
// Access available object field facets
const objectFacets = objectStore.availableObjectFieldFacets
// Example: { status: { type: 'string', facet_types: ['terms'] }, ... }
```

**currentFacets**
Returns current facet results with buckets:

```javascript
// Access current facet data
const facets = objectStore.currentFacets
// Example: { '@self': { register: { buckets: [{ key: 'Publications', results: 150 }] } } }
```

**hasFacets / hasFacetableFields**
Convenience getters for checking data availability:

```javascript
// Check if facets are available
if (objectStore.hasFacets) {
  // Display facet results
}

// Check if facetable fields are available
if (objectStore.hasFacetableFields) {
  // Show facet selection UI
}
```

### Vue Component Integration

The **FacetComponent** provides a ready-to-use interface for displaying and managing facets:

```vue
<template>
  <FacetComponent />
</template>

<script>
import FacetComponent from '../components/FacetComponent.vue'

export default {
  components: {
    FacetComponent
  }
}
</script>
```

#### Component Features

- **Automatic Discovery**: Shows available facetable fields automatically
- **Dynamic Interface**: Updates based on current register/schema selection
- **Interactive Selection**: Users can enable/disable facets with checkboxes
- **Real-time Results**: Displays facet counts and values
- **Loading States**: Shows loading indicators during facet operations
- **Responsive Design**: Follows Nextcloud design system

#### Integration in SearchSideBar

The component has been integrated at the bottom of the Filters tab in the search sidebar:

```vue
<!-- Within the Filters tab -->
<div class="filterSection">
  <FacetComponent />
</div>
```

#### Enhanced Automatic Filtering System

The FacetComponent now includes intelligent filtering interfaces for different field types:

**🎯 Smart Field Detection:**
- Automatically identifies field types and creates appropriate interfaces
- Excludes system fields ('id', 'uuid') from user interface
- Works with any schema without manual configuration
- Proper field name capitalization and tooltips

**📅 Date Range Pickers:**
- Automatic date range pickers for metadata date fields (created, updated, published)
- Shows available date range for context
- From/To date selection with native date pickers
- Clear functionality to remove date filters
- Supports range faceting in backend

**🔽 Multi-Select Dropdowns:**
- Terms-facetable object fields automatically become dropdowns
- Options populated from current facet results (with counts) or sample values
- Real-time filtering as users select/deselect values
- Support for both simple string values and complex object values
- Searchable dropdown interface with proper sorting

**☑️ Checkbox Facets:**
- Non-terms metadata fields use traditional checkbox interface
- Maintains backward compatibility for complex faceting scenarios

**✨ User Experience Enhancements:**
- **Capitalized Field Names**: 'Test' instead of 'test', 'Waarom' instead of 'waarom'
- **Helpful Tooltips**: Hover over field names to see descriptions
- **Coverage Information**: Shows how many objects have each field
- **Date Context**: Available date ranges shown for date fields

**Example Usage:**
Based on your data structure:
- **Date Fields**: 'Created', 'Updated', 'Published' appear as date range pickers
- **Text Fields**: 'Test' and 'Waarom' appear as searchable multi-select dropdowns
- **System Fields**: 'id' and 'uuid' are hidden from the interface

**Fallback Behavior:**
- Fields that don't support terms faceting remain as checkboxes
- If no facet results are available, dropdowns use sample values from field discovery
- Empty or unavailable fields are gracefully handled
- Date fields without range support fall back to checkboxes

### Usage Examples

#### Basic Facet Discovery

```javascript
// When register/schema changes
async handleSchemaChange(schema) {
  schemaStore.setSchemaItem(schema)
  
  // This automatically discovers facetable fields and loads basic facets
  await objectStore.refreshObjectList()
}
```

#### Custom Facet Configuration

```javascript
// Build custom facet interface
const customFacets = {
  _facets: {
    '@self': {
      register: { type: 'terms' },
      created: { type: 'date_histogram', interval: 'year' },
      updated: { type: 'range', ranges: [
        { to: '2023-01-01', key: 'Before 2023' },
        { from: '2023-01-01', key: '2023 and later' }
      ]}
    },
    status: { type: 'terms' },
    priority: { type: 'range', ranges: [
      { from: 1, to: 3, key: 'Low' },
      { from: 3, to: 7, key: 'Medium' },
      { from: 7, key: 'High' }
    ]}
  }
}

await objectStore.getFacets(customFacets)
```

#### Programmatic Facet Control

```javascript
// Enable metadata facets
await objectStore.updateActiveFacet('@self.register', 'terms', true)
await objectStore.updateActiveFacet('@self.created', 'date_histogram', true)

// Enable object field facets
await objectStore.updateActiveFacet('status', 'terms', true)
await objectStore.updateActiveFacet('priority', 'range', true)

// The store automatically refreshes facet data after each change
```

### Performance Considerations

- **Automatic Inclusion**: Facets are included by default but can be disabled with `includeFacets: false`
- **Context-Aware**: Discovery respects current filters for relevant results
- **Efficient Caching**: Store state prevents unnecessary API calls
- **Async Loading**: Facet operations don't block the main UI

### Error Handling

The store includes comprehensive error handling:

```javascript
try {
  await objectStore.getFacetableFields()
} catch (error) {
  console.error('Failed to load facetable fields:', error)
  // Store automatically resets to empty state
}
```

### Extending the Component

The FacetComponent can be extended or customized:

```vue
<template>
  <div class="custom-facet-wrapper">
    <FacetComponent />
    <!-- Add custom facet controls here -->
    <div class="custom-facet-actions">
      <NcButton @click="clearAllFacets">Clear All</NcButton>
      <NcButton @click="saveCurrentFacets">Save Configuration</NcButton>
    </div>
  </div>
</template>
```

This integration provides a solid foundation for building sophisticated search and filtering interfaces that automatically adapt to your data structure and content. 

## API Endpoint Compatibility

The OpenRegister API supports multiple ways to identify registers and schemas in endpoints:

- **Numeric IDs**: `/api/objects/4/666` (used by Nextcloud UI)
- **Slugs**: `/api/objects/petstore/dogs` (used by external frontends)  
- **UUIDs**: `/api/objects/550e8400-e29b-41d4-a716-446655440000/6ba7b810-9dad-11d1-80b4-00c04fd430c8`

### Controller Implementation Pattern

All controller methods follow this critical pattern for proper register/schema resolution:

```php
public function index(string $register, string $schema, ObjectService $objectService): JSONResponse
{
    // IMPORTANT: Set register and schema context first to resolve IDs, slugs, or UUIDs to numeric IDs
    // This is crucial for supporting both Nextcloud UI calls (/api/objects/4/666) and 
    // external frontend calls (/api/objects/petstore/dogs)
    $objectService->setRegister($register)->setSchema($schema);

    // Get resolved numeric IDs for the search query  
    $resolvedRegisterId = $objectService->getRegister();
    $resolvedSchemaId = $objectService->getSchema();

    // Use resolved IDs in queries and operations
    $query = $this->buildSearchQuery($resolvedRegisterId, $resolvedSchemaId);
    
    // ... rest of method
}
```

This pattern ensures that:
- Slugs like 'petstore' are resolved to their numeric IDs
- UUIDs are resolved to their numeric IDs
- Numeric IDs are validated and used directly
- All database operations use consistent numeric identifiers

// ... existing code ... 