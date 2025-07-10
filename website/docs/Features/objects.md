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

Objects are the core data entities in OpenRegister that store and manage structured information. This document explains everything you need to know about working with objects.

## Overview

An object in OpenRegister represents a single record of data that:
- Conforms to a defined schema
- Belongs to a specific register
- Has a unique UUID identifier
- Can contain nested objects and file attachments
- Maintains version history through audit logs
- Can be linked to other objects via relations

## Object Structure

Each object contains:

- `id`: Unique UUID identifier
- `uri`: Absolute URL to access the object
- `version`: Semantic version number (e.g. 1.0.0)
- `register`: The register this object belongs to
- `schema`: The schema this object must conform to
- `object`: The actual data payload as JSON
- `files`: Array of related file IDs
- `relations`: Array of related object IDs
- `textRepresentation`: Text representation of the object
- `locked`: Lock status and details
- `owner`: Nextcloud user that owns the object
- `authorization`: JSON object describing access permissions
- `updated`: Last modification timestamp
- `created`: Creation timestamp

## Key Features

### Schema Validation
- Objects are validated against their schema definition
- Supports both soft and hard validation modes
- Ensures data integrity and consistency

### Relations & Nesting
- Objects can reference other objects via UUIDs or URLs
- Supports nested object structures up to configured depth
- Maintains bidirectional relationship tracking

### Version Control
- Automatic version incrementing
- Full audit trail of changes
- Historical version access
- Ability to revert to any previous version
- Detailed change tracking between versions

### Access Control
- Object-level ownership
- Granular authorization rules
- Lock mechanism for concurrent access control

### File Attachments
- Support for file attachments
- Secure file storage integration
- File metadata tracking

## API Operations

Objects support standard CRUD operations:
- Create new objects
- Read existing objects
- Update object data
- Delete objects
- Search and filter objects
- Export object data
- Revert to previous versions

## Object Locking and Versioning

### Version Control and Revert Functionality

OpenRegister provides comprehensive version control capabilities:

- Every change creates a new version with full audit trail
- Changes are tracked at the field level
- Previous versions can be viewed and compared
- Objects can be reverted to any historical version
- Revert operation creates new version rather than overwriting
- Audit logs maintain full history of all changes including reverts
- Revert includes all object properties including relations and files
- Batch revert operations supported for related objects

### Locking Objects

Objects can be locked to prevent concurrent modifications. This is useful when:
- Long-running processes need exclusive access
- Multiple users/systems are working on the same object
- Ensuring data consistency during complex updates

## Object Relations

Objects in OpenRegister support sophisticated relationships through schema definitions. The way objects relate to each other depends on how you configure properties in your schema.

### Schema Property Configuration Options

When defining a property in a schema that references other objects, you have several configuration options:

#### For Single Objects (type: 'object')
```json
{
  "type": "object",
  "objectConfiguration": {
    "handling": "nested-object" // or "nested-schema", "related-schema", "uri"
  },
  "$ref": "schema-id-or-slug",
  "inversedBy": "property-name",
  "register": "register-id-or-slug",
  "cascadeDelete": true
}
```

#### For Arrays of Objects (type: 'array')
```json
{
  "type": "array",
  "items": {
    "type": "object",
    "$ref": "schema-id-or-slug",
    "inversedBy": "property-name",
    "register": "register-id-or-slug",
    "cascadeDelete": true
  },
  "objectConfiguration": {
    "handling": "nested-object" // or "nested-schema", "related-schema", "uri"
  }
}
```

## Schema Reference Configuration (`$ref` and `register`)

When defining object relationships in your schema properties, OpenRegister uses two key configuration options to determine where and how to store or reference related objects:

### `$ref` Property

The `$ref` property specifies which schema the related object should conform to. The backend supports two formats:

1. **Simple schema reference**: Just the schema ID or name
   ```json
   "$ref": "organisation-schema"
   ```

2. **Path-based reference**: A path where the schema name is extracted from the last segment
   ```json
   "$ref": "path/to/organisation-schema"
   ```

**Backend Resolution Logic:**
- If `$ref` contains a `/`, the backend extracts everything after the last slash
- Otherwise, it uses the value directly as the schema identifier
- This value is passed as the `schema` parameter when creating or updating related objects

### `register` Property

