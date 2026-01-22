# Property-Level Authorization

OpenRegister supports fine-grained property-level authorization, allowing you to control access to specific fields within objects independently from the object-level RBAC.

## Overview

While [conditional authorization](./conditional-authorization.md) controls access to entire objects, property-level authorization lets you:

- Restrict read access to specific properties based on user context
- Restrict update access to specific properties based on user context
- Apply different access rules per property on the same object

## Use Case Example

Consider a `gebruik` (usage) schema where:
- Most properties can be read by anyone with the `gebruik-beheerder` group
- The `interneAantekening` (internal notes) property should only be readable and writable by users belonging to the same organisation as the object

## Configuration

Property-level authorization is configured in the schema's `properties` definition using an `authorization` key:

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

In this example:
- `naam`: No property-level authorization, follows object-level RBAC
- `interneAantekening`: Only readable/writable if user's active organisation matches the object's `_organisation`

## Authorization Rules Structure

Property authorization uses the same rule structure as schema-level authorization:

### Simple Rules

Grant access based on group membership:

```json
{
  "authorization": {
    "read": ["admin", "editors"]
  }
}
```

### Conditional Rules

Grant access based on group membership AND object data matching:

```json
{
  "authorization": {
    "read": [
      { "group": "public", "match": { "_organisation": "$organisation" } },
      { "group": "admin" }
    ]
  }
}
```

## Supported Actions

- **`read`**: Controls whether a user can view the property value in API responses
- **`update`**: Controls whether a user can modify the property value

## Dynamic Variables

The following dynamic variables are available in match conditions:

| Variable | Description |
|----------|-------------|
| `$organisation` | Current user's active organisation UUID |
| `$activeOrganisation` | Alias for `$organisation` |
| `$userId` | Current user's ID |
| `$user` | Alias for `$userId` |

## Match Operators

The same operators available in conditional authorization work for property authorization:

| Operator | Description |
|----------|-------------|
| `$eq` | Equals |
| `$ne` | Not equals |
| `$in` | Value in array |
| `$nin` | Value not in array |
| `$exists` | Property exists (or not) |
| `$gt`, `$gte` | Greater than (or equal) |
| `$lt`, `$lte` | Less than (or equal) |

## Behavior

### Read Filtering (Outgoing Data)

When an API request returns objects:

1. The system checks if the schema has any properties with authorization
2. For each property with authorization:
   - The `read` rules are evaluated against the object data and user context
   - If the user cannot read the property, it's stripped from the response

```
GET /api/objects/register/schema/uuid

Response for user in matching organisation:
{
  "naam": "Example",
  "interneAantekening": "Private note"
}

Response for user in different organisation:
{
  "naam": "Example"
  // interneAantekening is not included
}
```

### Update Validation (Incoming Data)

When an API request modifies an object:

1. The system checks if the schema has any properties with authorization
2. For each property being submitted that has authorization:
   - The `update` rules are evaluated against the existing object and user context
   - If the user cannot update the property, a validation error is thrown

```
PUT /api/objects/register/schema/uuid
{
  "naam": "Updated Name",
  "interneAantekening": "New note"  // Unauthorized
}

Response (if unauthorized):
{
  "error": "You are not authorized to modify the following properties: interneAantekening"
}
```

### Object Creation

During object creation:

- Property authorization rules apply, **except** for organisation matching
- This is because there's no existing object to match the organisation against
- Other match conditions (like `$userId`) still apply on create

### Admin Override

Users in the `admin` group bypass all property-level authorization checks, just like object-level RBAC.

### Extended Objects (_extend)

Property authorization is applied recursively to extended/nested objects. Each object is evaluated against its own schema's property authorization rules.

## Performance Considerations

- Property authorization evaluation happens at render time
- Schemas with property authorization trigger the render pipeline even without explicit `_extend` parameters
- The system caches schema property authorization checks to minimize database queries

## Validation

Property authorization rules are validated when the schema is saved. Invalid configurations will produce validation errors:

- Unknown actions (only `read` and `update` are supported)
- Invalid rule structure
- Missing `group` key in conditional rules
- Invalid dynamic variable names

## Best Practices

1. **Use sparingly**: Property authorization adds processing overhead. Use it only when truly needed.
2. **Combine with object RBAC**: Property authorization works alongside object-level RBAC, not instead of it.
3. **Test thoroughly**: Ensure both read filtering and update validation work as expected.
4. **Document rules**: Use clear property titles and descriptions to indicate access restrictions.

## Related Documentation

- [Conditional Authorization](./conditional-authorization.md) - Object-level RBAC with conditions
- [RBAC Overview](./rbac.md) - Role-based access control basics
- [Schema Configuration](../configuration/schemas.md) - Schema definition reference
