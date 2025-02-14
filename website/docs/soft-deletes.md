# Soft Deletes

Soft Deletes provide a safe way to remove objects while maintaining the ability to recover them if needed.

## Overview

The soft delete system:
- Marks objects as deleted instead of removing them
- Maintains referential integrity
- Provides recovery options
- Supports permanent deletion when needed

## Key Benefits

1. **Data Safety**
   - Prevent accidental data loss
   - Maintain data relationships
   - Support data recovery

2. **Compliance**
   - Meet retention requirements
   - Support audit processes
   - Manage deletion policies

3. **Business Continuity**
   - Recover from mistakes
   - Maintain historical context
   - Support business processes

## Related Features

- [Time Travel](time-travel.md) - Review deleted objects
- [Audit Trails](audit-trails.md) - Track deletion operations 