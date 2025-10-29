# Objects in OpenRegister

Objects are the core data entities in OpenRegister that store and manage structured information. Objects are defined by schemas and can contain relationships to other objects within the same register or across different registers.

## Schema Structure

Schemas define the structure, validation rules, and relationships for objects in OpenRegister. Each schema contains:

- **Properties**: Define the data fields and their types
- **Required fields**: Specify which properties are mandatory
- **Validation rules**: Define constraints and data formats
- **Relationships**: Define connections to other objects via 'inversedBy' properties
- **Configuration**: Define metadata behavior and file handling settings

### Schema Configuration

Schemas support various configuration options that control how objects are handled and displayed:

#### Object Metadata Fields
These configuration options define which object properties should be used for common metadata. All fields support **dot notation** for nested properties and **twig-like templates** for combining multiple fields.

- **objectNameField**: (string) Field path or template for the object's display name
  - Simple path: 'naam' or 'contact.naam'
  - Twig-like template: '{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}'
  - If not configured, the object's UUID will be used as the name
  
- **objectDescriptionField**: (string) Field path or template for the object's description
  - Simple path: 'beschrijving' or 'case.summary'
  - Template: '{{ type }}: {{ korte_beschrijving }}'
  - Used for object previews and search results
  
- **objectSummaryField**: (string) Field path or template for the object's summary
  - Simple path: 'beschrijvingKort' or 'samenvatting'
  - Template: '{{ categorie }} - {{ status }}'
  - Used for condensed object information
  
- **objectImageField**: (string) Field path for the object's image
  - Simple path: 'afbeelding' or 'profile.avatar'
  - Expected to contain base64 encoded image data or file references
  - Used for visual representation of objects in lists and details

- **objectSlugField**: (string) Field path for generating URL-friendly slugs
  - Simple path: 'naam' or 'title'
  - Value will be automatically converted to URL-friendly format
  - Used for creating readable URLs and identifiers

- **objectPublishedField**: (string) Field path for the publication date
  - Simple path: 'publicatieDatum' or 'published'
  - Supports various datetime formats (ISO 8601, MySQL datetime, etc.)
  - Controls when objects become publicly visible

- **objectDepublishedField**: (string) Field path for the depublication date
  - Simple path: 'einddatum' or 'depublished'
  - Supports various datetime formats
  - Controls when objects are no longer publicly visible

- **autoPublish**: (boolean) Automatically set published date on object creation
  - When set to 'true', objects will be automatically published (published date set to now) when created
  - Only applies to new objects - existing objects being updated are not affected
  - If an object already has a published date (from field mapping or explicit data), auto-publish is skipped
  - Works for both individual object creation and bulk imports
  - Default: 'false' (disabled)

##### Twig-like Template Syntax
For combining multiple fields, use the template syntax:

```json
{
  "objectNameField": "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}",
  "objectDescriptionField": "{{ type }}: {{ beschrijving }}"
}
```

- Use `{{ fieldName }}` to reference object properties
- Supports dot notation: `{{ contact.email }}`
- Empty/null values are handled gracefully
- Excess whitespace is automatically cleaned up

##### Example Configuration
```json
{
  "configuration": {
    "objectNameField": "{{ voornaam }} {{ achternaam }}",
    "objectDescriptionField": "beschrijving",
    "objectSummaryField": "beschrijvingKort", 
    "objectImageField": "afbeelding",
    "objectSlugField": "naam",
    "objectPublishedField": "publicatieDatum",
    "objectDepublishedField": "einddatum",
    "autoPublish": true
  }
}
```

#### File Handling Configuration
These options control how files are handled within the schema:

- **allowFiles**: (boolean) Whether objects of this schema can have file attachments
  - Default: false
  - When enabled, indicates this schema supports file uploads and management
  - Currently used for indication purposes (filtering not yet implemented)
  
- **allowedTags**: (array of strings) Specifies which file tags/types are allowed for this schema
  - Example: ['image', 'document', 'audio', 'video']
  - Used for categorizing and filtering file attachments
  - Tags can be custom strings to match your use case

#### Configuration Example
```json
{
  'configuration': {
    'objectNameField': 'person.fullName',
    'objectDescriptionField': 'person.bio', 
    'objectImageField': 'person.avatar',
    'allowFiles': true,
    'allowedTags': ['image', 'document', 'identity']
  }
}
```

#### Dot-Notation Paths
The objectNameField, objectDescriptionField, and objectImageField use dot-notation to access nested properties:

- **Simple property**: 'title' → accesses object.title
- **Nested property**: 'person.name' → accesses object.person.name  
- **Deep nesting**: 'contact.address.street' → accesses object.contact.address.street

If the specified field doesn't exist or contains invalid data, the system will gracefully fall back to default values.

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

## Role-Based Access Control (RBAC)

OpenRegister implements a comprehensive Role-Based Access Control system that allows you to restrict access to objects based on the schema's authorization configuration and the user's Nextcloud group membership.

### Authorization Structure

RBAC permissions are defined in the schema's 'authorization' property using a CRUD-based structure:

```json
{
  'authorization': {
    'create': ['editors', 'managers'],
    'read': ['viewers', 'editors', 'managers'], 
    'update': ['editors', 'managers'],
    'delete': ['managers']
  }
}
```

### Permission Rules

The RBAC system follows these fundamental rules:

1. **Open Access by Default**: If no authorization is configured, all users have full CRUD access
2. **Action-Level Control**: Permissions are granted per CRUD action (create, read, update, delete)
3. **Group-Based Access**: Users must belong to specified Nextcloud groups to perform actions
4. **Admin Override**: Users in the 'admin' group always have full access to all schemas
5. **Owner Privilege**: Object owners always have full access to their specific objects regardless of group restrictions

