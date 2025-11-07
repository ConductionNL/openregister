# Organisation API Endpoints

The Organisation API provides comprehensive multi-tenancy management capabilities, enabling users to create, manage, and interact with organisations within OpenRegister.

## Authentication

All organisation endpoints require authentication. Include the following headers with your requests:

```http
Authorization: Basic <base64-encoded-credentials>
OCS-APIREQUEST: true
Content-Type: application/json
```

## Core Endpoints

### List User Organizations

**GET** `/api/organisations`

Returns all organisations the authenticated user belongs to, including the currently active organisation.

**Response Format:**
```json
{
  'total': 3,
  'active': {
    'id': 2,
    'uuid': 'e6d272630b866cad2dee3aa3ac879281',
    'name': 'ACME Corporation',
    'description': 'Test organisation for ACME Inc.',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:24:26+00:00',
    'updated': '2025-07-21T21:24:26+00:00'
  },
  'list': [
    {
      'id': 2,
      'uuid': 'e6d272630b866cad2dee3aa3ac879281',
      'name': 'ACME Corporation',
      'description': 'Test organisation for ACME Inc.',
      'users': ['admin'],
      'userCount': 1,
      'isDefault': false,
      'owner': 'admin',
      'created': '2025-07-21T21:24:26+00:00',
      'updated': '2025-07-21T21:24:26+00:00'
    }
  ]
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations'
```

---

### Create Organisation

**POST** `/api/organisations`

Creates a new organisation and automatically adds the authenticated user as the owner and first member.

**Request Body:**
```json
{
  'name': 'Organization Name',
  'description': 'Organization description'
}
```

**Required Fields:**
- `name` (string): Organisation name (required, non-empty)
- `description` (string, optional): Organisation description

**Response Format:**
```json
{
  'message': 'Organisation created successfully',
  'organisation': {
    'id': 5,
    'uuid': '55ebf05fce0a58e42ab0fc989c09c9e7',
    'name': 'Organization Name',
    'description': 'Organization description',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:30:03+00:00',
    'updated': '2025-07-21T21:30:03+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     -X POST \
     -d '{'name': 'API Test Corp', 'description': 'Testing organisation creation via API'}' \
     'http://localhost/index.php/apps/openregister/api/organisations'
```

**Error Responses:**
- **400 Bad Request**: `{'error': 'Organisation name is required'}` when name is empty

---

### Get Organisation Details

**GET** `/api/organisations/{uuid}`

Retrieves details for a specific organisation by UUID. User must be a member of the organisation.

**Path Parameters:**
- `uuid` (string): The UUID of the organisation

**Response Format:**
```json
{
  'organisation': {
    'id': 5,
    'uuid': '55ebf05fce0a58e42ab0fc989c09c9e7',
    'name': 'Organization Name',
    'description': 'Organization description',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:30:03+00:00',
    'updated': '2025-07-21T21:30:03+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7'
```

**Error Responses:**
- **404 Not Found**: `{'error': 'Access denied to this organisation'}` when user doesn't belong to organisation or organisation doesn't exist

---

### Update Organisation

**PUT** `/api/organisations/{uuid}`

Updates an existing organisation's details. User must be a member of the organisation.

**Path Parameters:**
- `uuid` (string): The UUID of the organisation

**Request Body:**
```json
{
  'name': 'Updated Organization Name',
  'description': 'Updated organization description'
}
```

**Response Format:**
```json
{
  'message': 'Organisation updated successfully',
  'organisation': {
    'id': 5,
    'uuid': '55ebf05fce0a58e42ab0fc989c09c9e7',
    'name': 'Updated Organization Name',
    'description': 'Updated organization description',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:30:03+00:00',
    'updated': '2025-07-21T21:30:26+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     -X PUT \
     -d '{'name': 'Updated API Test Corp', 'description': 'Updated description for testing'}' \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7'
```

---

## Active Organisation Management

### Get Active Organisation

**GET** `/api/organisations/active`

Returns the currently active organisation for the authenticated user's session.

**Response Format:**
```json
{
  'activeOrganisation': {
    'id': 2,
    'uuid': 'e6d272630b866cad2dee3aa3ac879281',
    'name': 'ACME Corporation',
    'description': 'Test organisation for ACME Inc.',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:24:26+00:00',
    'updated': '2025-07-21T21:24:26+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations/active'
```

---

### Set Active Organisation

**POST** `/api/organisations/{uuid}/set-active`

Sets the specified organisation as the active organisation for the user's session. User must be a member of the organisation.

**Path Parameters:**
- `uuid` (string): The UUID of the organisation to set as active

**Response Format:**
```json
{
  'message': 'Active organisation set successfully',
  'activeOrganisation': {
    'id': 5,
    'uuid': '55ebf05fce0a58e42ab0fc989c09c9e7',
    'name': 'Updated API Test Corp',
    'description': 'Updated description for testing',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:30:03+00:00',
    'updated': '2025-07-21T21:30:26+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -X POST \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7/set-active'
```

**Error Responses:**
- **404 Not Found**: `{'error': 'User does not belong to this organisation'}` when user is not a member
- **404 Not Found**: `{'error': 'Organisation not found'}` when organisation doesn't exist

---

## User-Organisation Relationships

### Join Organisation

**POST** `/api/organisations/{uuid}/join`

Adds a user to the specified organisation. By default, adds the authenticated user, but can optionally add a different user by providing a userId in the request body.

**Path Parameters:**
- `uuid` (string): The UUID of the organisation to join

**Request Body (Optional):**
```json
{
  'userId': 'username'
}
```

**Optional Fields:**
- `userId` (string, optional): The user ID to add to the organisation. If not provided, the authenticated user is added.

