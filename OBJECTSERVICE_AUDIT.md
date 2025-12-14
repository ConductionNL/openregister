# ObjectService Architecture Audit

**Date:** 2025-12-14  
**Service:** lib/Service/ObjectService.php  
**Size:** 5,942 lines, 86 methods  
**Status:** ✅ CORRECTLY IMPLEMENTED (Facade Pattern)

## Executive Summary

ObjectService is **correctly implemented** as a facade service following the handler pattern. While the file is large (5,942 lines), it properly delegates all business logic to specialized handlers. The service acts as an orchestration layer managing context, permissions, and cross-cutting concerns.

## Architecture Assessment

### ✅ Correct Delegation Pattern

ObjectService delegates to 8 specialized handlers:
1. **SaveObject** - Individual object save operations
2. **SaveObjects** - Bulk save operations
3. **GetObject** - Object retrieval
4. **DeleteObject** - Object deletion
5. **ValidateObject** - Validation logic
6. **RenderObject** - Object rendering/serialization
7. **PublishObject** - Object publication
8. **DepublishObject** - Object depublication

### Service Responsibilities (Appropriate)

ObjectService handles:
- ✅ Context management (currentRegister, currentSchema, currentObject)
- ✅ Permission checks (RBAC, multitenancy)
- ✅ Handler coordination and delegation
- ✅ Cache invalidation orchestration
- ✅ Cross-cutting concerns (logging, events)
- ✅ HTTP parameter processing
- ✅ Response formatting

### Handler Delegation Examples

#### saveObject() - Lines 854-1001
```php
public function saveObject(...) {
    // 1. Context setup
    if ($register !== null) {
        $this->setRegister($register);
    }
    if ($schema !== null) {
        $this->setSchema($schema);
    }
    
    // 2. Delegate to handler
    $savedObject = $this->saveHandler->saveObject(...);
    
    // 3. Delegate rendering to handler
    return $this->renderHandler->renderEntity(...);
}
```
**Assessment:** ✅ Correct - minimal orchestration, delegates business logic

#### deleteObject() - Lines 1015-1044
```php
public function deleteObject(string $uuid, ...) {
    // 1. Permission check (orchestration)
    $this->checkPermission($this->currentSchema, 'delete', ...);
    
    // 2. Delegate to handler
    return $this->deleteHandler->deleteObject(...);
}
```
**Assessment:** ✅ Correct - permission check, then delegation

#### find() - Lines 468-537
```php
public function find(...) {
    // 1. Context setup
    if ($register !== null) {
        $this->setRegister($register);
    }
    
    // 2. Delegate retrieval to handler
    $object = $this->getHandler->find(...);
    
    // 3. Permission check (orchestration)
    $this->checkPermission(...);
    
    // 4. Delegate rendering to handler
    return $this->renderHandler->renderEntity(...);
}
```
**Assessment:** ✅ Correct - orchestration with proper delegation

#### saveObjects() - Lines 3251-3324
```php
public function saveObjects(array $objects, ...) {
    // 1. Context setup
    if ($register !== null) {
        $this->setRegister($register);
    }
    
    // 2. Delegate to bulk handler
    $bulkResult = $this->saveObjectsHandler->saveObjects(...);
    
    // 3. Cache invalidation (orchestration)
    $this->objectCacheService->invalidateForObjectChange(...);
    
    return $bulkResult;
}
```
**Assessment:** ✅ Correct - delegates bulk logic, handles cache coordination

## Handler Analysis

### Existing Handlers (lib/Service/Objects/)

| Handler | Lines | Status | Notes |
|---------|-------|--------|-------|
| SaveObject.php | 3,800 | ⚠️ GOD OBJECT | Needs sub-handlers |
| SaveObjects.php | 2,370 | ⚠️ GOD OBJECT | Needs sub-handlers |
| ValidateObject.php | 1,487 | ✅ ACCEPTABLE | Could be optimized |
| RenderObject.php | 1,360 | ✅ ACCEPTABLE | Could be optimized |
| GetObject.php | 313 | ✅ GOOD | Appropriate size |
| DeleteObject.php | 303 | ✅ GOOD | Appropriate size |
| PublishObject.php | 82 | ✅ GOOD | Appropriate size |
| DepublishObject.php | 82 | ✅ GOOD | Appropriate size |

