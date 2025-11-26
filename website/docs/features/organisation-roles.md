---
sidebar_position: 8
title: Organisation Roles (RBAC)
description: Role-Based Access Control using Nextcloud Groups
---

# Organisation Roles

OpenRegister integrates with Nextcloud's group system to provide Role-Based Access Control (RBAC) at the organisation level.

## Overview

Each organisation can have one or more Nextcloud groups assigned as 'roles'. Users who belong to these groups automatically get access to the organisation's resources based on their group membership and Nextcloud's native permission system.

## Key Features

- **Nextcloud Integration**: Uses existing Nextcloud groups - no separate role management needed
- **Flexible Assignment**: Associate any number of Nextcloud groups with an organisation
- **Native Permissions**: Leverage Nextcloud's built-in group permissions and access controls
- **Easy Management**: Visual interface for assigning/removing groups from organisations

## Database Schema

### Organisation Entity

The `Organisation` entity includes:

```php
protected ?array $roles = [];
```

This JSON field stores an array of group definitions:

```json
{
  "roles": [
    {
      "id": "group-id",
      "name": "Group Display Name",
      "userCount": 5
    }
  ]
}
```

### Database Columns

| Column | Type | Description |
|--------|------|-------------|
| `roles` | `longtext` (JSON) | Array of Nextcloud group definitions |
| `active` | `tinyint(1)` | Whether the organisation is active |

## Managing Organisation Roles

### Via UI

There are two ways to manage organisation roles through the user interface:

#### Method 1: Edit Organisation Modal

1. Navigate to **Organisation Details** or **Organisation List**
2. Click **Edit** on an organisation or **Create Organisation** to add a new one
3. In the **Edit Organisation** modal:
   - **Basic Information Tab**: Use the 'Nextcloud Groups' multi-select dropdown to quickly add or remove groups
   - **Security Tab**: View the complete list of assigned groups with the ability to remove individual groups
4. Click **Save** or **Create** to persist changes

#### Method 2: Manage Roles Action (Legacy)

1. Navigate to **Organisation Details** or **Organisation List**
2. Click the **Actions** menu (three dots)
3. Select **Manage Roles**
4. In the modal:
   - View currently assigned groups
   - Add new groups from the dropdown
   - Remove groups by clicking the X icon
   - Click **Save Roles** to persist changes

**Recommended Approach**: Use the Edit Organisation modal (Method 1) for a streamlined experience where you can manage groups alongside other organisation settings.

### Via API

#### Get Organisation Roles

```bash
GET /index.php/apps/openregister/api/organisations/{uuid}
```

Response includes:

```json
{
  "uuid": "org-uuid-123",
  "name": "My Organisation",
  "roles": [
    {
      "id": "editors",
      "name": "Editors",
      "userCount": 10
    }
  ],
  "roleCount": 1
}
```

#### Update Organisation Roles

```bash
PUT /index.php/apps/openregister/api/organisations/{uuid}
```

Request body:

```json
{
  "roles": [
    {
      "id": "editors",
      "name": "Editors",
      "userCount": 10
    },
    {
      "id": "viewers",
      "name": "Viewers",
      "userCount": 50
    }
  ]
}
```

## PHP API

### Organisation Entity Methods

```php
// Add a role to the organisation
$organisation->addRole([
    'id' => 'group-id',
    'name' => 'Group Name',
    'userCount' => 5
]);

// Remove a role
$organisation->removeRole('group-id');

// Check if organisation has a role
if ($organisation->hasRole('group-id')) {
    // ...
}

// Get a specific role
$role = $organisation->getRole('group-id');

// Get all roles
$roles = $organisation->getRoles();

// Set all roles at once
$organisation->setRoles($rolesArray);
```

### Example: Adding Groups on Organisation Creation

```php
use OCA\OpenRegister\Service\OrganisationService;

$organisation = $organisationService->createOrganisation(
    'Research Department',
    'Handles all research activities'
);

// Add groups as roles
$organisation->addRole([
    'id' => 'researchers',
    'name' => 'Researchers',
    'userCount' => 25
]);

$organisation->addRole([
    'id' => 'lab-managers',
    'name' => 'Lab Managers',
    'userCount' => 5
]);

$organisationMapper->update($organisation);
```

## How It Works

### Group-Based Access

1. **Group Assignment**: Nextcloud groups are assigned to organisations as 'roles'
2. **User Membership**: Users are added to Nextcloud groups through normal Nextcloud user management
3. **Access Control**: When a user tries to access organisation resources:
   - Check if user is in the organisation
   - Check if user belongs to any of the organisation's assigned groups
   - Apply Nextcloud's native group permissions

### Integration with Nextcloud Groups