### Special Groups

#### Admin Group
- Always has full CRUD access to all schemas
- Cannot be restricted through authorization configuration  
- Represents system administrators with unrestricted access

#### Public Group
- Represents unauthenticated/anonymous access
- Can be explicitly granted permissions for public-facing schemas
- Useful for read-only public data or anonymous submissions

#### Object Owner
- The Nextcloud user who created or owns a specific object
- Has full CRUD access to their own objects regardless of schema authorization restrictions
- Different objects in the same schema can have different owners
- Object ownership cannot be overridden by group restrictions

### Permission Logic

The system evaluates permissions in this order:

1. **Admin Check**: If user is in 'admin' group → Allow all actions
2. **Owner Check**: If user is the object owner → Allow all actions on that specific object  
3. **Authorization Check**: If no authorization configured → Allow all actions
4. **Action Check**: If specific action not configured → Allow action
5. **Group Check**: If user's group is in authorized list → Allow action
6. **Default**: Deny action

### Configuration Examples

#### Fully Open Schema
```json
{
  'authorization': {}
}
```
*All users can perform all CRUD operations*

#### Read-Only Public Schema  
```json
{
  'authorization': {
    'create': ['editors'],
    'read': ['public'],
    'update': ['editors'],
    'delete': ['managers']
  }
}
```
*Anyone can read, only editors can create/update, only managers can delete*

#### Restricted Internal Schema
```json
{
  'authorization': {
    'create': ['staff'],
    'read': ['staff'],
    'update': ['staff'], 
    'delete': ['managers']
  }
}
```
*Only staff can access, only managers can delete*

#### Collaborative Schema
```json
{
  'authorization': {
    'create': ['contributors', 'editors'],
    'read': ['viewers', 'contributors', 'editors'],
    'update': ['editors'],
    'delete': ['editors']
  }
}
```
*Multiple groups with different permission levels*

### Query Filtering

When searching or listing objects, the RBAC system automatically filters results based on the user's read permissions:

- Objects from schemas with no read restrictions are always included
- Objects from restricted schemas are only included if the user has read permission
- Admin users see all objects regardless of restrictions
- Object owners see their own objects regardless of restrictions
- **Published objects** are accessible to everyone if their published date has passed and depublished date hasn't
- Filtering is applied at the database query level for optimal performance

### Publication-Based Public Access

OpenRegister supports automatic public access for published objects, regardless of schema authorization restrictions:

#### Publication Logic
Objects become publicly accessible when:
- The 'published' field is set to a date/time in the past or present
- The 'depublished' field is either null or set to a future date/time

#### Publication Examples
```json
{
  'name': 'Public Article',
  'content': 'This article is publicly accessible',
  'published': '2025-01-01T00:00:00+00:00',
  'depublished': null
}
```

```json
{
  'name': 'Temporary Public Content',
  'content': 'Available for limited time',
  'published': '2025-01-01T00:00:00+00:00',
  'depublished': '2025-12-31T23:59:59+00:00'
}
```

#### Use Cases
- **Public Documentation**: Make help articles accessible to all users
- **Announcements**: Publish news that everyone should see
- **Time-Limited Content**: Create content with automatic expiration dates
- **Progressive Disclosure**: Gradually release content based on publication schedules

### Best Practices

1. **Principle of Least Privilege**: Only grant necessary permissions to each group
2. **Clear Group Names**: Use descriptive group names that reflect their intended access level
3. **Regular Review**: Periodically review and update authorization configurations
4. **Test Thoroughly**: Verify permissions work as expected before deploying to production
5. **Document Decisions**: Maintain clear documentation about why specific permissions were granted

### API Error Responses

When RBAC blocks an unauthorized operation, the API returns consistent JSON error responses:

#### Successful Operation
```json
{
  'id': 'abc123',
  'name': 'My Object',
  'description': 'Created successfully'
}
```

#### Permission Denied
```json
{
  'error': 'User 'username' does not have permission to 'create' objects in schema 'Schema Name''
}
```

All RBAC-related errors return HTTP status code 403 (Forbidden) with descriptive error messages.

### Testing RBAC Permissions

#### Positive Testing
Verify users CAN perform authorized operations:
```bash
# Test authorized user can create
curl -u 'editor:password' -X POST '/api/objects/1/49' -d '{'name': 'Test'}'

# Expected: 200 OK with object data
```

#### Negative Testing  
Verify users CANNOT perform unauthorized operations:
```bash
# Test unauthorized user cannot create
curl -u 'viewer:password' -X POST '/api/objects/1/49' -d '{'name': 'Should Fail'}'

# Expected: 403 Forbidden with error message
```

#### Query Filtering Testing
Test that READ operations filter results appropriately:
```bash
# Authorized user sees objects
curl -u 'staff:password' '/api/objects/1/staff-schema'
# Expected: Array of accessible objects

# Unauthorized user sees empty results
curl -u 'guest:password' '/api/objects/1/staff-schema' 
# Expected: Empty results array
```

### Security Considerations

- RBAC permissions are enforced at the API level, not just the UI
- Direct database access bypasses RBAC controls
- Group membership changes in Nextcloud immediately affect OpenRegister permissions
- Schema modifications require appropriate permissions to prevent privilege escalation
- Always validate user permissions before performing any CRUD operations
- **Comprehensive Testing**: Test both positive (authorized) and negative (unauthorized) scenarios
- **JSON Responses**: All API errors return structured JSON, never HTML error pages