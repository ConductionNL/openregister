# ObjectService Refactoring Plan

## Current Issues

### 1. Handlers in Wrong Location
Handlers are in `lib/Service/Object/Handlers/` but should be in `lib/Service/Object/`

**Existing Handlers to Move:**
- CrudHandler.php (468 lines)
- RelationHandler.php (328 lines) - DUPLICATE! Also exists in Object/
- MergeHandler.php (299 lines) - DUPLICATE! Also exists in Object/
- VectorizationHandler.php (219 lines)
- ExportHandler.php (378 lines)
- AuditHandler.php (252 lines)
- LockHandler.php (262 lines)
- PublishHandler.php (299 lines)

### 2. Business Logic Still in ObjectService

**Methods with Business Logic (need extraction):**

#### Bulk Operations (High Priority)
1. **deleteObjects()** (lines 3949-3993)
   - Logic: Bulk delete with RBAC filtering and cache invalidation
   - Extract to: `BulkOperationsHandler` (already exists!)
   - LLOC: ~45 lines

2. **publishObjects()** (lines 4015-4059)
   - Logic: Bulk publish with RBAC filtering and cache invalidation
   - Extract to: `BulkOperationsHandler`
   - LLOC: ~45 lines

3. **depublishObjects()** (lines 4081-4145)
   - Logic: Bulk depublish with RBAC filtering and cache invalidation
   - Extract to: `BulkOperationsHandler`
   - LLOC: ~45 lines

4. **publishObjectsBySchema()** (lines 4145-4203)
   - Logic: Publish all objects in a schema
   - Extract to: `BulkOperationsHandler`
   - LLOC: ~58 lines

5. **deleteObjectsBySchema()** (lines 4204-4260)
   - Logic: Delete all objects in a schema
   - Extract to: `BulkOperationsHandler`
   - LLOC: ~57 lines

6. **deleteObjectsByRegister()** (lines 4260-4315)
   - Logic: Delete all objects in a register
   - Extract to: `BulkOperationsHandler`
   - LLOC: ~55 lines

7. **validateObjectsBySchema()** (lines 4315-4387)
   - Logic: Validate all objects in a schema
   - Extract to: `ValidationHandler` (already exists!)
   - LLOC: ~73 lines

#### Merge Operations (Critical Priority)
8. **mergeObjects()** (lines 3066-3276)
   - Logic: Complex merge with files, relations, references
   - Extract to: `MergeHandler` (already exists!)
   - LLOC: ~210 lines

9. **transferObjectFiles()** (lines 3276-3349)
   - Logic: Transfer files between objects during merge
   - Extract to: `MergeHandler`
   - LLOC: ~74 lines

10. **deleteObjectFiles()** (lines 3349-3416)
    - Logic: Delete files from source object after merge
    - Extract to: `MergeHandler`
    - LLOC: ~67 lines

#### Relation/Cascading Operations
11. **handlePreValidationCascading()** (lines 3416-3516)
    - Logic: Handle cascading creates before validation
    - Extract to: `RelationHandler` (already exists!)
    - LLOC: ~100 lines

12. **createRelatedObject()** (lines 3516-3596)
    - Logic: Create related objects during cascading
    - Extract to: `RelationHandler`
    - LLOC: ~80 lines

13. **extractRelatedData()** (lines 3596-3613)
    - Logic: Extract related data from results
    - Extract to: `RelationHandler`
    - LLOC: ~17 lines

#### Migration Operations
14. **migrateObjects()** (lines 3644-3824)
    - Logic: Migrate objects between registers/schemas
    - Create new: `MigrationHandler`
    - LLOC: ~180 lines

15. **mapObjectProperties()** (lines 3824-3850)
    - Logic: Map properties during migration
    - Extract to: `MigrationHandler`
    - LLOC: ~26 lines

#### Performance/Optimization
16. **extractAllRelationshipIds()** (lines 4519-4612)
    - Logic: Extract relationship IDs for bulk loading
    - Extract to: `PerformanceOptimizationHandler` (already exists!)
    - LLOC: ~93 lines

17. **bulkLoadRelationshipsBatched()** (lines 4612-4721)
    - Logic: Load relationships in batches
    - Extract to: `PerformanceOptimizationHandler`
    - LLOC: ~109 lines

18. **bulkLoadRelationshipsParallel()** (lines 4721-4829)
    - Logic: Load relationships in parallel
    - Extract to: `PerformanceOptimizationHandler`
    - LLOC: ~108 lines

19. **loadRelationshipChunkOptimized()** (lines 4829-4910)
    - Logic: Optimized loading of relationship chunks
    - Extract to: `PerformanceOptimizationHandler`
    - LLOC: ~81 lines

20. **createLightweightObjectEntity()** (lines 4910-5002)
    - Logic: Create lightweight entities for performance
    - Extract to: `PerformanceOptimizationHandler`
    - LLOC: ~92 lines

#### Search/Query Operations
21. **buildSearchQuery()** (lines 1191-1217)
    - Logic: Build search queries
    - Extract to: `SearchQueryHandler` (already exists!)
    - LLOC: ~26 lines

22. **applyViewsToQuery()** (lines 1217-1254)
    - Logic: Apply view filters to queries
    - Extract to: `SearchQueryHandler`
    - LLOC: ~37 lines

