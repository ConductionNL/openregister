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

### Schema Relationships

Open Register supports two powerful relationship mechanisms: inversion and revertedBy. These features enable complex data modeling and version control capabilities.

#### Inversion

Inversion is a powerful feature that enables bidirectional relationships between objects. When you define an inverse relationship in a schema, changes in one object automatically propagate to related objects, maintaining data consistency across your system.

**Key Features of Inversion:**

1. **Bidirectional Updates**
   - Changes in the source object reflect in the target object
   - Updates are automatically synchronized
   - Maintains referential integrity

2. **Schema Definition**
   ```json
   {
     "properties": {
       "manager": {
         "type": "object",
         "inversedBy": "subordinates",
         "description": "The person's manager"
       },
       "subordinates": {
         "type": "array",
         "items": {
           "type": "object"
         },
         "description": "People reporting to this person"
       }
     }
   }
   ```

3. **Use Cases**
   - Parent-child relationships
   - Manager-subordinate hierarchies
   - Document-revision chains
   - Project-task dependencies

4. **Benefits**
   - Automatic relationship maintenance
   - Reduced manual synchronization
   - Improved data consistency
   - Simplified relationship management

#### RevertedBy

The revertedBy property is a crucial part of Open Register's version control system. It enables tracking of object reversions, allowing you to maintain a complete history of changes and their reversions.

**Key Features of RevertedBy:**

1. **Version Control**
   - Tracks which object this version was reverted from
   - Maintains reversion history
   - Enables rollback capabilities

2. **Schema Definition**
   ```json
   {
     "properties": {
       "revertedBy": {
         "type": "string",
         "description": "UUID of the object this version was reverted from",
         "format": "uuid"
       }
     }
   }
   ```

3. **Use Cases**
   - Undoing changes
   - Restoring previous versions
   - Audit trail maintenance
   - Compliance requirements

4. **Benefits**
   - Complete change history
   - Audit trail preservation
   - Compliance support
   - Data recovery options

### Schema Import & Sharing

Open Register provides powerful schema import capabilities, allowing organizations to leverage existing standards and share their own schemas through Open Catalogi.

## Overview

The schema system supports importing from:
- Schema.org definitions
- OpenAPI Specification (OAS) files
- Gemeentelijk Gegevensmodel (GGM)
- Open Catalogi
- Custom JSON Schema files

## Import Sources

### Schema.org
- Import standard web vocabularies
- Use established data structures
- Benefit from widespread adoption
- Maintain semantic compatibility

### OpenAPI Specification
- Import API definitions
- Reuse existing data models
- Maintain API compatibility
- Leverage API documentation

### Gemeentelijk Gegevensmodel (GGM)
- Import Dutch municipal data models
- Comply with government standards
- Ensure data compatibility
- Support Common Ground principles

### Open Catalogi
- Share schemas between organizations
- Import from central repositories
- Collaborate on definitions
- Version control schemas

## Schema Sharing

Organizations can share their schemas through Open Catalogi:
- Publish schemas publicly
- Version control
- Collaborative development
- Change management
- Documentation
- Usage statistics

## Key Benefits

1. **Standardization**
   - Reuse existing standards
   - Ensure compatibility
   - Reduce development time
   - Share best practices

2. **Collaboration**
   - Share schemas
   - Collaborate on definitions
   - Build on existing work
   - Community involvement

3. **Maintenance**
   - Central updates
   - Version management
   - Change tracking
   - Documentation

## Schema Design Best Practices

1. **Start Simple**: Begin with the minimum required fields and add complexity as needed
2. **Use Clear Names**: Choose descriptive property names that reflect their purpose
3. **Add Descriptions**: Document each property with clear descriptions
4. **Consider Validation**: Add appropriate validation rules to ensure data quality
5. **Think About Relationships**: Design schemas with relationships in mind
6. **Plan for Evolution**: Design schemas to accommodate future changes
7. **Reuse Common Patterns**: Create reusable components for common data structures

## Relationship to Other Concepts

- **Registers**: Registers specify which schemas they support
- **Objects**: Objects must conform to a schema to be valid
- **Validation**: The validation engine uses schemas to validate objects

## Conclusion

Schemas are the foundation of data quality in Open Register. By defining clear, consistent structures for your data, you ensure that all information in the system meets your requirements and can be reliably used across different applications and processes. 