The `register` property specifies which register the related object belongs to:

```json
"register": "my-register-id"
```

**Backend Behavior:**
- If `register` is specified, related objects are created/updated in that register
- If `register` is omitted, the current object's register is used as the default
- This ensures related objects can be stored in different registers if needed

### Complete Configuration Example

Here's how these properties work together in a schema configuration:

```json
{
  "properties": {
    "deelnemers": {
      "type": "array",
      "items": {
        "$ref": "organisation-schema",
        "register": "organisations-register",
        "inversedBy": "deelnames",
        "writeBack": true,
        "removeAfterWriteBack": true
      }
    }
  }
}
```

**What happens when saving:**
1. Backend identifies that `deelnemers` contains organization objects
2. Uses `organisation-schema` to validate and structure the objects
3. Stores/updates objects in the `organisations-register`
4. Sets up inverse relations via the `deelnames` property
5. Handles write-back operations as configured

### Use Cases

**Same Register Relations:**
```json
{
  "community": {
    "type": "object",
    "$ref": "community-schema"
    // register omitted - uses current object's register
  }
}
```

**Cross-Register Relations:**
```json
{
  "members": {
    "type": "array",
    "items": {
      "$ref": "person-schema",
      "register": "people-register",
      "inversedBy": "communities"
    }
  }
}
```

This configuration system allows for flexible object relationships while maintaining proper data organization across different registers and schemas.

## Object Handling Types

When defining object relationships in your schema properties, you can specify how related objects should be handled using the `handling` configuration option within `objectConfiguration`. OpenRegister supports four different handling types, each with specific behaviors for storage and retrieval:

### 1. Nested Object (`nested-object`)

**Description**: Stores object data directly within the parent object as embedded JSON data.

**Behavior**:
- Object data is stored inline within the parent object's JSON structure
- No separate database entity is created for the nested object
- Changes to the nested object are saved as part of the parent object
- Best for simple, tightly coupled data that doesn't need independent lifecycle management

**Example Schema Configuration**:
```json
{
  "address": {
    "type": "object",
    "objectConfiguration": {
      "handling": "nested-object"
    },
    "properties": {
      "street": {"type": "string"},
      "city": {"type": "string"},
      "zipCode": {"type": "string"}
    }
  }
}
```

**Resulting Data Structure**:
```json
{
  "id": "123e4567-e89b-12d3-a456-426614174000",
  "name": "John Doe",
  "address": {
    "street": "Main Street 1",
    "city": "Amsterdam", 
    "zipCode": "1000 AA"
  }
}
```

### 2. Nested Schema (`nested-schema`)

**Description**: Stores object as a separate entity in the database but embeds the full object data in API responses.

**Behavior**:
- Creates a separate `ObjectEntity` in the database with its own UUID
- Object follows the schema specified in `$ref`
- During rendering, the full nested object data is included in the response
- Allows independent lifecycle management while providing convenient access to nested data
- Supports cascade operations when configured with `inversedBy`

**Example Schema Configuration**:
```json
{
  "profile": {
    "type": "object",
    "$ref": "profile-schema",
    "objectConfiguration": {
      "handling": "nested-schema"
    },
    "inversedBy": "owner",
    "register": "users"
  }
}
```

**Resulting Data Structure**:
```json
{
  "id": "123e4567-e89b-12d3-a456-426614174000", 
  "name": "John Doe",
  "profile": {
    "id": "987fcdeb-51a2-43d7-8f9e-123456789abc",
    "bio": "Software developer",
    "skills": ["PHP", "JavaScript", "Vue.js"],
    "owner": "123e4567-e89b-12d3-a456-426614174000"
  }
}
```

### 3. Related Schema (`related-schema`) 

**Description**: Stores object as a separate entity and references it by UUID/ID only.

**Behavior**:
- Creates a separate `ObjectEntity` in the database with its own UUID
- Only the UUID reference is stored in the parent object
- Client must make additional API calls to retrieve the full nested object data
- Provides the most separation and independence between objects
- Optimal for loosely coupled relationships and large datasets
- Supports cascade operations when configured with `inversedBy`

**Example Schema Configuration**:
```json
{
  "department": {
    "type": "object", 
    "$ref": "department-schema",
    "objectConfiguration": {
      "handling": "related-schema"
    },
    "inversedBy": "employees",
    "register": "organization"
  }
}
```

