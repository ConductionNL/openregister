## Error Handling for Missing Register or Schema

If you request a schema or register by slug or ID that does not exist, the API will return a 404 Not Found response with a clear error message. This applies to all endpoints that use register or schema slugs/IDs, including object listing, creation, update, and detail endpoints.

### Example Error Response

```json
{
  "message": "Schema not found: voorzieningen"
}
```

or

```json
{
  "message": "Register not found: voorzieningen"
}
```

**Note:**
- The error message will specify whether the missing resource is a register or a schema.
- This behavior ensures that clients can distinguish between missing resources and other types of errors.

## Schema Relationships (related endpoint)

The '/related' endpoint for schemas returns both:
- **incoming**: schemas that reference the given schema (i.e., schemas that have a property with a $ref to this schema)
- **outgoing**: schemas that the given schema refers to in its own properties (i.e., schemas this schema references)

This provides a full bidirectional view of schema relationships.

### Example Request

'GET /api/schemas/{id}/related'

### Example Response

'
{
  'incoming': [
    { 'id': 2, 'title': 'Referrer Schema', ... },
    ...
  ],
  'outgoing': [
    { 'id': 3, 'title': 'Referenced Schema', ... },
    ...
  ],
  'total': 2
}
'

- 'incoming' contains schemas that reference the given schema.
- 'outgoing' contains schemas that are referenced by the given schema.
- 'total' is the sum of both arrays.

This endpoint helps you understand both which schemas depend on a given schema and which schemas it depends on.

## Schema Statistics (stats)

The 'stats' object for a schema now includes the following fields:

| Field      | Type   | Description |
|------------|--------|-------------|
| objects    | object | Statistics about objects attached to the schema |
| logs       | object | Statistics about logs (audit trails) for the schema |
| files      | object | Statistics about files for the schema |
| registers  | int    | The number of registers that reference this schema |

Example:

'
{
  'id': 123,
  'title': 'My Schema',
  ...
  'stats': {
    'objects': { 'total': 10, ... },
    'logs': { 'total': 5, ... },
    'files': { 'total': 0, 'size': 0 },
    'registers': 2
  }
}
'

## Schema Exploration Endpoints

OpenRegister provides specialized endpoints for analyzing existing object data to discover properties not defined in the current schema.

### Explore Schema Properties

Analyzes all objects belonging to a schema to discover missing properties and their characteristics.

**Endpoint:** `GET /api/schemas/{id}/explore`

**Parameters:**
- `id` (path): Schema ID or UUID

