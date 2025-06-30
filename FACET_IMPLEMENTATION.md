# Facet System Implementation Guide

This document describes the implementation of the automatic faceting system in OpenRegister, including both backend and frontend components.

## Overview

The faceting system provides dynamic filtering capabilities for object searches. It automatically discovers which fields can be used for faceting and provides intelligent suggestions based on your data.

## Backend Components

### Core PHP Classes

1. **`ObjectEntityMapper`** - Main database mapper with facet integration
   - Location: `lib/Db/ObjectEntityMapper.php`
   - Enhanced `searchObjects()` method supports facet requests
   - New `getSimpleFacets()` and `getFacetableFields()` methods

2. **`MariaDbFacetHandler`** - Handles JSON object field facets
   - Location: `lib/Db/ObjectHandlers/MariaDbFacetHandler.php`
   - Supports terms, date_histogram, and range facets for JSON fields
   - Includes field discovery and analysis

3. **`MetaDataFacetHandler`** - Handles database table column facets
   - Location: `lib/Db/ObjectHandlers/MetaDataFacetHandler.php`
   - Optimized for metadata fields (register, schema, created, etc.)

4. **`MariaDbSearchHandler`** - Enhanced search capabilities
   - Location: `lib/Db/ObjectHandlers/MariaDbSearchHandler.php`
   - Supports complex filtering and sorting operations

## Frontend Implementation

### Store Enhancement

The object store (`src/store/modules/object.js`) has been enhanced with comprehensive facet support:

#### New State Properties
```javascript
facets: {}, // Current facet results
facetableFields: {}, // Available facetable fields for dynamic UI
activeFacets: {}, // Currently active/selected facets
facetsLoading: false, // Loading state for facets
```

#### Key Methods
- `getFacetableFields()` - Discovers available facetable fields
- `getFacets()` - Retrieves facet data for current context
- `updateActiveFacet()` - Manages active facet selection
- Enhanced `refreshObjectList()` - Automatically includes facets

#### New Getters
- `availableMetadataFacets` - Metadata fields available for faceting
- `availableObjectFieldFacets` - Object fields available for faceting
- `currentFacets` - Current facet results with buckets
- `hasFacets` / `hasFacetableFields` - Availability checks

### Vue Components

#### FacetComponent
A ready-to-use Vue component for facet management:
- Location: `src/components/FacetComponent.vue`
- Automatically discovers and displays available facets
- Interactive checkbox interface for enabling/disabling facets
- Real-time display of facet results
- Responsive design following Nextcloud patterns

#### SearchSideBar Integration
The FacetComponent has been integrated into the existing Filters tab:
- Positioned at the bottom of the Filters tab
- Seamless integration with existing filters and search functionality

## API Integration

### Backend API Enhancement

The faceting system integrates with the existing API endpoints by adding new query parameters:

#### Facet Discovery
```
GET /api/objects/{register}/{schema}?_facetable=true&_limit=0
```

Response includes `facetable` field with available facet options:
```json
{
  "results": [],
  "total": 0,
  "facetable": {
    "@self": {
      "register": {
        "type": "categorical",
        "facet_types": ["terms"],
        "description": "Register that contains the object"
      }
    },
    "object_fields": {
      "status": {
        "type": "string",
        "facet_types": ["terms"],
        "appearance_rate": 85,
        "sample_values": ["active", "draft", "archived"]
      }
    }
  }
}
```

#### Facet Data Request
```
GET /api/objects/{register}/{schema}?_facets[@self][register][type]=terms&_facets[status][type]=terms
```

Response includes `facets` field with aggregated data:
```json
{
  "results": [...],
  "total": 150,
  "facets": {
    "@self": {
      "register": {
        "type": "terms",
        "buckets": [
          {"key": "Publications Register", "results": 150}
        ]
      }
    },
    "status": {
      "type": "terms", 
      "buckets": [
        {"key": "active", "results": 120},
        {"key": "draft", "results": 25},
        {"key": "archived", "results": 5}
      ]
    }
  }
}
```