**Resulting Data Structure**:
```json
{
  "id": "123e4567-e89b-12d3-a456-426614174000",
  "name": "John Doe", 
  "department": "456e7890-e12b-34c5-d678-901234567890"
}
```

### 4. URI Reference (`uri`)

**Description**: References external objects by URI/URL without local storage.

**Behavior**:
- No local `ObjectEntity` is created
- Stores only the URI reference as a string
- External object is not managed by OpenRegister
- Useful for referencing objects in external systems or APIs
- No cascade operations or local validation
- Client is responsible for resolving the URI to retrieve object data

**Example Schema Configuration**:
```json
{
  "externalService": {
    "type": "object",
    "objectConfiguration": {
      "handling": "uri"
    },
    "format": "uri"
  }
}
```

**Resulting Data Structure**:
```json
{
  "id": "123e4567-e89b-12d3-a456-426614174000",
  "name": "John Doe",
  "externalService": "https://api.external.com/services/auth-service-v2"
}
```

## Handling Type Comparison

| Feature | Nested Object | Nested Schema | Related Schema | URI Reference |
|---------|---------------|---------------|----------------|---------------|
| **Separate Entity** | ❌ | ✅ | ✅ | ❌ |
| **Independent Lifecycle** | ❌ | ✅ | ✅ | ✅ |
| **Embedded in Response** | ✅ | ✅ | ❌ | ❌ |
| **Cascade Operations** | ❌ | ✅ | ✅ | ❌ |
| **Schema Validation** | ✅ | ✅ | ✅ | ❌ |
| **Performance (Read)** | ⭐⭐⭐ | ⭐⭐ | ⭐ | ⭐ |
| **Performance (Write)** | ⭐⭐⭐ | ⭐⭐ | ⭐⭐ | ⭐⭐⭐ |
| **Storage Efficiency** | ⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐ |
| **Relationship Complexity** | ⭐ | ⭐⭐ | ⭐⭐⭐ | ⭐ |

## When to Use Each Type

### Use **Nested Object** when:
- Data is simple and tightly coupled to the parent
- You don't need independent lifecycle management
- The nested data is relatively small and stable
- Performance is critical and you want to minimize database queries

### Use **Nested Schema** when:
- You need schema validation for nested objects
- You want independent lifecycle management but convenient access
- The nested object might be referenced by multiple parents
- You need cascade operations but want embedded responses

### Use **Related Schema** when:
- Objects have independent lifecycles and complex relationships
- You're working with large datasets where embedding would be inefficient
- You need maximum flexibility in how relationships are managed
- Performance optimization through selective loading is important

### Use **URI Reference** when:
- Referencing objects in external systems
- You don't want to store or manage the referenced object locally
- Integration with third-party APIs or services
- The referenced resource is managed outside OpenRegister

## Inverse Relations

Inverse relations allow objects to automatically show related objects that reference them, creating bidirectional relationships while storing data only once.

### How Inverse Relations Work

1. **Schema Definition:** Define `inversedBy` on the schema that should show the inverse relation
2. **Automatic Detection:** Backend automatically finds objects that reference this object
3. **Dynamic Population:** Inverse relations are populated when rendering objects
4. **Single Storage:** Relationship data is stored only once, preventing duplicate references

### Basic Example: Person and Addresses

**Person Schema:**
```json
{
  "name": "Person", 
  "properties": {
    "name": {"type": "string"},
    "addresses": {
      "type": "array",
      "items": {"$ref": "address-schema"},
      "inversedBy": "person"
    }
  }
}
```

**Address Schema:**
```json
{
  "name": "Address",
  "properties": {
    "street": {"type": "string"},
    "person": {"type": "string"}
  }
}
```

**Result:** When fetching a person, their addresses are automatically included even though they're stored separately.

### Advanced Example: Organisation Communities

A more complex example showing parent-child relationships within the same schema:

**Organisation Schema:**
```json
{
  "name": "Organisation",
  "properties": {
    "name": {"type": "string"},
    "type": {"type": "string"}, // "community" or "member"
    "deelnames": {
      "type": "array",
      "items": {"type": "string"},
      "description": "UUIDs of communities this organisation participates in"
    },
    "deelnemers": {
      "type": "array", 
      "items": {
        "$ref": "organisation-schema",
        "inversedBy": "deelnames",
        "writeBack": true,
        "removeAfterWriteBack": true
      },
      "description": "UUIDs of organisations that participate in this community (inverse relation with write-back)"
    }
  }
}
```

