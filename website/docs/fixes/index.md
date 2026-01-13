---
sidebar_position: 1
title: Fixes Overview
description: Overview of fixes and solutions implemented in OpenRegister
---

# Fixes Overview

This section contains documentation for fixes and solutions implemented in OpenRegister to address specific issues and improve system stability.

## Available Fixes

### [Entity __toString() Magic Method Fix](./ENTITY_TOSTRING_FIX.md)
**Issue**: Object entities could not be saved due to string conversion errors when the framework attempted to convert entity objects to strings.

**Solution**: Added `__toString()` magic methods to all entity classes and fixed organisation handling logic in the SaveObject service.

**Impact**: Resolves object entity saving errors and prevents similar string conversion issues across all entity operations.

### [Organisation Default Fix](./ORGANISATION_DEFAULT_FIX.md)
**Issue**: Import functionality failing on production with missing default organisation methods.

**Solution**: Added missing methods to `OrganisationMapper`, enhanced `Organisation` entity, and created migration for `is_default` column.

**Impact**: Resolves production import errors and ensures proper multi-tenancy support.

### [Folder Deletion Fix](./FOLDER_DELETION_FIX.md)
**Issue**: Import errors when reusing UUIDs due to orphaned folders from previous deletions.

**Solution**: Enhanced hard delete process to clean up folders, improved multiple folder handling, and added foundation for automated cleanup.

**Impact**: Prevents import errors with reused UUIDs and ensures complete resource cleanup.

### [Migration Errors Fix](./MIGRATION_INDEX_COLLISION_FIX.md)
**Issue**: OpenRegister startup failing with three database errors: (1) Index name collision between 'openregister_views' and 'openregister_view' tables, (2) Missing 'extend' column for index creation, and (3) Foreign key constraint errors in entity_relations table.

**Solution**: (1) Updated migration to rename indexes when copying from plural to singular table names, (2) Removed deprecated 'extend' property from Schema entity and disabled the migration that added it, and (3) Removed foreign key constraints that were causing migration failures (kept indexes for performance).

**Impact**: Resolves all OpenRegister initialization errors, enables proper table renaming migrations, removes deprecated schema extension functionality, and avoids foreign key constraint migration issues.

## Fix Categories

### Database & Migration Fixes
- Schema updates and migrations
- Data integrity improvements
- Multi-tenancy enhancements

### File System Fixes
- Folder cleanup and management
- Orphaned resource handling
- Import/export improvements

### Service Layer Fixes
- Missing method implementations
- Error handling improvements
- Performance optimizations

## Contributing to Fixes

When implementing fixes, please:

1. **Document the problem** clearly with error messages and context
2. **Explain the root cause** and why it occurred
3. **Detail the solution** with code examples and file changes
4. **Include testing scenarios** to verify the fix works
5. **Add deployment instructions** for production rollout
6. **Update this index** with new fix documentation

## Fix Documentation Template

Use the following structure for new fix documentation:

```markdown
# [Fix Name]

## Problem Description
[Describe the issue and error messages]

## Root Cause
[Explain why the problem occurred]

## Solution Implemented
[Detail the changes made]

## Files Modified
[List all files changed]

## Testing
[Describe how to test the fix]

## Deployment
[Instructions for production deployment]

## Impact
[Summary of improvements]
```

## Related Documentation

- [Development Guide](../developers/testing.md)
- [API Documentation](../api/objects.md)
- [Technical Documentation](../technical/unified-faceting-system.md) 