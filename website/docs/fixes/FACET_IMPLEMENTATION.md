# Facet System Implementation Guide

This document describes the implementation of the schema-based faceting system in OpenRegister, including both backend and frontend components.

## Overview

The faceting system provides dynamic filtering capabilities for object searches. It automatically discovers which fields can be used for faceting based on schema property definitions, providing intelligent suggestions based on your schema configuration rather than analyzing object data.

**Key Features:**
- **Schema-driven discovery**: Facetable fields are defined in schema properties with `'facetable': true`
- **Efficient performance**: No need to analyze object data for field discovery
- **Consistent behavior**: Facets are always available based on schema design, not data content
- **Type-aware faceting**: Automatically determines appropriate facet types based on schema property types

## Backend Components

### Core PHP Classes

1. **`ObjectEntityMapper`** - Main database mapper with schema-based facet integration
   - Location: `lib/Db/ObjectEntityMapper.php`
   - Enhanced `searchObjects()` method supports facet requests
   - New `getFacetableFieldsFromSchemas()` method for schema-based discovery
   - Enhanced `getSimpleFacets()` and `getFacetableFields()` methods

2. **`MariaDbFacetHandler`** - Handles JSON object field facets
   - Location: `lib/Db/ObjectHandlers/MariaDbFacetHandler.php`
   - Supports terms, date_histogram, and range facets for JSON fields
   - Works with schema-defined facetable fields

3. **`MetaDataFacetHandler`** - Handles database table column facets
   - Location: `lib/Db/ObjectHandlers/MetaDataFacetHandler.php`
   - Optimized for metadata fields (register, schema, created, etc.)

4. **`MariaDbSearchHandler`** - Enhanced search capabilities
   - Location: `lib/Db/ObjectHandlers/MariaDbSearchHandler.php`
   - Supports complex filtering and sorting operations

### Schema Property Configuration

Fields are marked as facetable in schema properties using the `facetable` flag:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "title": "Status",
      "description": "Current status of the item",
      "facetable": true,
      "example": "active"
    },
    "priority": {
      "type": "integer",
      "title": "Priority Level",
      "description": "Priority from 1 to 10",
      "facetable": true,
      "minimum": 1,
      "maximum": 10
    },
    "created_date": {
      "type": "string",
      "format": "date",
      "title": "Creation Date",
      "description": "When the item was created",
      "facetable": true
    },
    "internal_notes": {
      "type": "string",
      "title": "Internal Notes",
      "description": "Notes for internal use only",
      "facetable": false
    }
  }
}
```

### Automatic Facet Type Detection

Based on schema property definitions, the system automatically determines appropriate facet types:

- **String fields**: 
  - Regular strings → `terms` facet
  - Date/datetime format → `date_histogram` and `range` facets
  - Email/URI/UUID format → `terms` facet

- **Numeric fields** (integer/number): `range` and `terms` facets

- **Boolean fields**: `terms` facet

- **Array fields**: `terms` facet

## Frontend Implementation

### Store Enhancement

The object store (`src/store/modules/object.js`) has been enhanced with comprehensive facet support:

#### New State Properties
```javascript
facets: {}, // Current facet results
facetableFields: {}, // Available facetable fields from schemas
activeFacets: {}, // Currently active/selected facets
facetsLoading: false, // Loading state for facets
```

#### Key Methods
- `getFacetableFields()` - Discovers available facetable fields from schemas
- `getFacets()` - Retrieves facet data for current context
- `updateActiveFacet()` - Manages active facet selection
- Enhanced `refreshObjectList()` - Automatically includes facets

#### New Getters
- `availableMetadataFacets` - Metadata fields available for faceting
- `availableObjectFieldFacets` - Object fields available for faceting (from schemas)
- `currentFacets` - Current facet results with buckets
- `hasFacets` / `hasFacetableFields` - Availability checks

### Vue Components

#### FacetComponent
A ready-to-use Vue component for facet management:
- Location: `src/components/FacetComponent.vue`
- Automatically discovers and displays available facets from schemas
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

#### Facet Discovery (Schema-Based)
```
GET /api/objects/{register}/{schema}?_facetable=true&_limit=0
```

Response includes `facetable` field with available facet options based on schema definitions:
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
        "format": "",
        "title": "Status",
        "description": "Current status of the item",
        "facet_types": ["terms"],
        "source": "schema",
        "example": "active"
      },
      "priority": {
        "type": "integer",
        "title": "Priority Level",
        "description": "Priority from 1 to 10",
        "facet_types": ["range", "terms"],
        "source": "schema",
        "minimum": 1,
        "maximum": 10
      },
      "created_date": {
        "type": "string",
        "format": "date",
        "title": "Creation Date",
        "description": "When the item was created",
        "facet_types": ["date_histogram", "range"],
        "source": "schema",
        "intervals": ["day", "week", "month", "year"]
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

1. **Schema Configuration**: Define facetable fields in your schema properties with `'facetable': true`.

2. **Automatic Discovery**: When a register and schema are selected, facets are automatically discovered from schema definitions.

3. **Interactive Selection**: Users can enable/disable facets in the sidebar's Facets tab.

4. **Real-time Results**: Facet counts update automatically when filters or search terms change.

### Schema Configuration Example

```json
{
  "title": "Publication Schema",
  "properties": {
    "title": {
      "type": "string",
      "title": "Publication Title",
      "facetable": false
    },
    "category": {
      "type": "string",
      "title": "Category",
      "description": "Publication category",
      "facetable": true,
      "example": "Research"
    },
    "published_date": {
      "type": "string",
      "format": "date",
      "title": "Publication Date",
      "facetable": true
    },
    "priority": {
      "type": "integer",
      "title": "Priority",
      "minimum": 1,
      "maximum": 5,
      "facetable": true
    },
    "is_featured": {
      "type": "boolean",
      "title": "Featured Publication",
      "facetable": true
    }
  }
}
```

### Programmatic Usage

```javascript
// Get available facetable fields (from schemas)
const facetableFields = await objectStore.getFacetableFields()

