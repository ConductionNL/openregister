# Unified Faceting System

## Overview

OpenRegister implements a unified two-stage faceting system that leverages SOLR's native faceting capabilities while providing a simplified interface. The system uses schema properties marked with `facetable: true` which are automatically converted to SOLR fields with `docValues: true` for efficient faceting.

## Key Benefits

- **SOLR Native Performance**: Uses SOLR's built-in JSON faceting for maximum efficiency
- **Schema-Driven**: Based on explicit `facetable: true` properties in schemas
- **Two-Stage UX**: Clear separation between discovery and data loading
- **Translation Layer**: Converts SOLR responses to OpenRegister's unified format
- **Backward Compatible**: Works with existing faceting interfaces

## Two-Stage Architecture

### Stage 1: Facet Discovery (`_facets=true`)
Discovers available facetable fields and their characteristics without computing actual counts.

```
GET /api/objects/{register}/{schema}?_facets=true&_limit=0
```

**Response Structure:**
```json
{
  "results": [],
  "total": 0,
  "facets": {
    "available": {
      "@self": {
        "register": {
          "type": "categorical",
          "facet_types": ["terms"],
          "description": "Register that contains the object"
        },
        "created": {
          "type": "date",
          "facet_types": ["date_histogram", "range"],
          "description": "Creation timestamp"
        }
      },
      "object_fields": {
        "status": {
          "type": "string",
          "facet_types": ["terms"],
          "title": "Status",
          "source": "schema"
        },
        "priority": {
          "type": "integer", 
          "facet_types": ["range", "terms"],
          "title": "Priority Level",
          "source": "schema"
        }
      }
    }
  }
}
```

### Stage 2: Facet Data (`_facets[field][type]=...`)
Retrieves actual facet counts and buckets for specified fields.

```
GET /api/objects/{register}/{schema}?_facets[@self][register][type]=terms&_facets[status][type]=terms
```

**Response Structure:**
```json
{
  "results": [...],
  "total": 150,
  "facets": {
    "data": {
      "@self": {
        "register": {
          "type": "terms",
          "buckets": [
            {"key": "1", "label": "Publications", "count": 120},
            {"key": "2", "label": "Events", "count": 30}
          ]
        }
      },
      "status": {
        "type": "terms",
        "buckets": [
          {"key": "active", "count": 100},
          {"key": "draft", "count": 35},
          {"key": "archived", "count": 15}
        ]
      }
    }
  }
}
```

### Optional: Combined Mode (`_facets=include`)
For performance-optimized scenarios, get both discovery and data in one call.

```
GET /api/objects/{register}/{schema}?_facets=include
```

## Implementation Strategy

### SOLR-Native Translation
The system works by:
1. **Schema Analysis**: Reads `facetable: true` properties from schemas
2. **SOLR Field Mapping**: Uses existing SOLR fields with `docValues: true`
3. **JSON Faceting**: Leverages SOLR's native `json.facet` parameter
4. **Response Translation**: Converts SOLR format to OpenRegister format

### Data Source Priority
1. **SOLR** (primary) - Native JSON faceting with high performance
2. **Database** (fallback) - SQL-based aggregation for compatibility

### Field Discovery Method
- **Schema-based only** - Uses fields marked with `facetable: true`
- **No object analysis** - Relies on schema definitions for consistency

### Facet Types Supported
- **Terms**: Categorical data (status, category, etc.)
- **Date Histogram**: Time-based data with intervals
- **Range**: Numeric data with custom buckets

## Frontend Integration

### SearchSideBar Implementation
```javascript
// Stage 1: Discover available facets
const discovery = await objectStore.getFacetableFields()

// Stage 2: Enable specific facets
await objectStore.enableFacets(['@self.register', 'status', 'priority'])

// Optional: Get everything at once
await objectStore.getFacetsIncluded()
```

### User Experience Flow
1. User selects register/schema
2. System discovers available facets (Stage 1)
3. User enables desired facets via checkboxes
4. System loads facet data (Stage 2)
5. User applies facet filters to search results

## Performance Considerations

### Discovery Stage (`_facets=true`)
- **SOLR**: Schema property analysis (~2ms)
- **Database**: Schema property analysis (~2ms)

### Data Stage (`_facets[field]=...`)
- **SOLR**: Native JSON faceting (~5-10ms per facet)
- **Database**: SQL GROUP BY queries (~15-25ms per facet)

### Combined Mode (`_facets=include`)
- **SOLR**: Single request with JSON faceting (~15ms)
- **Database**: Parallel queries (~35ms)

### SOLR Advantages
- **Native docValues**: Fields created with faceting optimizations
- **JSON Faceting**: Modern, efficient aggregation API
- **No Sampling**: Uses actual indexed data, not samples
- **Concurrent Processing**: Multiple facets processed in parallel

## Migration Path

### From Current Systems
1. **Schema-based**: Continue using `facetable: true` (preferred)
2. **Automatic**: Fallback for schemas without facetable properties
3. **Legacy**: Maintain backward compatibility

### API Compatibility
- Existing `_facetable=true` parameter maps to new `_facets=true`
- Existing facet configuration syntax remains supported
- New simplified syntax available for common use cases

## Configuration

### Schema Properties
```json
{
  "properties": {
    "status": {
      "type": "string",
      "title": "Status",
      "facetable": true,
      "facet_config": {
        "type": "terms",
        "sort": "count"
      }
    }
  }
}
```

### System Settings
```php
// In app config
'faceting' => [
    'default_source' => 'solr', // 'solr' or 'database'
    'discovery_sample_size' => 100,
    'max_facet_values' => 50,
    'enable_combined_mode' => true
]
```

## Error Handling

### Graceful Degradation
- SOLR unavailable → Fall back to database
- Schema missing facetable properties → Use automatic discovery
- Discovery fails → Show basic metadata facets only

### User Feedback
- Loading states for each stage
- Clear error messages
- Fallback options when features unavailable

## Future Enhancements

### Planned Features
- Facet value search/filtering
- Saved facet configurations
- Custom facet types
- Hierarchical facets

### Performance Optimizations
- Facet result caching
- Incremental facet loading
- Background facet pre-computation
