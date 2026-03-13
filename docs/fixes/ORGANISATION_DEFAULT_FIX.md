---
sidebar_position: 2
title: Organisation Default Fix
description: Fix for missing default organisation methods causing production import errors
---

# Organisation Default Fix Summary

## Problem Description

The import functionality was failing on production with the error:
```
Call to undefined method OCA\OpenRegister\Db\OrganisationMapper::findDefault()
```

This occurred because:
1. The `OrganisationService` was calling `findDefault()` and `createDefault()` methods that didn't exist in `OrganisationMapper`
2. The `Organisation` entity was missing the `isDefault` property and related methods
3. The database was missing the `is_default` column
4. No default organisation was set in production environments

## Root Cause

The production environment had no default organisation configured, while the local environment already had one. The code was trying to call methods that didn't exist when attempting to ensure a default organisation exists.

## Solution Implemented

### 1. **Added Missing Methods to OrganisationMapper**

Added the following methods to `lib/Db/OrganisationMapper.php`:

- **`findDefault()`** - Finds the default organisation by `is_default = true`
- **`findDefaultForUser(string $userId)`** - Finds the default organisation for a specific user
- **`createDefault()`** - Creates a new default organisation with admin user
- **`setAsDefault(Organisation $organisation)`** - Sets an organisation as default and updates all entities without organisation

### 2. **Enhanced Organisation Entity**

Added to `lib/Db/Organisation.php`:

- **`isDefault` property** - Boolean flag indicating if this is the default organisation
- **`getIsDefault()` method** - Getter for the isDefault property
- **`setIsDefault(bool $isDefault)` method** - Setter for the isDefault property
- **Updated `jsonSerialize()`** - Includes `isDefault` in API responses
- **Updated constructor** - Added type mapping for `is_default` column

### 3. **Created New Migration**

Created `lib/Migration/Version1Date20250723110323.php` to:

- **Add `is_default` column** to `openregister_organisations` table
- **Set first organisation as default** if no default exists
- **Handle existing environments** gracefully

### 4. **Cleaned Up Existing Migration**

Updated `lib/Migration/Version1Date20250801000000.php` to:

- **Remove redundant default organisation creation** (moved to OrganisationService)
- **Simplify organisation creation** to basic setup for existing data
- **Add clear documentation** about default handling being moved to service layer

## Files Modified

### Core Files
1. **`lib/Db/OrganisationMapper.php`** - Added missing methods
2. **`lib/Db/Organisation.php`** - Added isDefault property and methods
3. **`lib/Migration/Version1Date20250723110323.php`** - New migration for is_default column
4. **`lib/Migration/Version1Date20250801000000.php`** - Cleaned up existing migration

### Migration Details
- **Migration Name**: `Version1Date20250723110323`
- **Purpose**: Add `is_default` column and ensure default organisation
- **Schema Changes**: Adds `is_default` boolean column with default `false`
- **Data Migration**: Sets the oldest organisation as default if none exists

## Database Schema Changes

### Before
```sql
CREATE TABLE openregister_organisations (
    id INTEGER PRIMARY KEY,
    uuid VARCHAR(255),
    slug VARCHAR(255),
    name VARCHAR(255),
    description TEXT,
    users JSON,
    owner VARCHAR(255),
    created DATETIME,
    updated DATETIME
);
```

### After
```sql
CREATE TABLE openregister_organisations (
    id INTEGER PRIMARY KEY,
    uuid VARCHAR(255),
    slug VARCHAR(255),
    name VARCHAR(255),
    description TEXT,
    users JSON,
    owner VARCHAR(255),
    is_default BOOLEAN DEFAULT FALSE,
    created DATETIME,
    updated DATETIME
);
```

## API Changes

The Organisation entity now includes `isDefault` in JSON responses:

```json
{
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "name": "Default Organisation",
    "description": "Default organisation for the system",
    "users": ["admin"],
    "userCount": 1,
    "owner": "admin",
    "isDefault": true,
    "created": "2024-01-01T00:00:00+00:00",
    "updated": "2024-01-01T00:00:00+00:00"
}
```

## Deployment Instructions

1. **Deploy the code changes** to production
2. **Run the migration**:
   ```bash
   docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:execute openregister 20250723110323
   ```
3. **Verify the migration** completed successfully
4. **Test the import functionality** to ensure it works

## Testing

The fix ensures that:
- ✅ Import functionality works on environments without existing organisations
- ✅ Default organisation is automatically created when needed
- ✅ Existing organisations are properly handled
- ✅ Backward compatibility is maintained
- ✅ API responses include the new `isDefault` field

## Impact

This fix resolves the production import error and ensures that:
1. **Multi-tenancy works correctly** across all environments
2. **Default organisation handling** is robust and automatic
3. **Import functionality** works consistently across environments
4. **Database schema** is properly aligned with the application code 