## Inverse Relations with Write-Back

OpenRegister's inverse relations system supports both read and write operations:

- **Standard Inverse Relations (`inversedBy`)**: Automatically show related objects during rendering (read-only)
- **Inverse Relations with Write-Back (`inversedBy` + `writeBack: true`)**: Additionally update target objects during save operations

### How Inverse Relations Write-Back Works

1. **Schema Definition:** Define `inversedBy` with `writeBack: true` in the schema property configuration
2. **Automatic Updates:** When saving an object, the system automatically updates referenced objects
3. **Bidirectional Maintenance:** Target objects get updated to include references back to the current object
4. **Import-Friendly:** Perfect for import processes where you have parent data with child references

### Community/Deelnemers Example

**Schema Configuration:**
```json
{
  "name": "Organisation",
  "properties": {
    "name": {"type": "string"},
    "type": {"type": "string"},
    "deelnames": {
      "type": "array",
      "items": {"type": "string"},
      "description": "UUIDs of communities this organisation participates in"
    },
    "deelnemers": {
      "type": "array",
      "items": {
        "$ref": "organisation-schema", 
        "inversedBy": "deelnames",
        "writeBack": true,
        "removeAfterWriteBack": true
      },
      "description": "UUIDs of participants - will update their deelnames arrays"
    }
  }
}
```

**Usage Example:**

**Step 1: Create member organisations**
```json
// POST /organisations
{
  "name": "Organisation A",
  "type": "member",
  "deelnames": []
}
// Returns: {"id": "org-a-uuid", ...}

// POST /organisations  
{
  "name": "Organisation B",
  "type": "member", 
  "deelnames": []
}
// Returns: {"id": "org-b-uuid", ...}
```

**Step 2: Create community with deelnemers**
```json
// POST /organisations
{
  "name": "Tech Community",
  "type": "community",
  "deelnames": [],
  "deelnemers": ["org-a-uuid", "org-b-uuid"]
}
// Returns: {"id": "community-uuid", ...}
```

