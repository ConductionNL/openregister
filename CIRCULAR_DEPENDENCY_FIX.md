# Circular Dependency Fix

**Date:** December 15, 2024  
**Issue:** Xdebug infinite loop preventing app from loading  
**Root Cause:** Circular dependencies between ObjectService and its handlers

---

## Problem

### Circular Dependencies Detected

**ObjectService** injects:
- `ExportHandler`
- `VectorizationHandler`
- `MergeHandler`
- `RelationHandler`
- `CrudHandler`

**These Handlers inject ObjectService back:**
- `ExportHandler` →  ✅ **FIXED** (removed, using ObjectEntityMapper)
- `VectorizationHandler` → ❌ **CIRCULAR** (line 59)
- `MergeHandler` → ❌ **CIRCULAR** (line 54)
- `RelationHandler` → ❌ **CIRCULAR** (line 59)
- `CrudHandler` → ❌ **CIRCULAR** (line 59)

### Why This Is a Problem

```
ObjectService needs VectorizationHandler
├─ VectorizationHandler needs ObjectService
   └─ ObjectService needs VectorizationHandler
      └─ VectorizationHandler needs ObjectService
         └─ ♾️ INFINITE LOOP
```

---

## Solution Strategy

### Principle: Handlers Should Be Dumb

Handlers should:
- ✅ Call mappers directly
- ✅ Call specialized services (FileService, VectorizationService, etc.)
- ❌ NOT call ObjectService (they ARE called BY ObjectService!)

### Fix for Each Handler

#### 1. VectorizationHandler ✅ SIMPLE
- **Remove:** `ObjectService` injection
- **Keep:** `VectorizationService` (already injected)
- **Why:** It only needs to call VectorizationService, not ObjectService

#### 2. MergeHandler ⚠️ COMPLEX
- **Remove:** `ObjectService` injection
- **Add:** `ObjectEntityMapper` injection
- **Why:** Needs to find/update objects directly
- **Check:** Does it call other ObjectService methods?

#### 3. RelationHandler ⚠️ COMPLEX
- **Remove:** `ObjectService` injection  
- **Add:** `ObjectEntityMapper` injection
- **Why:** Needs to find objects and their relations
- **Check:** Does it use contracts/uses/used methods?

#### 4. CrudHandler ⚠️ VERY COMPLEX
- **Remove:** `ObjectService` injection
- **Add:** Multiple mapper injections as needed
- **Why:** It's the workhorse handler with many operations
- **Check:** What ObjectService methods does it call?

---

## Implementation Plan

### Step 1: Analyze Each Handler's Usage

For each handler, check:
```bash
grep "this->objectService->" HandlerFile.php
```

### Step 2: Replace ObjectService Calls

Replace with direct mapper calls:
- `$this->objectService->find()` → `$this->objectEntityMapper->find()`
- `$this->objectService->update()` → `$this->objectEntityMapper->update()`  
- `$this->objectService->create()` → `$this->objectEntityMapper->insert()`
- etc.

### Step 3: Update Constructors

Remove `ObjectService` parameter, add required mappers.

### Step 4: Test App Loading

```bash
docker exec -u 33 master-nextcloud-1 php occ app:disable openregister
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

Should NOT see: "Xdebug has detected a possible infinite loop"

---

## Files To Modify

1. ✅ `lib/Service/Object/Handlers/ExportHandler.php` - DONE
2. ❌ `lib/Service/Object/Handlers/VectorizationHandler.php` - TODO
3. ❌ `lib/Service/Object/Handlers/MergeHandler.php` - TODO
4. ❌ `lib/Service/Object/Handlers/RelationHandler.php` - TODO
5. ❌ `lib/Service/Object/Handlers/CrudHandler.php` - TODO

---

## Progress

- [x] Identified circular dependencies
- [x] Fixed ExportHandler
- [ ] Fix VectorizationHandler
- [ ] Fix MergeHandler
- [ ] Fix RelationHandler
- [ ] Fix CrudHandler
- [ ] Test app loads
- [ ] Run integration tests

---

## Next Steps

1. Analyze each handler's `$this->objectService->` calls
2. Determine what mappers/services are actually needed
3. Update constructors and replace calls
4. Test incrementally

**START WITH:** VectorizationHandler (simplest - probably doesn't need ObjectService at all!)

