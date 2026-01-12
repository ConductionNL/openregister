# Multi-Tenancy Testing Scenarios for OpenRegister

This document provides comprehensive testing scenarios for the multi-tenancy implementation in OpenRegister. It covers organisation management, user-organisation relationships, session management, and entity isolation.

## Prerequisites

### Test Environment Setup
```bash
# Ensure clean test environment
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ maintenance:mode --on
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:disable openregister
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ maintenance:mode --off
```

### Test Users
Create the following test users:
```bash
# Create test users
OC_PASS='password123' php /var/www/html/occ user:add --password-from-env alice
OC_PASS='password123' php /var/www/html/occ user:add --password-from-env bob
OC_PASS='password123' php /var/www/html/occ user:add --password-from-env charlie
OC_PASS='password123' php /var/www/html/occ user:add --password-from-env diana
OC_PASS='password123' php /var/www/html/occ user:add --password-from-env eve
```

### Authentication Headers
For all API tests, include authentication:
```bash
# Basic Auth (replace with actual credentials)
-u alice:password123
-H "Content-Type: application/json"
```

## 1. Default Organisation Management Tests

### Positive Tests

#### Test 1.1: Default Organisation Creation on Empty Database
**Scenario**: System creates default organisation when none exists
```bash
# Reset database (remove all organisations)
# On first user login/API call, default organisation should be created

curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations"
```

**Expected Result**:
```json
{
  "total": 1,
  "active": {
    "uuid": "[UUID]",
    "name": "Default Organisation",
    "description": "Default organisation for users without specific organisation membership",
    "isDefault": true,
    "users": ["alice"],
    "userCount": 1
  },
  "list": [...]
}
```

#### Test 1.2: User Auto-Assignment to Default Organisation
**Scenario**: New user automatically gets assigned to default organisation
```bash
# First API call by a new user
curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations"
```

**Expected Result**: Bob should be added to default organisation's user list

### Negative Tests

#### Test 1.3: Multiple Default Organisations Prevention
**Scenario**: System prevents creation of multiple default organisations
```bash
# Attempt to create another default organisation (direct database manipulation test)
# Should be handled by database constraints or application logic
```

**Expected Result**: System should prevent multiple default organisations

## 2. Organisation CRUD Operations Tests

### Positive Tests

#### Test 2.1: Create New Organisation
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Acme Corporation",
    "description": "Test organisation for ACME Inc."
  }'
```

**Expected Result**:
```json
{
  "message": "Organisation created successfully",
  "organisation": {
    "uuid": "[UUID]",
    "name": "Acme Corporation",
    "description": "Test organisation for ACME Inc.",
    "isDefault": false,
    "owner": "alice",
    "users": ["alice"],
    "userCount": 1
  }
}
```

#### Test 2.2: Get Organisation Details
```bash
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]"
```

#### Test 2.3: Update Organisation
```bash
curl -u alice:password123 \
  -X PUT "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "ACME Corporation Ltd",
    "description": "Updated description"
  }'
```

#### Test 2.4: Search Organisations
```bash
curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/search?query=ACME"
```

**Expected Result**: Should return ACME organisation without user/owner details

### Negative Tests

#### Test 2.5: Create Organisation with Empty Name
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "",
    "description": "Invalid test"
  }'
```

**Expected Result**: HTTP 400 with error message

#### Test 2.6: Access Organisation Without Membership
```bash
# Bob tries to access Alice's private organisation details
curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]"
```

**Expected Result**: HTTP 403 Forbidden

#### Test 2.7: Update Organisation Without Access
```bash
curl -u bob:password123 \
  -X PUT "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]" \
  -H "Content-Type: application/json" \
  -d '{"name": "Hacked Name"}'
```

**Expected Result**: HTTP 403 Forbidden

## 3. User-Organisation Relationship Tests

### Positive Tests

#### Test 3.1: Join Organisation
```bash
curl -u bob:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/join"
```

**Expected Result**: HTTP 200, Bob added to ACME organisation

#### Test 3.2: Multiple Organisation Membership
```bash
# Alice creates another organisation
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -d '{"name": "Tech Startup", "description": "Innovation company"}'

# Bob joins both organisations
curl -u bob:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[STARTUP_UUID]/join"
```

#### Test 3.3: Leave Organisation (Non-Last)
```bash
# Bob leaves one organisation while staying in others
curl -u bob:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/leave"
```

### Negative Tests

#### Test 3.4: Join Non-Existent Organisation
```bash
curl -u bob:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/invalid-uuid/join"
```

