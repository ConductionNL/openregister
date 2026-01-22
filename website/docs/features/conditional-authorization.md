# Conditional Authorization

OpenRegister supports conditional authorization, allowing fine-grained access control based on object properties. This enables scenarios where different objects within the same schema have different visibility rules.

## Basic Structure

Authorization is configured at the schema level using the `authorization` property. The structure follows CRUD operations (Create, Read, Update, Delete) as the primary division:

```json
{
  "authorization": {
    "read": [...],
    "create": [...],
    "update": [...],
    "delete": [...]
  }
}
```

## Authorization Rules

Each CRUD action contains an array of authorization rules. A rule can be:

### 1. Simple Group (Unconditional)

A string representing a group name. Users in this group always have access.

```json
{
  "authorization": {
    "read": ["admin", "editors"]
  }
}
```

### 2. Conditional Rule (Object-Based)

An object with a `group` and optional `match` conditions. Access is granted only when the object matches the specified conditions.

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

## Special Groups

- `"public"` - Allows access to any logged-in user (no specific group membership required)
- `"admin"` - Nextcloud admin users (always bypass all RBAC checks)

## Dynamic Variables

The `match` property supports dynamic variables that are resolved at query time:

### $organisation / $activeOrganisation
Resolves to the current user's active organisation UUID.

```json
{
  "group": "public",
  "match": { "aanbieder": "$organisation" }
}
```
This grants access when the `aanbieder` property matches the user's active organisation.

### $userId / $user
Resolves to the current user's ID.

```json
{
  "group": "public",
  "match": { "createdBy": "$userId" }
}
```
This grants access when the `createdBy` property matches the current user.

**Note:** If a dynamic variable cannot be resolved (e.g., user has no active organisation), the condition is not met.

## Match Operators

The `match` property supports various operators for flexible condition matching:

### Equals (Shorthand)
```json
{ "property": "value" }
```
Matches when `property` equals `"value"`.

### Equals (Explicit)
```json
{ "property": { "$eq": "value" } }
```
Same as shorthand, but explicit.

### Not Equals
```json
{ "property": { "$ne": "value" } }
```
Matches when `property` does NOT equal `"value"`.

### In Array
```json
{ "property": { "$in": ["value1", "value2"] } }
```
Matches when `property` equals any value in the array.

### Not In Array
```json
{ "property": { "$nin": ["value1", "value2"] } }
```
Matches when `property` does NOT equal any value in the array.

### Exists (Not Null)
```json
{ "property": { "$exists": true } }
```
Matches when `property` is not null.

### Not Exists (Is Null)
```json
{ "property": { "$exists": false } }
```
Matches when `property` is null.

## Multiple Conditions (AND)

When multiple properties are specified in `match`, ALL conditions must be met (AND logic):

```json
{
  "group": "public",
  "match": {
    "status": "published",
    "visibility": "public"
  }
}
```

## Multiple Rules (OR)

Multiple rules in the authorization array are evaluated with OR logic. Access is granted if ANY rule matches:

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
- User is in `admin` group, OR
- User is logged in AND object has `geregistreerdDoor = "Leverancier"`, OR
- User is in `gebruik-beheerder` group

## Complete Example

Here's a complete example for a module schema where:
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

## Evaluation Order

1. **Admin Bypass**: Admin users always have full access (no RBAC applied)
2. **No Authorization**: If schema has no `authorization` property, all access is allowed
3. **No Action Config**: If a specific action (e.g., `read`) is not configured, all access is allowed for that action
4. **Rule Evaluation**: Rules are evaluated in order; first matching rule grants access
5. **Owner Access**: Object owners always have access to their own objects (in addition to RBAC rules)

## Backward Compatibility

The simple array format is fully supported:

```json
{
  "authorization": {
    "read": ["public"],
    "create": ["editors"],
    "update": ["editors"],
    "delete": ["admin"]
  }
}
```

This is equivalent to unconditional access for the specified groups.
