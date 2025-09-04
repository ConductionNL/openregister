# Authorization Exception System

## Overview

The Authorization Exception System provides a flexible way to override the standard Role-Based Access Control (RBAC) system in OpenRegister. It allows for fine-grained control over permissions by defining specific inclusions and exclusions that take precedence over normal authorization rules.

## Key Concepts

### Exception Types

1. **Inclusions**: Grant additional permissions to users or groups that they wouldn't normally have
2. **Exclusions**: Deny permissions to users or groups even if they would normally have access through RBAC

### Subject Types

- **User**: Exceptions that apply to specific individual users
- **Group**: Exceptions that apply to all members of a specific group

### Priority System

Authorization exceptions use a priority system to resolve conflicts:

1. **Exclusions** (highest priority) - Always deny access if applicable
2. **Inclusions** (medium priority) - Grant access if no exclusions apply  
3. **Normal RBAC** (lowest priority) - Default system behavior

Within each type, exceptions with higher numerical priority values take precedence.

## Architecture

### Database Schema

The `openregister_authorization_exceptions` table stores authorization exceptions with the following key fields:

- `type`: 'inclusion' or 'exclusion'
- `subject_type`: 'user' or 'group'  
- `subject_id`: The actual user ID or group ID
- `action`: CRUD operation ('create', 'read', 'update', 'delete')
- `schema_uuid`: Optional - limits exception to specific schema
- `register_uuid`: Optional - limits exception to specific register
- `organization_uuid`: Optional - limits exception to specific organization
- `priority`: Integer priority for conflict resolution
- `active`: Boolean to enable/disable exceptions

### Core Components

1. **AuthorizationException** (Entity) - Data model for exceptions
2. **AuthorizationExceptionMapper** (Mapper) - Database operations
3. **AuthorizationExceptionService** (Service) - Business logic
4. **ObjectEntityMapper** (Updated) - Integrated with existing RBAC system

## Usage Examples

### Example 1: Ambtenaar Group Cross-Organization Access

Allow users in the 'ambtenaar' group to read 'gebruik' objects from all organizations:

```php
$exception = new AuthorizationException();
$exception->setType(AuthorizationException::TYPE_INCLUSION);
$exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_GROUP);
$exception->setSubjectId('ambtenaar');
$exception->setAction(AuthorizationException::ACTION_READ);
$exception->setSchemaUuid('gebruik-schema-uuid');
$exception->setPriority(20); // High priority to override multi-tenancy
$exception->setDescription('Allow ambtenaar group to read gebruik objects from all organizations');

$authService->createException(/* parameters */);
```

### Example 2: User Exclusion

Deny a specific user update access despite group membership:

```php
$exception = new AuthorizationException();
$exception->setType(AuthorizationException::TYPE_EXCLUSION);
$exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_USER);
$exception->setSubjectId('problematic-user');
$exception->setAction(AuthorizationException::ACTION_UPDATE);
$exception->setSchemaUuid('sensitive-schema-uuid');
$exception->setPriority(15);
$exception->setDescription('Deny user update access due to security concerns');
```

### Example 3: Organization-Specific Group Permission

Grant contractors create access only within a specific client organization:

```php
$exception = new AuthorizationException();
$exception->setType(AuthorizationException::TYPE_INCLUSION);
$exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_GROUP);
$exception->setSubjectId('contractors');
$exception->setAction(AuthorizationException::ACTION_CREATE);
$exception->setSchemaUuid('project-schema-uuid');
$exception->setOrganizationUuid('client-org-uuid');
$exception->setPriority(10);
```

## API Usage

### Creating Exceptions

```bash
# Create user inclusion
curl -X POST http://localhost/index.php/apps/openregister/api/authorization-exceptions \
  -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -H 'OCS-APIREQUEST: true' \
  -d '{
    "type": "inclusion",
    "subject_type": "user",
    "subject_id": "special-user",
    "action": "read",
    "schema_uuid": "confidential-schema-uuid",
    "priority": 10,
    "description": "Allow special user to read confidential data"
  }'

# Create group exclusion
curl -X POST http://localhost/index.php/apps/openregister/api/authorization-exceptions \
  -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -H 'OCS-APIREQUEST: true' \
  -d '{
    "type": "exclusion",
    "subject_type": "group", 
    "subject_id": "restricted-group",
    "action": "delete",
    "schema_uuid": "protected-schema-uuid",
    "priority": 15,
    "description": "Prevent group from deleting protected data"
  }'
```

### Listing Exceptions

