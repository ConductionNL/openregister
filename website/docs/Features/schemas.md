---
title: Schemas
sidebar_position: 2
description: An overview of how core concepts in Open Register interact with each other.
keywords:
  - Open Register
  - Core Concepts
  - Relationships
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Schemas

## What is a Schema?

In Open Register, a **Schema** defines the structure, validation rules, and relationships for data objects. Schemas act as blueprints that specify:

- What **fields** an object should have
- What **data types** those fields should be
- Which fields are **required** vs. optional
- Any **constraints** on field values (min/max, patterns, enums)
- **Relationships** between different objects

Open Register uses [JSON Schema](https://json-schema.org/) as its schema definition language, providing a powerful and standardized way to describe data structures.

## Schema Structure

A schema in Open Register follows the JSON Schema specification (see [JSON Schema Core](https://json-schema.org/understanding-json-schema) and [JSON Schema Validation](https://json-schema.org/draft/2020-12/json-schema-validation.html)) and consists of the following key components defined in the specification:


<ApiSchema id="open-register" example   pointer="#/components/schemas/Schema" />

## Schema Validation

Open Register provides robust schema validation capabilities to ensure data integrity and quality. The validation system is built on top of JSON Schema validation and includes additional custom validation rules.

### Validation Types

1. **Type Validation**
   - Ensures data types match schema definitions
   - Validates string, number, boolean, object, and array types
   - Checks format specifications (email, date, URI, etc.)

2. **Required Fields**
   - Validates presence of mandatory fields
   - Supports conditional requirements
   - Handles nested required fields

3. **Constraints**
   - Minimum/maximum values for numbers
   - String length limits
   - Pattern matching for strings
   - Array size limits
   - Custom validation rules

4. **Relationships**
   - Validates object references
   - Checks relationship integrity
   - Ensures bidirectional relationships

### Custom Validation Rules

Open Register supports custom validation rules through PHP classes. These rules can be defined in your schema:

```json
{
  "properties": {
    "age": {
      "type": "integer",
      "minimum": 0,
      "maximum": 150,
      "customValidation": {
        "class": "OCA\\OpenRegister\\Validation\\AgeValidator",
        "method": "validate"
      }
    }
  }
}
```

### Validation Process

1. **Pre-validation**
   - Schema structure validation
   - Custom rule registration
   - Relationship validation setup

2. **Data Validation**
   - Type checking
   - Required field verification
   - Constraint validation
   - Custom rule execution

3. **Post-validation**
   - Relationship integrity check
   - Cross-field validation
   - Business rule validation

### Error Handling

The validation system provides detailed error messages:

```json
{
  "valid": false,
  "errors": [
    {
      "field": "email",
      "message": "Invalid email format",
      "code": "INVALID_EMAIL"
    },
    {
      "field": "age",
      "message": "Age must be between 0 and 150",
      "code": "INVALID_AGE"
    }
  ]
}
```

### Best Practices

1. **Validation Design**
   - Define clear validation rules
   - Use appropriate constraints
   - Consider performance impact
   - Document custom rules

2. **Error Messages**
   - Provide clear error descriptions
   - Include helpful suggestions
   - Use consistent error codes
   - Support multiple languages

3. **Performance**
   - Optimize validation rules
   - Cache validation results
   - Batch validate when possible
   - Monitor validation times

### Example Schema with Validation

```json
{
  "title": "Person",
  "version": "1.0.0",
  "required": ["firstName", "lastName", "email", "age"],
  "properties": {
    "firstName": {
      "type": "string",
      "minLength": 2,
      "maxLength": 50,
      "pattern": "^[A-Za-z\\s-]+$",
      "description": "Person's first name"
    },
    "lastName": {
      "type": "string",
      "minLength": 2,
      "maxLength": 50,
      "pattern": "^[A-Za-z\\s-]+$",
      "description": "Person's last name"
    },
    "email": {
      "type": "string",
      "format": "email",
      "description": "Person's email address"
    },
    "age": {
      "type": "integer",
      "minimum": 0,
      "maximum": 150,
      "description": "Person's age"
    },
    "phoneNumbers": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["type", "number"],
        "properties": {
          "type": {
            "type": "string",
            "enum": ["home", "work", "mobile"]
          },
          "number": {
            "type": "string",
            "pattern": "^\\+?[1-9]\\d{1,14}$"
          }
        }
      }
    }
  }
}
```

## Property Structure

Before diving into schema examples, let's understand the key components of a property definition. These components are primarily derived from JSON Schema specifications (see [JSON Schema Validation](https://json-schema.org/draft/2020-12/json-schema-validation.html)) with some additional extensions required for storage and validation purposes:

| Property | Description | Example |
|----------|-------------|---------|
| [`type`](https://json-schema.org/understanding-json-schema/reference/type#type-specific-keywords) | Data type of the property (string, number, boolean, object, array) | `"type": "string"` |
| [`description`](https://json-schema.org/understanding-json-schema/keywords#description) | Human-readable explanation of the property's purpose | `"description": "Person's full name"` |
| [`format`](https://json-schema.org/understanding-json-schema/reference/type#format) | Specific for the type (date, email, uri, etc) | `"format": "date-time"` |
| `pattern` | Regular expression pattern the value must match | `"pattern": "^[A-Z][a-z]+$"` |
| `enum` | Array of allowed values | `"enum": ["active", "inactive"]` |
| `minimum`/`maximum` | Numeric range constraints | `"minimum": 0, "maximum": 100` |
| `minLength`/`maxLength` | String length constraints | `"minLength": 3, "maxLength": 50` |
| `required` | Whether the property is mandatory | `"required": true` |
| `default` | Default value if none provided | `"default": "pending"` |
| `examples` | Sample valid values | `"examples": ["John Smith"]` |

Properties can also have nested objects and arrays with their own validation rules, allowing for complex data structures while maintaining strict validation. See the [Nesting schema's](#nesting-schemas) section below for more details.

### File Properties

File properties allow you to attach files directly to specific object properties with validation and automatic processing. File properties support both single files and arrays of files.

#### Basic File Property

```json
{
  'properties': {
    'avatar': {
      'type': 'file',
      'description': 'User profile picture',
      'allowedTypes': ['image/jpeg', 'image/png', 'image/gif'],
      'maxSize': 2097152,
      'autoTags': ['profile-image', 'auto-uploaded']
    }
  }
}
```

#### Array of Files Property

```json
{
  'properties': {
    'attachments': {
      'type': 'array',
      'description': 'Document attachments',
      'items': {
        'type': 'file',
        'allowedTypes': ['application/pdf', 'image/jpeg', 'image/png'],
        'maxSize': 10485760,
        'allowedTags': ['document', 'attachment'],
        'autoTags': ['auto-uploaded', 'property-attachments']
      }
    }
  }
}
```

#### File Property Configuration

| Property | Type | Description | Example |
|----------|------|-------------|---------|
| 'allowedTypes' | array | Array of allowed MIME types | '['image/jpeg', 'image/png']' |
| 'maxSize' | integer | Maximum file size in bytes | '5242880' (5MB) |
| 'allowedTags' | array | Tags that are allowed on files | '['document', 'public']' |
| 'autoTags' | array | Tags automatically applied to uploaded files | '['auto-uploaded', 'property-{propertyName}']' |

#### File Input Types

File properties support three types of input:

1. **Base64 Data URIs**: 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAAA...'
2. **URLs**: 'https://example.com/image.jpg' (system fetches the file)
3. **File Objects**: '{id: '12345', title: 'image.jpg', downloadUrl: '...'}'

#### File Property Processing

When objects are saved:

1. **File Detection**: System detects file data (base64, URLs, or file objects) in file properties
2. **File Processing**: 
   - Base64: Decoded and validated
   - URLs: Fetched from remote source
   - File Objects: Validated against existing files
3. **Validation**: Files are validated against 'allowedTypes' and 'maxSize' constraints
4. **File Creation**: Files are created and stored in the object's folder
5. **Auto Tagging**: 'autoTags' are automatically applied to files
6. **ID Storage**: File IDs replace file content in the object data

When objects are rendered:

1. **ID Detection**: System detects file IDs in file properties
2. **File Hydration**: File IDs are replaced with complete file objects
3. **Metadata**: File objects include path, size, type, tags, and access URLs

#### Auto Tag Placeholders

Auto tags support placeholder replacement:

| Placeholder | Replacement | Example |
|-------------|-------------|---------|
| '{property}' or '{propertyName}' | Property name | 'property-avatar' |
| '{index}' | Array index (for array properties) | 'file-0', 'file-1' |

#### File Upload Examples

**Base64 Input:**
```json
// Input object with base64 file data
{
  'name': 'John Doe',
  'avatar': 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAAA...',
  'documents': [
    'data:application/pdf;base64,JVBERi0xLjQKJcOkw6k...',
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...'
  ]
}
```

**URL Input:**
```json
// Input object with URLs (system fetches files)
{
  'name': 'Jane Smith',
  'avatar': 'https://example.com/avatars/jane.jpg',
  'documents': [
    'https://example.com/docs/resume.pdf',
    'https://example.com/certificates/cert.png'
  ]
}
```

**File Object Input:**
```json
// Input object with existing file objects
{
  'name': 'Bob Wilson',
  'avatar': {
    'id': '12345',
    'title': 'profile.jpg',
    'downloadUrl': 'https://example.com/s/AbCdEfGh/download'
  },
  'documents': [
    {
      'id': '12346',
      'title': 'document1.pdf',
      'downloadUrl': 'https://example.com/s/XyZwVuTs/download'
    }
  ]
}
```

**All Input Types - Final Storage:**

// Stored object with file IDs
{
  'name': 'John Doe',
  'avatar': 12345,
  'documents': [12346, 12347]
}

// Rendered object with file objects
{
  'name': 'John Doe',
  'avatar': {
    'id': '12345',
    'title': 'avatar_1640995200.jpg',
    'type': 'image/jpeg',
    'size': 15420,
    'accessUrl': 'https://example.com/s/AbCdEfGh',
    'downloadUrl': 'https://example.com/s/AbCdEfGh/download',
    'labels': ['profile-image', 'auto-uploaded']
  },
  'documents': [
    {
      'id': '12346',
      'title': 'documents_0_1640995200.pdf',
      'type': 'application/pdf',
      'size': 245760,
      'accessUrl': 'https://example.com/s/XyZwVuTs',
      'labels': ['auto-uploaded', 'property-documents']
    },
    {
      'id': '12347',
      'title': 'documents_1_1640995200.png',
      'type': 'image/png',
      'size': 89123,
      'accessUrl': 'https://example.com/s/MnOpQrSt',
      'labels': ['auto-uploaded', 'property-documents']
    }
  ]
}
```

#### Best Practices

1. **MIME Type Validation**: Always specify 'allowedTypes' to prevent unwanted file uploads
2. **Size Limits**: Set appropriate 'maxSize' limits to prevent storage abuse
3. **Auto Tags**: Use descriptive auto tags for better file organization
4. **Property Names**: Use clear property names that indicate file purpose
5. **Array Usage**: Use array properties for multiple files of the same type

## Example Schema

```json
{
  "id": "person",
  "title": "Person",
  "version": "1.0.0",
  "description": "Schema for representing a person with basic information",
  "summary": "Basic person information",
  "required": ["firstName", "lastName", "birthDate"],
  "properties": {
    "firstName": {
      "type": "string",
      "description": "Person's first name"
    },
    "lastName": {
      "type": "string",
      "description": "Person's last name"
    },
    "birthDate": {
      "type": "string",
      "format": "date",
      "description": "Person's date of birth in ISO 8601 format"
    },
    "email": {
      "type": "string",
      "format": "email",
      "description": "Person's email address"
    },
    "address": {
      "type": "object",
      "description": "Person's address",
      "properties": {
        "street": { "type": "string" },
        "city": { "type": "string" },
        "postalCode": { "type": "string" },
        "country": { "type": "string" }
      }
    },
    "phoneNumbers": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "type": { 
            "type": "string",
            "enum": ["home", "work", "mobile"]
          },
          "number": { "type": "string" }
        }
      }
    }
  },
  "archive": {},
  "updated": "2023-04-20T11:25:00Z",
  "created": "2023-01-05T08:30:00Z"
}
```

## Schema Use Cases

Schemas serve multiple purposes in Open Register:

### 1. Data Validation

Schemas ensure that all data entering the system meets defined requirements, maintaining data quality and consistency.

### 2. Documentation

Schemas serve as self-documenting specifications for data structures, helping developers understand what data is available and how it's organized.

### 3. API Contract

Schemas define the contract between different systems, specifying what data can be exchanged and in what format.

### 4. UI Generation

Schemas can be used to automatically generate forms and other UI elements, ensuring that user interfaces align with data requirements.

## Working with Schemas

### Creating a Schema

To create a new schema, you define its structure and validation rules:

```json
POST /api/schemas
{
  "title": "Product",
  "version": "1.0.0",
  "description": "Schema for product information",
  "required": ["name", "sku", "price"],
  "properties": {
    "name": {
      "type": "string",
      "description": "Product name"
    },
    "sku": {
      "type": "string",
      "description": "Stock keeping unit"
    },
    "price": {
      "type": "number",
      "minimum": 0,
      "description": "Product price"
    },
    "description": {
      "type": "string",
      "description": "Product description"
    },
    "category": {
      "type": "string",
      "description": "Product category"
    }
  }
}
```

### Retrieving Schema Information

You can retrieve information about a specific schema:

```
GET /api/schemas/{id}
```

Or list all available schemas:

```
GET /api/schemas
```

### Updating a Schema

Schemas can be updated to add new fields, change validation rules, or fix issues:

```json
PUT /api/schemas/{id}
{
  "title": "Product",
  "version": "1.1.0",
  "description": "Schema for product information",
  "required": ["name", "sku", "price"],
  "properties": {
    "name": {
      "type": "string",
      "description": "Product name"
    },
    "sku": {
      "type": "string",
      "description": "Stock keeping unit"
    },
    "price": {
      "type": "number",
      "minimum": 0,
      "description": "Product price"
    },
    "description": {
      "type": "string",
      "description": "Product description"
    },
    "category": {
      "type": "string",
      "description": "Product category"
    },
    "tags": {
      "type": "array",
      "items": {
        "type": "string"
      },
      "description": "Product tags"
    }
  }
}
```
### Nesting schema's


### Schema Versioning

Open Register supports schema versioning to manage changes over time:

1. **Minor Updates**: Adding optional fields or relaxing constraints
2. **Major Updates**: Adding required fields, removing fields, or changing field types
3. **Archive**: Previous versions are stored in the schema's archive property

# Schema References and Object Cascading

OpenRegister supports schema references to enable reusable schema definitions and complex object relationships. This document explains how schema references work, how they are resolved, and how to configure object cascading.

## Reference Format

Schema references use the JSON Schema format: `#/components/schemas/[slug]`

### Examples

```json
{
  'type': 'object',
  'properties': {
    'person': {
      'type': 'object',
      '$ref': '#/components/schemas/Person'
    },
    'contacts': {
      'type': 'array',
      'items': {
        '$ref': '#/components/schemas/Contactgegevens'
      }
    }
  }
}
```

## Object Handling Configuration

OpenRegister provides different ways to handle nested objects through the `objectConfiguration.handling` property:

### 1. Nested Objects (`nested-object`)

Objects are stored as nested data within the parent object. This is the default behavior.

```json
{
  'contactgegevens': {
    'type': 'object',
    '$ref': '#/components/schemas/Contactgegevens',
    'objectConfiguration': {
      'handling': 'nested-object'
    }
  }
}
```

**Result**: The contactgegevens data is stored directly in the parent object.

### 2. Cascading Objects (`cascade`)

Objects are created as separate entities. There are two types of cascading behavior:

#### 2a. Cascading with `inversedBy` (Relational Cascading)

Creates separate entities with back-references to the parent object:

   ```json
   {
  'contactgegevens': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Contactgegevens',
      'inversedBy': 'organisatie'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '34'
    }
  }
}
```

**Result**: 
- Each contactgegevens object is created as a separate entity with the `organisatie` property set to the parent object's UUID
- The parent object's `contactgegevens` property becomes an empty array `[]`

#### 2b. Cascading without `inversedBy` (ID Storage Cascading)

Creates independent entities and stores their IDs in the parent object:

```json
{
  'contactgegevens': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Contactgegevens'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '34'
    }
  }
}
```

**Result**: 
- Each contactgegevens object is created as a separate, independent entity
- The parent object's `contactgegevens` property stores an array of the created objects' UUIDs: `['uuid1', 'uuid2']`

## Cascading Configuration

For objects to be cascaded (saved as separate entities), they must have:

1. **Schema Reference**: `$ref` property pointing to the target schema
2. **Object Configuration**: `objectConfiguration.handling` set to `'cascade'`
3. **Target Schema**: `objectConfiguration.schema` property specifying the schema ID for cascaded objects

### Optional Configuration

4. **Inverse Relationship**: `inversedBy` property for relational cascading (creates back-reference)
5. **Register**: `register` property (defaults to parent object's register)

### Cascading Types

- **With `inversedBy`**: Creates relational cascading where cascaded objects reference the parent
- **Without `inversedBy`**: Creates independent cascading where parent stores cascaded object IDs

### Single Object Cascading

#### With `inversedBy` (Relational)

```json
{
  'manager': {
    'type': 'object',
    '$ref': '#/components/schemas/Person',
    'inversedBy': 'managedOrganisation',
    'register': '6',
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '12'
    }
  }
}
```

**Result**: Manager object is created separately with `managedOrganisation` property set to parent UUID. Parent object's `manager` property becomes `null`.

#### Without `inversedBy` (ID Storage)

```json
{
  'manager': {
    'type': 'object',
    '$ref': '#/components/schemas/Person',
    'register': '6',
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '12'
       }
     }
   }
   ```

**Result**: Manager object is created independently. Parent object's `manager` property stores the manager's UUID as a string.

### Array Object Cascading

#### With `inversedBy` (Relational)

```json
{
  'employees': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Person',
      'inversedBy': 'employer'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '12'
    }
  }
}
```

**Result**: Each employee object is created separately with `employer` property set to parent UUID. Parent object's `employees` property becomes an empty array `[]`.

#### Without `inversedBy` (ID Storage)

   ```json
   {
  'employees': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Person'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '12'
       }
     }
   }
   ```

**Result**: Each employee object is created independently. Parent object's `employees` property stores an array of employee UUIDs: `['uuid1', 'uuid2', 'uuid3']`.

## Schema Resolution Process

Schema references are resolved through a **pre-processing approach** that happens before validation:

### 1. Schema Pre-processing

Before validation, the system:
- Scans the schema for `$ref` properties
- Resolves references to actual schema definitions
- Embeds the resolved schemas in place of references
- Creates union types for properties that support both objects and UUID references

### 2. Reference Resolution Order

The system resolves schema references in the following order:

1. **Direct ID/UUID**: `'34'`, `'21aab6e0-2177-4920-beb0-391492fed04b'`
2. **JSON Schema path**: `'#/components/schemas/Contactgegevens'`
3. **URL references**: `'http://example.com/api/schemas/34'`
4. **Slug references**: `'contactgegevens'` (case-insensitive)

For path and URL references, the system extracts the last path segment and matches it against schema slugs.

### 3. Union Type Creation

For cascading objects, the system creates union types that allow both:
- **Full nested object**: Complete object data
- **UUID reference**: String UUID pointing to an existing object

```json
{
  'type': ['object', 'string'],
  'properties': {
    // ... full schema properties
  },
  'pattern': '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
}
```

## Cascading Behavior

### Object Creation Flow

1. **Validation**: Objects are validated against the union type schema
2. **Sanitization**: Empty values are cleaned up
3. **Cascading**: Objects with `inversedBy` are extracted and saved separately
4. **Relationship Setting**: The `inversedBy` property is set to the parent object's UUID
5. **Separate Storage**: Cascaded objects are saved as independent entities
6. **Parent Update**: Cascaded objects are removed from the parent object's data

### Example Flow

Input data:
```json
{
  'naam': 'Test Organization',
  'contactgegevens': [
    {
      'email': 'contact@example.com',
      'telefoon': '123-456-7890',
      'voornaam': 'John',
      'achternaam': 'Doe'
    }
  ]
}
```

Result:
1. **Parent object** (organisatie): Stored with `contactgegevens: []`
2. **Cascaded object** (contactgegevens): Stored separately with `organisatie: 'parent-uuid'`

## Configuration Examples

### E-commerce Product with Reviews

```json
{
  'reviews': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Review',
      'inversedBy': 'product'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '25'
    }
  }
}
```

### Organization with Departments

```json
{
  'departments': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Department',
      'inversedBy': 'organization',
      'register': '6'
    },
    'objectConfiguration': {
      'handling': 'cascade'
    }
  }
}
```

### Project with Tasks

```json
{
  'tasks': {
    'type': 'array',
    'items': {
      'type': 'object',
      '$ref': '#/components/schemas/Task',
      'inversedBy': 'project'
    },
    'objectConfiguration': {
      'handling': 'cascade',
      'schema': '18'
    }
  }
}
```

## Best Practices

### When to Use Cascading

Use cascading when:
- Objects have independent lifecycle management
- You need to query/filter child objects separately
- Child objects may be referenced by multiple parents
- You want to maintain referential integrity

### When to Use Nested Objects

Use nested objects when:
- Data is simple and doesn't need independent management
- Objects are tightly coupled to their parent
- Performance is critical (fewer database queries)
- You don't need to query child objects separately

### Configuration Tips

1. **Always specify `inversedBy`** for cascading relationships
2. **Use descriptive relationship names** that make sense from the child's perspective
3. **Consider register boundaries** - cascaded objects can be in different registers
4. **Test with both object and UUID inputs** to ensure union types work correctly
5. **Document your schema relationships** for other developers

## Troubleshooting

### Common Issues

1. **Objects not cascading**: Check that both `$ref` and `inversedBy` are present
2. **Validation errors**: Ensure the referenced schema exists and is accessible
3. **Missing relationships**: Verify the `inversedBy` property name matches the target schema
4. **Performance issues**: Consider using nested objects for simple, non-queryable data

### Debug Tips

1. Check the schema resolution logs for reference resolution issues
2. Verify that the target schema exists and has the expected `inversedBy` property
3. Test with simple object data first before complex nested structures
4. Use the API to inspect created objects and verify relationships

---

# Two-Way Relationships with writeBack

OpenRegister supports **two-way relationships** that automatically maintain bidirectional references between objects. This feature allows you to create relationships where both objects reference each other, ensuring data consistency and enabling efficient queries from either direction.

## Understanding Two-Way Relationships

### Key Concepts

- **`inversedBy`**: Declarative property that defines the relationship direction ("referenced objects have a property that points back to me")
- **`writeBack`**: Action property that triggers the actual update ("when I set this property, update the referenced objects' reverse property")
- **`removeAfterWriteBack`**: Optional property that removes the source property after the write-back is complete

### Relationship Flow

```mermaid
graph TD
    A[Samenwerking Object] -->|deelnemers: [org1, org2]| B[Organization 1]
    A -->|deelnemers: [org1, org2]| C[Organization 2]
    B -->|deelnames: [samenwerking]| A
    C -->|deelnames: [samenwerking]| A
    
    subgraph "Write-Back Process"
        D[Create Samenwerking] --> E[Process deelnemers]
        E --> F[Find Organizations]
        F --> G[Update deelnames on each Organization]
        G --> H[Remove deelnemers from Samenwerking]
    end
```

## Schema Configuration

### Basic Two-Way Relationship

```json
{
  "deelnemers": {
    "type": "array",
    "title": "Deelnemers",
    "description": "Organisaties die deelnemen aan deze community",
    "items": {
      "type": "object",
      "objectConfiguration": {"handling": "related-object"},
      "$ref": "#/components/schemas/organisatie",
      "inversedBy": "deelnames",
      "writeBack": true,
      "removeAfterWriteBack": true
    }
  }
}
```

### Configuration Properties

| Property | Type | Description | Example |
|----------|------|-------------|---------|
| `inversedBy` | string | Name of the property on the referenced object that points back | `"deelnames"` |
| `writeBack` | boolean | Enables automatic update of the reverse relationship | `true` |
| `removeAfterWriteBack` | boolean | Removes the source property after write-back (optional) | `true` |

## Implementation Details

### How It Works

1. **Object Creation**: When a samenwerking is created with `deelnemers`
2. **Property Detection**: System detects properties with `writeBack: true`
3. **Target Resolution**: Finds the referenced organizations using UUIDs
4. **Reverse Update**: Updates each organization's `deelnames` property
5. **Cleanup**: Optionally removes the `deelnemers` property from the samenwerking

### Processing Order

The write-back process happens **before** cascading operations to ensure the source data is available:

1. **Sanitization**: Clean empty values
2. **Write-Back**: Process inverse relationships
3. **Cascading**: Handle object cascading
4. **Default Values**: Set any default values

### UUID Validation

The system validates UUIDs using a strict regex pattern:
```
^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$
```

This ensures only valid UUIDs are processed for write-back operations.

## Example: Samenwerking and Organizations

### Samenwerking Schema (Source)

```json
{
  "properties": {
    "naam": {
      "type": "string",
      "title": "Naam",
      "description": "Naam van de samenwerking"
    },
    "deelnemers": {
      "type": "array",
      "title": "Deelnemers",
      "description": "Organisaties die deelnemen aan deze community",
      "items": {
        "type": "object",
        "objectConfiguration": {"handling": "related-object"},
        "$ref": "#/components/schemas/organisatie",
        "inversedBy": "deelnames",
        "writeBack": true,
        "removeAfterWriteBack": true
      }
    }
  }
}
```

### Organization Schema (Target)

```json
{
  "properties": {
    "naam": {
      "type": "string",
      "title": "Naam",
      "description": "Naam van de organisatie"
    },
    "deelnames": {
      "type": "array",
      "title": "Deelnames",
      "description": "UUIDs van communities waar deze organisatie aan deelneemt",
      "items": {
        "type": "string",
        "format": "uuid"
      }
    }
  }
}
```

## API Usage

### Creating a Samenwerking

```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  -H 'Content-Type: application/json' \
  -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
  -d '{
    "naam": "Test Samenwerking",
    "website": "https://samenwerking.nl",
    "type": "Samenwerking",
    "deelnemers": ["13382394-13cf-4f59-93ae-4c4e4998543f"]
  }'
```

**Response**:
```json
{
  "id": "9ee70e18-1852-4321-a70a-dff29c604aaa",
  "naam": "Test Samenwerking",
  "website": "https://samenwerking.nl",
  "type": "Samenwerking",
  "deelnemers": [],
  "@self": {
    "id": "9ee70e18-1852-4321-a70a-dff29c604aaa",
    "name": "Test Samenwerking",
    "description": 2806,
    "uri": "http://localhost/index.php/apps/openregister/api/objects/voorzieningen/organisatie/9ee70e18-1852-4321-a70a-dff29c604aaa",
    "register": "6",
    "schema": "35"
  }
}
```

### Checking the Organization

```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/objects/6/35/13382394-13cf-4f59-93ae-4c4e4998543f'
```

**Response**:
```json
{
  "id": "13382394-13cf-4f59-93ae-4c4e4998543f",
  "naam": "Test Organisatie 1",
  "website": "https://test1.nl",
  "type": "Leverancier",
  "deelnames": ["9ee70e18-1852-4321-a70a-dff29c604aaa"],
  "deelnemers": [],
  "@self": {
    "id": "13382394-13cf-4f59-93ae-4c4e4998543f",
    "name": "Test Organisatie 1",
    "updated": "2025-07-14T20:14:46+00:00"
  }
}
```

## Best Practices

### When to Use Two-Way Relationships

Use two-way relationships when:
- You need to query relationships from both directions
- Data consistency is critical
- You want to maintain referential integrity
- Performance benefits from bidirectional queries

### Configuration Guidelines

1. **Clear Property Names**: Use descriptive names for both sides of the relationship
2. **Consistent Data Types**: Ensure both sides use compatible data types
3. **UUID Validation**: Always use valid UUIDs for references
4. **Documentation**: Document the relationship purpose and behavior

### Performance Considerations

- Write-back operations add processing time to object creation
- Consider the impact on bulk operations
- Monitor database performance with large relationship sets
- Use `removeAfterWriteBack` to reduce storage overhead

## Default Values Configuration

Open Register provides enhanced default value functionality that allows you to configure how and when default values are applied to object properties.

### Basic Default Values

Default values can be set for any property type to provide fallback values when data is not provided:

```json
{
  'properties': {
    'status': {
      'type': 'string',
      'default': 'pending',
      'description': 'Object status'
    },
    'priority': {
      'type': 'integer',
      'default': 1,
      'description': 'Priority level'
    },
    'active': {
      'type': 'boolean',
      'default': true,
      'description': 'Whether object is active'
    }
  }
}
```

### Array Default Values

For array properties with string items, you can set default values that will be applied as arrays:

```json
{
  'properties': {
    'tags': {
      'type': 'array',
      'items': {
        'type': 'string'
      },
      'default': ['general', 'untagged'],
      'description': 'Default tags applied to objects'
    },
    'categories': {
      'type': 'array',
      'items': {
        'type': 'string'
      },
      'default': ['uncategorized'],
      'description': 'Object categories'
    }
  }
}
```

### Object Default Values

Object properties can have default JSON values:

```json
{
  'properties': {
    'metadata': {
      'type': 'object',
      'default': {
        'version': '1.0',
        'author': 'system',
        'created': true
      },
      'description': 'Default metadata object'
    }
  }
}
```

### Default Behavior Configuration

Open Register supports two modes for applying default values via the `defaultBehavior` property:

#### Mode 1: 'false' (Default Behavior)

Default values are only applied when the property is **missing** or **null**:

```json
{
  'properties': {
    'status': {
      'type': 'string',
      'default': 'pending',
      'defaultBehavior': 'false'
    }
  }
}
```

**Application Logic:**
- Property not provided → Apply default
- Property is `null` → Apply default  
- Property is empty string `''` → Keep empty string
- Property has value → Keep existing value

#### Mode 2: 'falsy' (Enhanced Behavior)

Default values are applied when the property is **missing**, **null**, **empty string**, or **empty array/object**:

```json
{
  'properties': {
    'description': {
      'type': 'string',
      'default': 'No description provided',
      'defaultBehavior': 'falsy'
    },
    'tags': {
      'type': 'array',
      'items': { 'type': 'string' },
      'default': ['untagged'],
      'defaultBehavior': 'falsy'
    }
  }
}
```

**Application Logic:**
- Property not provided → Apply default
- Property is `null` → Apply default
- Property is empty string `''` → Apply default
- Property is empty array `[]` → Apply default
- Property is empty object `{}` → Apply default
- Property has meaningful value → Keep existing value

### Practical Use Cases

#### Preventing Empty Values

Use `defaultBehavior: 'falsy'` when you want to ensure properties always have meaningful values:

```json
{
  'properties': {
    'title': {
      'type': 'string',
      'default': 'Untitled',
      'defaultBehavior': 'falsy',
      'description': 'Ensures every object has a title'
    }
  }
}
```

#### Optional Fields with Fallbacks

Use `defaultBehavior: 'false'` when empty values should be preserved but missing values need defaults:

```json
{
  'properties': {
    'notes': {
      'type': 'string',
      'default': 'No notes',
      'defaultBehavior': 'false',
      'description': 'User can intentionally leave empty'
    }
  }
}
```

### Template Support

Default values support Twig templating for dynamic defaults:

```json
{
  'properties': {
    'created_by': {
      'type': 'string',
      'default': '{{ user.name }}',
      'description': 'Auto-filled with current user'
    },
    'reference': {
      'type': 'string', 
      'default': 'REF-{{ uuid }}',
      'description': 'Generated reference number'
    }
  }
}
```

### Processing Order

Default values are applied during object saving in this order:

1. **Sanitization**: Clean empty values based on schema
2. **Write-Back**: Process inverse relationships  
3. **Cascading**: Handle object cascading
4. **Default Values**: Apply defaults based on behavior configuration
5. **Constants**: Apply constant values (always override)

### Frontend Configuration

In the OpenRegister frontend, default values can be configured through the schema editor:

1. **Basic Defaults**: Set default values for string, number, boolean properties
2. **Array Defaults**: Comma-separated values for string arrays  
3. **Object Defaults**: JSON object notation for object properties
4. **Behavior Toggle**: Choose between 'false' and 'falsy' behavior modes

The behavior toggle appears when a default value is set and shows helpful hints about when defaults will be applied.

## Troubleshooting

### Common Issues

1. **Write-back not working**: Check that both `inversedBy` and `writeBack` are configured
2. **UUID validation errors**: Ensure UUIDs match the expected format
3. **Missing reverse properties**: Verify the target schema has the expected property
4. **Performance issues**: Consider the number of relationships being processed

### Debug Steps

1. Check the debug logs for write-back processing
2. Verify schema configuration is correct
3. Test with simple UUIDs first
4. Inspect both objects to confirm the relationship

### Recent Fixes

#### UUID Regex Fix (v1.0.0)
**Issue**: UUID validation regex was incorrectly configured for the third group.
**Fix**: Updated regex from `[0-9a-f]{3}` to `[0-9a-f]{4}` for the third UUID group.
**Impact**: Enables proper UUID validation for write-back operations.

#### Cascade Integration Fix (v1.0.0)
**Issue**: Properties with `writeBack` were being removed by cascade operations before write-back processing.
**Fix**: Modified cascade logic to skip properties with `writeBack` enabled.
**Impact**: Ensures write-back operations receive the correct data for processing. 