---
title: Objects
sidebar_position: 4
---

# Objects

## What is an Object?

In Open Register, an **Object** is an instance of data that conforms to a specific schema and is stored in a register. Objects are the actual content of your data management system - they represent real-world entities like people, products, locations, or events.

Each object:
- Has a unique identifier
- Conforms to a schema that defines its structure
- Belongs to a register that organizes related objects
- Can have relationships with other objects
- Can have attached files
- Maintains version history

## Object Structure

An object in Open Register consists of the following key components:

| Property | Description |
|----------|-------------|
| `id` | Unique identifier for the object |
| `uuid` | Universally unique identifier |
| `uri` | URI to access this object |
| `register` | Register identifier |
| `schema` | Schema identifier |
| `object` | Object data (JSON string) |
| `relations` | Relations data (JSON string) |
| `files` | Files data (JSON string) |
| `folder` | Folder path |
| `updated` | Timestamp of last update |
| `created` | Timestamp of creation |
| `locked` | Array of lock tokens or null if not locked |
| `owner` | Owner of the object |

## Example Object

```json
{
  "id": "person-12345",
  "uuid": "123e4567-e89b-12d3-a456-426614174000",
  "uri": "/api/objects/person-12345",
  "register": "person-register",
  "schema": "person",
  "object": "{\"firstName\":\"John\",\"lastName\":\"Doe\",\"birthDate\":\"1980-01-15\",\"email\":\"john.doe@example.com\",\"address\":{\"street\":\"123 Main St\",\"city\":\"Anytown\",\"postalCode\":\"12345\",\"country\":\"USA\"},\"phoneNumbers\":[{\"type\":\"mobile\",\"number\":\"555-123-4567\"}]}",
  "relations": "[{\"type\":\"spouse\",\"target\":\"person-67890\"}]",
  "files": "[{\"id\":\"file-12345\",\"name\":\"profile.jpg\",\"contentType\":\"image/jpeg\",\"size\":24680,\"url\":\"/api/files/file-12345\"}]",
  "folder": "/persons/john-doe",
  "updated": "2023-05-20T10:15:00Z",
  "created": "2023-02-15T14:30:00Z",
  "locked": null,
  "owner": "user-12345"
}
```

## Object Features

### 1. Schema Validation

All objects are validated against their schema before being stored, ensuring data quality and consistency.

### 2. Relationships

Objects can have relationships with other objects, creating a network of connected data. Relationships are stored in the `relations` property and can represent various types of connections:

```json
[
  {
    "type": "spouse",
    "target": "person-67890"
  },
  {
    "type": "employer",
    "target": "organization-12345"
  }
]
```

### 3. File Attachments

Objects can have files attached to them, such as documents, images, or other binary data. File metadata is stored in the `files` property:

```json
[
  {
    "id": "file-12345",
    "name": "profile.jpg",
    "contentType": "image/jpeg",
    "size": 24680,
    "url": "/api/files/file-12345"
  },
  {
    "id": "file-67890",
    "name": "resume.pdf",
    "contentType": "application/pdf",
    "size": 123456,
    "url": "/api/files/file-67890"
  }
]
```

### 4. Version History

