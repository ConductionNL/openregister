# Quick Fix Summary

## The Problem
Handlers inject `ObjectService` but ObjectService also injects the handlers = circular dependency

## The Solution
Handlers should NOT call ObjectService. They should call mappers/services directly.

## Changes Made

### 1. VectorizationHandler ✅ DONE
- Removed: `ObjectService`
- Added: `ObjectEntityMapper`
- Changed: `$this->objectService->searchObjects()` → `$this->objectEntityMapper->findAll()`

### 2. ExportHandler ✅ DONE  
- Removed: `ObjectService`
- Added: `ObjectEntityMapper`
- Changed: `$this->objectService->find()` → `$this->objectEntityMapper->find()`

### 3. MergeHandler ⚠️ IN PROGRESS
- Removing: `ObjectService`
- Adding: `ObjectEntityMapper`, `FileService`, `IUserSession`
- Problem: Calls `$this->objectService->mergeObjects()` which has 195 lines of logic

### 4. RelationHandler ⚠️ TODO
- Need to remove: `ObjectService`
- Need to add: `ObjectEntityMapper`
- Calls: `find()`, `searchObjectsPaginated()`

### 5. CrudHandler ⚠️ TODO  
- Need to remove: `ObjectService`
- Need to add: `ObjectEntityMapper` and others
- Calls: `find()`, `saveObject()`, `deleteObject()`, `searchObjectsPaginated()`, `buildSearchQuery()`

## Temporary Workaround

Since MergeHandler, RelationHandler, and CrudHandler have complex logic, let's use a simpler approach:

**Make handlers just log and return empty/placeholder responses for now!**

This will break functionality temporarily but allow the app to load so we can test the import.

## Implementation Strategy

1. Fix handlers to remove ObjectService
2. Add placeholder/empty implementations  
3. Test app loads
4. Run import tests
5. Come back later to properly implement handler logic

**Priority: Get app loading > Get tests running > Implement full logic**