```bash
# List all exceptions
curl http://localhost/index.php/apps/openregister/api/authorization-exceptions \
  -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true'

# Filter by criteria
curl 'http://localhost/index.php/apps/openregister/api/authorization-exceptions?type=inclusion&active=true' \
  -u 'admin:admin' \
  -H 'OCS-APIREQUEST: true'
```

### Checking Permissions

```bash
# Check if user has permission
curl -X POST http://localhost/index.php/apps/openregister/api/authorization-exceptions/check-permission \
  -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -H 'OCS-APIREQUEST: true' \
  -d '{
    "user_id": "test-user",
    "action": "read", 
    "schema_uuid": "test-schema-uuid"
  }'
```

## Integration with Existing RBAC

The authorization exception system integrates seamlessly with the existing RBAC system:

1. **Query Level**: The `ObjectEntityMapper::applyRbacFilters()` method checks for exceptions before applying normal RBAC rules
2. **Object Level**: The `ObjectEntityMapper::checkObjectPermission()` method evaluates exceptions first, then falls back to standard permission checks
3. **Evaluation Order**: Exclusions → Inclusions → Normal RBAC → Object ownership → Publication status

## Best Practices

### 1. Use Specific Scope

Always limit exceptions to the most specific scope possible:

```php
// Good - specific to schema and organization
$exception->setSchemaUuid('specific-schema');
$exception->setOrganizationUuid('specific-org');

// Avoid - too broad, affects everything
// (leaving schema_uuid and organization_uuid as null)
```

### 2. Set Appropriate Priorities

Use priority levels consistently:

- **1-10**: Low priority inclusions
- **11-20**: Medium priority inclusions  
- **21-30**: High priority inclusions
- **31-40**: Low priority exclusions
- **41-50**: Medium priority exclusions
- **51+**: High priority exclusions

### 3. Document Exceptions

Always provide clear descriptions explaining why the exception exists:

```php
$exception->setDescription('Allow support team to read customer data for troubleshooting purposes - ticket #12345');
```

### 4. Regular Audits

Regularly review authorization exceptions to ensure they're still needed:

```php
// Find old exceptions
$oldExceptions = $mapper->findByCriteria([
    'created_at' => '<' . (new DateTime('-6 months'))->format('Y-m-d'),
    'active' => true
]);
```

## Testing

The system includes comprehensive tests:

- **Unit Tests**: `AuthorizationExceptionServiceTest`, `AuthorizationExceptionMapperTest`
- **Integration Tests**: `AuthorizationExceptionIntegrationTest` 
- **API Tests**: `AuthorizationExceptionApiTest`

Run tests with:

```bash
# Run all authorization exception tests
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ test:unit --group authorization-exceptions

# Run specific test class
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ test:unit AuthorizationExceptionServiceTest
```

## Troubleshooting

### Exception Not Working

1. Check if exception is active:
   ```php
   $exception = $mapper->findByUuid($uuid);
   var_dump($exception->getActive());
   ```

2. Verify priority is high enough:
   ```php
   $exceptions = $service->getUserExceptions($userId);
   usort($exceptions, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
   ```

3. Check scope matching:
   ```php
   $result = $exception->matches($subjectType, $subjectId, $action, $schemaUuid);
   ```

### Performance Issues

If exception evaluation is slow:

1. Add database indexes:
   ```sql
   CREATE INDEX auth_exc_lookup ON openregister_authorization_exceptions 
   (subject_type, subject_id, action, active);
   ```

2. Cache frequently accessed exceptions:
   ```php
   $cached = $cache->get('user_exceptions_' . $userId);
   ```

## Migration

To add the authorization exception system to an existing installation:

1. Run the migration:
   ```bash
   docker exec -u 33 master-nextcloud-1 php /var/www/html/occ upgrade
   ```

2. Verify table creation:
   ```bash
   docker exec -u 33 master-nextcloud-1 mysql -u nextcloud -p nextcloud \
     -e 'DESCRIBE openregister_authorization_exceptions;'
   ```

3. Test with a simple exception:
   ```php
   $service->createException('inclusion', 'user', 'test-user', 'read');
   ```

## Security Considerations

1. **Audit Trail**: All exception creation/modification is logged with user information
2. **Admin Only**: Only administrators should create/modify authorization exceptions
3. **Regular Review**: Exceptions should be reviewed quarterly to ensure they're still appropriate
4. **Principle of Least Privilege**: Use the most restrictive scope possible for each exception

## Future Enhancements

Planned improvements to the authorization exception system:

1. **Time-based Exceptions**: Exceptions that automatically expire
2. **Conditional Exceptions**: Exceptions based on object properties or context
3. **Exception Templates**: Predefined exception patterns for common scenarios
4. **Visual Management**: Web interface for managing exceptions
5. **Advanced Reporting**: Analytics on exception usage and effectiveness

