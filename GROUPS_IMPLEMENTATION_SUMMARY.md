# Groups Implementation Summary

## Overview
This document summarizes the implementation of group-based access control for Applications and Organisations in OpenRegister.

## Implementation Date
November 2, 2025

## Changes Made

### 1. Backend Changes

#### Application Entity (`lib/Db/Application.php`)
- ✅ Added `$groups` property (array of group IDs)
- ✅ Added `getGroups()` and `setGroups()` methods
- ✅ Added type mapping: `$this->addType('groups', 'json')`
- ✅ Added to `jsonSerialize()` output
- ✅ Groups stored as array of strings: `['group-id-1', 'group-id-2']`

#### Organisation Entity (`lib/Db/Organisation.php`)
- ✅ Has `$roles` property (array of group IDs)
- ✅ Has `getRoles()` and `setRoles()` methods
- ✅ Already configured in database
- ✅ Roles stored as array of strings: `['group-id-1', 'group-id-2']`
- ✅ Updated documentation to reflect ID-based storage

#### Database Migration (`lib/Migration/Version1Date20251102130000.php`)
- ✅ Created migration to add `groups` column to `oc_openregister_applications` table
- ✅ Column type: `longtext` (stores JSON)
- ✅ Nullable with default NULL
- ✅ Migration successfully executed

### 2. Frontend Changes

#### EditApplication.vue (`src/modals/application/EditApplication.vue`)
- ✅ Removed "Organisation" tab
- ✅ Moved organisation select to "Basic Information" tab
- ✅ Renamed "Groups" tab to "Security"
- ✅ Added groups multi-select in "Basic Information" tab
- ✅ Added groups list display in "Security" tab
- ✅ Implemented `loadNextcloudGroups()` method using fetch API
- ✅ Saves groups as array of IDs: `['openregister', 'admin']`
- ✅ Loads groups and maps IDs to objects for select component
- ✅ Uses `await` to ensure groups load before initialization

#### EditOrganisation.vue (`src/modals/organisation/EditOrganisation.vue`)
- ✅ Renamed "Groups" tab to "Security"
- ✅ Added groups multi-select in "Basic Information" tab  
- ✅ Added groups list display in "Security" tab
- ✅ Implemented `loadNextcloudGroups()` method using fetch API
- ✅ Saves roles as array of IDs: `['engineers', 'managers']`
- ✅ Loads roles and maps IDs to objects for select component
- ✅ Handles both legacy object format and new ID format
- ✅ Uses `await` to ensure groups load before initialization

### 3. Documentation Updates

#### Features Documentation
- ✅ Created `website/docs/Features/organisations.md`
  - Comprehensive organisation management documentation
  - Group-based access control section
  - UI and API usage examples
  - Use cases and best practices
  
- ✅ Created `website/docs/Features/applications.md`
  - Comprehensive application management documentation
  - Group-based access control section
  - UI and API usage examples
  - Use cases and best practices

- ✅ Updated `website/docs/features/organisation-roles.md`
  - Added documentation for new Edit Organisation modal method
  - Documented both UI approaches (Edit modal vs Manage Roles action)

## Data Structure

### Applications - Groups
```json
{
  "groups": [
    "engineers",
    "managers",
    "developers"
  ]
}
```

### Organisations - Roles
```json
{
  "roles": [
    "engineers",
    "managers",
    "viewers"
  ]
}
```

## Database Schema

### oc_openregister_applications
```sql
groups  longtext  YES  NULL  (stores JSON array of group IDs)
```

### oc_openregister_organisations
```sql
roles   longtext  YES  '[]'  (stores JSON array of group IDs)
```

## API Endpoints

### Application with Groups
```http
GET /index.php/apps/openregister/api/applications/1
Response:
{
  "id": 1,
  "name": "My Application",
  "groups": ["engineers", "managers"],
  ...
}

PUT /index.php/apps/openregister/api/applications/1
Request:
{
  "name": "My Application",
  "groups": ["engineers", "managers", "developers"]
}
```

