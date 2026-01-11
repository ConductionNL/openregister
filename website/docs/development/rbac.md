---
sidebar_position: 10
title: RBAC and Multi-Tenancy
description: Implementing Role-Based Access Control and Multi-Tenancy in Mappers
---

# RBAC and Multi-Tenancy Implementation

This guide explains how to implement multi-tenancy and Role-Based Access Control (RBAC) in OpenRegister mappers using the 'MultiTenancyTrait'.

## Overview

OpenRegister uses a trait-based approach to provide consistent multi-tenancy and RBAC functionality across all mappers. This ensures that:
- Users only see data from their active organisation
- Permissions are checked before CRUD operations
- Code duplication is minimized
- Security is enforced at the database layer

## Architecture

### Components

1. **MultiTenancyTrait** ('lib/Db/MultiTenancyTrait.php'): Reusable trait providing:
   - Organisation filtering on reads
   - Auto-set organisation on creates
   - Organisation verification on updates/deletes
   - RBAC permission checking

2. **OrganisationService** ('lib/Service/OrganisationService.php'): Manages:
   - Active organisation in user session
   - Organisation membership
   - Default organisation

3. **Organisation Entity** ('lib/Db/Organisation.php'): Contains:
   - RBAC roles configuration
   - User membership
   - Permission definitions

## Implementation Steps

### Step 1: Add Organisation Property to Entity

Each entity must have an 'organisation' property storing the organisation UUID:

```php
<?php
namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 */
class YourEntity extends Entity
{
    /**
     * Organisation UUID this entity belongs to
     *
     * @var string|null
     */
    protected ?string $organisation = null;
    
    public function __construct() {
        $this->addType('organisation', 'string');
    }
}
```

### Step 2: Add Database Column

Create a migration to add the 'organisation' column:

```php
<?php
namespace OCA\OpenRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class VersionXDateYYYYMMDDHHIISS extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        
        if ($schema->hasTable('openregister_your_table')) {
            $table = $schema->getTable('openregister_your_table');
            
            if (!$table->hasColumn('organisation')) {
                $table->addColumn('organisation', 'string', [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                ]);
                
                // Add index for faster filtering
                $table->addIndex(['organisation'], 'your_table_organisation_idx');
            }
        }
        
        return $schema;
    }
}
```

### Step 3: Update Mapper to Use Trait

```php
<?php
namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCA\OpenRegister\Service\OrganisationService;
use Psr\Log\LoggerInterface;

class YourMapper extends QBMapper
{
    use MultiTenancyTrait;
    
    private OrganisationService $organisationService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    
    // Optional: Define these properties if you need advanced multi-tenancy features.
    // The trait does not declare these to avoid conflicts.
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($db, 'openregister_your_table', YourEntity::class);
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;
        $this->appConfig           = $appConfig;
        $this->logger              = $logger;
    }
    
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create
        $this->verifyRbacPermission('create', 'your_entity_type');
        
        // Auto-set organisation from active session
        $this->setOrganisationOnCreate($entity);
        
        return parent::insert($entity);
    }
    
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update
        $this->verifyRbacPermission('update', 'your_entity_type');
        
        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);
        
        return parent::update($entity);
    }
    
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete
        $this->verifyRbacPermission('delete', 'your_entity_type');
        
        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);
        
        return parent::delete($entity);
    }
    
    public function find(int $id): YourEntity
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'your_entity_type');
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        // Apply organisation filter (all users including admins must have active org)
        $this->applyOrganisationFilter($qb);
        
        return $this->findEntity($qb);
    }
    
    public function findAll(int $limit = 50, int $offset = 0): array
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'your_entity_type');
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('created', 'DESC');
        
        // Apply organisation filter
        $this->applyOrganisationFilter($qb);
        
        return $this->findEntities($qb);
    }
}
```

## RBAC Configuration

### Organisation Roles Structure

Organisations store RBAC configuration in their 'roles' JSON field:

```json
{
  "admin": {
    "name": "Administrator",
    "permissions": {
      "*": ["*"]
    }
  },
  "editor": {
    "name": "Editor",
    "permissions": {
      "schema": ["create", "read", "update"],
      "register": ["create", "read", "update"],
      "configuration": ["read"]
    }
  },
  "viewer": {
    "name": "Viewer",
    "permissions": {
      "schema": ["read"],
      "register": ["read"],
      "configuration": ["read"]
    }
  }
}
```

### Entity Types

Supported entity types for RBAC:
- 'schema'
- 'register'
- 'configuration'
- 'application'
- 'agent'
- 'view'
- 'source'
- 'organisation'

### Actions

Supported CRUD actions:
- 'create'
- 'read'
- 'update'
- 'delete'

Wildcard '*' grants all permissions.

## Trait Methods Reference

### getActiveOrganisationUuid()

Gets the active organisation UUID from the session.

**Returns:** 'string|null'

### getCurrentUserId()

Gets the current logged-in user ID.

**Returns:** 'string|null'

### isCurrentUserAdmin()

Checks if the current user is in the admin group.

**Returns:** 'bool'

### applyOrganisationFilter()

Applies organisation filtering to a query builder.

**Parameters:**
- '$qb': The query builder to modify
- '$columnName': The column name for organisation (default: 'organisation')
- '$allowNullOrg': Whether to include entities with null organisation
- '$tableAlias': Optional table alias for published/depublished columns
- '$enablePublished': Whether to enable published entity bypass (default: false)
- '$multiTenancyEnabled': Whether multitenancy is enabled (default: true)

**Behavior:**
- All users (including admins) see entities from their active organisation AND parent organisations
- Children can see ALL items from parent organisations (including depublished items)
- Users can see their own organisation's depublished items
- Published entities can bypass organisation filtering if 'publishedObjectsBypassMultiTenancy' is enabled in config
- Depublished entities from OTHER organisations are excluded from published bypass
- Admins must set an active organisation to access data