**Response Format:**
```json
{
  'message': 'Successfully joined organisation'
}
```

**Usage Examples:**

Join organisation as current user:
```bash
curl -u 'newuser:password' \
     -H 'OCS-APIREQUEST: true' \
     -X POST \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7/join'
```

Join organisation on behalf of another user:
```bash
curl -u 'admin:password' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     -X POST \
     -d '{'userId': 'specificuser'}' \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7/join'
```

**Error Responses:**
- **404 Not Found**: `{'error': 'Organisation not found'}` when organisation doesn't exist
- **400 Bad Request**: `{'error': 'User already belongs to this organisation'}` when user is already a member
- **404 Not Found**: `{'error': 'Target user not found'}` when specified userId does not exist

---

### Leave Organisation

**POST** `/api/organisations/{uuid}/leave`

Removes the authenticated user from the specified organisation.

**Path Parameters:**
- `uuid` (string): The UUID of the organisation to leave

**Response Format:**
```json
{
  'message': 'Successfully left organisation',
  'organisation': {
    'id': 5,
    'uuid': '55ebf05fce0a58e42ab0fc989c09c9e7',
    'name': 'Organization Name',
    'description': 'Organization description',
    'users': ['admin'],
    'userCount': 1,
    'isDefault': false,
    'owner': 'admin',
    'created': '2025-07-21T21:30:03+00:00',
    'updated': '2025-07-21T21:30:26+00:00'
  }
}
```

**Usage Example:**
```bash
curl -u 'someuser:password' \
     -H 'OCS-APIREQUEST: true' \
     -X POST \
     'http://localhost/index.php/apps/openregister/api/organisations/55ebf05fce0a58e42ab0fc989c09c9e7/leave'
```

**Error Responses:**
- **404 Not Found**: `{'error': 'User does not belong to this organisation'}` when user is not a member
- **400 Bad Request**: `{'error': 'Cannot leave organisation - this is your only organisation'}` when it's the user's last organisation

---

## Search and Discovery

### Search Organisations

**GET** `/api/organisations/search`

Searches organisations by name. Returns all organisations when query is empty, or filtered organisations matching the search term.

**Query Parameters:**
- `query` (string, optional): Search term to filter organisations by name. If empty, returns all organisations.

**Response Format:**
```json
{
  'organisations': [
    {
      'id': 2,
      'uuid': 'e6d272630b866cad2dee3aa3ac879281',
      'name': 'ACME Corporation',
      'description': 'Test organisation for ACME Inc.',
      'userCount': 1,
      'isDefault': false,
      'created': '2025-07-21T21:24:26+00:00',
      'updated': '2025-07-21T21:24:26+00:00'
    }
  ]
}
```

**Usage Examples:**

Search for specific organisations:
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations/search?query=ACME'
```

Get all organisations:
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations/search'
```

---

## System Management

### Organisation Statistics

**GET** `/api/organisations/stats`

Returns system-wide organisation statistics.

**Response Format:**
```json
{
  'statistics': {
    'total': 5,
    'default': 1,
    'custom': 4
  }
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/openregister/api/organisations/stats'
```

---

### Clear Organisation Cache

**POST** `/api/organisations/clear-cache`

Clears the organisation cache for performance optimization. Useful for debugging or when organisation data has been updated externally.

**Response Format:**
```json
{
  'message': 'Cache cleared successfully'
}
```

**Usage Example:**
```bash
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -X POST \
     'http://localhost/index.php/apps/openregister/api/organisations/clear-cache'
```

---

## Entity Organisation Context

When creating registers, schemas, or objects, they are automatically assigned to the user's currently active organisation. The organisation UUID will be included in the entity's response:

### Register Creation Example
```json
{
  'id': 9,
  'uuid': '51364151-d9d1-4045-9add-ad3182e83ab8',
  'title': 'Customer Database',
  'description': 'Customer records for ACME Corp',
  'organisation': 'e6d272630b866cad2dee3aa3ac879281',
  ...
}
```

### Schema Creation Example
```json
{
  'id': 52,
  'uuid': '8e61f403-284d-4579-93f4-442ef93f6ed7',
  'title': 'Customer Schema',
  'description': 'Schema for customer records',
  'organisation': 'e6d272630b866cad2dee3aa3ac879281',
  ...
}
```

## Error Handling

All organisation endpoints return consistent error responses:

### Common Error Codes
- **400 Bad Request**: Invalid input or business rule violation
- **401 Unauthorized**: Authentication required
- **404 Not Found**: Resource not found or access denied
- **500 Internal Server Error**: Server error

### Error Response Format
```json
{
  'error': 'Error message describing the issue'
}
```

## Rate Limiting

The organisation API includes built-in rate limiting to prevent abuse:

- **Organisation Creation**: Maximum 10 organisations per user per hour
- **Join/Leave Operations**: Maximum 50 operations per user per hour  
- **Search Operations**: Maximum 100 searches per user per minute
- **Cache Clear**: Maximum 10 cache clears per user per hour

Rate limit headers are included in responses:
```http
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 1642781234
```

## Security Considerations

### Access Control
- Users can only access organisations they belong to
- Organisation creation automatically makes the user the owner
- Cross-organisation access is prevented at the API level

### Data Isolation
- All API responses are filtered by organisation membership
- Database queries include organisation context filtering
- Session-based active organisation prevents data leakage

### Audit Trails
- All organisation operations are logged with user and organisation context
- Organisation membership changes are tracked in audit logs
- Failed access attempts are logged for security monitoring

---

*For comprehensive implementation details, see [Multi-Tenancy Technical Documentation](../multi-tenancy.md)*
*For testing information, see [Multi-Tenancy Testing Framework](../multi-tenancy-testing.md)* 