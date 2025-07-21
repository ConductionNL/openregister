# OpenRegister RBAC Testing Plan

## Overview

This document outlines comprehensive testing scenarios for the Role-Based Access Control (RBAC) system in OpenRegister. The tests cover all combinations of:

- **CRUD Operations**: Create, Read, Update, Delete
- **Role Types**: Admin, Public, Custom Groups
- **Authorization Configurations**: Open, Restrictive, Mixed permissions

## Test Environment Setup

### Prerequisites

1. Running Nextcloud development environment with OpenRegister
2. Docker container access for API testing
3. Test users and groups configured in Nextcloud

### Troubleshooting: User Unblocking

If users get blocked due to failed login attempts during testing, use these commands to unblock them:

```bash
# Clear bruteforce protection for specific IP (most common case)
docker exec -it -u 33 master-nextcloud-1 php /var/www/html/occ security:bruteforce:reset 127.0.0.1

# View current bruteforce attempts to diagnose issues
docker exec -it -u 33 master-nextcloud-1 php /var/www/html/occ security:bruteforce:attempts

# Alternative: Clear from database directly (if occ command doesn't work)
docker exec -it master-nextcloud-1 mysql -u nextcloud -pnextcloud nextcloud -e "DELETE FROM oc_bruteforce_attempts;"
```

**When to unblock:**
- Getting "Login failed" or "IP address throttled" errors
- Receiving 401 Unauthorized responses unexpectedly
- After running multiple test scenarios with wrong credentials

### API Header Requirements

**Important Discovery**: The `OCS-APIREQUEST: true` header is **NOT required** for OpenRegister API endpoints, despite being mentioned in some Nextcloud documentation. Our testing confirms:

- ✅ All CRUD operations work without the header
- ✅ RBAC enforcement works correctly without the header  
- ✅ Frontend UI calls don't use this header

**Recommendation**: You can omit `-H "OCS-APIREQUEST: true"` from all test commands for simplicity. It's included in the examples below for compatibility with general Nextcloud API documentation, but it's optional.

### Required Test Users and Groups

Create the following test users and groups in Nextcloud:

```bash
# Create test groups (run in Nextcloud container)
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:add editors"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:add viewers" 
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:add managers"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:add staff"

# Create test users (use OC_PASS environment variable)
docker exec -it -u 33 master-nextcloud-1 bash -c "OC_PASS='password123' php /var/www/html/occ user:add --password-from-env editor_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "OC_PASS='password123' php /var/www/html/occ user:add --password-from-env viewer_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "OC_PASS='password123' php /var/www/html/occ user:add --password-from-env manager_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "OC_PASS='password123' php /var/www/html/occ user:add --password-from-env staff_user"

# Add users to groups
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:adduser editors editor_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:adduser viewers viewer_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:adduser managers manager_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:adduser staff staff_user"
```

## Test Schema Configurations

### Schema 1: Open Access (Baseline)
No authorization configured - all users should have all permissions.

```json
{
  "title": "Open Access Schema",
  "authorization": {}
}
```

### Schema 2: Read-Only Public
Public users can read, only editors can create/update, managers can delete.

```json
{
  "title": "Public Read Schema", 
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["public"],
    "update": ["editors", "managers"], 
    "delete": ["managers"]
  }
}
```

### Schema 3: Staff Only
Only staff can access, managers can delete.

```json
{
  "title": "Staff Only Schema",
  "authorization": {
    "create": ["staff"],
    "read": ["staff"],
    "update": ["staff"],
    "delete": ["managers", "staff"]
  }
}
```

### Schema 4: Collaborative
Multiple groups with different permission levels.

```json
{
  "title": "Collaborative Schema",
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["viewers", "editors", "managers"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}
```

## Test Matrix

| Schema Type | User Type | Create | Read | Update | Delete | Expected Result |
|-------------|-----------|--------|------|--------|---------|-----------------|
| Open Access | Admin | ✅ | ✅ | ✅ | ✅ | All operations allowed |
| Open Access | Public | ✅ | ✅ | ✅ | ✅ | All operations allowed |
| Open Access | Custom | ✅ | ✅ | ✅ | ✅ | All operations allowed |
| Public Read | Admin | ✅ | ✅ | ✅ | ✅ | Admin override |
| Public Read | Public | ❌ | ✅ | ❌ | ❌ | Only read allowed |
| Public Read | Editor | ✅ | ✅ | ✅ | ❌ | Create/Read/Update only |
| Public Read | Manager | ✅ | ✅ | ✅ | ✅ | All operations allowed |
| Public Read | Viewer | ❌ | ❌ | ❌ | ❌ | No permissions |
| Staff Only | Admin | ✅ | ✅ | ✅ | ✅ | Admin override |
| Staff Only | Public | ❌ | ❌ | ❌ | ❌ | No access |
| Staff Only | Staff | ✅ | ✅ | ✅ | ✅ | Full access |
| Staff Only | Manager | ❌ | ❌ | ❌ | ✅ | Delete only |
| Collaborative | Admin | ✅ | ✅ | ✅ | ✅ | Admin override |
| Collaborative | Viewer | ❌ | ✅ | ❌ | ❌ | Read only |
| Collaborative | Editor | ✅ | ✅ | ✅ | ❌ | Create/Read/Update |
| Collaborative | Manager | ✅ | ✅ | ✅ | ✅ | All operations |