OpenRegister leverages Nextcloud's `/ocs/v2.php/cloud/groups/details` API to:
- List available groups
- Get group member counts
- Display group metadata

No duplication of user-role assignments - everything is managed through Nextcloud's native group system.

## Permissions

### Who Can Manage Roles?

Only users who can **edit** the organisation can manage its roles:

- Organisation owner
- System administrators
- Users with organisation edit permissions

### UI Visibility

The "Manage Roles" button only appears if:

```javascript
canEditOrganisation(organisation)
```

This ensures proper access control for role management.

## Use Cases

### Scenario 1: Department Organisation

```
Organisation: 'Engineering Department'
Roles:
  - 'engineers' (50 users) - Full access to engineering resources
  - 'engineering-managers' (5 users) - Administrative access
  - 'interns' (10 users) - Read-only access
```

### Scenario 2: Project-Based Organisation

```
Organisation: 'Website Redesign Project'
Roles:
  - 'designers' (8 users) - Design asset access
  - 'developers' (12 users) - Code repository access
  - 'project-managers' (2 users) - Full project oversight
```

### Scenario 3: Multi-Tenant Organisation

```
Organisation: 'Client: Acme Corp'
Roles:
  - 'acme-admins' (3 users) - Client administrators
  - 'acme-users' (100 users) - Regular client users
  - 'support-staff' (10 users) - Internal support team
```

## Best Practices

### 1. Use Descriptive Group Names

Create Nextcloud groups with clear, descriptive names:
- ✅ `marketing-editors`, `finance-viewers`
- ❌ `group1`, `temp-group`

### 2. Organize Groups Hierarchically

Use prefixes for related groups:
```
hr-administrators
hr-managers
hr-staff
hr-contractors
```

### 3. Regular Audits

Periodically review:
- Which groups are assigned to each organisation
- Whether group members still need access
- Unused or obsolete groups

### 4. Document Group Purposes

In Nextcloud's group descriptions, document:
- What access the group provides
- Which organisations use this group
- Who should be added to the group

### 5. Separate Concerns

Don't mix unrelated permissions in a single group:
- ✅ Create `project-a-editors` and `project-b-editors`
- ❌ Use single `editors` group for all projects

## Troubleshooting

### Groups Not Appearing in Dropdown

**Problem**: Available groups list is empty

**Solutions**:
1. Verify Nextcloud groups exist: Settings → Users → Groups
2. Check OCS API access: `curl -u admin:password http://nextcloud/ocs/v2.php/cloud/groups/details`
3. Review browser console for API errors

### Roles Not Saving

**Problem**: Changes to roles are not persisted

**Solutions**:
1. Verify user has edit permissions for the organisation
2. Check database column exists: `SHOW COLUMNS FROM oc_openregister_organisations LIKE 'roles'`
3. Run migrations: `php occ migrations:migrate openregister`
4. Check server logs for errors

### Default Organisation Flag Missing

**Problem**: Multiple organisations but none marked as default

**Solutions**:
1. Check database: `SELECT id, name, is_default FROM oc_openregister_organisations`
2. Set default manually:
```sql
UPDATE oc_openregister_organisations SET is_default = 1 WHERE id = 1 LIMIT 1;
```
3. Remove duplicates if needed

## API Reference

### ManageOrganisationRoles Modal Component

**Props**: None (uses organisationStore.organisationItem)

**Events**:
- Modal opened: Loads Nextcloud groups
- Role added: Updates local state
- Role removed: Updates local state
- Save clicked: Persists to backend via organisationStore

**Methods**:
- `loadNextcloudGroups()` - Fetches groups from Nextcloud OCS API
- `addRole(group)` - Adds a group to selected roles
- `removeRole(role)` - Removes a group from selected roles
- `saveRoles()` - Saves changes to backend

## Migration History

| Version | Migration | Description |
|---------|-----------|-------------|
| 1.0 | `Version1Date20250102000000` | Added `roles` column (JSON, default `[]`) |
| 1.0 | `Version1Date20250102000001` | Added `active` column (boolean, default `true`) |

## Future Enhancements

Potential improvements for future versions:

1. **Role Templates**: Pre-defined role sets for common scenarios
2. **Nested Groups**: Support for Nextcloud group hierarchies
3. **Permission Presets**: Quick-apply common permission patterns
4. **Audit Logging**: Track role assignment changes
5. **Bulk Operations**: Assign roles to multiple organisations at once
6. **Role Inheritance**: Child organisations inherit parent roles

## Related Documentation

- [Organisation Management](../Features/organisations.md)
- [RBAC (Role-Based Access Control)](../development/rbac.md)
- [Nextcloud Groups Documentation](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/user_configuration.html)