**Response:**
```json
{
  "total_objects": 242,
  "discovered_properties": {
    "email_address": {
      "property_name": "email_address",
      "type": "string",
      "recommended_type": "string",
      "detected_format": "email",
      "confidence_score": 94,
      "examples": ["john@example.com", "admin@domain.org"],
      "max_length": 64,
      "min_length": 7,
      "type_variations": ["string"],
      "string_patterns": ["email"],
      "numeric_range": null,
      "description": "Email property detected through analysis"
    },
    "user_score": {
      "property_name": "user_score",
      "type": "integer",
      "recommended_type": "integer", 
      "detected_format": null,
      "confidence_score": 89,
      "examples": [85, 92, 67],
      "max_length": null,
      "min_length": null,
      "type_variations": ["integer"],
      "string_patterns": [],
      "numeric_range": {
        "min": 0,
        "max": 100,
        "type": "integer"
      },
      "description": "User score property detected through analysis"
    }
  },
  "analysis_date": "2025-01-10T11:30:00Z",
  "suggestions": [
    {
      "property_name": "email_address",
      "recommended_type": "string",
      "confidence_score": 94,
      "detected_format": "email",
      "max_length": 64,
      "min_length": 7,
      "examples": ["john@example.com", "admin@domain.org"],
      "type_variations": ["string"],
      "string_patterns": ["email"],
      "numeric_range": null,
      "description": "Email property detected through analysis"
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `total_objects` | integer | Total number of objects analyzed |
| `discovered_properties` | object | Detailed analysis of each discovered property |
| `property_name` | string | Name of the discovered property |
| `recommended_type` | string | Suggested JSON Schema type (string, integer, boolean, etc.) |
| `confidence_score` | integer | Confidence percentage (0-100) |
| `detected_format` | string | Detected format (email, date, url, uuid, etc.) |
| `examples` | array | Sample values found in the data |
| `max_length` | integer | Maximum string length observed |
| `min_length` | integer | Minimum string length observed |
| `type_variations` | array | Types detected across different objects |
| `string_patterns` | array | Pattern types (camelCase, snake_case, etc.) |
| `numeric_range` | object | Min/max numeric values and type |
| `analysis_date` | string | ISO timestamp when analysis was performed |

### Update Schema from Exploration

Updates a schema with properties discovered through exploration.

**Endpoint:** `POST /api/schemas/{id}/update-from-exploration`

**Parameters:**
- `id` (path): Schema ID or UUID
- `properties` (body): Array of properties to add/update

**Request Body:**
```json
{
  "properties": [
    {
      "property_name": "email_address",
      "type": "string",
      "title": "Email Address",
      "description": "User's email address",
      "format": "email",
      "required": false,
      "visible": true,
      "facetable": true,
      "hideOnCollection": false,
      "hideOnForm": false,
      "immutable": false,
      "deprecated": false,
      "maxLength": 64,
      "minLength": 7,
      "displayTitle": "Email Address",
      "userDescription": "Contact email for the user account",
      "technicalDescription": "Email property for authentication and communication",
      "example": "user@example.com",
      "order": 10
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Schema updated successfully with 1 properties",
  "schema": {
    "id": 123,
    "title": "User Schema",
    "version": "1.1.0",
    "properties": {
      "email_address": {
        "type": "string",
        "format": "email",
        "description": "User's email address",
        "maxLength": 64,
        "minLength": 7
      }
    }
  }
}
```

### Property Configuration Options

When updating schemas from exploration, you can configure comprehensive property settings:

#### Technical Configuration
| Field | Type | Description |
|-------|------|-------------|
| `type` | string | JSON Schema type (string, integer, boolean, array, object) |
| `title` | string | Property title for forms |
| `description` | string | Technical description |
| `format` | string | Specific format (email, date, uri, uuid, etc.) |
| `example` | mixed | Example value |
| `order` | integer | Display order (0 = first) |

#### User Interface Configuration  
| Field | Type | Description |
|-------|------|-------------|
| `displayTitle` | string | User-facing label |
| `userDescription` | string | Help text for users |
| `visible` | boolean | Show in user interfaces |
| `hideOnCollection` | boolean | Hide in list/grid views |
| `hideOnForm` | boolean | Hide in forms |

#### Behavior Configuration
| Field | Type | Description |
|-------|------|-------------|
| `required` | boolean | Field is mandatory |
| `immutable` | boolean | Cannot be changed after creation |
| `deprecated` | boolean | Marked for removal |
| `facetable` | boolean | Enable filtering/searching |

#### Type-Specific Constraints
```json
// String constraints
{
  "type": "string",
  "maxLength": 255,
  "minLength": 1,
  "pattern": "^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$"
}

// Number constraints  
{
  "type": "integer",
  "minimum": 0,
  "maximum": 9999,
  "multipleOf": 1
}

// Boolean constraints
{
  "type": "boolean"
}

// Array constraints
{
  "type": "array",
  "items": { "type": "string" },
  "minItems": 1,
  "maxItems": 10
}
```

### Error Handling

#### Schema Not Found
```json
Status: 404
{
  "error": "Schema not found"
}
```

#### Invalid Property Configuration
```json
Status: 400
{
  "error": "Invalid property configuration",
  "details": {
    "property_name": "Invalid maxLength for string type"
  }
}
```

#### Empty Exploration Results
```json
Status: 200
{
  "total_objects": 150,
  "discovered_properties": {},
  "suggestions": [],
  "message": "No new properties discovered"
}
```

### Usage Examples

#### Complete Exploration Workflow

```bash
# 1. Start exploration
curl -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'GET /api/schemas/123/explore'

# 2. Review results and configure properties
# 3. Update schema with selected properties
curl -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -X POST '/api/schemas/123/update-from-exploration' \
  -d '{
    "properties": [
      {
        "property_name": "last_login",
        "type": "string",
        "title": "Last Login",
        "format": "date-time",
        "required": false,
        "visible": true,
        "facetable": true,
        "description": "Date/time of user last login",
        "displayTitle": "Last Login Date",
        "userDescription": "When the user last logged into the system"
      }
    ]
  }'
```

#### Automation Script Example

```javascript
// Explore multiple schemas programmatically
const schemas = [123, 124, 125];

for (const schemaId of schemas) {
  const exploration = await fetch(`/api/schemas/${schemaId}/explore`);
  const data = await exploration.json();
  
  if (data.suggestions.length > 0) {
    console.log(`Schema ${schemaId}: Found ${data.suggestions.length} new properties`);
    
    // Auto-accept high-confidence suggestions
    const highConfidence = data.suggestions.filter(s => s.confidence_score > 90);
    
    if (highConfidence.length > 0) {
      await fetch(`/api/schemas/${schemaId}/update-from-exploration`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ properties: highConfidence })
      });
    }
  }
}
``` 