# Object Deletion

Open Register implements a soft deletion strategy for objects, ensuring data can be recovered and maintaining referential integrity.

## Overview

The deletion system provides:
- Soft deletion of objects
- Retention of relationships
- Configurable retention periods
- Recovery capabilities
- Audit trail preservation

## Deletion Metadata

When an object is deleted, the following metadata is stored:

| Field | Type | Description |
|-------|------|-------------|
| deleted | datetime | When the object was marked as deleted |
| deletedBy | string | User ID who performed the deletion |
| deletedReason | string | Optional reason for deletion |
| retentionPeriod | integer | How long to keep the deleted object (in days) |
| purgeDate | datetime | When the object will be permanently deleted |

## Deletion States

Objects can exist in the following states:
- Active (deleted = null)
- Soft Deleted (deleted = timestamp)
- Pending Purge (current date > purgeDate)
- Purged (permanently deleted)

## Deletion Logic

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

## Related Features

- [Audit Trails](audit-trails.md) - Track deletion operations
- [Object Locking](object-locking.md) - Prevent deletions of locked objects
- [Access Control](access-control.md) - Manage deletion permissions
- [Time Travel](time-travel.md) - View objects at points before deletion 