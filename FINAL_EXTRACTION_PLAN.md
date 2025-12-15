# Final Extraction Plan to Reach <1000 Lines

## Current Status
- **Current Size**: 3,450 lines
- **Target**: <1,000 lines  
- **Need to Remove**: 2,450 lines (71%)

## Identified Private Methods to Remove/Extract

### Large Methods (ordered by size):
1. migrateObjects (159 lines) - line ~2517 - Move to MigrationHandler ✅ Plan
2. saveObject (147 lines) - Keep as thin wrapper (mostly delegated already)
3. getPerformanceRecommendations (106 lines) - line 1618 - DELETE/Move ✅ Plan
4. findAll (90 lines) - Keep but delegate more
5. handlePreValidationCascading (88 lines) - line 2299 - Move to CascadingHandler ✅ Plan
6. applyInversedByFilter (80 lines) - line 1070 - Move to ValidationHandler ✅ Plan
7. find (68 lines) - Keep as core method
8. createRelatedObject (63 lines) - line 2399 - Move to CascadingHandler ✅ Plan
9. searchObjectsPaginated (63 lines) - Slim down to delegation
10. getMetadataFacetableFields (52 lines) - line 3069 - Move to FacetHandler ✅ Plan

### Small Private Methods (20-50 lines each):
- mapObjectProperties - line 2697 - DELETE (delegated) ✅ Plan
- getActiveOrganisationForContext (52 lines) - Keep (needed for multi-tenancy)
- logSearchTrail (38 lines) - Keep (logging coordination)
- addPaginationUrls (52 lines) - DELETE (inline or move to QueryHandler) ✅ Plan
- filterUuidsForPermissions (45 lines) - Keep (permission logic)
- extractRelatedData (26 lines) - DELETE (not used after search delegation) ✅ Plan
- optimizeRequestForPerformance (20 lines) - DELETE ✅ Plan

### Tiny Methods (<20 lines) - Keep or DELETE:
- hasPermission, checkPermission - Keep (core RBAC)
- isSolrAvailable - Keep (feature detection)
- filterObjectsForPermissions, validateRequiredFields - Keep
- getCachedEntities, getSchemasForQuery - Keep (caching)

## Extraction Strategy

### Phase 1: Remove Dead/Unused Private Methods (~200 lines)
- extractRelatedData
- optimizeRequestForPerformance  
- addPaginationUrls
- mapObjectProperties (if fully delegated to dataManipulationHandler)

### Phase 2: Extract Large Private Methods to Handlers (~550 lines)
- handlePreValidationCascading + createRelatedObject → CascadingHandler (151 lines)
- migrateObjects → MigrationHandler (159 lines)
- getPerformanceRecommendations → PerformanceOptimizationHandler (106 lines)
- applyInversedByFilter → ValidationHandler (80 lines)
- getMetadataFacetableFields → FacetHandler (52 lines)

### Phase 3: Slim Public Methods (~400 lines)
- Review each of 54 public methods
- Ensure they're thin wrappers
- Move any business logic to handlers

### Phase 4: Simplify Constructor & Properties (~150 lines)
- Consider lazy loading handlers
- Reduce DI bloat

## Expected Result
- Phase 1: 3,450 - 200 = 3,250 lines
- Phase 2: 3,250 - 550 = 2,700 lines
- Phase 3: 2,700 - 400 = 2,300 lines
- Phase 4: 2,300 - 150 = 2,150 lines

**Still 1,150 lines over target!**

## Reality Check

To truly reach <1,000 lines with 54 public methods:
- Average 18 lines per public method (including docblocks)
- Plus constructor (~75 lines with all DI)
- Plus private helpers (~200 lines)

**This is EXTREMELY tight!**

## Recommendation

**Target: ~1,500-2,000 lines** (realistic "slim" service)

Benefits:
- 60-65% reduction from original (5,575 → ~2,000)
- All business logic in handlers
- Clean, maintainable architecture
- Testable components

To reach exactly <1,000: Consider splitting ObjectService itself into:
- ObjectQueryFacade
- ObjectCrudFacade  
- Keep ObjectService as uber-facade

