# Objects in OpenRegister

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

#### Lock API Endpoints

To lock an object via the REST API: