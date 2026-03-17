# @self Metadata Handling

The OpenRegister application uses a sophisticated @self metadata system to manage object ownership, organization assignment, and publication states. This system ensures proper data integrity and supports advanced features like organization self-ownership.

## Overview

The @self metadata is a special object property that contains system-managed information about an object. It includes fields like `owner`, `organisation`, `published`, `depublished`, and other metadata that control object behavior and access.

## @self Metadata Fields

### Core Fields
- **`id`**: Object UUID
- **`name`**: Object display name
- **`register`**: Register identifier
- **`schema`**: Schema identifier
- **`created`**: Creation timestamp
- **`updated`**: Last update timestamp

### Ownership and Organization
- **`owner`**: UUID of the user or entity that owns this object
- **`organisation`**: UUID of the organization this object belongs to

### Publication State
- **`published`**: Publication timestamp (when object becomes publicly visible)
- **`depublished`**: Depublication timestamp (when object becomes private again)
- **`deleted`**: Soft deletion timestamp

## Setting @self Metadata via API

### Allowed Properties

When creating or updating objects via the API, you can explicitly set certain @self metadata properties:

```json
{
  "naam": "My Organization",
  "type": "Leverancier",
  "@self": {
    "owner": "organization-uuid",
    "organisation": "organization-uuid",
    "published": "2024-01-01T00:00:00Z",
    "depublished": null
  }
}
```

### Supported @self Properties

The following @self properties can be explicitly set via API requests:
- `owner`
- `organisation` 
- `published`
- `depublished`

Other @self properties are system-managed and cannot be overridden.

## Organization Self-Ownership

A key feature of the @self metadata system is support for organization self-ownership, where an organization object owns itself.

### Use Case: Organization Activation

When an organization is activated in external applications (like softwarecatalog), the organization should become the owner of its own object:

```json
{
  "status": "Actief",
  "@self": {
    "owner": "organization-uuid",
    "organisation": "organization-uuid"
  }
}
```

### Implementation Details

The system handles organization self-ownership through several components:

#### 1. ObjectsController Filtering

The `ObjectsController` allows specific @self properties to pass through request filtering:

```php
// Allow specific @self metadata properties for organization activation
$requestParams = $this->request->getParams();
if (isset($requestParams['@self']) && is_array($requestParams['@self'])) {
    $allowedSelfProperties = ['owner', 'organisation', 'published', 'depublished'];
    $filteredSelf = array_intersect_key(
        $requestParams['@self'],
        array_flip($allowedSelfProperties)
    );
    if (!empty($filteredSelf)) {
        $object['@self'] = $filteredSelf;
    }
}
```

#### 2. SaveObject Metadata Handling

The `SaveObject` service processes @self metadata and applies it to the object entity:

```php
// Set organisation from @self metadata if provided
if (array_key_exists('organisation', $selfData) && !empty($selfData['organisation'])) {
    $objectEntity->setOrganisation($selfData['organisation']);
}

// Set owner from @self metadata if provided  
if (array_key_exists('owner', $selfData) && !empty($selfData['owner'])) {
    $objectEntity->setOwner($selfData['owner']);
}
```

#### 3. Active Organization Override Prevention

The system prevents automatic organization assignment from overriding explicit @self metadata:

```php
// Set organisation from active organisation if not already set
// BUT: Don't override if organisation was explicitly set via @self metadata
if (($objectEntity->getOrganisation() === null || $objectEntity->getOrganisation() === '') 
    && !isset($selfData['organisation'])) {
    $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
    $objectEntity->setOrganisation($organisationUuid);
}
```

## Best Practices

### 1. Organization Self-Ownership

When implementing organization activation:

```php
// ✅ Correct: Set both owner and organisation to the organization's UUID
$updateData = [
    'status' => 'Actief',
    '@self' => [
        'owner' => $organizationUuid,
        'organisation' => $organizationUuid
    ]
];
```

### 2. Publication Management

When managing object publication:

```php
// ✅ Publish an object
$updateData = [
    '@self' => [
        'published' => date('c'), // Current timestamp
        'depublished' => null
    ]
];

// ✅ Depublish an object
$updateData = [
    '@self' => [
        'depublished' => date('c') // Current timestamp
    ]
];
```

### 3. Ownership Transfer

When transferring object ownership:

```php
// ✅ Transfer ownership to another user/organization
$updateData = [
    '@self' => [
        'owner' => $newOwnerUuid,
        'organisation' => $newOrganisationUuid
    ]
];
```

## Security Considerations

### 1. Access Control

- Only authorized users can modify @self metadata
- RBAC rules apply to @self metadata modifications
- Admin users have broader @self metadata modification rights

### 2. Data Integrity

- The system validates @self metadata values
- Invalid UUIDs are rejected
- Circular ownership references are prevented

### 3. Audit Trail

- All @self metadata changes are logged
- Ownership transfers are tracked
- Publication state changes are recorded

## Troubleshooting

### Common Issues

#### 1. @self Metadata Not Applied

**Problem**: @self metadata in API request is ignored

**Solution**: Ensure you're setting allowed properties (`owner`, `organisation`, `published`, `depublished`)

```php
// ❌ Wrong: Setting non-allowed property
{
  "@self": {
    "created": "2024-01-01T00:00:00Z"  // This will be ignored
  }
}

// ✅ Correct: Setting allowed property
{
  "@self": {
    "owner": "user-uuid"  // This will be applied
  }
}
```

#### 2. Organization Not Self-Owning

**Problem**: Organization activation doesn't set self-ownership

**Solution**: Explicitly set both `owner` and `organisation` in @self metadata:

```php
// ✅ Correct approach
$updateData = [
    'status' => 'Actief',
    '@self' => [
        'owner' => $organizationUuid,
        'organisation' => $organizationUuid
    ]
];
```

#### 3. Active Organization Override

**Problem**: System overrides explicit @self.organisation with active organization

**Solution**: The system now respects explicit @self metadata and won't override it with active organization settings.

## API Examples

### Create Object with Self-Ownership

```bash
POST /api/objects/1/7
Content-Type: application/json

{
  "naam": "My Organization",
  "type": "Leverancier",
  "@self": {
    "owner": "org-uuid-123",
    "organisation": "org-uuid-123"
  }
}
```

### Update Object Publication State

```bash
PATCH /api/objects/1/7/object-uuid
Content-Type: application/json

{
  "@self": {
    "published": "2024-01-01T00:00:00Z",
    "depublished": null
  }
}
```

### Transfer Object Ownership

```bash
PATCH /api/objects/1/7/object-uuid
Content-Type: application/json

{
  "@self": {
    "owner": "new-owner-uuid",
    "organisation": "new-org-uuid"
  }
}
```

## Related Documentation

- [Objects API](../api/objects.md) - Complete API reference
- [Object Handling](./object-handling.md) - Object service usage
- [Multi-tenancy](../Features/multi-tenancy.md) - Organization-based access control
- [Access Control](../Features/access-control.md) - RBAC and permissions
