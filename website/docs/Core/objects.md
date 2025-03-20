---
title: Objects
sidebar_position: 3
description: An overview of how core concepts in Open Register interact with each other.
keywords:
  - Open Register
  - Core Concepts
  - Relationships
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

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

All objects are stored as object entities, or json objects holding both the metadata and data of the actual object. These object entities are available trought the `objects api`. Object enties are a way of storing objects theym might be seen as an envelope for the actual object. When serialization happens the objects is changed to the actual object (and the envelope moved to @self see (metadata)[#metadata].

<Tabs>
  <TabItem value="stored_object" label="Specificaties" default>
    <ApiSchema id="open-register" example   pointer="#/components/schemas/ObjectEntity" />
  </TabItem>
  <TabItem value="serialized_object" label="Serialized Object" >
    <ApiSchema id="open-register" example   pointer="#/components/schemas/SerializeEntity" />
  </TabItem>
</Tabs>



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

### Serialisation


### Metadata
Open Register keeps tabs on the metadata of objects, it always keeps tabs on the following feelds wherte or not if they ara part of the object. This data is stored in object entity but transfered to the @self property when the objects is serialized.

<ApiSchema id="open-register" example pointer="#/components/schemas/@self" />

### 2. Relationships
Object Relations enable the creation and management of connections between objects, supporting complex data structures and relationships.

The relations system provides:
- Multiple relationship types
- Bi-directional relationships
- Relationship metadata
- Integrity management

## Key Benefits

1. **Data Organization**
   - Model complex relationships
   - Maintain data connections
   - Support hierarchical structures

2. **Data Integration**
   - Link related information
   - Create data networks
   - Support cross-referencing

3. **Process Management**
   - Track dependencies
   - Manage workflows
   - Support business processes

Objects can have relationships with other objects, creating a network of connected data. Relationships are stored in the `relations` property as an array where the keys are dot notations referring to properties in the object, and the values are references to external objects. References can be either an id, uuid, or URL to an extended object. In this way, the relationship metadata property forms a quick index of all the objects that an object is related to. For more information on dot notation, please refer to this [dot notation explanation](https://en.wikipedia.org/wiki/Dot_notation).

```json{
    {
    "@self": {
      "relations":{
        "user":"1",
        "user":"1",
      }
    }
  .... The actual object  
  }
```

#### Extending
Data Extension allows you to automatically include related entities in API responses, reducing the need for multiple API calls and providing complete context in a single request. This is useful when you need to retrieve related data for a specific object or collection an lowers the number of API calls needed therby reducing the load on the server and improving performence client side.

The extend patern is based was orginally developed for the [Open Catalogi](https://opencatalogi.org) project and is now available in the ObjectStore API. Its baed on the extend functionality of [Zaak gericht werken](https://github.com/VNG-Realisatie/gemma-zaken) but brought in line with p[NLGov REST API Design Rules](https://logius-standaarden.github.io/API-Design-Rules/) by adding a _ prefix to the parameter

Extention patern is suported trough the objects api

Extend a single property:
- `?_extend=author` - Include full author object
- `?_extend=category` - Include full category object
- `?_extend=files` - Include file metadata

Extend nested properties:
- `?_extend=author.organization` - Include author with their organization
- `?_extend=department.employees` - Include department with all employees
- `?_extend=project.tasks.assignee` - Include project with tasks and their assignees

Combine multiple extensions:
- `?_extend=author,category,comments` - Include multiple related objects
- `?_extend=files,metadata,relations` - Include all related data
- `?_extend=all` - Include all possible relations on the root object

#### reverdedBy
Objects van ce extended 

#### Inversion
inversedBy

### Locking

Locking is a mechanism used to prevent concurrent editing of objects, ensuring data integrity in multi-user environments. A user (or a process on behalf of a user) might lock an object to prevent other users or processes from performing changes or deletions. This is particularly useful in scenarios such as:

- When a user is editing an object in a form, and you want to prevent use collisions.
- For BPMN processes that might take some time and cannot have their underlying data altered. However, keep in mind that for the latter example, a BPMN process could also use a specific version of an object and might run into trouble if it tries to update it later.

Locks are by default created for five minutes but can be created for any duration by supplying the duration period. Locks can be extended, but only by the user that created the lock. Locks can also be removed, but only by the user that created the lock. Locks are automatically removed if the user that created the lock performs an update or delete operation.

**Key Benefits**

1. **Data Integrity**
   - Prevent concurrent modifications
   - Avoid data conflicts
   - Maintain consistency

2. **Process Management**
   - Support long-running operations
   - Coordinate multi-step updates
   - Manage workflow dependencies

3. **User Coordination**
   - Clear ownership indication
   - Transparent lock status
   - Managed access control

<ApiSchema id="open-register" example pointer="#/components/schemas/lock" />

### 3. File Attachments

File Attachments allow objects to include and manage associated files and documents. Open Register leverages Nextcloud's powerful file storage capabilities to provide a robust and secure file management system. By building on top of Nextcloud's proven infrastructure, Open Register inherits all the benefits of Nextcloud's file handling including:

- Secure file storage and encryption
- File versioning and history
- Collaborative features like sharing and commenting
- Preview generation for supported file types
- Automated virus scanning
- Flexible storage backend support

When a register is created in Open Register, a share is also automatically created in Nextcloud. Then when a schema is created, a folder is created within that share, and when an object is created, a folder is created (using the UUID of the object) in the folder of the schema. This means that every object has a corresponding folder. Files present in that folder are assumed to be attached to the object. This gives a simple and intuitive system of coupling files to objects.

Alternatively, users can also relate (existing) files to an object by using the Nextcloud file system and tagging the file 'object:[uuid]' where '[uuid]' is the UUID of the object. In neither case is there a relation between the file and a property in the object. The files are however available through the object API because file objects are returned in the object metadata under the files array.

<ApiSchema id="open-register" example pointer="#/components/schemas/file" />

### Soft Deleting

Open Register implements a soft deletion strategy for objects, ensuring data can be recovered and maintaining referential integrity.

**Overview**

The deletion system provides:
- Soft deletion of objects
- Retention of relationships
- Configurable retention periods
- Recovery capabilities
- Audit trail preservation


1. Objects are never immediately deleted from the database
2. Deletion sets the 'deleted' timestamp and related metadata
3. Deleted objects are excluded from normal queries
4. Relations to deleted objects are preserved
5. Files linked to deleted objects are moved to a trash folder
6. Deleted objects can be restored until purge date
7. Objects are only permanently deleted after retention period

## Key Benefits

1. **Data Safety**
   - Prevent accidental data loss
   - Maintain data relationships
   - Support data recovery
   - Preserve audit history

2. **Compliance**
   - Meet retention requirements
   - Support legal holds
   - Track deletion reasons
   - Document deletion process

3. **Management**
   - Flexible retention policies
   - Controlled purge process
   - Recovery options
   - Clean data lifecycle

<ApiSchema id="open-register" example pointer="#/components/schemas/deletion" />

### 4. Version History

Open Register maintains a complete history of changes to objects, allowing you to track modifications over time and revert to previous versions if needed. This version history powers the [Time Travel](#time-travel) feature, which enables you to:

- View an object as it existed at any point in time
- Compare different versions to see what changed
- Restore objects to previous states
- Analyze the evolution of data over time


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
## Features

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