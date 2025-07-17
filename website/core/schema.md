# Objects in OpenRegister

Objects are the core data entities in OpenRegister that store and manage structured information. Objects are defined by schemas and can contain relationships to other objects within the same register or across different registers.

## Schema Structure

Schemas define the structure, validation rules, and relationships for objects in OpenRegister. Each schema contains:

- **Properties**: Define the data fields and their types
- **Required fields**: Specify which properties are mandatory
- **Validation rules**: Define constraints and data formats
- **Relationships**: Define connections to other objects via 'inversedBy' properties

## Creating Objects

### Simple Object Creation

To create a basic object, send a POST request to the register endpoint with the object data:

```json
{
  'title': 'My Organization',
  'description': 'A sample organization',
  'email': 'contact@example.com'
}
```

### Creating Objects with Nested Related Objects

OpenRegister supports creating objects with nested related objects in a single POST request. This is particularly useful for creating complex data structures where objects have relationships defined through 'inversedBy' properties.

#### Structure for Nested Objects

When a schema property has an 'inversedBy' relationship, you can provide related objects as nested arrays:

```json
{
  'title': 'My Organization',
  'description': 'A sample organization',
  'email': 'contact@example.com',
  'contactpersonen': [
    {
      'naam': 'John Doe',
      'email': 'john@example.com',
      'telefoon': '+1234567890'
    },
    {
      'naam': 'Jane Smith',
      'email': 'jane@example.com',
      'telefoon': '+0987654321'
    }
  ]
}
```

#### How Nested Object Creation Works

1. **Parent Object Processing**: The main object is validated and prepared for creation
2. **Relationship Detection**: Properties with 'inversedBy' relationships are identified
3. **Nested Object Creation**: Related objects are created first with proper back-references
4. **Context Preservation**: The parent object maintains its register/schema context throughout the process
5. **Final Creation**: The parent object is created with references to the related objects

#### Example: Organization with Contact Persons

Consider an organization schema with a 'contactpersonen' property that has 'inversedBy: "organisatie"':

**POST Request:**
```json
{
  'title': 'Acme Corporation',
  'description': 'Technology company',
  'website': 'https://acme.com',
  'contactpersonen': [
    {
      'naam': 'Alice Johnson',
      'email': 'alice@acme.com',
      'functie': 'CEO'
    },
    {
      'naam': 'Bob Wilson',
      'email': 'bob@acme.com',
      'functie': 'CTO'
    }
  ]
}
```

**What Happens:**
1. Two contact person objects are created in the contactgegevens schema
2. Each contact person gets a back-reference to the organization ('organisatie' property)
3. The organization is created with references to both contact persons
4. All objects are created in the correct register/schema context

## Relationship Handling

### InversedBy Properties

The 'inversedBy' property defines bidirectional relationships between objects:

- **Parent Object**: Contains an array of references to related objects
- **Related Objects**: Automatically get a back-reference to the parent object
- **Schema Mapping**: The system automatically determines the correct schema for related objects

### Context Preservation

OpenRegister ensures that:
- Parent objects are created in their intended register/schema
- Related objects are created in their appropriate register/schema
- Context is preserved even during complex nested operations
- Back-references are correctly established

## Validation and Error Handling

### Validation Modes

- **Hard Validation**: Strict validation according to schema rules
- **Soft Validation**: More lenient validation for flexibility

### Common Errors

- **Missing Required Properties**: Ensure all required fields are provided
- **Invalid Data Types**: Check that data matches schema property types
- **Relationship Errors**: Verify that related objects conform to their target schema
- **Context Errors**: Ensure objects are created in the correct register/schema

## Best Practices

1. **Plan Your Structure**: Design your schemas with clear relationships
2. **Use Nested Creation**: Create related objects together for data consistency
3. **Validate Early**: Test your object structure before production
4. **Monitor Creation**: Check that objects are created in correct contexts
5. **Handle Errors**: Implement proper error handling for failed creations

## API Endpoints

- **Create Object**: 'POST /api/registers/{registerId}/objects'
- **Update Object**: 'PUT /api/registers/{registerId}/objects/{objectId}'
- **Get Object**: 'GET /api/registers/{registerId}/objects/{objectId}'
- **Delete Object**: 'DELETE /api/registers/{registerId}/objects/{objectId}'

## Example Use Cases

### Customer Management
Create customers with multiple addresses and contact methods in a single request.

### Project Management
Create projects with associated team members, tasks, and resources.

### Content Management
Create articles with embedded media, tags, and author information.

### Inventory Management
Create products with variants, suppliers, and pricing information.

The nested object creation feature provides a powerful way to build complex data structures while maintaining data integrity and proper relationship management across your OpenRegister application.