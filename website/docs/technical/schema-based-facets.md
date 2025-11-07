# Schema-Based Faceting

OpenRegister now supports schema-based faceting, which allows you to define facetable fields directly in your schema properties. This approach is more efficient and provides consistent faceting behavior based on your schema design rather than analyzing object data.

## Overview

Schema-based faceting works by marking properties in your schema as facetable using the `'facetable': true` flag. The system then automatically:

1. Discovers facetable fields from schema definitions
2. Determines appropriate facet types based on property types and formats
3. Provides efficient faceting without analyzing object data

## Benefits

- **Better Performance**: No need to analyze object data for field discovery
- **Consistent Behavior**: Facets are always available based on schema design
- **Type-Aware**: Automatically determines appropriate facet types
- **Designer Control**: Schema designers control which fields are facetable

## Configuring Facetable Properties

### Basic Configuration

To make a property facetable, add `'facetable': true` to the property definition:

```json
{
  'properties': {
    'status': {
      'type': 'string',
      'title': 'Status',
      'description': 'Current status of the item',
      'facetable': true,
      'example': 'active'
    }
  }
}
```

### Complete Example

Here's a complete schema example with various facetable field types:

```json
{
  'title': 'Publication Schema',
  'description': 'Schema for academic publications',
  'properties': {
    'title': {
      'type': 'string',
      'title': 'Publication Title',
      'description': 'The title of the publication',
      'facetable': false
    },
    'category': {
      'type': 'string',
      'title': 'Category',
      'description': 'Publication category',
      'facetable': true,
      'example': 'Research'
    },
    'published_date': {
      'type': 'string',
      'format': 'date',
      'title': 'Publication Date',
      'description': 'When the publication was published',
      'facetable': true
    },
    'priority': {
      'type': 'integer',
      'title': 'Priority',
      'description': 'Priority level from 1 to 5',
      'minimum': 1,
      'maximum': 5,
      'facetable': true
    },
    'is_featured': {
      'type': 'boolean',
      'title': 'Featured Publication',
      'description': 'Whether this is a featured publication',
      'facetable': true
    },
    'tags': {
      'type': 'array',
      'title': 'Tags',
      'description': 'Publication tags',
      'items': {
        'type': 'string'
      },
      'facetable': true
    },
    'internal_notes': {
      'type': 'string',
      'title': 'Internal Notes',
      'description': 'Notes for internal use only',
      'facetable': false
    }
  }
}
```

## Automatic Facet Type Detection

The system automatically determines appropriate facet types based on property definitions:

### String Properties

- **Regular strings**: Terms facet for categorical filtering
- **Date/datetime format**: Date histogram and range facets
- **Email/URI/UUID format**: Terms facet

```json
{
  'email': {
    'type': 'string',
    'format': 'email',
    'facetable': true
  },
  'created_date': {
    'type': 'string',
    'format': 'date',
    'facetable': true
  }
}
```

### Numeric Properties

Integer and number properties get both range and terms facets:

```json
{
  'priority': {
    'type': 'integer',
    'minimum': 1,
    'maximum': 10,
    'facetable': true
  },
  'rating': {
    'type': 'number',
    'facetable': true
  }
}
```

### Boolean Properties

Boolean properties get terms facets (true/false options):

```json
{
  'is_published': {
    'type': 'boolean',
    'facetable': true
  }
}
```

### Array Properties

Array properties get terms facets for filtering by array values:

```json
{
  'categories': {
    'type': 'array',
    'items': {
      'type': 'string'
    },
    'facetable': true
  }
}
```

## Using Facets in the Interface

Once you've configured facetable properties in your schema:

1. **Navigate** to the objects list for your register and schema
2. **Open** the filters sidebar
3. **Scroll** to the 'Facets' section at the bottom of the filters tab
4. **Enable** facets by checking the boxes for fields you want to filter by
5. **View** the facet results showing available values and counts
6. **Filter** by clicking on specific facet values

## API Usage

### Discovering Available Facets

Get facetable fields defined in schemas:

```
GET /api/objects/{register}/{schema}?_facetable=true&_limit=0
```

Response includes schema-based facet configuration:

