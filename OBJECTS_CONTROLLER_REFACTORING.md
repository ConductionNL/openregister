# ObjectsController Refactoring Plan

**Date:** December 15, 2024  
**Status:** 🚧 In Progress  
**Goal:** Move business logic from ObjectsController (2,084 lines) to handlers

---

## Current State

**ObjectsController.php:** 2,084 lines with 24 public methods

### Method Analysis

#### CRUD Operations (Core)
1. `index()` - List objects in register/schema
2. `objects()` - List all objects
3. `show()` - Get single object
4. `create()` - Create new object
5. `update()` - Full update (PUT)
6. `patch()` - Partial update (PATCH)
7. `destroy()` - Delete object

#### Relations & References
8. `contracts()` - Get contracts for object
9. `uses()` - What this object uses
10. `used()` - What uses this object

#### Locking
11. `lock()` - Lock object for editing
12. `unlock()` - Unlock object

#### Audit
13. `logs()` - Get audit trail

#### Import/Export
14. `export()` - Export objects
15. `import()` - Import objects
16. `downloadFiles()` - Download related files

#### Publishing
17. `publish()` - Publish object
18. `depublish()` - Unpublish object

#### Merge/Migration
19. `merge()` - Merge objects
20. `migrate()` - Migrate objects

#### Vectorization
21. `vectorizeBatch()` - Batch vectorize objects
22. `getObjectVectorizationStats()` - Get stats
23. `getObjectVectorizationCount()` - Get count

---

## Target Architecture

```
ObjectsController (thin)
         ↓ delegates to
    ObjectService (coordinator)
         ↓ uses handlers
    ┌────────────────────────────┐
    │                            │
    ▼                            ▼
Object/CrudHandler      Object/RelationHandler
Object/LockHandler      Object/ExportHandler
Object/PublishHandler   Object/MergeHandler
Object/VectorHandler    Object/AuditHandler
```

---

## Handlers to Create

### 1. `lib/Service/Objects/Handlers/CrudHandler.php`
**Responsibility:** Core CRUD operations
**Methods:**
- `list()` - List objects with filters
- `get()` - Get single object
- `create()` - Create object
- `update()` - Update object
- `patch()` - Patch object
- `delete()` - Delete object

**Estimated lines:** ~400

---

### 2. `lib/Service/Objects/Handlers/RelationHandler.php`
**Responsibility:** Object relationships and references
**Methods:**
- `getContracts()` - Get contracts
- `getUses()` - Get what object uses
- `getUsedBy()` - Get what uses object
- `resolveReferences()` - Resolve object references

**Estimated lines:** ~300

---

### 3. `lib/Service/Objects/Handlers/LockHandler.php`
**Responsibility:** Object locking mechanism
**Methods:**
- `lock()` - Lock object
- `unlock()` - Unlock object
- `isLocked()` - Check lock status
- `canUnlock()` - Check unlock permission

**Estimated lines:** ~150

---

### 4. `lib/Service/Objects/Handlers/AuditHandler.php`
**Responsibility:** Audit trail and logging
**Methods:**
- `getLogs()` - Get audit trail
- `logChange()` - Log a change
- `logAccess()` - Log access

**Estimated lines:** ~200

---

### 5. `lib/Service/Objects/Handlers/ExportHandler.php`
**Responsibility:** Export and import operations
**Methods:**
- `export()` - Export objects
- `import()` - Import objects
- `downloadFiles()` - Download files
- `validateImport()` - Validate import data

**Estimated lines:** ~350

---

### 6. `lib/Service/Objects/Handlers/PublishHandler.php`
**Responsibility:** Publishing workflow
**Methods:**
- `publish()` - Publish object
- `depublish()` - Unpublish object
- `isPublished()` - Check publish status
- `canPublish()` - Check publish permission

**Estimated lines:** ~150

---

### 7. `lib/Service/Objects/Handlers/MergeHandler.php`
**Responsibility:** Object merging and migration
**Methods:**
- `merge()` - Merge objects
- `migrate()` - Migrate objects
- `validateMerge()` - Validate merge

**Estimated lines:** ~250

---

### 8. `lib/Service/Objects/Handlers/VectorizationHandler.php`
**Responsibility:** Object vectorization operations
**Methods:**
- `vectorizeBatch()` - Batch vectorize
- `getStats()` - Get vectorization stats
- `getCount()` - Get vectorization count

**Estimated lines:** ~200

---

## Refactoring Strategy

### Phase 1: Create Handler Structure ✅
- [x] Create `lib/Service/Objects/Handlers/` directory
- [ ] Create handler base class or interface (optional)

### Phase 2: Extract Simple Handlers First
**Priority Order (easiest to hardest):**
1. ✅ LockHandler (simplest)
2. ✅ AuditHandler
3. ✅ PublishHandler
4. ✅ VectorizationHandler
5. ✅ RelationHandler
6. ✅ MergeHandler
7. ✅ ExportHandler
8. ✅ CrudHandler (most complex - do last)

### Phase 3: Update ObjectService
- [ ] Add handler dependencies to ObjectService constructor
- [ ] Create delegation methods in ObjectService
- [ ] Update existing ObjectService methods to use handlers

### Phase 4: Refactor Controller
- [ ] Replace direct logic with ObjectService calls
- [ ] Thin down controller methods
- [ ] Keep only HTTP concerns (request/response handling)

### Phase 5: Testing & Validation
- [ ] Run PHPQA
- [ ] Fix any linting issues
- [ ] Test API endpoints
- [ ] Update documentation

---

## Benefits

### 🎯 Single Responsibility
- Each handler has ONE clear purpose
- Easy to understand and maintain

### 📦 Reusability
- Handlers can be used by other services/commands
- Logic not tied to HTTP layer

### 🧪 Testability
- Test handlers independently
- Mock handlers in controller tests

### 🔧 Maintainability
- Smaller files (<400 lines each)
- Clear separation of concerns
- Easier to find and fix bugs

---

## Expected Outcome

**Before:**
- ObjectsController: 2,084 lines (fat controller)
- ObjectService: 5,305 lines (god object)

**After:**
- ObjectsController: ~500 lines (thin controller)
- ObjectService: ~800 lines (coordinator)
- 8 Handlers: ~2,000 lines total (focused logic)

**Total reduction:** ~4,089 lines reorganized into maintainable structure

---

## Notes

- Export/Import already use ExportService/ImportService - keep that delegation
- Vectorization should eventually use VectorizationService
- Some methods already call ObjectService - keep that pattern
- Focus on extracting NEW handlers for logic currently IN the controller

