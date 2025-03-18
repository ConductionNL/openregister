---
title: Schemas
sidebar_position: 3
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

| Property | Description |
|----------|-------------|
| `id` | Unique identifier for the schema |
| `title` | Human-readable name of the schema |
| `version` | Schema version in semantic versioning format |
| `description` | Detailed explanation of what the schema represents |
| `summary` | Brief summary of the schema's purpose |
| `required` | Array of property names that are required |
| `properties` | Object defining the properties and their types |
| `archive` | Archive of previous schema versions |
| `updated` | Timestamp of last update |
| `created` | Timestamp of creation |

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