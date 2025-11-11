# Organization Filter Consolidation

## Overview

The organization filtering functionality has been consolidated into a single, comprehensive method in the `MultiTenancyTrait` to prevent code duplication and provide consistent multi-tenancy behavior across all entities.

## Changes Made

### 1. Enhanced `applyOrganisationFilter()` in MultiTenancyTrait

The `applyOrganisationFilter()` method in `MultiTenancyTrait` has been enhanced with advanced features:

**Features:**
- ✅ Hierarchical organisation support (active org + all parents)
- ✅ Published object bypass for multi-tenancy (objects table only)
- ✅ Admin override capabilities
- ✅ System default organisation special handling
- ✅ NULL organisation legacy data access for admins
- ✅ Unauthenticated request handling
- ✅ Multitenancy configuration checking

**Method Signature:**

'''php
protected function applyOrganisationFilter(
    IQueryBuilder $qb, 
    string $columnName = 'organisation', 
    bool $allowNullOrg = false,
    string $tableAlias = '',
    bool $enablePublished = false
): void
'''

### 2. Parameters Explanation

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$qb` | IQueryBuilder | Required | The query builder instance |
| `$columnName` | string | 'organisation' | Column name for organisation filtering |
| `$allowNullOrg` | bool | false | Allow admins to see NULL organisation entities (legacy data) |
| `$tableAlias` | string | '' | Table alias for published/depublished columns |
| `$enablePublished` | bool | false | Enable published object bypass (objects table only) |

### 3. ObjectEntityMapper Integration

`ObjectEntityMapper` now uses the `MultiTenancyTrait` and has access to the enhanced filtering method:

'''php
class ObjectEntityMapper extends QBMapper
{
    use MultiTenancyTrait;
    
    // OrganisationService injected in constructor
    private OrganisationService $organisationService;
    
    // Call the enhanced filter method
    $this->applyOrganisationFilter(
        qb: $qb,
        columnName: 'organisation',
        allowNullOrg: true,        // Admins can see NULL org objects
        tableAlias: 'o',             // Objects table alias
        enablePublished: true        // Enable published bypass
    );
}
'''

### 4. Other Mappers Usage

For entities without published/depublished fields, simply use the method without those parameters:

'''php
class SchemaMapper extends QBMapper
{
    use MultiTenancyTrait;
    
    // Simple organisation filtering
    $this->applyOrganisationFilter(
        qb: $qb,
        columnName: 'organisation',
        allowNullOrg: false  // Standard filtering
    );
}
'''

## Benefits

### 1. Code Consolidation
- **Before**: Duplicate filtering logic in `ObjectEntityMapper::applyOrganizationFilters()` and `MultiTenancyTrait::applyOrganisationFilter()`
- **After**: Single source of truth in `MultiTenancyTrait::applyOrganisationFilter()`

### 2. Consistent Behavior
- All entities now use the same advanced filtering logic
- Published object bypass can be enabled for any entity with published/depublished fields
- Admin override and default organisation handling consistent across all mappers

### 3. Flexibility
- Conditional published/depublished logic based on `$enablePublished` parameter
- Works for both simple and complex entity filtering
- Easy to extend with additional features

### 4. Maintainability
- Single method to update for security fixes or feature enhancements
- Clear documentation of all multitenancy behaviors
- Consistent parameter naming and signatures

## Technical Details

### Published Object Bypass

When `$enablePublished` is true, the filter adds logic to include published objects regardless of organisation:

'''php
// Published objects visible across organisations
if ($publishedBypassEnabled && $enablePublished) {
    $now = (new \DateTime())->format('Y-m-d H:i:s');
    $orgConditions->add(
        $qb->expr()->andX(
            $qb->expr()->isNotNull($publishedColumn),
            $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
            $qb->expr()->orX(
                $qb->expr()->isNull($depublishedColumn),
                $qb->expr()->gt($depublishedColumn, $qb->createNamedParameter($now))
            )
        )
    );
}
'''

### Configuration Checking

The filter automatically checks multitenancy configuration:

'''php
// Check if multitenancy is enabled
if (isset($this->appConfig)) {
    $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
    if (!empty($multitenancyConfig)) {
        $multitenancyData = json_decode($multitenancyConfig, true);
        $multitenancyEnabled = $multitenancyData['enabled'] ?? true;
        
        if (!$multitenancyEnabled) {
            // Multitenancy disabled, skip filtering
            return;
        }
    }
}
'''

### Admin Override

Admins with override enabled see all entities:

'''php
$adminOverrideEnabled = false;
if ($isAdmin && isset($this->appConfig)) {
    $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
    if (!empty($multitenancyConfig)) {
        $multitenancyData = json_decode($multitenancyConfig, true);
        $adminOverrideEnabled = $multitenancyData['adminOverride'] ?? false;
    }
}

if ($isAdmin && $adminOverrideEnabled) {
    // No filtering for admins
    return;
}
'''

## Migration Guide

### For Developers

If you have custom mappers using organisation filtering:

**Old Way:**
'''php
// Custom organisation filter in your mapper
private function myCustomOrgFilter(IQueryBuilder $qb) {
    // Complex filtering logic...
}
'''

**New Way:**
'''php
// Use the trait
class MyCustomMapper extends QBMapper
{
    use MultiTenancyTrait;
    
    // Required dependencies
    private OrganisationService $organisationService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    
    // Optional for advanced features
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    
    public function findAll() {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->tableName);
        
        // Apply standard organisation filtering
        $this->applyOrganisationFilter($qb);
        
        return $this->findEntities($qb);
    }
}
'''

### For ObjectEntity Queries

If you were calling the old `applyOrganizationFilters()` method:

**Old Way:**
'''php
$this->applyOrganizationFilters(
    $qb, 
    'o',                      // table alias
    $activeOrganisationUuids, // org UUIDs
    $multi                    // multitenancy flag
);
'''

**New Way:**
'''php
$this->applyOrganisationFilter(
    qb: $qb,
    columnName: 'organisation',
    allowNullOrg: true,
    tableAlias: 'o',
    enablePublished: true
);
'''

## Entity Properties

### Required Properties

For proper organisation filtering, entities should have:

| Property | Type | Purpose | Required |
|----------|------|---------|----------|
| `organisation` | string (UUID) | Organisation ownership | ✅ Yes |
| `owner` | string (userId) | Entity owner | ✅ Recommended |
| `created` | DateTime | Creation timestamp | ✅ Recommended |

### Optional Properties (for Advanced Features)

| Property | Type | Purpose | Used By |
|----------|------|---------|---------|
| `published` | DateTime | Publication date | ObjectEntity |
| `depublished` | DateTime | Depublication date | ObjectEntity |
| `deleted` | DateTime/array | Soft delete timestamp | Schema, Register, ObjectEntity |

### Current Entity Status

| Entity | organisation | owner | created | deleted | published |
|--------|-------------|-------|---------|---------|-----------|
| ObjectEntity | ✅ | ✅ | ✅ | ✅ | ✅ |
| Schema | ✅ | ✅ | ✅ | ✅ | ❌ |
| Register | ✅ | ✅ | ✅ | ✅ | ❌ |
| Agent | ✅ | ✅ | ✅ | ❌ | ❌ |
| Application | ✅ | ✅ | ✅ | ❌ | ❌ |
| Source | ✅ | ❌ | ✅ | ❌ | ❌ |
| View | ✅ | ✅ | ✅ | ❌ | ❌ |
| Configuration | ✅ | ✅ | ✅ | ❌ | ❌ |

Note: Only `ObjectEntity` has `published`/`depublished` fields, so only ObjectEntity queries should use `enablePublished: true`.

## Testing

Test your mapper's organisation filtering:

'''php
public function testOrganisationFiltering() {
    // Setup: Create test organisations and entities
    $org1 = $this->createOrganisation('Org 1');
    $org2 = $this->createOrganisation('Org 2');
    
    $entity1 = $this->createEntity(['organisation' => $org1->getUuid()]);
    $entity2 = $this->createEntity(['organisation' => $org2->getUuid()]);
    
    // Set active organisation to org1
    $this->organisationService->setActiveOrganisation($org1->getUuid());
    
    // Test: findAll should only return entity1
    $results = $this->mapper->findAll();
    
    $this->assertCount(1, $results);
    $this->assertEquals($entity1->getId(), $results[0]->getId());
}
'''

## Security Considerations

1. **Always Filter at Database Level**: Organisation filtering is applied at the query level for security
2. **Never Trust Client Input**: Organisation is set from session, not from user input
3. **Admin Privileges**: Carefully consider when to enable `allowNullOrg` or admin override
4. **Published Bypass**: Only enable for public-facing content with proper publication workflow
5. **Audit Logging**: All organisation context is logged for compliance

## Future Enhancements

Potential improvements to consider:

1. **Caching**: Cache organisation hierarchy lookups
2. **Performance**: Optimize recursive parent chain queries
3. **Extended Entities**: Add published/depublished to more entities as needed
4. **Granular Permissions**: Per-entity published bypass configuration
5. **Cross-Organisation Sharing**: Explicit sharing mechanisms between organisations

## References

- [Multi-Tenancy Documentation](./multi-tenancy.md)
- [Organisation Hierarchies](../Features/multi-tenancy.md#organisation-hierarchies)
- [MultiTenancyTrait Source](../../lib/Db/MultiTenancyTrait.php)
- [ObjectEntityMapper Source](../../lib/Db/ObjectEntityMapper.php)

## Changelog

### v0.2.7-beta.151 (Current)

- ✅ Consolidated organisation filtering into `MultiTenancyTrait`
- ✅ Added conditional published/depublished logic
- ✅ Enhanced `ObjectEntityMapper` to use trait
- ✅ Added comprehensive documentation
- ✅ No breaking changes (backward compatible)

---

**Last Updated**: November 11, 2025  
**Author**: Conduction Development Team  
**Status**: Complete ✅

