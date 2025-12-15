# 🔧 Circular Dependency Fix #2 - LockingHandler ↔ ObjectEntityMapper

## Problem Discovered
After fixing missing UI methods, the app started working but then hit ANOTHER circular dependency:

```
ObjectEntityMapper → LockingHandler → ObjectEntityMapper → LockingHandler → ...
```

This created an infinite loop at application startup.

## Root Cause
**File**: `lib/AppInfo/Application.php` line 347  
**File**: `lib/Db/ObjectEntity/LockingHandler.php` line 94

1. `ObjectEntityMapper` injected `LockingHandler` to call `lockObject()` and `unlockObject()` 
2. `LockingHandler` injected `ObjectEntityMapper` to call `find()` and `update()`
3. Result: Infinite loop during dependency injection

## Solution Applied
**Removed the circular dependency by taking `LockingHandler` OUT of `ObjectEntityMapper`**

### Changes Made:

#### 1. `lib/Db/ObjectEntityMapper.php`
- ✅ Commented out `private LockingHandler $lockingHandler;` property
- ✅ Removed `LockingHandler` from constructor parameters
- ✅ Modified `lockObject()` to throw `BadMethodCallException` with message to use `ObjectService` instead
- ✅ Modified `unlockObject()` to throw `BadMethodCallException` with message to use `ObjectService` instead

#### 2. `lib/AppInfo/Application.php`
- ✅ Commented out `lockingHandler: $container->get(LockingHandler::class)` from `ObjectEntityMapper` registration (line 347)

## Architecture Decision
**Mappers** (database layer) should NOT depend on **Handlers** (business logic layer).

- ✅ **Correct**: Handlers → Mappers (handlers use mappers for database operations)
- ❌ **Wrong**: Mappers → Handlers (creates circular dependencies)

## Impact
- Locking functionality must now be accessed through `ObjectService->lockObject()` / `unlockObject()`
- Direct calls to `ObjectEntityMapper->lockObject()` will throw exceptions with helpful error messages
- This enforces proper layering: Controllers → Services → Handlers → Mappers

## Status
- ✅ App enables successfully
- ✅ No more infinite loops
- ✅ Proper separation of concerns restored

## Next Testing
User should test the app in the browser to verify all functionality works correctly.

