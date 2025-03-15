# Object Metadata

Object Metadata provides a structured way to store and manage additional information about objects beyond their core data.

## Overview

The metadata system supports:
- Custom metadata fields
- Automatic system metadata
- Searchable attributes
- Version tracking

## System Metadata Fields

| Field | Type | Description | Purpose |
|-------|------|-------------|----------|
| uuid | string | Unique universal identifier | Globally unique object identification |
| uri | string | Uniform Resource Identifier | Unique addressable location |
| version | string | Semantic version number | Track object versions |
| register | string | Register identifier | Object categorization/grouping |
| schema | string | Schema identifier | Data validation reference |
| textRepresentation | text | Text representation of object | Search and display optimization |
| locked | json | Lock information object | Concurrent access control |
| owner | string | Nextcloud user identifier | Object ownership |
| authorization | json | Authorization rules | Access control configuration |
| updated | datetime | Last modification timestamp | Change tracking |
| created | datetime | Creation timestamp | Lifecycle management |
| folder | string | Storage folder path | File organization |

## Relationship Metadata

| Field | Type | Description | Purpose |
|-------|------|-------------|----------|
| files | json | Related file IDs | Track associated files |
| relations | json | Related object IDs | Track object relationships |

## Lock Information Structure
When an object is locked, the following metadata is stored:

```json
{
    'user': 'user_id',
    'process': 'optional_process_name',
    'created': 'timestamp',
    'duration': 'seconds',
    'expiration': 'timestamp'
}
```

You can read more about locking objects [here](object-locking.md).

## Authorization Structure
The authorization metadata can contain:
- Access rules
- Permission levels
- User/group assignments
- Role definitions
- Custom policies

## Key Benefits

1. **Enhanced Organization**
   - Improved searchability through metadata fields
   - Better categorization using registers and schemas
   - Flexible classification via custom fields

2. **Process Support**
   - Workflow status tracking through version and lock info
   - Process automation using metadata triggers
   - Integration support via standardized fields

3. **Data Management**
   - Rich context storage in metadata fields
   - Extended object information tracking
   - Custom attributes support
   - Complete audit capability

## Related Features

- [Schema Validation](schema-validation.md) - Validate metadata
- [Content Search](content-search.md) - Search metadata
- [Automatic Facets](automatic-facets.md) - Generate facets from metadata
- [Object Locking](object-locking.md) - Concurrent access control
- [Access Control](access-control.md) - Authorization management 