## Usage Examples

### Basic Usage

1. **Automatic Discovery**: When a register and schema are selected, facets are automatically discovered and loaded.

2. **Interactive Selection**: Users can enable/disable facets in the sidebar's Facets tab.

3. **Real-time Results**: Facet counts update automatically when filters or search terms change.

### Programmatic Usage

```javascript
// Get available facetable fields
const facetableFields = await objectStore.getFacetableFields()

// Enable specific facets
await objectStore.updateActiveFacet('@self.register', 'terms', true)
await objectStore.updateActiveFacet('status', 'terms', true)

// Get facet data with custom configuration
const customFacets = await objectStore.getFacets({
  _facets: {
    '@self': {
      created: { type: 'date_histogram', interval: 'month' }
    },
    priority: { type: 'range', ranges: [
      { from: 1, to: 5, key: 'Low' },
      { from: 5, key: 'High' }
    ]}
  }
})
```

## Testing

### Manual Testing

Use the test functions in `src/tests/facet-integration-test.js`:

```javascript
// In browser console
window.facetTests.testCompleteWorkflow()
window.facetTests.checkStoreState()
```

### Test API Endpoints

1. **Test Facet Discovery**:
   ```
   GET /index.php/apps/openregister/api/objects/1/1?_facetable=true&_limit=0
   ```

2. **Test Basic Facets**:
   ```
   GET /index.php/apps/openregister/api/objects/1/1?_facets[@self][register][type]=terms&_limit=0
   ```

## Performance Considerations

### Backend Optimization
- Facet handlers use efficient database queries
- Metadata facets use indexed columns for fast performance
- Object field discovery is sample-based to limit analysis overhead
- Async methods available for concurrent operations

### Frontend Optimization
- Facets are included by default but can be disabled with `includeFacets: false`
- Store state prevents unnecessary API calls
- Loading states provide user feedback
- Error handling with automatic fallbacks

## Configuration Options

### Discovery Sample Size
Control how many objects are analyzed for field discovery:
```javascript
await objectStore.getFacetableFields({ sampleSize: 200 })
```

### Facet Types
Supported facet types:
- **Terms**: For categorical data (low cardinality strings)
- **Date Histogram**: For date fields with configurable intervals
- **Range**: For numeric data with custom range definitions

### Custom Intervals
Date histogram intervals:
- `day` - Daily grouping
- `week` - Weekly grouping  
- `month` - Monthly grouping (default)
- `year` - Yearly grouping

## Troubleshooting

### Common Issues

1. **No Facets Appear**: Ensure register and schema are selected
2. **Slow Performance**: Reduce sample size or disable facets for large datasets
3. **Missing Fields**: Check field appearance rate (must be >10% of objects)
4. **API Errors**: Verify facet parameter formatting in URL

### Debug Tools

Use browser console:
```javascript
// Check store state
window.facetTests.checkStoreState()

// Test individual components
await window.facetTests.testFacetDiscovery()
await window.facetTests.testBasicFacets()
```

## Future Enhancements

### Planned Features
- Facet value filtering (search within facet results)
- Saved facet configurations
- Facet-based data export
- Advanced range facet UI with sliders
- Hierarchical facets for nested data

### Extension Points
- Custom facet types can be added to handlers
- FacetComponent can be extended with additional UI elements
- Store methods can be overridden for custom behavior

## Related Documentation

- [Automatic Facets Documentation](website/docs/automatic-facets.md)
- [Advanced Search Guide](website/docs/advanced-search.md)
- [API Reference](website/docs/api-reference.md)

## Support

For issues or questions about the faceting system:
1. Check the troubleshooting section above
2. Review the test examples in `src/tests/facet-integration-test.js`
3. Consult the comprehensive documentation in `website/docs/automatic-facets.md` 