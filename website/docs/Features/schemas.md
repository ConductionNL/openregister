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