```json
{
  'facetable': {
    'object_fields': {
      'category': {
        'type': 'string',
        'title': 'Category',
        'description': 'Publication category',
        'facet_types': ['terms'],
        'source': 'schema',
        'example': 'Research'
      },
      'priority': {
        'type': 'integer',
        'title': 'Priority',
        'facet_types': ['range', 'terms'],
        'source': 'schema',
        'minimum': 1,
        'maximum': 5
      }
    }
  }
}
```

### Requesting Facet Data

Request specific facets:

```
GET /api/objects/{register}/{schema}?_facets[category][type]=terms&_facets[priority][type]=range
```

## Migration from Object-Based Faceting

If you're upgrading from the previous system that analyzed object data:

### Update Your Schemas

Add `'facetable': true` to properties you want to be facetable:

```json
// Before: Facets discovered by analyzing objects
{
  'status': {
    'type': 'string',
    'title': 'Status'
  }
}

// After: Explicitly mark as facetable
{
  'status': {
    'type': 'string',
    'title': 'Status',
    'facetable': true
  }
}
```

### Benefits of Migration

1. **Faster Performance**: No object analysis required
2. **Consistent Results**: Facets always available based on schema
3. **Better Control**: Designers control which fields are facetable
4. **Type Safety**: Proper facet types based on schema definitions

## Best Practices

### When to Mark Fields as Facetable

- **Categorical data**: Status, category, type fields
- **Date fields**: Creation dates, publication dates, deadlines
- **Numeric ranges**: Priorities, ratings, scores
- **Boolean flags**: Published status, featured items
- **Tags/arrays**: Multi-value categorical data

### When NOT to Mark Fields as Facetable

- **Large text fields**: Descriptions, content, notes
- **Unique identifiers**: UUIDs, unique names
- **High-cardinality strings**: Email addresses, URLs
- **Sensitive data**: Internal notes, private information

### Performance Tips

- Use facets on fields that have reasonable cardinality (not too many unique values)
- Consider the user experience - only make fields facetable if users would want to filter by them
- Use meaningful titles and descriptions for better user interface

## Examples by Use Case

### Document Management

```json
{
  'document_type': {
    'type': 'string',
    'title': 'Document Type',
    'facetable': true
  },
  'department': {
    'type': 'string', 
    'title': 'Department',
    'facetable': true
  },
  'created_date': {
    'type': 'string',
    'format': 'date',
    'facetable': true
  },
  'is_confidential': {
    'type': 'boolean',
    'title': 'Confidential',
    'facetable': true
  }
}
```

### Product Catalog

```json
{
  'category': {
    'type': 'string',
    'title': 'Product Category',
    'facetable': true
  },
  'price': {
    'type': 'number',
    'title': 'Price',
    'facetable': true
  },
  'brand': {
    'type': 'string',
    'title': 'Brand',
    'facetable': true
  },
  'in_stock': {
    'type': 'boolean',
    'title': 'In Stock',
    'facetable': true
  }
}
```

### Event Management

```json
{
  'event_type': {
    'type': 'string',
    'title': 'Event Type',
    'facetable': true
  },
  'event_date': {
    'type': 'string',
    'format': 'date',
    'title': 'Event Date',
    'facetable': true
  },
  'capacity': {
    'type': 'integer',
    'title': 'Capacity',
    'facetable': true
  },
  'is_public': {
    'type': 'boolean',
    'title': 'Public Event',
    'facetable': true
  }
}
```

## Troubleshooting

### No Facets Appear

1. Check that your schema properties have `'facetable': true`
2. Verify the register and schema are selected
3. Ensure the schema contains at least one facetable property

### Facets Show No Data

1. Make sure objects exist for the schema
2. Check that object data contains the facetable fields
3. Verify the property names match between schema and object data

### Performance Issues

Schema-based faceting should be much faster than object-based analysis. If you experience performance issues:

1. Check database indexes on metadata fields
2. Consider the cardinality of your facetable fields
3. Monitor query performance in logs

## Advanced Configuration

### Custom Facet Types

While facet types are automatically determined, you can influence them through property configuration:

```json
{
  'date_field': {
    'type': 'string',
    'format': 'date-time',  // Enables date_histogram facets
    'facetable': true
  },
  'numeric_field': {
    'type': 'integer',
    'minimum': 1,           // Provides range context
    'maximum': 100,         // Provides range context
    'facetable': true
  }
}
```

For more advanced faceting features and configuration options, see the [Advanced Faceting Guide](advanced-faceting.md). 