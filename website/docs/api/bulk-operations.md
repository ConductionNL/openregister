# Bulk Operations API

The OpenRegister Bulk Operations API provides endpoints for performing bulk actions on multiple objects simultaneously. This is particularly useful for managing large datasets efficiently.

## Overview

Bulk operations allow you to perform the same action on multiple objects in a single API call, reducing the number of requests needed and improving performance. All bulk operations require admin privileges and support RBAC (Role-Based Access Control) and multi-organization filtering.

## Base URL

All bulk operation endpoints follow this pattern:
```
POST /api/bulk/{register}/{schema}/{operation}
```

Where:
- `{register}` - The register identifier
- `{schema}` - The schema identifier  
- `{operation}` - The operation to perform (save, delete, publish, depublish)

## Authentication

All bulk operations require:
- **Admin privileges** - Only admin users can perform bulk operations
- **Authentication** - Use basic auth with admin credentials
- **CSRF bypass** - Endpoints are marked with `@NoCSRFRequired` for API access

## Common Response Format

All bulk operations return a consistent JSON response format:

```json
{
  "success": true,
  "message": "Operation description",
  "operation_count": 0,
  "operation_uuids": [],
  "requested_count": 0,
  "skipped_count": 0,
  "additional_fields": "..."
}
```

Where:
- `success` - Boolean indicating if the operation completed successfully
- `message` - Human-readable description of the operation result
- `operation_count` - Number of objects that were actually processed
- `operation_uuids` - Array of UUIDs that were successfully processed
- `requested_count` - Number of objects requested for processing
- `skipped_count` - Number of objects that were skipped (due to permissions, not found, etc.)
- `additional_fields` - Operation-specific additional information

## Bulk Delete

Deletes multiple objects by UUID. Supports both soft delete and hard delete based on the current state of objects.

### Endpoint
```
POST /api/bulk/{register}/{schema}/delete
```

### Request Body
```json
{
  "uuids": ["uuid1", "uuid2", "uuid3"]
}
```

### Delete Behavior

- **Soft Delete**: If an object has no `deleted` value set, it performs a soft delete by setting the deleted timestamp
- **Hard Delete**: If an object already has a `deleted` value set, it performs a hard delete by removing the object from the database

### Example Request
```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  -H 'Content-Type: application/json' \
  -X POST \
  -d '{"uuids": ["550e8400-e29b-41d4-a716-446655440000", "550e8400-e29b-41d4-a716-446655440001"]}' \
  'http://localhost/index.php/apps/openregister/api/bulk/myregister/myschema/delete'
```

### Example Response
```json
{
  "success": true,
  "message": "Bulk delete operation completed successfully",
  "deleted_count": 2,
  "deleted_uuids": [
    "550e8400-e29b-41d4-a716-446655440000",
    "550e8400-e29b-41d4-a716-446655440001"
  ],
  "requested_count": 2,
  "skipped_count": 0
}
```

## Bulk Publish

Publishes multiple objects by setting their published timestamp.

### Endpoint
```
POST /api/bulk/{register}/{schema}/publish
```

### Request Body
```json
{
  "uuids": ["uuid1", "uuid2", "uuid3"],
  "datetime": "2024-01-01T12:00:00Z"
}
```

### Datetime Parameter

The `datetime` parameter controls when the publish timestamp is set:

- **`true`** (default) - Use current datetime
- **`false`** - Unset the published timestamp
- **`null`** - Use current datetime
- **ISO 8601 string** - Use the specified datetime (e.g., "2024-01-01T12:00:00Z")
- **DateTime object** - Use the specified datetime

### Example Request
```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  -H 'Content-Type: application/json' \
  -X POST \
  -d '{
    "uuids": ["550e8400-e29b-41d4-a716-446655440000"],
    "datetime": "2024-01-01T12:00:00Z"
  }' \
  'http://localhost/index.php/apps/openregister/api/bulk/myregister/myschema/publish'
```

### Example Response
```json
{
  "success": true,
  "message": "Bulk publish operation completed successfully",
  "published_count": 1,
  "published_uuids": ["550e8400-e29b-41d4-a716-446655440000"],
  "requested_count": 1,
  "skipped_count": 0,
  "datetime_used": "2024-01-01 12:00:00"
}
```

## Bulk Depublish

Depublishes multiple objects by setting their depublished timestamp.

### Endpoint
```
POST /api/bulk/{register}/{schema}/depublish
```

