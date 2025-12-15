# ⚠️ CRITICAL: Circular Dependency Issue

**Status:** 🚨 **APP CANNOT LOAD**  
**Date:** December 15, 2024  
**Xdebug Error:** "Detected a possible infinite loop, aborted with stack depth of 512 frames"

---

## TL;DR - The Problem

When we moved logic from `ObjectsController` to handlers, we created **4 circular dependencies** that prevent the app from loading:

```
ObjectService ←→ VectorizationHandler
ObjectService ←→ MergeHandler  
ObjectService ←→ RelationHandler
ObjectService ←→ CrudHandler
```

---

## Root Cause Analysis

### What Went Wrong

The handlers we created are **NOT true handlers** - they're **wrapper/delegation methods** that call back to `ObjectService`!

**Example from MergeHandler:**
```php
// MergeHandler.php (line 97)
public function merge(...) {
    $result = $this->objectService->mergeObjects(...);  // ← Calls ObjectService!
}

// ObjectService.php
public function mergeObjects(...) {
    return $this->mergeHandler->merge(...);  // ← Calls MergeHandler!
}
```

This creates an infinite loop: Handler → Service → Handler → Service → ♾️

### Why This Happened

When extracting methods from `ObjectsController`, we:
1. ✅ Created handler classes
2. ✅ Moved controller code to handlers
3. ❌ **BUT** the handlers still call `ObjectService` methods
4. ❌ **AND** `ObjectService` delegates to handlers

**Result:** Circular dependency!

---

## The Handler Anti-Pattern

###What We Have Now (BROKEN):

```
Controller → ObjectService → Handler → ObjectService → Handler → ♾️
```

### What We Should Have:

```
Controller → ObjectService → Handler → Mappers/Services
                                    (NO callback to ObjectService!)
```

---

## Detailed Breakdown

### VectorizationHandler
```php
// PROBLEM: Calls ObjectService
$this->objectService->searchObjects(...)

// SOLUTION: Should call ObjectEntityMapper directly
$this->objectEntityMapper->find...(...) 
```

### MergeHandler  
```php
// PROBLEM: Calls ObjectService->mergeObjects()
$this->objectService->mergeObjects(...)

// SOLUTION: Implement merge logic directly in handler
// Using mappers and other services, NOT ObjectService!
```

### RelationHandler
```php
// PROBLEM: Calls ObjectService methods
$this->objectService->find(...)
$this->objectService->searchObjectsPaginated(...)

// SOLUTION: Call mappers directly  
$this->objectEntityMapper->find(...)
```

### CrudHandler
```php
// PROBLEM: Calls ObjectService methods
$this->objectService->find(...)
$this->objectService->saveObject(...)
$this->objectService->deleteObject(...)
$this->objectService->searchObjectsPaginated(...)

// SOLUTION: Implement CRUD directly using mappers
```

---

## Solution Options

### Option 1: Remove Handler Injections from Constructors (QUICK FIX)
- Remove `ObjectService` from ALL handler constructors
- Inject `ObjectEntityMapper` and other required services instead  
- Replace all `$this->objectService->method()` calls with direct mapper calls

**Pros:**  
✅ Fixes circular dependency immediately  
✅ Makes handlers truly independent

**Cons:**  
⚠️ Requires updating handler implementation  
⚠️ May need to duplicate some ObjectService logic

### Option 2: Keep Current Structure, Fix Delegation (COMPLEX)
- Keep `ObjectService` injection in handlers
- Move ALL business logic from ObjectService to handlers
- Make ObjectService a pure thin facade with NO logic
- Handlers become fat, ObjectService becomes thin

**Pros:**  
✅ Maintains current architecture
✅ Handlers contain all logic

**Cons:**  
❌ Requires extensive refactoring  
❌ More complex dependency graph  
❌ Still have tight coupling

### Option 3: Lazy Service Locator Pattern (HACKY)
- Use service locator to break circular dependency
- Inject IContainer and resolve ObjectService lazily when needed

**Pros:**  
✅ Quick fix  
✅ Minimal code changes

**Cons:**  
❌ Anti-pattern  
❌ Hides dependencies  
❌ Makes testing harder

---

## Recommended Solution: Option 1 (Quick Fix)

### Implementation Steps

1. **For Each Handler:**
   - Remove `ObjectService` from constructor
   - Add `ObjectEntityMapper` to constructor
   - Add any other required services (FileService, etc.)

2. **Replace Method Calls:**
   ```php
   // OLD
   $this->objectService->find($id)
   
   // NEW
   $this->objectEntityMapper->find($id)
   ```

3. **Extract Business Logic:**
   - If handler calls `$this->objectService->complexMethod()`
   - Move `complexMethod` logic directly into handler
   - Use mappers and services, not ObjectService

4. **Test Each Handler:**
   ```bash
   docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
   ```

---

## Files Requiring Changes

### Handlers (Remove ObjectService injection):
1. `lib/Service/Object/Handlers/ExportHandler.php` - ✅ FIXED
2. `lib/Service/Object/Handlers/VectorizationHandler.php` - ❌ TODO
3. `lib/Service/Object/Handlers/MergeHandler.php` - ❌ TODO
4. `lib/Service/Object/Handlers/RelationHandler.php` - ❌ TODO
5. `lib/Service/Object/Handlers/CrudHandler.php` - ❌ TODO (MOST COMPLEX)

### No Changes Required:
- `lib/Service/ObjectService.php` - Already correct (delegates to handlers)
- `lib/AppInfo/Application.php` - DI will resolve automatically
- `lib/Controller/ObjectsController.php` - Already correct (delegates to ObjectService)

---

## Impact

### Current State: 🚨
- ❌ App cannot load (infinite loop)
- ❌ All OpenRegister functionality broken
- ❌ Integration tests cannot run

### After Fix: ✅
- ✅ App loads normally
- ✅ Handlers are independent
- ✅ No circular dependencies
- ✅ Tests can run
- ✅ Import functionality works

---

## Testing Plan

### 1. Fix VectorizationHandler (Simplest)
```bash
# Should enable without infinite loop
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

### 2. Fix MergeHandler
### 3. Fix RelationHandler  
### 4. Fix CrudHandler (Most Complex)

### 5. Run Integration Tests
```bash
docker exec -u 33 master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && ./vendor/bin/phpunit tests/Integration/ObjectImportIntegrationTest.php'
```

---

## Lessons Learned

### ❌ What NOT To Do:
1. Don't inject a service into handlers that also injects those handlers
2. Don't make handlers call back to the parent service
3. Don't create circular dependencies in DI

### ✅ What TO Do:
1. Handlers should be self-contained
2. Handlers should only inject mappers and specialized services  
3. Parent service should only delegate, not be called by handlers
4. Keep dependency graph acyclic!

---

## Priority: 🔴 CRITICAL

This MUST be fixed before:
- Running tests
- Deploying to any environment
- Continuing development
- Merging to any branch

**The app literally cannot load right now!**

---

**Next Action:** Fix handlers one by one, starting with VectorizationHandler.