## Test Commands

## Negative Testing Scenarios

**Critical**: All blocked operations should return JSON error responses with HTTP 403, **not HTML error pages**

### Public Read Schema (ID: 49) - Blocked Operations
```bash
# ❌ CREATE - Viewer user should NOT be able to create
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/49" -d '"'"'{"name": "Should Fail", "description": "Viewer cannot create"}'"'"''
# Expected: {"error": "User 'viewer_user' does not have permission to 'create' objects..."} (HTTP 403)

# ❌ UPDATE - Viewer user should NOT be able to update  
# First create an object as admin, then try to update as viewer
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "admin:admin" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/49" -d '"'"'{"name": "Admin Object", "description": "For update test"}'"'"''
# Get the object UUID from response, then try to update as viewer:
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/1/49/[UUID]" -d '"'"'{"name": "Hacked", "description": "Should fail"}'"'"''
# Expected: {"error": "User 'viewer_user' does not have permission to 'update' objects..."} (HTTP 403)

# ❌ DELETE - Viewer user should NOT be able to delete
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/1/49/[UUID]"'
# Expected: {"error": "User 'viewer_user' does not have permission to 'delete' objects..."} (HTTP 403)

# ❌ DELETE - Editor user should NOT be able to delete (only managers can)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "editor_user:password123" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/1/49/[UUID]"'
# Expected: {"error": "User 'editor_user' does not have permission to 'delete' objects..."} (HTTP 403)
```

### Staff Only Schema (ID: 50) - Blocked Operations  
```bash
# ❌ CREATE - Manager user should NOT be able to create (only staff can)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "manager_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/50" -d '"'"'{"name": "Should Fail", "description": "Manager cannot create in staff-only"}'"'"''
# Expected: {"error": "User 'manager_user' does not have permission to 'create' objects..."} (HTTP 403)

# ❌ READ - Manager user should NOT see objects (only staff can read)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "manager_user:password123" "http://localhost/index.php/apps/openregister/api/objects/1/50"'
# Expected: Empty result set [] (query-level filtering)

# ❌ UPDATE - Manager user should NOT be able to update (only staff can)
# First create object as staff member, then try to update as manager
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "staff_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/50" -d '"'"'{"name": "Staff Object", "description": "For update test"}'"'"''
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "manager_user:password123" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/1/50/[UUID]" -d '"'"'{"name": "Hacked", "description": "Should fail"}'"'"''
# Expected: {"error": "User 'manager_user' does not have permission to 'update' objects..."} (HTTP 403)

# ❌ All Operations - Public user should be completely blocked
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/50" -d '"'"'{"name": "Should Fail", "description": "Public cannot create"}'"'"''
# Expected: {"error": "User 'public' does not have permission to 'create' objects..."} (HTTP 403)
```

### Collaborative Schema (ID: 51) - Blocked Operations
```bash  
# ❌ CREATE - Viewer user should NOT be able to create
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/51" -d '"'"'{"name": "Should Fail", "description": "Viewer cannot create"}'"'"''
# Expected: {"error": "User 'viewer_user' does not have permission to 'create' objects..."} (HTTP 403)

# ❌ UPDATE - Viewer user should NOT be able to update
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "editor_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/51" -d '"'"'{"name": "Editor Object", "description": "For update test"}'"'"''
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/1/51/[UUID]" -d '"'"'{"name": "Hacked", "description": "Should fail"}'"'"''
# Expected: {"error": "User 'viewer_user' does not have permission to 'update' objects..."} (HTTP 403)

# ❌ DELETE - Editor user should NOT be able to delete (only managers can)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "editor_user:password123" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/1/51/[UUID]"'
# Expected: {"error": "User 'editor_user' does not have permission to 'delete' objects..."} (HTTP 403)

# ❌ DELETE - Viewer user should NOT be able to delete  
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -s -u "viewer_user:password123" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/1/51/[UUID]"'
# Expected: {"error": "User 'viewer_user' does not have permission to 'delete' objects..."} (HTTP 403)
```