Open Register maintains a complete history of changes to objects, allowing you to track modifications over time and revert to previous versions if needed. This version history powers the [Time Travel](#time-travel) feature, which enables you to:

- View an object as it existed at any point in time
- Compare different versions to see what changed
- Restore objects to previous states
- Analyze the evolution of data over time

### 5. Locking Mechanism

Objects can be locked to prevent concurrent editing, ensuring data integrity in multi-user environments.

## Working with Objects

### Creating an Object

To create a new object, you specify the register, schema, and object data:

```json
POST /api/objects
{
  "register": "person-register",
  "schema": "person",
  "object": {
    "firstName": "Jane",
    "lastName": "Smith",
    "birthDate": "1985-03-22",
    "email": "jane.smith@example.com",
    "address": {
      "street": "456 Oak Ave",
      "city": "Somewhere",
      "postalCode": "67890",
      "country": "USA"
    },
    "phoneNumbers": [
      {
        "type": "mobile",
        "number": "555-987-6543"
      }
    ]
  }
}
```

### Retrieving Object Information

You can retrieve information about a specific object:

```
GET /api/objects/{id}
```

Or list objects with filtering options:

```
GET /api/objects?register=person-register&schema=person
```

### Updating an Object

Objects can be updated to modify their data:

```json
PUT /api/objects/{id}
{
  "register": "person-register",
  "schema": "person",
  "object": {
    "firstName": "Jane",
    "lastName": "Smith-Johnson",
    "birthDate": "1985-03-22",
    "email": "jane.johnson@example.com",
    "address": {
      "street": "789 Pine St",
      "city": "Newtown",
      "postalCode": "54321",
      "country": "USA"
    },
    "phoneNumbers": [
      {
        "type": "mobile",
        "number": "555-987-6543"
      },
      {
        "type": "home",
        "number": "555-123-4567"
      }
    ]
  }
}
```

### Deleting an Object

Objects can be deleted when no longer needed:

```
DELETE /api/objects/{id}
```

### Working with Object Relationships

You can add relationships between objects:

```json
POST /api/objects/{id}/relations
{
  "type": "manager",
  "target": "person-54321"
}
```

And retrieve relationships for an object:

```
GET /api/objects/relations/{id}
```

### Working with Object Files

You can attach files to objects:

```
POST /api/objects/{id}/files
Content-Type: multipart/form-data

file: [binary data]
```

And retrieve files for an object:

```
GET /api/objects/files/{id}
```

### Locking and Unlocking Objects

To prevent concurrent editing, you can lock an object:

```
POST /api/objects/{id}/lock
```

And unlock it when finished:

```json
POST /api/objects/{id}/unlock
{
  "lockToken": "lock-token-12345"
}
```

## Object Audit Trails

Open Register maintains audit trails for objects, tracking who made changes, when they were made, and what was changed:

```
GET /api/objects/audit-trails/{id}
```

Example audit trail entry:

```json
{
  "id": "audit-12345",
  "uuid": "123e4567-e89b-12d3-a456-426614174001",
  "schema": 1,
  "register": 1,
  "object": 1,
  "action": "update",
  "changed": "{\"lastName\":{\"old\":\"Smith\",\"new\":\"Smith-Johnson\"},\"email\":{\"old\":\"jane.smith@example.com\",\"new\":\"jane.johnson@example.com\"},\"address\":{\"old\":{\"street\":\"456 Oak Ave\",\"city\":\"Somewhere\",\"postalCode\":\"67890\",\"country\":\"USA\"},\"new\":{\"street\":\"789 Pine St\",\"city\":\"Newtown\",\"postalCode\":\"54321\",\"country\":\"USA\"}}}",
  "user": "user-67890",
  "userName": "Admin User",
  "session": "session-12345",
  "request": "request-67890",
  "ipAddress": "192.168.1.1",
  "version": "1.1",
  "created": "2023-05-21T09:30:00Z"
}
```

## Time Travel

Time Travel in Open Register allows you to view and restore objects to any previous state in their history. This powerful feature enables data recovery, audit compliance, and historical analysis.

### Viewing Object History

You can retrieve the version history of an object:

```
GET /api/objects/{id}/history
```

Example response:

```json
{
  "id": "person-12345",
  "versions": [
    {
      "version": "1.0",
      "timestamp": "2023-02-15T14:30:00Z",
      "user": "user-12345",
      "action": "create"
    },
    {
      "version": "1.1",
      "timestamp": "2023-03-10T09:45:00Z",
      "user": "user-12345",
      "action": "update"
    },
    {
      "version": "1.2",
      "timestamp": "2023-05-20T10:15:00Z",
      "user": "user-67890",
      "action": "update"
    }
  ]
}
```

### Retrieving a Specific Version

You can retrieve a specific version of an object:

```
GET /api/objects/{id}/versions/{version}
```

Or retrieve an object as it existed at a specific point in time:

```
GET /api/objects/{id}/at/{timestamp}
```

Example response:

```json
{
  "id": "person-12345",
  "uuid": "123e4567-e89b-12d3-a456-426614174000",
  "uri": "/api/objects/person-12345",
  "register": "person-register",
  "schema": "person",
  "object": "{\"firstName\":\"John\",\"lastName\":\"Doe\",\"birthDate\":\"1980-01-15\",\"email\":\"john.doe@example.com\",\"address\":{\"street\":\"123 Main St\",\"city\":\"Anytown\",\"postalCode\":\"12345\",\"country\":\"USA\"},\"phoneNumbers\":[{\"type\":\"mobile\",\"number\":\"555-123-4567\"}]}",
  "relations": "[{\"type\":\"spouse\",\"target\":\"person-67890\"}]",
  "files": "[{\"id\":\"file-12345\",\"name\":\"profile.jpg\",\"contentType\":\"image/jpeg\",\"size\":24680,\"url\":\"/api/files/file-12345\"}]",
  "folder": "/persons/john-doe",
  "updated": "2023-03-10T09:45:00Z",
  "created": "2023-02-15T14:30:00Z",
  "version": "1.1",
  "locked": null,
  "owner": "user-12345"
}
```

### Comparing Versions

You can compare two versions of an object to see what changed:

```
GET /api/objects/{id}/compare?version1=1.0&version2=1.2
```

Example response:

```json
{
  "id": "person-12345",
  "changes": {
    "lastName": {
      "old": "Doe",
      "new": "Smith-Johnson"
    },
    "email": {
      "old": "john.doe@example.com",
      "new": "jane.johnson@example.com"
    },
    "address": {
      "old": {
        "street": "123 Main St",
        "city": "Anytown",
        "postalCode": "12345",
        "country": "USA"
      },
      "new": {
        "street": "789 Pine St",
        "city": "Newtown",
        "postalCode": "54321",
        "country": "USA"
      }
    }
  }
}
```

### Restoring Previous Versions

You can restore an object to a previous version:

```json
POST /api/objects/{id}/restore
{
  "version": "1.1"
}
```

Or restore an object as it existed at a specific point in time:

```json
POST /api/objects/{id}/restore
{
  "timestamp": "2023-03-10T09:45:00Z"
}
```

### Key Benefits of Time Travel

1. **Data Recovery**
   - Recover from accidental changes
   - Restore deleted data
   - Fix incorrect updates

2. **Historical Analysis**
   - Review data evolution
   - Track business changes
   - Analyze decision points

3. **Compliance**
   - Meet regulatory requirements
   - Support audit processes
   - Maintain data lineage

## Best Practices

1. **Validate Before Submission**: Ensure objects conform to their schema before submission
2. **Use Meaningful IDs**: Create object IDs that reflect their content when possible
3. **Manage Relationships**: Use relationships to create connections between related objects
4. **Lock When Editing**: Use the locking mechanism to prevent concurrent edits
5. **Track Audit Trails**: Review audit trails to understand object history
6. **Organize with Folders**: Use the folder property to organize objects logically
7. **Leverage Time Travel**: Use the Time Travel feature for data recovery and historical analysis

## Relationship to Other Concepts

- **Registers**: Objects belong to registers that organize related data
- **Schemas**: Objects conform to schemas that define their structure
- **Sources**: Objects are stored in the data sources configured for their register
- **Time Travel**: Objects maintain version history that enables viewing and restoring previous states
- **Audit Trails**: Objects track all changes for compliance and historical analysis

## Conclusion

Objects are the heart of Open Register, representing the actual data you're managing. By combining structured data validation through schemas with features like relationships, file attachments, and version history, Open Register provides a comprehensive system for managing complex data in a consistent and reliable way. 