23. **getFacetsForObjects()** (lines 1659-1695)
    - Logic: Get facets for search results
    - Extract to: `FacetHandler` (already exists!)
    - LLOC: ~36 lines

24. **getFacetableFields()** (lines 1695-1813)
    - Logic: Get facetable fields from schema
    - Extract to: `FacetHandler`
    - LLOC: ~118 lines

#### Import/Export
25. **exportObjects()** (lines 5497-5531)
    - Logic: Export objects to Excel
    - Extract to: `ExportHandler` (already exists in Handlers/)
    - LLOC: ~35 lines

26. **importObjects()** (lines 5531-5565)
    - Logic: Import objects from file
    - Create new: `ImportHandler`
    - LLOC: ~35 lines

#### File Operations
27. **downloadObjectFiles()** (lines 5565-5575)
    - Logic: Download files from object
    - Extract to: `FileHandler` (in FileService?)
    - LLOC: ~10 lines

#### Utility Methods (Low Priority but still logic)
28. **applyInversedByFilter()** (lines 1045-1138)
    - Logic: Apply inversed relation filters
    - Extract to: `RelationHandler`
    - LLOC: ~93 lines

29. **getActiveOrganisationForContext()** (lines 1138-1191)
    - Logic: Get active organization
    - Extract to: `PermissionHandler` (already exists!)
    - LLOC: ~53 lines

30. **filterObjectsForPermissions()** (lines 2969-2991)
    - Logic: Filter objects based on permissions
    - Extract to: `PermissionHandler`
    - LLOC: ~22 lines

31. **validateRequiredFields()** (lines 2991-3066)
    - Logic: Validate required fields in bulk objects
    - Extract to: `ValidationHandler`
    - LLOC: ~75 lines

32. **getValueFromPath()** (lines 2879-2905)
    - Logic: Extract value from nested path
    - Extract to: `UtilityHandler` (already exists!)
    - LLOC: ~26 lines

33. **generateSlugFromValue()** (lines 2905-2933)
    - Logic: Generate slug from value
    - Extract to: `UtilityHandler`
    - LLOC: ~28 lines

34. **createSlugHelper()** (lines 2933-2969)
    - Logic: Create URL-friendly slug
    - Extract to: `UtilityHandler`
    - LLOC: ~36 lines

35. **cleanQuery()** (lines 3877-3949)
    - Logic: Clean query parameters
    - Extract to: `SearchQueryHandler`
    - LLOC: ~72 lines

36. **logSearchTrail()** (lines 3850-3877)
    - Logic: Log search trail
    - Extract to: `AuditHandler` (already exists in Handlers/)
    - LLOC: ~27 lines

## Refactoring Strategy

### Phase 1: Resolve Duplicates and Move Handlers (Immediate)
1. Check for duplicate handlers (RelationHandler, MergeHandler exist in both locations)
2. Compare duplicates and keep the most recent/complete version
3. Move all handlers from `lib/Service/Object/Handlers/` to `lib/Service/Object/`
4. Update all import statements in ObjectService
5. Delete the empty `Handlers/` directory

### Phase 2: Extract Bulk Operations (High Priority)
1. Extend `BulkOperationsHandler` with:
   - deleteObjects()
   - publishObjects()
   - depublishObjects()
   - publishObjectsBySchema()
   - deleteObjectsBySchema()
   - deleteObjectsByRegister()

### Phase 3: Extract Complex Operations (Critical)
1. Move merge logic to `MergeHandler`
2. Move migration logic to new `MigrationHandler`
3. Move validation schema logic to `ValidationHandler`

### Phase 4: Extract Performance Operations
1. Move all relationship loading logic to `PerformanceOptimizationHandler`
2. Consolidate caching logic in `CacheHandler`

### Phase 5: Extract Utility Operations
1. Move utility methods to `UtilityHandler`
2. Move permission checks to `PermissionHandler`
3. Move search/query building to `SearchQueryHandler`
4. Move facet logic to `FacetHandler`

### Phase 6: Import/Export
1. Move export logic to `ExportHandler`
2. Create `ImportHandler` for import logic

## Expected Results

**Before:**
- ObjectService: 1873 LLOC, 617 WMC, 98 methods
- Rating: CRITICAL

**After:**
- ObjectService: ~300-400 LLOC, ~50-80 WMC, ~25-30 methods (delegation only)
- Rating: ACCEPTABLE
- Business logic properly distributed across 15+ focused handlers

## Handlers After Refactoring

1. **BulkOperationsHandler** - All bulk operations (delete, publish, migrate schemas)
2. **MergeHandler** - Object merging with files/relations
3. **MigrationHandler** - Object migration between schemas/registers
4. **ValidationHandler** - Validation logic including schema-wide validation
5. **RelationHandler** - All relationship operations and cascading
6. **PerformanceOptimizationHandler** - Relationship loading and optimization
7. **PermissionHandler** - All permission and RBAC logic
8. **SearchQueryHandler** - Query building and manipulation
9. **FacetHandler** - Facet operations
10. **ExportHandler** - Export operations
11. **ImportHandler** - Import operations (new)
12. **UtilityHandler** - Utility methods (slug, path extraction)
13. **AuditHandler** - Audit logging
14. **CrudHandler** - Basic CRUD operations
15. **VectorizationHandler** - Vector operations
16. **LockHandler** - Object locking
17. **PublishHandler** - Publishing operations