### Request Body
```json
{
  "uuids": ["uuid1", "uuid2", "uuid3"],
  "datetime": "2024-01-01T12:00:00Z"
}
```

### Datetime Parameter

Same behavior as bulk publish:

- **`true`** (default) - Use current datetime
- **`false`** - Unset the depublished timestamp
- **`null`** - Use current datetime
- **ISO 8601 string** - Use the specified datetime
- **DateTime object** - Use the specified datetime

### Example Request
```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  -H 'Content-Type: application/json' \
  -X POST \
  -d '{
    "uuids": ["550e8400-e29b-41d4-a716-446655440000"],
    "datetime": false
  }' \
  'http://localhost/index.php/apps/openregister/api/bulk/myregister/myschema/depublish'
```

### Example Response
```json
{
  "success": true,
  "message": "Bulk depublish operation completed successfully",
  "depublished_count": 1,
  "depublished_uuids": ["550e8400-e29b-41d4-a716-446655440000"],
  "requested_count": 1,
  "skipped_count": 0,
  "datetime_used": false
}
```

## Bulk Save

Saves multiple objects (creates new ones or updates existing ones) in a single operation.

### Endpoint
```
POST /api/bulk/{register}/{schema}/save
```

### Request Body
```json
{
  "objects": [
    {
      "@self": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Object 1",
        "description": "Description for object 1"
      }
    },
    {
      "@self": {
        "name": "Object 2",
        "description": "Description for object 2"
      }
    }
  ]
}
```

### Object Format

Objects should follow the standard OpenRegister object format with `@self` section containing the object data. Objects without an `id` field will be created as new objects, while objects with an existing `id` will be updated.

### Example Request
```bash
curl -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true' \
  -H 'Content-Type: application/json' \
  -X POST \
  -d '{
    "objects": [
      {
        "@self": {
          "name": "New Object",
          "description": "A new object created via bulk save"
        }
      }
    ]
  }' \
  'http://localhost/index.php/apps/openregister/api/bulk/myregister/myschema/save'
```

### Example Response
```json
{
  "success": true,
  "message": "Bulk save operation completed successfully",
  "saved_count": 1,
  "saved_objects": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440002",
      "name": "New Object",
      "description": "A new object created via bulk save",
      "created": "2024-01-01T12:00:00Z",
      "updated": "2024-01-01T12:00:00Z"
    }
  ],
  "requested_count": 1
}
```

## Error Handling

### Common Error Responses

#### 403 Forbidden - Insufficient Permissions
```json
{
  "error": "Insufficient permissions. Admin access required."
}
```

#### 400 Bad Request - Invalid Input
```json
{
  "error": "Invalid input. \"uuids\" array is required."
}
```

#### 400 Bad Request - Invalid Datetime Format
```json
{
  "error": "Invalid datetime format. Use ISO 8601 format (e.g., \"2024-01-01T12:00:00Z\")."
}
```

#### 500 Internal Server Error - Operation Failed
```json
{
  "error": "Bulk delete operation failed: Database connection error"
}
```

## Best Practices

### Performance Considerations

1. **Batch Size**: For large datasets, consider processing objects in batches of 100-1000 objects per request
2. **Rate Limiting**: Avoid sending too many requests simultaneously to prevent server overload
3. **Error Handling**: Always check the response for skipped objects and handle them appropriately

### Security Considerations

1. **Admin Access**: Only admin users can perform bulk operations
2. **RBAC Filtering**: Objects are automatically filtered based on user permissions
3. **Multi-Organization**: Objects are filtered based on the active organization context

### Data Validation

1. **Input Validation**: Always validate UUIDs and object data before sending requests
2. **Response Validation**: Check the response to ensure all expected objects were processed
3. **Error Recovery**: Implement retry logic for failed operations

## Implementation Details

### Database Transactions

All bulk operations are performed within database transactions to ensure data consistency. If any part of the operation fails, the entire transaction is rolled back.

### Permission Filtering

Objects are automatically filtered based on:
- **RBAC permissions**: User must have appropriate permissions for the schema
- **Multi-organization context**: Objects must belong to the active organization
- **Object ownership**: Users can only modify objects they own (unless admin)

### Logging

All bulk operations are logged with:
- Operation type and parameters
- Number of objects processed
- Number of objects skipped
- Execution time
- Error details (if any)

## Related Documentation

- [Objects API](./objects.md) - Individual object operations
- [Authentication](./authentication.md) - API authentication methods
- [RBAC](./rbac.md) - Role-based access control
- [Multi-tenancy](./multi-tenancy.md) - Multi-organization support
