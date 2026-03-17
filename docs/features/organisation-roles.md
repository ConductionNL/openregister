---
sidebar_position: 8
title: Access Control (RBAC)
description: Role-Based Access Control — organisations, schemas, and properties
---

# Access Control (RBAC)

OpenRegister provides a multi-layered Role-Based Access Control system that controls access at three levels:

| Level | What it controls | Where configured |
|-------|-----------------|-----------------|
| **Organisation** | Who can manage registers, schemas, views, agents, and special actions | `Organisation.authorization` |
| **Schema** | Who can create, read, update, delete **objects** of a given type | `Schema.authorization` |
| **Property** | Who can read or update **individual fields** within an object | Per-property `authorization` inside the schema |

All three levels integrate with **Nextcloud groups** — no separate role management is needed.

---

## Core Concepts

### Groups

RBAC rules reference **Nextcloud groups** by their group ID. Two special group names exist:

| Group | Meaning |
|-------|---------|
| `"admin"` | Nextcloud administrators — **always bypass all RBAC checks** |
| `"public"` | Any logged-in user, regardless of group membership |

Unauthenticated (anonymous) requests are evaluated against `"public"` rules only.

### Evaluation Priorities

Permission checks follow this order (first match wins):

1. **Admin bypass** — users in the `admin` group always have full access
2. **Owner bypass** — the object owner always has full access to their own objects
3. **No authorization configured** — if the `authorization` field is empty/missing, all users have all permissions
4. **Missing action** — if a specific CRUD action is not listed, all users have that permission
5. **Rule matching** — rules are evaluated in order; first matching rule grants access

### RBAC Toggle

RBAC enforcement can be enabled or disabled globally via the admin settings API:

```
GET  /api/settings/rbac
PUT  /api/settings/rbac
```

When disabled, all permission checks are bypassed (all users can do everything).

---

## Level 1: Organisation Authorization