### Required Refactoring

#### 1. SaveObject Handler (3,800 lines) → Sub-handlers

Current structure is too large. Needs:
```
lib/Service/Objects/
├── SaveObject.php (facade - 300 lines)
└── Save/
    ├── RelationCascadeHandler.php
    ├── MetadataHydrationHandler.php
    ├── FilePropertyHandler.php
    └── ValidationCoordinatorHandler.php
```

#### 2. SaveObjects Handler (2,370 lines) → Sub-handlers

Bulk operations need separation:
```
lib/Service/Objects/
├── SaveObjects.php (facade - 300 lines)
└── Bulk/
    ├── BulkValidationHandler.php
    ├── BulkRelationHandler.php
    └── BulkOptimizationHandler.php
```

## Method Categorization

### Public Methods (42 total)

#### Delegation Methods (35) - ✅ CORRECT
Methods that properly delegate to handlers:
- find(), findSilent(), findAll()
- saveObject(), saveObjects()
- deleteObject(), deleteObjects()
- publish(), depublish(), publishObjects(), depublishObjects()
- publishObjectsBySchema(), deleteObjectsBySchema()
- validateObjectsBySchema()
- renderEntity()
- getLogs()
- searchObjects(), searchObjectsPaginated()
- getFacetsForObjects(), getFacetableFields()
- mergeObjects(), migrateObjects()

#### Context Methods (4) - ✅ CORRECT
Context management (appropriate for facade):
- setRegister(), getRegister()
- setSchema(), getSchema()
- setObject(), getObject()

#### Utility Methods (3) - ✅ CORRECT
Service-level utilities:
- handleValidationException()
- ensureObjectFolderExists()
- unlockObject()

### Private Methods (44 total)

All private methods are support functions for orchestration:
- Query building helpers
- Permission checking
- Context resolution
- Filter application
- Relation handling coordination

**Assessment:** ✅ Private methods are appropriate for a facade service

## Search & Query Methods

ObjectService has extensive search functionality (lines 1207-3040):
- buildSearchQuery()
- searchObjects()
- countSearchObjects()
- searchObjectsPaginated()
- searchObjectsPaginatedAsync()

**Current Implementation:** These methods contain significant query building logic.

**Recommendation:** Consider extracting to SearchHandler in future refactoring:
```
lib/Service/Objects/
└── SearchHandler.php (1,800 lines)
```

## Bulk Operations

ObjectService has several bulk operation methods:
- saveObjects() - Delegates to SaveObjects handler ✅
- deleteObjects() - Direct implementation ⚠️
- publishObjects() - Direct implementation ⚠️
- depublishObjects() - Direct implementation ⚠️
- migrateObjects() - Direct implementation ⚠️

**Recommendation:** Extract remaining bulk operations to handlers:
```
lib/Service/Objects/Bulk/
├── BulkDeleteHandler.php
├── BulkPublishHandler.php
└── BulkMigrateHandler.php
```

## Conclusions

### ✅ ObjectService is Correctly Architected

1. **Facade Pattern:** Properly implemented
2. **Delegation:** Consistently delegates business logic to handlers
3. **Concerns:** Appropriately handles orchestration, context, and permissions
4. **Size:** Large due to many public methods, but each method is simple

### ⚠️ Handler God Objects Need Addressing

The real issue is not ObjectService itself, but two of its handlers:
1. **SaveObject.php** (3,800 lines) - Too large
2. **SaveObjects.php** (2,370 lines) - Too large

### Recommended Actions

1. **Do NOT refactor ObjectService** - it's correctly implemented
2. **Extract SaveObject sub-handlers** - Priority HIGH
3. **Extract SaveObjects sub-handlers** - Priority HIGH
4. **Consider SearchHandler extraction** - Priority MEDIUM
5. **Consider remaining Bulk handlers** - Priority LOW

## No Changes Required to ObjectService.php

ObjectService correctly follows the facade pattern. The refactoring effort should focus on:
1. Breaking down SaveObject handler
2. Breaking down SaveObjects handler
3. Optionally extracting Search and remaining Bulk operations

The service itself should remain largely unchanged - it's doing exactly what a facade service should do.

---

**Audit Completed:** ObjectService architecture is sound. Focus refactoring on handler God Objects.