### Response Format Verification
**CRITICAL**: All error responses must be JSON, never HTML. If you see HTML responses like:
```html
<!DOCTYPE html>
<html>
<head><title>Error</title></head>
...
```
This indicates the exception handling in `ObjectsController.php` needs to be fixed.

**Expected Error Format**:
```json
{
  "error": "User 'username' does not have permission to 'action' objects in schema 'Schema Title'"
}
```

### Quick Reference - Simplified Commands (No OCS Header Required)

For faster testing, you can use these simplified commands without the `OCS-APIREQUEST` header:

```bash
# CREATE test - Should succeed (Open Access schema)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "editor_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/48" -d '"'"'{"name": "Test Object", "description": "Test description"}'"'"''

# CREATE test - Should fail (Public Read schema, viewer cannot create)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/49" -d '"'"'{"name": "Should Fail", "description": "Viewer cannot create"}'"'"''

# READ test - Should succeed (viewer can read Public Read schema)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" "http://localhost/index.php/apps/openregister/api/objects/1/49"'

# READ test - Should return empty (viewer cannot read Staff Only schema)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" "http://localhost/index.php/apps/openregister/api/objects/1/50"'
```

### Setup Test Schemas

First, create test schemas with different authorization configurations:

```bash
# Schema 1: Open Access
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
  "title": "RBAC Test - Open Access",
  "description": "Test schema with no authorization restrictions",
  "version": "1.0.0",
  "properties": {
    "name": {"type": "string", "required": true},
    "description": {"type": "string"}
  },
  "authorization": {}
}'"'"''

# Schema 2: Public Read Only  
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
  "title": "RBAC Test - Public Read",
  "description": "Test schema with public read access",
  "version": "1.0.0", 
  "properties": {
    "name": {"type": "string", "required": true},
    "description": {"type": "string"}
  },
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["public"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}'"'"''

# Schema 3: Staff Only
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
  "title": "RBAC Test - Staff Only",
  "description": "Test schema restricted to staff members",
  "version": "1.0.0",
  "properties": {
    "name": {"type": "string", "required": true}, 
    "description": {"type": "string"}
  },
  "authorization": {
    "create": ["staff"],
    "read": ["staff"], 
    "update": ["staff"],
    "delete": ["managers", "staff"]
  }
}'"'"''

# Schema 4: Collaborative
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
  "title": "RBAC Test - Collaborative",
  "description": "Test schema with different permission levels",
  "version": "1.0.0",
  "properties": {
    "name": {"type": "string", "required": true},
    "description": {"type": "string"}
  },
  "authorization": {
    "create": ["editors", "managers"],
    "read": ["viewers", "editors", "managers"],
    "update": ["editors", "managers"],
    "delete": ["managers"]
  }
}'"'"''
```

### CRUD Testing Commands

Replace `{SCHEMA_ID}` and `{REGISTER_ID}` with actual IDs from schema creation responses.

#### CREATE Operations

```bash
# Test CREATE as Admin User (should always work)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Admin Created Object", "description": "Created by admin user"}'"'"''

# Test CREATE as Public User (unauthenticated)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Public Created Object", "description": "Created by public user"}'"'"''

# Test CREATE as Editor User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "editor_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Editor Created Object", "description": "Created by editor"}'"'"''

# Test CREATE as Viewer User  
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Viewer Created Object", "description": "Created by viewer"}'"'"''

# Test CREATE as Manager User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "manager_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Manager Created Object", "description": "Created by manager"}'"'"''

# Test CREATE as Staff User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "staff_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Staff Created Object", "description": "Created by staff"}'"'"''
```

#### READ Operations

```bash
# Test READ as Admin User  
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}"'

# Test READ as Public User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}"'

# Test READ as Editor User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "editor_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}"'

# Test READ as Viewer User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}"'

# Test READ specific object by UUID
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}"'
```

#### UPDATE Operations  

```bash
# Test UPDATE as Admin User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}" -d '"'"'{"name": "Admin Updated Object", "description": "Updated by admin"}'"'"''

# Test UPDATE as Editor User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "editor_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}" -d '"'"'{"name": "Editor Updated Object", "description": "Updated by editor"}'"'"''

# Test UPDATE as Viewer User (should fail on restrictive schemas)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X PUT "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}" -d '"'"'{"name": "Viewer Updated Object", "description": "Updated by viewer"}'"'"''

# Test PATCH UPDATE as Manager User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "manager_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X PATCH "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}" -d '"'"'{"description": "Partially updated by manager"}'"'"''
```