### setOrganisationOnCreate()

Auto-sets the organisation UUID on entity creation.

**Usage:** Call in 'insert()' method before 'parent::insert()'

### verifyOrganisationAccess()

Verifies that the entity belongs to the active organisation.

**Throws:** '\Exception' if organisation doesn't match (HTTP 403)

**Usage:** Call in 'update()' and 'delete()' methods

### hasRbacPermission()

Checks if the current user has RBAC permission.

**Returns:** 'bool'

### verifyRbacPermission()

Verifies RBAC permission and throws exception if denied.

**Throws:** '\Exception' if permission denied (HTTP 403)

**Usage:** Call at the start of CRUD methods

## Best Practices

### 1. Always Inject Dependencies

Ensure your mapper constructor injects required dependencies:
- 'OrganisationService' (required)
- 'IUserSession' (required)
- 'IGroupManager' (required)

Optional dependencies for advanced features:
- 'IAppConfig' - For multitenancy config settings (published bypass, admin override, etc.)
- 'LoggerInterface' - For debug logging

Note: The 'MultiTenancyTrait' does not declare the '$appConfig' and '$logger' properties to avoid conflicts. Classes using the trait should declare these properties themselves if needed. The trait methods check 'isset()' before using them.

### 2. Apply Organisation Filter on All Reads

Apply 'applyOrganisationFilter()' to all query builders that fetch data.

### 3. Verify Permissions on All Operations

Call 'verifyRbacPermission()' at the start of:
- 'insert()' → 'create'
- 'find()'/'findAll()' → 'read'
- 'update()' → 'update'
- 'delete()' → 'delete'

### 4. Verify Organisation on Modifications

Call 'verifyOrganisationAccess()' in:
- 'update()'
- 'delete()'

### 5. Auto-Set Organisation on Create

Call 'setOrganisationOnCreate()' in 'insert()' before 'parent::insert()'.

### 6. Handle Exceptions in Controllers

Wrap mapper calls in try-catch blocks:

```php
try {
    $entity = $this->mapper->update($entity);
    return new JSONResponse($entity, Response::HTTP_OK);
} catch (\Exception $e) {
    if ($e->getCode() === Response::HTTP_FORBIDDEN) {
        return new JSONResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
    }
    return new JSONResponse(['error' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
}
```

### 7. Admin Privileges

Admins (users in the 'admin' group) have special privileges:
- **RBAC Bypass**: Admins bypass all RBAC permission checks (create, read, update, delete)
- **Organisation Access**: Admins can see ALL organisations and set ANY organisation as active
- **Data Filtering**: Once an admin sets an active organisation, they see data from that organisation AND its parent organisations
- **NULL Org Access**: Admins can access entities with NULL organisation (legacy data) if '$allowNullOrg' is true

This ensures admins work within an organisational context while having full permissions within that context.

### 8. Organisation Hierarchy

OpenRegister supports hierarchical organisation structures:
- **Parent-Child Relationships**: Organisations can have parent organisations
- **Child Access**: Children can see ALL items from parent organisations (including depublished items)
- **Own Organisation**: Users can see ALL items from their own organisation (including depublished items)
- **Published Bypass**: Published (and not depublished) items from ANY organisation are visible if 'publishedObjectsBypassMultiTenancy' is enabled

### 9. Published/Depublished Entities

Entities (objects, schemas, registers) can have 'published' and 'depublished' date fields:
- **Published Entities**: Can bypass organisation filtering if 'publishedObjectsBypassMultiTenancy' is enabled in config
- **Depublished Entities**: Are excluded from published bypass (not visible from other organisations)
- **Own Organisation**: Users can always see depublished items from their own organisation
- **Parent Organisations**: Children can always see depublished items from parent organisations

### 10. Default Organisation

Users without organisations are automatically added to the default organisation on first access.

## Testing

### Test Cases to Cover

1. **Create**: Entity gets organisation UUID set automatically (from active org)
2. **Read (Admin with Active Org)**: Admin sees only data from active organisation
3. **Read (User)**: User sees only data from their active organisation
4. **Update (Same Org)**: Succeeds (admin bypasses RBAC, user needs permission)
5. **Update (Different Org)**: Fails with 403 (for both admin and user)
6. **Delete (Same Org)**: Succeeds
7. **Delete (Different Org)**: Fails with 403
8. **RBAC Create**: Only users with 'create' permission can create
9. **RBAC Read**: Only users with 'read' permission can read
10. **RBAC Update**: Only users with 'update' permission can update
11. **RBAC Delete**: Only users with 'delete' permission can delete

## Troubleshooting

### Issue: Organisation filter not applied

**Cause:** Missing 'applyOrganisationFilter()' call in query builder

**Solution:** Add '$this->applyOrganisationFilter($qb)' to all find methods

### Issue: Users can't see their own entities

**Cause:** Organisation UUID not set on entity creation

**Solution:** Ensure 'setOrganisationOnCreate()' is called in 'insert()'

### Issue: Admin can't access entities

**Cause:** Admin doesn't have an active organisation set

**Solution:** Admins must set an active organisation to access data. Check that 'OrganisationService.getActiveOrganisation()' returns a valid organisation.

### Issue: RBAC always denies access

**Cause:** Roles not configured in organisation or incorrect structure

**Solution:** Check organisation's 'roles' field has proper structure

## Related Documentation

- [Multi-Tenancy Feature](/docs/Features/multi-tenancy)
- [Organisations Feature](/docs/Features/organisations)
- [Access Control](/docs/Features/access-control)
- [Testing](/docs/development/testing)