Organisations define **what entity types** their members can manage. This controls access to administrative resources like registers, schemas, views, and agents — not to individual data objects (that's schema-level authorization).

### Configuration

The `authorization` field on an Organisation entity uses a hierarchical structure with CRUD permissions per entity type:

```json
{
  "authorization": {
    "register": {
      "create": ["admin-group"],
      "read": ["staff", "viewers"],
      "update": ["admin-group"],
      "delete": ["admin-group"]
    },
    "schema": {
      "create": ["admin-group"],
      "read": ["staff", "viewers"],
      "update": ["admin-group"],
      "delete": ["admin-group"]
    },
    "object": {
      "create": ["staff"],
      "read": ["staff", "viewers"],
      "update": ["staff"],
      "delete": ["admin-group"]
    },
    "view": {
      "create": ["admin-group"],
      "read": ["staff", "viewers"],
      "update": ["admin-group"],
      "delete": ["admin-group"]
    },
    "agent": {
      "create": ["admin-group"],
      "read": ["staff"],
      "update": ["admin-group"],
      "delete": ["admin-group"]
    }
  }
}
```

### Entity Types

| Type | Controls access to |
|------|-------------------|
| `register` | Register management |
| `schema` | Schema management |
| `object` | Object CRUD (general, overridden by schema-level auth) |
| `view` | View management |
| `agent` | Agent management |

### Special Rights

In addition to entity-type CRUD, organisations can define special action permissions:

| Key | Controls |
|-----|---------|
| `object_publish` | Who can publish/depublish objects |
| `agent_use` | Who can execute agents |
| `dashboard_view` | Who can access the dashboard |
| `llm_use` | Who can use LLM features |

### Nextcloud Groups on Organisations

Each organisation has a `groups` field — an array of Nextcloud group IDs associated with it:

```json
{
  "uuid": "org-uuid-123",
  "name": "My Organisation",
  "groups": ["staff", "editors", "viewers"]
}
```

Users who belong to these Nextcloud groups automatically get access based on the organisation's `authorization` rules.

### Managing via UI

1. Navigate to **Organisation Details** or **Organisation List**
2. Click **Edit** on an organisation
3. In the **Edit Organisation** modal:
   - **Basic Information Tab**: Use the "Nextcloud Groups" multi-select dropdown to assign groups
   - **Security Tab**: View and manage the authorization rules
4. Click **Save** to persist changes

### Managing via API

```bash
# Get organisation (includes groups and authorization)
GET /api/organisations/{uuid}

# Update organisation groups and authorization
PUT /api/organisations/{uuid}
{
  "groups": ["staff", "editors"],
  "authorization": {
    "register": { "create": ["editors"], "read": ["staff"] }
  }
}

# Join an organisation
POST /api/organisations/{uuid}/join

# Leave an organisation
POST /api/organisations/{uuid}/leave

# Get/set active organisation
GET  /api/organisations/active
POST /api/organisations/{uuid}/set-active
```

### Organisation Hierarchy

Organisations support parent-child relationships:

- Set via the `parent` field (UUID of parent organisation)
- **Children can see all resources from parent organisations** (including depublished items)
- Parents **cannot** see child resources
- Users can see depublished items from their **own** organisation

---

## Level 2: Schema Authorization

Schema authorization controls who can **create, read, update, and delete objects** of a given type. This is the main RBAC mechanism for data access.

### Configuration

The `authorization` field on a Schema entity maps CRUD actions to arrays of rules:

```json
{
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["public"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}
```

### Full Schema Examples

Below are complete schema JSON examples showing different authorization patterns. These are the same configurations used in the OpenRegister test suite.

#### Example 1: Open Access (No Restrictions)

Any user (including unauthenticated) can perform all operations. This is the default when no `authorization` is set.

```json
{
  "title": "Public Knowledge Base",
  "description": "Open wiki-style content",
  "properties": {
    "title": { "type": "string", "required": true },
    "content": { "type": "string" },
    "category": { "type": "string" }
  },
  "authorization": {}
}
```

| User | Create | Read | Update | Delete |
|------|--------|------|--------|--------|
| Admin | Yes | Yes | Yes | Yes |
| Any logged-in user | Yes | Yes | Yes | Yes |
| Anonymous | Yes | Yes | Yes | Yes |

#### Example 2: Public Read, Restricted Write

Anyone can read, but only specific groups can create, update, or delete. Good for catalogues, directories, and published content.

```json
{
  "title": "Software Module",
  "description": "Published software catalogue entry",
  "properties": {
    "naam": { "type": "string", "required": true },
    "beschrijving": { "type": "string" },
    "versie": { "type": "string" },
    "status": { "type": "string", "enum": ["concept", "actief", "ingetrokken"] }
  },
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["public"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}
```

| User | Create | Read | Update | Delete |
|------|--------|------|--------|--------|
| Admin | Yes | Yes | Yes | Yes |
| `editors` group | Yes | Yes* | Yes | No |
| `managers` group | Yes | Yes* | Yes | Yes |
| `viewers` group | No | Yes | No | No |
| Anonymous | No | Yes | No | No |

*Editors and managers also get read access because logged-in users inherit `public` rights.

#### Example 3: Staff Only (Internal Data)

All operations restricted to a single group, with deletion reserved for managers. Good for internal records, HR data, or confidential information.

```json
{
  "title": "Medewerker",
  "description": "Internal employee record",
  "properties": {
    "naam": { "type": "string", "required": true },
    "email": { "type": "string", "format": "email" },
    "afdeling": { "type": "string" },
    "startdatum": { "type": "string", "format": "date" }
  },
  "authorization": {
    "create": ["staff"],
    "read": ["staff"],
    "update": ["staff"],
    "delete": ["managers", "staff"]
  }
}
```

| User | Create | Read | Update | Delete |
|------|--------|------|--------|--------|
| Admin | Yes | Yes | Yes | Yes |
| `staff` group | Yes | Yes | Yes | Yes |
| `managers` group | No | No | No | Yes |
| Any other user | No | No | No | No |
| Anonymous | No | No | No | No |

#### Example 4: Collaborative (Tiered Access)

Multiple groups with escalating privileges. Good for team workflows with viewers, editors, and administrators.

```json
{
  "title": "Zaak",
  "description": "Case management record",
  "properties": {
    "onderwerp": { "type": "string", "required": true },
    "beschrijving": { "type": "string" },
    "status": { "type": "string", "enum": ["open", "in_behandeling", "afgerond"] },
    "verantwoordelijke": { "type": "string" },
    "deadline": { "type": "string", "format": "date" }
  },
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["viewers", "editors", "managers"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}
```

| User | Create | Read | Update | Delete |
|------|--------|------|--------|--------|
| Admin | Yes | Yes | Yes | Yes |
| `viewers` group | No | Yes | No | No |
| `editors` group | Yes | Yes | Yes | No |
| `managers` group | Yes | Yes | Yes | Yes |
| Anonymous | No | No | No | No |

#### Example 5: Conditional Access (Organisation-Scoped)

Access depends on object data matching the user's context. Good for multi-tenant data where organisations should only see their own entries.

```json
{
  "title": "Gebruik",
  "description": "Software usage record per organisation",
  "properties": {
    "module": { "type": "string", "required": true },
    "aanbieder": { "type": "string", "description": "Organisation UUID of the provider" },
    "status": { "type": "string" },
    "geregistreerdDoor": { "type": "string" }
  },
  "authorization": {
    "read": [
      { "group": "public", "match": { "geregistreerdDoor": "Leverancier" } },
      "gebruik-beheerder"
    ],
    "create": ["gebruik-beheerder"],
    "update": [
      { "group": "gebruik-beheerder", "match": { "_organisation": "$organisation" } }
    ],
    "delete": ["admin"]
  }
}
```

| User | Create | Read | Update | Delete |
|------|--------|------|--------|--------|
| Admin | Yes | Yes | Yes | Yes |
| `gebruik-beheerder` (same org) | Yes | Yes | Yes | No |
| `gebruik-beheerder` (different org) | Yes | Yes | No | No |
| Any logged-in (object has `geregistreerdDoor = "Leverancier"`) | No | Yes | No | No |
| Any logged-in (other objects) | No | No | No | No |

#### Example 6: Full Schema with Property-Level Authorization

Combines schema-level and property-level authorization. Some fields have stricter access than the object itself.

```json
{
  "title": "Gebruik",
  "description": "Usage record with restricted internal notes",
  "properties": {
    "module": {
      "type": "string",
      "required": true
    },
    "status": {
      "type": "string",
      "enum": ["aangevraagd", "actief", "beeindigd"]
    },
    "aanbieder": {
      "type": "string"
    },
    "interneAantekening": {
      "type": "string",
      "title": "Interne Aantekening",
      "description": "Only visible to users in the same organisation",
      "authorization": {
        "read": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ],
        "update": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ]
      }
    },
    "beoordeling": {
      "type": "string",
      "title": "Beoordeling",
      "description": "Only managers can modify this field",
      "authorization": {
        "read": ["gebruik-beheerder"],
        "update": ["managers"]
      }
    }
  },
  "authorization": {
    "create": ["gebruik-beheerder"],
    "read": ["gebruik-beheerder"],
    "update": ["gebruik-beheerder"],
    "delete": ["managers"]
  }
}
```

**What happens:**

| Field | `gebruik-beheerder` (same org) | `gebruik-beheerder` (different org) | `managers` |
|-------|-------------------------------|-------------------------------------|-----------|
| `module` | Read + Write | Read + Write | Read + Write |
| `status` | Read + Write | Read + Write | Read + Write |
| `interneAantekening` | Read + Write | **Hidden** | Read + Write** |
| `beoordeling` | Read only | Read only | Read + Write |

** Managers also get `interneAantekening` access if they're in the same organisation.

### Rule Types

#### Simple Rule (Unconditional)

A string representing a group name. Users in this group always have access:

```json
{
  "authorization": {
    "read": ["admin", "editors"]
  }
}
```

#### Conditional Rule (Object-Based)

An object with a `group` and optional `match` conditions. Access is granted only when the object matches the specified conditions:

```json
{
  "authorization": {
    "read": [
      {
        "group": "public",
        "match": { "status": "published" }
      }
    ]
  }
}
```

### Multiple Rules (OR Logic)

Multiple rules in the array are evaluated with **OR** logic — access is granted if **any** rule matches:

```json
{
  "authorization": {
    "read": [
      "admin",
      { "group": "public", "match": { "geregistreerdDoor": "Leverancier" } },
      { "group": "gebruik-beheerder" }
    ]
  }
}
```

This grants read access if:
- User is in `admin` group, **OR**
- User is logged in AND object has `geregistreerdDoor = "Leverancier"`, **OR**
- User is in `gebruik-beheerder` group

### Dynamic Variables

Match conditions support dynamic variables that are resolved at query time:

| Variable | Resolves to |
|----------|------------|
| `$organisation` | Current user's active organisation UUID |
| `$activeOrganisation` | Alias for `$organisation` |
| `$userId` | Current user's ID |
| `$user` | Alias for `$userId` |

```json
{
  "group": "public",
  "match": { "aanbieder": "$organisation" }
}
```

This grants access when the `aanbieder` property matches the user's active organisation.

If a dynamic variable cannot be resolved (e.g., user has no active organisation), the condition is **not** met.

### Match Operators

The `match` property supports various operators:

| Operator | Example | Description |
|----------|---------|-------------|
| *(shorthand)* | `{ "field": "value" }` | Equals |
| `$eq` | `{ "field": { "$eq": "value" } }` | Equals (explicit) |
| `$ne` | `{ "field": { "$ne": "value" } }` | Not equals |
| `$in` | `{ "field": { "$in": ["a", "b"] } }` | In array |
| `$nin` | `{ "field": { "$nin": ["a", "b"] } }` | Not in array |
| `$exists` | `{ "field": { "$exists": true } }` | Not null |
| `$gt` / `$gte` | `{ "field": { "$gt": 5 } }` | Greater than (or equal) |
| `$lt` / `$lte` | `{ "field": { "$lt": 10 } }` | Less than (or equal) |

### Multiple Conditions (AND Logic)

When multiple properties are specified in `match`, **all** conditions must be met:

```json
{
  "group": "public",
  "match": {
    "status": "published",
    "visibility": "public"
  }
}
```

### Special Fields

| Field | Matches against |
|-------|----------------|
| `_organisation` | The object's `@self.organisation` metadata field |
| *(any other)* | The object's data properties |

### Complete Example

A module schema where:
- Modules registered by "Leverancier" are publicly readable
- Other modules require the `gebruik-beheerder` group
- Only `gebruik-beheerder` can create/update
- Only `admin` can delete

```json
{
  "authorization": {
    "read": [
      { "group": "public", "match": { "geregistreerdDoor": "Leverancier" } },
      "gebruik-beheerder"
    ],
    "create": ["gebruik-beheerder"],
    "update": ["gebruik-beheerder"],
    "delete": ["admin"]
  }
}
```

---

## Level 3: Property Authorization

Property-level authorization controls access to **individual fields** within objects, independently from the schema-level RBAC.

### Use Case

Consider a `gebruik` (usage) schema where most properties can be read by anyone with the `gebruik-beheerder` group, but the `interneAantekening` (internal notes) field should only be visible to users in the same organisation as the object.

### Configuration

Property authorization is configured inside the schema's `properties` definition using an `authorization` key on individual properties:

```json
{
  "properties": {
    "naam": {
      "type": "string",
      "title": "Naam"
    },
    "interneAantekening": {
      "type": "string",
      "title": "Interne Aantekening",
      "authorization": {
        "read": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ],
        "update": [
          { "group": "public", "match": { "_organisation": "$organisation" } }
        ]
      }
    }
  }
}
```

- `naam`: No property-level authorization — follows schema-level RBAC
- `interneAantekening`: Only readable/writable if user's active organisation matches the object's organisation

### Supported Actions

| Action | Effect |
|--------|--------|
| `read` | Controls whether the property appears in API responses |
| `update` | Controls whether the property can be modified |

### Rule Structure

Property authorization uses the **exact same rule structure** as schema authorization — simple rules, conditional rules, dynamic variables, and match operators all work identically.

### Read Filtering (Outgoing Data)

When an API response is rendered, properties are filtered based on read rules:

```
GET /api/objects/register/schema/uuid

# User in matching organisation:
{ "naam": "Example", "interneAantekening": "Private note" }

# User in different organisation:
{ "naam": "Example" }
```

The `interneAantekening` field is silently removed from the response for unauthorized users.

### Update Validation (Incoming Data)

When an API request modifies an object, property update rules are checked:

```
PUT /api/objects/register/schema/uuid
{ "naam": "Updated Name", "interneAantekening": "New note" }

# If unauthorized for interneAantekening:
{ "error": "You are not authorized to modify the following properties: interneAantekening" }
```

Unchanged properties are **skipped** during update validation — this allows PATCH-style updates without triggering authorization errors on fields the user didn't modify.

### Object Creation

During object creation, property authorization rules apply **except** for organisation matching. This is because there is no existing object to match the organisation against yet. Other match conditions (like `$userId`) still apply on create.

### Extended Objects

Property authorization is applied recursively to extended/nested objects. Each object is evaluated against its own schema's property authorization rules.

---

## Enforcement Architecture

### Handler Pipeline

RBAC is enforced by dedicated handlers in the object lifecycle:

```
Request → PermissionHandler → SaveObject/RenderObject → Response
                                    ↓
                          PropertyRbacHandler
```

| Handler | Responsibility |
|---------|---------------|
| `PermissionHandler` | Schema-level RBAC — checks if user can perform CRUD action |
| `PropertyRbacHandler` | Property-level RBAC — filters fields on read, validates fields on write |
| `MagicRbacHandler` | Applies RBAC filters directly in SQL for magic table queries |

### Where Checks Happen

| Operation | Schema RBAC | Property RBAC |
|-----------|------------|---------------|
| **Create** | `SaveObject` calls `PermissionHandler.checkPermission()` | `PropertyRbacHandler.getUnauthorizedProperties()` validates incoming data |
| **Read** | `PermissionHandler.hasPermission()` filters the result set | `PropertyRbacHandler.filterReadableProperties()` strips unauthorized fields from response |
| **Update** | `SaveObject` calls `PermissionHandler.checkPermission()` | `PropertyRbacHandler.getUnauthorizedProperties()` validates incoming data |
| **Delete** | `SaveObject` calls `PermissionHandler.checkPermission()` | N/A |
| **List** | `PermissionHandler.filterObjectsForPermissions()` filters results | Property filtering applied per-object during rendering |

### Database-Level Enforcement

For magic table queries, `MagicRbacHandler` pushes RBAC filters into SQL WHERE clauses, ensuring unauthorized objects are never loaded from the database. This provides:
- Better performance (no post-load filtering)
- Correct pagination (filtered before limit/offset)
- Publication-based public access controls

---

## Multi-Tenancy Integration

RBAC works alongside the multi-tenancy system. They are complementary but independent:

| System | Controls | Toggle |
|--------|---------|--------|
| **Multi-tenancy** | Users only see objects from their active organisation | `/api/settings/multitenancy` |
| **RBAC** | Users can only perform actions their groups allow | `/api/settings/rbac` |

Both can be enabled or disabled independently. When both are active:

1. Multi-tenancy filters objects by organisation **first**
2. RBAC filters the remaining objects by permission

### Active Organisation

Users must have an **active organisation** set to access data (even admins). The active organisation:
- Determines which objects are visible (multi-tenancy)
- Resolves the `$organisation` / `$activeOrganisation` variable in match conditions (RBAC)
- Is stamped on newly created objects as `@self.organisation`

```bash
# Get current user's active organisation
GET /api/organisations/active

# Set active organisation
POST /api/organisations/{uuid}/set-active
```

### Published Object Bypass

When `publishedObjectsBypassMultiTenancy` is enabled in config, published objects (with a `published` date set and no `depublished` date, or `depublished` in the future) are visible across all organisations. Depublished objects remain restricted to their own organisation.

---

## Validation

### Schema Authorization Validation

When a schema is saved, the authorization structure is validated:

- Actions must be one of: `create`, `read`, `update`, `delete`
- Each action maps to an array of rules
- Each rule must be either a string (group name) or an object with a `group` key
- Conditional rules may include a `match` object

Invalid structures produce validation errors.

### Property Authorization Validation

Property authorization is validated alongside the schema:

- Only `read` and `update` actions are supported (not `create` or `delete`)
- Same rule structure validation as schema-level
- Invalid dynamic variable names are flagged

---

## API Reference

### Organisation Endpoints

```
GET    /api/organisations                     # List all organisations (admin)
POST   /api/organisations                     # Create organisation (admin)
GET    /api/organisations/{uuid}              # Get organisation
PUT    /api/organisations/{uuid}              # Update organisation
PATCH  /api/organisations/{uuid}              # Partial update
POST   /api/organisations/{uuid}/join         # Join organisation
POST   /api/organisations/{uuid}/leave        # Leave organisation
GET    /api/organisations/active              # Get active organisation
POST   /api/organisations/{uuid}/set-active   # Set active organisation
```

### Settings Endpoints

```
GET    /api/settings/rbac                     # Get RBAC settings
PUT    /api/settings/rbac                     # Update RBAC settings
GET    /api/settings/multitenancy             # Get multi-tenancy settings
PUT    /api/settings/multitenancy             # Update multi-tenancy settings
GET    /api/settings/organisation             # Get organisation settings
PUT    /api/settings/organisation             # Update organisation settings
```

---

## Test Coverage

The RBAC system has comprehensive test coverage:

| Test File | Tests | Coverage |
|-----------|-------|---------|
| `RbacTest.php` | 14 | Core Schema permission logic, admin/owner overrides |
| `RbacComprehensiveTest.php` | 79 | All 64 RBAC scenarios (4 schema types x 4 user types x 4 operations) + owner privileges + validation |
| `ObjectServiceRbacTest.php` | 13+ | Integration with ObjectService, Nextcloud dependency mocking |

### Tested Scenarios

The comprehensive test matrix covers:

| Schema Type | Description |
|-------------|-------------|
| Open | No authorization — all access allowed |
| Public-read | Read open, create/update/delete restricted |
| Staff-only | All actions restricted to staff group |
| Collaborative | Different groups for different actions |

Each schema type is tested with 4 user types (admin, public, group1, group2) across all 4 CRUD operations, plus owner override tests.

---

## Best Practices

1. **Start with schema authorization** — most use cases only need object-level CRUD control
2. **Add property authorization sparingly** — it adds processing overhead; only use when fields truly need different access rules
3. **Use `"public"` for open read access** — rather than listing every group
4. **Leave actions unconfigured for open access** — an action not listed in `authorization` allows all users
5. **Test with multiple user types** — verify admin, owner, group member, and unauthenticated access
6. **Use descriptive Nextcloud group names** — e.g., `marketing-editors` instead of `group1`

---

## Troubleshooting

### Users Can't Access Objects

1. Check if RBAC is enabled: `GET /api/settings/rbac`
2. Check the schema's `authorization` field — is the user's group listed for the action?
3. Check if the user has an active organisation set: `GET /api/organisations/active`
4. Check if multi-tenancy is enabled and the object belongs to the user's organisation

### Admin Can't See Data

Admins bypass RBAC but still need an **active organisation** when multi-tenancy is enabled. Set one via:
```bash
POST /api/organisations/{uuid}/set-active
```

### Property Fields Missing from Response

This is likely property-level authorization filtering. Check the schema's property definitions for `authorization` rules on the missing field.

### Conditional Rules Not Matching

1. Verify the dynamic variable resolves — does the user have an active organisation?
2. Check the field name matches exactly (case-sensitive)
3. For `_organisation` matches, the comparison is against `@self.organisation`, not a data field
4. If the object is a resolved relation (array with `id` key), the system extracts the `id` automatically