**What happens automatically:**
1. Community object is created with `deelnemers` property **removed** (since it's a reverse relation)
2. Organisation A's `deelnames` array is updated to include `"community-uuid"`
3. Organisation B's `deelnames` array is updated to include `"community-uuid"`

**Final state:**
```json
// Organisation A
{
  "id": "org-a-uuid",
  "name": "Organisation A",
  "type": "member",
  "deelnames": ["community-uuid"]  // Automatically updated
}

// Organisation B  
{
  "id": "org-b-uuid",
  "name": "Organisation B",
  "type": "member",
  "deelnames": ["community-uuid"]  // Automatically updated
}

// Tech Community
{
  "id": "community-uuid", 
  "name": "Tech Community",
  "type": "community",
  "deelnames": []  // deelnemers property was removed - not stored here
}
```

### Import Process Benefits

This reverse relations pattern is particularly useful during import processes:

**CSV Import Example:**
```csv
name,type,deelnemers
"Tech Community","community","org-a-uuid,org-b-uuid"
"Business Community","community","org-a-uuid,org-c-uuid"
```

**What happens during import:**
1. ImportService creates community objects with `deelnemers` arrays
2. SaveObject.handleInverseRelationsWriteBack() processes each UUID in `deelnemers`
3. Target organisations' `deelnames` arrays are automatically updated
4. Community objects are saved without the `deelnemers` property (if `removeAfterWriteBack: true`)

### Backend Implementation Details

**File: `lib/Service/ObjectHandlers/SaveObject.php`**

The inverse relations write-back functionality is implemented in:
- `handleInverseRelationsWriteBack()`: Main method that processes inverse relations with write-back
- Called during both object creation and updates
- Integrates with cascading and default value setting

**Key features:**
- Extends existing inverse relations system (`inversedBy`)
- Supports both single objects and arrays of objects
- Handles missing target objects gracefully (logs errors, continues processing)
- Optionally removes properties from source object after processing
- Prevents duplicate entries in target arrays

**Schema Configuration Options:**
```json
{
  "deelnemers": {
    "type": "array",
    "items": {
      "$ref": "target-schema-id",              // Required: target schema
      "inversedBy": "property-name",           // Required: property to update (same as read-only inverse relations)
      "writeBack": true,                       // Required: enables write-back functionality
      "removeAfterWriteBack": true,            // Optional: remove property from source after write-back
      "register": "register-id"                // Optional: defaults to current register
    }
  }
}
```

## Relation Types Summary

OpenRegister supports three types of object relationships, each serving different use cases:

### 1. Inverse Relations (`inversedBy`)
- **Purpose:** Show related objects during rendering (read-only)
- **Direction:** Target → Source (automatic lookup)
- **Storage:** Relationship data stored on source objects
- **Use case:** Display all addresses for a person, show all posts for a blog

**Example:**
```json
{
  "addresses": {
    "type": "array",
    "items": {"$ref": "address-schema"},
    "inversedBy": "person"
  }
}
```

### 2. Inverse Relations with Write-Back (`inversedBy` + `writeBack: true`)
- **Purpose:** Show related objects during rendering AND update target objects during save operations
- **Direction:** Bidirectional (Target → Source for reading, Source → Target for writing)
- **Storage:** Relationship data stored on target objects
- **Use case:** Community membership, parent-child relationships, import processes

**Example:**
```json
{
  "deelnemers": {
    "type": "array",
    "items": {
      "$ref": "organisation-schema",
      "inversedBy": "deelnames",
      "writeBack": true,
      "removeAfterWriteBack": true
    }
  }
}
```

### 3. Cascade Relations (`inversedBy` with `objectConfiguration`)
- **Purpose:** Create/update related objects during save operations
- **Direction:** Source → Target (create new objects)
- **Storage:** Separate objects created and linked
- **Use case:** Creating addresses when creating a person, creating posts when creating a blog

**Example:**
```json
{
  "addresses": {
    "type": "array",
    "items": {
      "$ref": "address-schema",
      "inversedBy": "person"
    },
    "objectConfiguration": {"handling": "related-schema"}
  }
}
```

### When to Use Each Type

| Use Case | Relation Type | Reason |
|----------|---------------|---------|
| Display related objects | Inverse Relations | Read-only, automatic lookup |
| Import with existing children | Inverse Relations with Write-Back | Update existing objects |
| Create objects with new children | Cascade Relations | Create new related objects |
| Parent-child where child owns relationship | Inverse Relations with Write-Back | Maintain single source of truth |
| Parent-child where parent owns relationship | Cascade Relations | Natural parent-driven creation |
| Bidirectional relationships | Inverse Relations with Write-Back | Both read and write capabilities |

**How this works:**

1. **Data Storage (One-Way):**
   ```json
   // Organisation A (Member)
   {
     "id": "org-a-uuid",
     "name": "Organisation A",
     "type": "member",
     "deelnames": ["community-uuid-1", "community-uuid-2"]
   }
   
   // Organisation B (Member)  
   {
     "id": "org-b-uuid",
     "name": "Organisation B", 
     "type": "member",
     "deelnames": ["community-uuid-1"]
   }
   
   // Community Organisation
   {
     "id": "community-uuid-1",
     "name": "Tech Community",
     "type": "community",
     "deelnames": [] // Empty - this is the parent
   }
   ```

2. **Automatic Inverse Population:**
   When fetching the community organisation, the `deelnemers` property is automatically populated:
   ```json
   // GET /organisations/community-uuid-1?extend=deelnemers
   {
     "id": "community-uuid-1",
     "name": "Tech Community",
     "type": "community", 
     "deelnames": [],
     "deelnemers": ["org-a-uuid", "org-b-uuid"] // Automatically populated
   }
   ```

3. **With Extension:**
   ```json
   // GET /organisations/community-uuid-1?extend=deelnemers
   {
     "id": "community-uuid-1",
     "name": "Tech Community",
     "type": "community",
     "deelnames": [],
     "deelnemers": [
       {
         "id": "org-a-uuid",
         "name": "Organisation A",
         "type": "member",
         "deelnames": ["community-uuid-1", "community-uuid-2"]
       },
       {
         "id": "org-b-uuid", 
         "name": "Organisation B",
         "type": "member",
         "deelnames": ["community-uuid-1"]
       }
     ]
   }
   ```

### Benefits of Inverse Relations

- **Single Source of Truth:** Relationship data stored only once
- **Automatic Synchronization:** No risk of inconsistent bidirectional references
- **Performance:** No need to update multiple objects when relationships change
- **Flexibility:** Can view relationships from either direction

### Backend Implementation Details

The inverse relations functionality is handled by several PHP files in the OpenRegister codebase:

#### Core Files and Their Responsibilities

**1. `lib/Service/ObjectHandlers/RenderObject.php`**
- **Primary responsibility:** Handles rendering objects and populating inverse relations
- **Key methods:**
  - `getInversedProperties(Schema $schema)`: Extracts properties with `inversedBy` configurations from schema
  - `handleInversedProperties()`: Finds and populates inverse relations during object rendering
  - `renderEntity()`: Main rendering method that calls `handleInversedProperties()` when depth < 10

**2. `lib/Service/ObjectHandlers/SaveObject.php`**
- **Primary responsibility:** Handles saving objects and maintaining relation tracking
- **Key methods:**
  - `scanForRelations(array $data)`: Scans object data for UUIDs and URLs, stores them in dot notation
  - `updateObjectRelations(ObjectEntity $objectEntity, array $data)`: Updates the relations property on objects
  - `saveObject()`: Main save method that calls `updateObjectRelations()` to track relations

**3. `lib/Service/ObjectService.php`**
- **Primary responsibility:** Main service facade that coordinates object operations
- **Key methods:**
  - `findByRelations(string $search)`: Finds objects that reference a specific UUID/URL
  - `renderHandler->renderEntity()`: Delegates to RenderObject for rendering with inverse relations

**4. `lib/Db/ObjectEntityMapper.php`**
- **Primary responsibility:** Database operations for finding related objects
- **Key methods:**
  - `findByRelation(string $search)`: Database query to find objects containing specific UUIDs in their relations
  - `findByRelationUri()`: Alternative method for finding objects by URI references

#### How Inverse Relations Are Processed

```mermaid
graph TD
    A[Object Requested] --> B[RenderObject::renderEntity]
    B --> C[Check Schema for inversedBy properties]
    C --> D[RenderObject::getInversedProperties]
    D --> E[ObjectEntityMapper::findByRelation]
    E --> F[Filter objects by schema and inversedBy field]
    F --> G[Populate inverse relation property]
    G --> H[Return rendered object]
```

#### Development Notes for Future Work

**When working with inverse relations:**

1. **Schema Changes:** Modify `RenderObject::getInversedProperties()` if you need to support new inverse relation configurations

2. **Performance Optimization:** The `ObjectEntityMapper::findByRelation()` method can be optimized for large datasets by adding database indexes on the `relations` JSON column

3. **Circular Reference Prevention:** The rendering system includes circular reference detection in `RenderObject::renderEntity()` using the `$visitedIds` parameter

4. **Caching:** Consider implementing caching in `RenderObject` for frequently accessed inverse relations

5. **Database Schema:** Relations are stored as JSON in the `relations` column of the `oc_openregister_objects` table in dot notation format

**Example of relations storage in database:**
```json
{
  "deelnames.0": "community-uuid-1",
  "deelnames.1": "community-uuid-2", 
  "contact.email": "user@example.com"
}
```

This architecture ensures that inverse relations are dynamically calculated and always up-to-date, while maintaining performance through caching and efficient database queries.

## UUID Relations and Automatic Detection

The backend automatically detects and tracks relations:

### Automatic Relation Detection

```javascript
// These patterns are automatically detected as relations:
{
  "relatedObject": "550e8400-e29b-41d4-a716-446655440000", // UUID
  "externalRef": "https://api.example.com/objects/123",     // URL
  "nestedRefs": [
    "550e8400-e29b-41d4-a716-446655440001",
    "550e8400-e29b-41d4-a716-446655440002"
  ]
}
```

### Relations Storage

Relations are stored in dot notation:
```json
{
  "relations": {
    "relatedObject": "550e8400-e29b-41d4-a716-446655440000",
    "externalRef": "https://api.example.com/objects/123",
    "nestedRefs.0": "550e8400-e29b-41d4-a716-446655440001", 
    "nestedRefs.1": "550e8400-e29b-41d4-a716-446655440002"
  }
}
```

## Extending Objects with Related Data

You can extend objects to include related data using the `extend` parameter:

### Basic Extension

```javascript
// Extend a single property
GET /objects/123?extend=addresses

// Extend multiple properties  
GET /objects/123?extend=addresses,employer

// Extend all relations
GET /objects/123?extend=all
```

### Nested Extension

```javascript
// Extend nested properties
GET /objects/123?extend=addresses.person,addresses.contacts

// Wildcard extension for arrays
GET /objects/123?extend=addresses.$.person
```

## Cascade Delete

When `cascadeDelete` is set to `true`, deleting a parent object will also delete related objects:

```json
{
  "addresses": {
    "type": "array",
    "items": {
      "type": "object", 
      "$ref": "address-schema",
      "cascadeDelete": true
    }
  }
}
```

**Behavior:** Deleting a person will also delete all their addresses.

## Complete Example: Blog System

Here's a complete example showing different relation types:

### Blog Schema
```json
{
  "name": "Blog",
  "properties": {
    "title": {"type": "string"},
    "author": {
      "type": "object",
      "$ref": "person-schema", 
      "objectConfiguration": {"handling": "related-schema"}
    },
    "posts": {
      "type": "array",
      "items": {
        "type": "object",
        "$ref": "post-schema",
        "inversedBy": "blog",
        "cascadeDelete": true
      },
      "objectConfiguration": {"handling": "related-schema"}
    },
    "settings": {
      "type": "object",
      "objectConfiguration": {"handling": "nested-object"},
      "properties": {
        "theme": {"type": "string"},
        "private": {"type": "boolean"}
      }
    }
  }
}
```

### Post Schema
```json
{
  "name": "Post",
  "properties": {
    "title": {"type": "string"},
    "content": {"type": "string"},
    "blog": {"type": "string"},
    "tags": {
      "type": "array",
      "items": {"type": "string"}
    }
  }
}
```

### Creating a Blog

**Input:**
```json
{
  "title": "My Tech Blog",
  "author": "550e8400-e29b-41d4-a716-446655440000",
  "posts": [
    {
      "title": "First Post",
      "content": "Welcome to my blog!",
      "tags": ["welcome", "intro"]
    },
    {
      "title": "Second Post", 
      "content": "More content here",
      "tags": ["tech", "programming"]
    }
  ],
  "settings": {
    "theme": "dark",
    "private": false
  }
}
```

**What happens:**
1. Blog object created with UUID `blog-uuid-123`
2. Two post objects created separately:
   - Each gets `"blog": "blog-uuid-123"` automatically
   - Each gets its own UUID
3. Blog object stores: `"posts": ["post-uuid-1", "post-uuid-2"]`
4. Settings nested directly in blog object
5. Author reference stored as UUID
6. Relations automatically tracked

**Retrieved with extension:**
```javascript
GET /blogs/blog-uuid-123?extend=posts,author
```

**Response:**
```json
{
  "id": "blog-uuid-123",
  "title": "My Tech Blog",
  "author": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "John Doe",
    "email": "john@example.com"
  },
  "posts": [
    {
      "id": "post-uuid-1",
      "title": "First Post",
      "content": "Welcome to my blog!",
      "blog": "blog-uuid-123",
      "tags": ["welcome", "intro"]
    },
    {
      "id": "post-uuid-2", 
      "title": "Second Post",
      "content": "More content here",
      "blog": "blog-uuid-123",
      "tags": ["tech", "programming"]
    }
  ],
  "settings": {
    "theme": "dark",
    "private": false
  }
}
```

## Summary

The OpenRegister object relation system provides flexible ways to model data relationships:

- **Nested Objects:** Simple embedding without validation
- **Nested Schema:** Embedding with schema validation  
- **Related Schema:** Separate objects with automatic relation management
- **URI References:** Loose coupling with external resources
- **Inverse Relations:** Automatic population of referring objects
- **Cascade Operations:** Automatic creation and deletion of related objects
- **Automatic Detection:** UUIDs and URLs are automatically tracked as relations

Choose the appropriate handling type based on your data modeling needs and whether you want separate storage, automatic relation management, and cascade operations.