**Expected Result**: HTTP 400 with "Organisation not found"

#### Test 3.5: Leave Last Organisation
```bash
# User tries to leave their only remaining organisation
curl -u charlie:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[DEFAULT_UUID]/leave"
```

**Expected Result**: HTTP 400 with "Cannot leave last organisation"

#### Test 3.6: Join Already Member Organisation
```bash
# User tries to join organisation they're already in
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/join"
```

**Expected Result**: Should handle gracefully (no duplicate membership)

## 4. Active Organisation Management Tests

### Positive Tests

#### Test 4.1: Get Active Organisation (Auto-Set)
```bash
# First call should auto-set the oldest organisation as active
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/active"
```

#### Test 4.2: Set Active Organisation
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[STARTUP_UUID]/set-active"
```

**Expected Result**: Active organisation changed to Tech Startup

#### Test 4.3: Active Organisation Persistence
```bash
# Multiple calls should return the same active organisation
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/active"
```

#### Test 4.4: Active Organisation Auto-Switch on Leave
```bash
# If user leaves their active organisation, another should become active
curl -u bob:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[CURRENT_ACTIVE]/leave"

curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/active"
```

### Negative Tests

#### Test 4.5: Set Non-Member Organisation as Active
```bash
# User tries to set organisation they don't belong to as active
curl -u charlie:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/set-active"
```

**Expected Result**: HTTP 400 with "User does not belong to this organisation"

#### Test 4.6: Set Non-Existent Organisation as Active
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/invalid-uuid/set-active"
```

**Expected Result**: HTTP 400 with "Organisation not found"

## 5. Entity Organisation Assignment Tests

### Positive Tests

#### Test 5.1: Register Creation with Active Organisation
```bash
# Set specific organisation as active
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/set-active"

# Create register - should be assigned to ACME organisation
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/registers" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "ACME Employee Register",
    "description": "Employee data for ACME Corp"
  }'
```

**Expected Result**: Register should have organisation set to ACME UUID

#### Test 5.2: Schema Creation with Active Organisation
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/schemas" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Employee Schema",
    "description": "Schema for employee data"
  }'
```

#### Test 5.3: Object Creation with Active Organisation
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/objects/[REGISTER]/[SCHEMA]" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@acme.com"
  }'
```

#### Test 5.4: Entity Access Within Same Organisation
```bash
# Bob (ACME member) should access ACME entities
curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/registers/[ACME_REGISTER_ID]"
```

### Negative Tests

#### Test 5.5: Entity Access Across Organisations
```bash
# Charlie (not ACME member) should not access ACME entities
curl -u charlie:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/registers/[ACME_REGISTER_ID]"
```

**Expected Result**: HTTP 403 Forbidden or filtered results

#### Test 5.6: Cross-Organisation Object Creation
```bash
# User tries to create object in different organisation's register
curl -u charlie:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/objects/[ACME_REGISTER]/[ACME_SCHEMA]" \
  -d '{"name": "Unauthorized User"}'
```

**Expected Result**: HTTP 403 Forbidden

## 6. Data Migration and Legacy Data Tests

### Positive Tests

#### Test 6.1: Existing Data Migration to Default Organisation
```bash
# After running migration, existing entities should be assigned to default org
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/registers"
```

**Expected Result**: All existing registers should have organisation field populated

#### Test 6.2: Mandatory Organisation and Owner Fields
```bash
# Create new entities - organisation and owner should be mandatory
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/registers" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Register"
  }'
```

**Expected Result**: Should succeed with auto-assigned organisation and owner

### Negative Tests

#### Test 6.3: Invalid Organisation Reference
```bash
# Attempt to create entity with invalid organisation (direct database test)
# Should be prevented by foreign key constraints or validation
```

## 7. Session and Cache Management Tests

### Positive Tests

#### Test 7.1: Session Persistence
```bash
# Set active organisation
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/set-active"

# In subsequent requests, active organisation should persist
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/active"
```

#### Test 7.2: Cache Performance
```bash
# First call (should hit database)
time curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations"

# Second call (should hit cache)
time curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations"
```

**Expected Result**: Second call should be faster

#### Test 7.3: Manual Cache Clear
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/clear-cache"
```

### Negative Tests

#### Test 7.4: Cross-User Session Isolation
```bash
# Alice's session should not affect Bob's
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ACME_UUID]/set-active"