#### DELETE Operations

```bash
# Test DELETE as Admin User
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}"'

# Test DELETE as Manager User  
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "manager_user:password123" -H "OCS-APIREQUEST: true" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}"'

# Test DELETE as Editor User (should fail on restrictive schemas)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "editor_user:password123" -H "OCS-APIREQUEST: true" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}"'

# Test DELETE as Viewer User (should fail)
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" -X DELETE "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}/{OBJECT_UUID}"'
```

### Search and Query Operations

```bash  
# Test Search as different users
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_search=test"'

docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_search=test"'

# Test Search with schema filtering
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "staff_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?@self[schema]={SCHEMA_ID}"'
```

## Expected Results

### Open Access Schema
- **All users**: Full CRUD access (200 responses for all operations)

### Public Read Schema
- **Admin**: Full CRUD access (200 for all)
- **Public/Unauthenticated**: Read only (200 for GET, 403 for POST/PUT/DELETE)  
- **Editors**: Create/Read/Update (200), Delete forbidden (403)
- **Managers**: Full CRUD access (200 for all)
- **Viewers**: No access (403 for all operations)

### Staff Only Schema  
- **Admin**: Full CRUD access (200 for all)
- **Public**: No access (403 for all)
- **Staff**: Full CRUD access (200 for all) 
- **Managers**: Delete only (403 for CREATE/READ/UPDATE, 200 for DELETE)
- **Others**: No access (403 for all)

### Collaborative Schema
- **Admin**: Full CRUD access (200 for all)
- **Viewers**: Read only (200 for GET, 403 for POST/PUT/DELETE)
- **Editors**: Create/Read/Update (200), Delete forbidden (403)  
- **Managers**: Full CRUD access (200 for all)
- **Others**: No access (403 for all)

## Owner Override Testing

Test that schema owners always have full access regardless of group restrictions:

```bash
# Create schema as specific user
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "staff_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
  "title": "Owner Test Schema",
  "authorization": {"read": ["managers"], "create": ["managers"], "update": ["managers"], "delete": ["managers"]}
}'"'"''

# Test that owner (staff_user) can still perform all operations despite restrictions
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "staff_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Owner Created"}'"'"''
```

## Test Result Validation

### Success Indicators  
- **200/201**: Operation successful
- **Expected response structure**: Proper JSON response with object data

### Failure Indicators
- **403**: Forbidden - User lacks permission  
- **401**: Unauthorized - Authentication required
- **Error message**: Clear RBAC-related error message

### Response Structure Validation

Expected successful response:
```json
{
  "status": "success",
  "data": {
    "id": "123",
    "uuid": "uuid-string",
    "name": "Object Name",
    "description": "Object Description"
  }
}
```

Expected permission denied response:
```json
{
  "status": "error", 
  "message": "User 'username' does not have permission to 'action' objects in schema 'Schema Name'"
}
```

## Performance Testing

Test RBAC performance with large datasets:

```bash
# Create 100 objects and test search filtering performance
for i in {1..100}; do
  docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/{REGISTER_ID}/{SCHEMA_ID}" -d '"'"'{"name": "Test Object '$i'"}'"'"''
done

# Test search performance with different users
time docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_limit=100"'
```

## Test Cleanup

After testing, clean up test data:

```bash
# Delete test schemas
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -X DELETE "http://localhost/index.php/apps/openregister/api/schemas/{SCHEMA_ID}"'

# Remove test users and groups  
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ user:delete editor_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ user:delete viewer_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ user:delete manager_user"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ user:delete staff_user"

docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:delete editors"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:delete viewers"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:delete managers"
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ group:delete staff"
```

## Troubleshooting

### Common Issues

1. **403 Forbidden but expected 200**: Check user group membership and schema authorization configuration
2. **401 Unauthorized**: Verify authentication credentials (Note: OCS-APIREQUEST header is optional for OpenRegister)
3. **500 Internal Server Error**: Check Nextcloud logs for RBAC implementation errors
4. **Empty search results**: Verify RBAC query filtering is working correctly

### Debug Commands

```bash
# Check user groups
docker exec -it -u 33 master-nextcloud-1 bash -c "php /var/www/html/occ user:info editor_user"

# Check schema authorization
docker exec -it -u 33 master-nextcloud-1 bash -c 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/schemas/{SCHEMA_ID}"'

# View logs
docker logs -f master-nextcloud-1 | grep -i rbac
```

This comprehensive testing plan ensures that all RBAC functionality works correctly across different user types, permission configurations, and CRUD operations. 