// Enable specific facets
await objectStore.updateActiveFacet('@self.register', 'terms', true)
await objectStore.updateActiveFacet('category', 'terms', true)

// Get facet data with custom configuration
const customFacets = await objectStore.getFacets({
  _facets: {
    '@self': {
      created: { type: 'date_histogram', interval: 'month' }
    },
    priority: { type: 'range', ranges: [
      { from: 1, to: 3, key: 'Low' },
      { from: 3, to: 5, key: 'High' }
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
- **Schema-based discovery**: No object data analysis required, improving performance significantly
- Facet handlers use efficient database queries
- Metadata facets use indexed columns for fast performance
- No sampling overhead since discovery is schema-based
- Async methods available for concurrent operations

### Frontend Optimization
- Facets are included by default but can be disabled with `includeFacets: false`
- Store state prevents unnecessary API calls
- Loading states provide user feedback
- Error handling with automatic fallbacks

## Configuration Options

### Schema Property Configuration
Control facet availability by setting `facetable: true/false` in schema properties:
```json
{
  "property_name": {
    "type": "string",
    "title": "Display Name",
    "description": "Field description",
    "facetable": true,
    "example": "sample value"
  }
}
```

### Facet Types
Supported facet types (automatically determined from schema):
- **Terms**: For categorical data (strings, booleans, low-cardinality data)
- **Date Histogram**: For date/datetime fields with configurable intervals
- **Range**: For numeric data with custom range definitions

### Custom Intervals
Date histogram intervals:
- `day` - Daily grouping
- `week` - Weekly grouping  
- `month` - Monthly grouping (default)
- `year` - Yearly grouping

## Troubleshooting

### Common Issues

1. **No Facets Appear**: 
   - Ensure register and schema are selected
   - Check that schema properties have `'facetable': true`
   - Verify schema contains facetable properties

2. **Missing Fields**: 
   - Check schema property definitions
   - Ensure `'facetable': true` is set on desired fields
   - Verify property types are supported for faceting

3. **API Errors**: Verify facet parameter formatting in URL

4. **Performance Issues**: Schema-based approach should be much faster than previous object-based analysis

### Debug Tools

Use browser console:
```javascript
// Check store state
window.facetTests.checkStoreState()

// Test individual components
await window.facetTests.testFacetDiscovery()
await window.facetTests.testBasicFacets()
```

## Migration from Object-Based Faceting

If you're upgrading from the previous object-based faceting system:

1. **Update Schema Definitions**: Add `'facetable': true` to properties you want to be facetable
2. **Remove Sample Size Concerns**: No longer need to worry about sample sizes for discovery
3. **Consistent Results**: Facets are now always available based on schema, not data content
4. **Better Performance**: No object analysis overhead

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
- Schema property extensions for advanced facet configuration

## Related Documentation

- [Faceting Features](../Features/faceting.md)
- [Unified Faceting System](../technical/unified-faceting-system.md)
- [Schema-Based Facets](../technical/schema-based-facets.md)
- [Search Documentation](../Features/search.md)

## Support

For issues or questions about the faceting system:
1. Check the troubleshooting section above
2. Review the test examples in `src/tests/facet-integration-test.js`
3. Consult the [Unified Faceting System documentation](../technical/unified-faceting-system.md) 