curl -u bob:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/active"
```

**Expected Result**: Bob should have his own active organisation, not Alice's

## 8. Performance and Scalability Tests

### Test 8.1: Large Organisation with Many Users
```bash
# Create organisation with 100+ users (script test)
for i in {1..100}; do
  curl -u "user$i:password123" \
    -X POST "http://localhost:8080/apps/openregister/api/organisations/[LARGE_ORG_UUID]/join"
done
```

### Test 8.2: User with Many Organisations
```bash
# Create user belonging to 50+ organisations
for i in {1..50}; do
  # Create organisation and add user
done
```

### Test 8.3: Concurrent Active Organisation Changes
```bash
# Multiple concurrent requests to change active organisation
for i in {1..10}; do
  curl -u alice:password123 \
    -X POST "http://localhost:8080/apps/openregister/api/organisations/[ORG_$i_UUID]/set-active" &
done
wait
```

## 9. Edge Cases and Error Handling Tests

### Test 9.1: Unauthenticated Requests
```bash
curl -X GET "http://localhost:8080/apps/openregister/api/organisations"
```

**Expected Result**: HTTP 401 Unauthorized

### Test 9.2: Malformed JSON Requests
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -H "Content-Type: application/json" \
  -d '{"name": "Test", invalid json}'
```

**Expected Result**: HTTP 400 Bad Request

### Test 9.3: SQL Injection Attempts
```bash
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/organisations/search?query='; DROP TABLE organisations; --"
```

**Expected Result**: Safe handling, no SQL injection

### Test 9.4: Very Long Organisation Names
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -d '{"name": "'"$(python3 -c "print('A' * 1000)")"'", "description": "Test"}'
```

**Expected Result**: Should handle gracefully (truncate or reject)

### Test 9.5: Unicode and Special Characters
```bash
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations" \
  -d '{"name": "测试机构 🏢", "description": "Multi-language org with émojis"}'
```

**Expected Result**: Should support Unicode properly

## 10. Integration Tests

### Test 10.1: RBAC Integration with Multi-Tenancy
```bash
# Create organisation with RBAC-enabled schema
curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/organisations/[ORG_UUID]/set-active"

curl -u alice:password123 \
  -X POST "http://localhost:8080/apps/openregister/api/schemas" \
  -d '{
    "title": "RBAC Test Schema",
    "authorization": {
      "create": ["editors"],
      "read": ["viewers", "editors"],
      "update": ["editors"],
      "delete": ["managers"]
    }
  }'
```

### Test 10.2: Search Filtering by Organisation
```bash
# Search should only return results from user's organisations
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/search?q=test"
```

### Test 10.3: Audit Trail Organisation Context
```bash
# Audit trails should include organisation context
curl -u alice:password123 \
  -X GET "http://localhost:8080/apps/openregister/api/audit-trails"
```

## Test Execution Guidelines

### Automated Test Suite
Create PHPUnit tests covering all scenarios:

```php
// Example test structure
class MultiTenancyTest extends TestCase
{
    public function testDefaultOrganisationCreation() { }
    public function testUserOrganisationMembership() { }
    public function testActiveOrganisationManagement() { }
    public function testEntityOrganisationIsolation() { }
    // ... more tests
}
```

### Manual Test Checklist

- [ ] Default organisation created on empty database
- [ ] Users auto-assigned to default organisation
- [ ] Organisation CRUD operations work correctly
- [ ] User-organisation relationships managed properly
- [ ] Active organisation persistence and switching
- [ ] Entity isolation by organisation
- [ ] Cross-organisation access properly blocked
- [ ] Session and cache management working
- [ ] Performance acceptable under load
- [ ] Error handling robust
- [ ] Integration with existing features (RBAC, search, etc.)

### Test Data Cleanup

After testing, clean up test data:

```bash
# Remove test users
php /var/www/html/occ user:delete alice
php /var/www/html/occ user:delete bob
php /var/www/html/occ user:delete charlie
php /var/www/html/occ user:delete diana
php /var/www/html/occ user:delete eve

# Reset application state if needed
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:disable openregister
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister
```

## Expected Outcomes Summary

### Positive Test Results
- All organisation CRUD operations should succeed for authorized users
- Users should be automatically assigned to default organisation
- Active organisation should persist across sessions
- Entity isolation should work correctly
- Performance should be acceptable

### Negative Test Results  
- Unauthorized access should be blocked (HTTP 403/401)
- Invalid data should be rejected (HTTP 400)
- System should handle edge cases gracefully
- No data leakage between organisations
- No security vulnerabilities

This comprehensive test plan ensures the multi-tenancy implementation is robust, secure, and performs well under various conditions. 