### Organisation with Roles
```http
GET /index.php/apps/openregister/api/organisations/{uuid}
Response:
{
  "uuid": "org-123",
  "name": "Engineering Dept",
  "roles": ["engineers", "managers"],
  ...
}

PUT /index.php/apps/openregister/api/organisations/{uuid}
Request:
{
  "name": "Engineering Dept",
  "roles": ["engineers", "managers", "viewers"]
}
```

## Key Implementation Details

### 1. Groups API Integration
- Both modals use Nextcloud OCS API: `/ocs/v1.php/cloud/groups?format=json`
- Direct `fetch()` call (no `generateUrl()` since OCS is at root level)
- Returns array of group IDs: `{ocs: {data: {groups: ['admin', 'users', ...]}}}`

### 2. Data Flow
1. **Loading Groups**: `await loadNextcloudGroups()` fetches all Nextcloud groups
2. **Initialization**: Maps stored IDs to group objects for select component
3. **User Selection**: Select component works with group objects
4. **Saving**: Extracts IDs from objects: `groups.map(g => g.id)`
5. **Backend Storage**: Stores as JSON array of strings

### 3. Backwards Compatibility
- Organisation modal handles both legacy object format and new ID format
- Gracefully falls back to creating temporary objects if group not found

## UI Flow

### Application Modal
```
Tab: Basic Information
  ├─ Name *
  ├─ Description
  ├─ Organisation (select)
  └─ Nextcloud Groups (multi-select)

Tab: Resource Allocation
  ├─ Storage Quota
  ├─ Bandwidth Quota
  └─ API Request Quota

Tab: Security
  └─ List of selected groups (with remove buttons)
```

### Organisation Modal
```
Tab: Basic Information
  ├─ Name *
  ├─ Slug
  ├─ Description
  └─ Nextcloud Groups (multi-select)

Tab: Settings
  ├─ Default Organisation (checkbox)
  └─ Active (checkbox)

Tab: Resource Allocation
  ├─ Storage Quota
  ├─ Bandwidth Quota
  └─ API Request Quota

Tab: Security
  └─ List of selected groups (with remove buttons)
```

## Testing Checklist

- [x] Application entity has groups field
- [x] Application entity getters/setters work
- [x] Application entity serializes groups to JSON
- [x] Organisation entity has roles field
- [x] Database migration applied successfully
- [x] Applications table has groups column
- [x] Organisations table has roles column
- [x] EditApplication.vue loads groups from API
- [x] EditApplication.vue saves groups as IDs
- [x] EditApplication.vue displays groups in Security tab
- [x] EditOrganisation.vue loads groups from API
- [x] EditOrganisation.vue saves roles as IDs
- [x] EditOrganisation.vue displays groups in Security tab
- [x] Documentation updated

## Future Considerations

### Potential Enhancement: RBAC/Security Property
Currently, groups provide **access control** (who can access).

Future enhancement could add a separate **security/rbac** property for **permissions** (what they can do):

```json
{
  "groups": ["engineers", "managers"],
  "security": {
    "engineers": {
      "create": true,
      "read": true,
      "update": false,
      "delete": false
    },
    "managers": {
      "create": true,
      "read": true,
      "update": true,
      "delete": true
    }
  }
}
```

This would be similar to Schema's authorization property but at the application/organisation level.

## Files Modified

### Backend
- `openregister/lib/Db/Application.php`
- `openregister/lib/Db/Organisation.php` (documentation only)
- `openregister/lib/Migration/Version1Date20251102130000.php` (new)

### Frontend
- `openregister/src/modals/application/EditApplication.vue`
- `openregister/src/modals/organisation/EditOrganisation.vue`

### Documentation
- `openregister/website/docs/Features/applications.md` (new)
- `openregister/website/docs/Features/organisations.md` (new)
- `openregister/website/docs/features/organisation-roles.md` (updated)

## Completed By
AI Assistant with User Guidance - November 2, 2025

## Status
✅ **COMPLETE** - All changes verified and tested

