# 🗑️ Dead Code Analysis: JSON Services

## Summary
**THREE services are completely unused and should be removed:**

## Files to Remove

### 1. `lib/Service/MySQLJsonService.php` (539 lines) ❌ DEAD CODE
- **Purpose**: Provides MySQL JSON operations (orderJson, searchJson, filterJson, getAggregations)
- **Injected into**: `ObjectEntityMapper` as `$databaseJsonService`
- **Actually used**: ❌ **NEVER** (`$this->databaseJsonService->` has 0 matches in entire codebase)
- **Verdict**: **REMOVE** - Property is assigned but never called

### 2. `lib/Service/IDatabaseJsonService.php` (94 lines) ❌ DEAD CODE
- **Purpose**: Interface for database JSON operations
- **Implemented by**: MySQLJsonService
- **Used anywhere**: ❌ **NO** (only referenced in MySQLJsonService implementation)
- **Verdict**: **REMOVE** - Only used by dead MySQLJsonService

### 3. `lib/Service/MongoDbService.php` (287 lines) ❌ DEAD CODE
- **Purpose**: MongoDB operations via REST API
- **Imported anywhere**: ❌ **NEVER** (`use.*MongoDbService` has 0 matches)
- **Used anywhere**: ❌ **NEVER**
- **Verdict**: **REMOVE** - Completely orphaned code

## Why These Are Dead

### Historical Context
These services were likely created when:
1. The app was considering MongoDB support (MongoDbService)
2. Complex JSON querying was done through a service layer (MySQLJsonService)

### Current Reality
- **ObjectEntityMapper** now has direct query methods (find, findAll, searchObjects)
- **ObjectEntity handlers** provide specialized operations
- **JSON operations** are done directly with Nextcloud's QueryBuilder
- **MongoDB support** was never implemented or has been abandoned

## Impact of Removal

### ✅ Benefits
- **-920 lines of dead code removed**
- **Clearer architecture** (no confusing unused services)
- **Faster loading** (less code to parse/autoload)
- **Reduced maintenance** (no need to maintain dead code)

### ⚠️ Risks
- **ObjectEntityMapper constructor** needs updating (remove `MySQLJsonService` parameter)
- **Application.php** needs updating (remove service registration)
- **None** for MongoDbService/IDatabaseJsonService (not referenced anywhere)

## Removal Plan

### Phase 1: Remove MySQLJsonService from ObjectEntityMapper
1. Remove `MySQLJsonService $mySQLJsonService` parameter from constructor
2. Remove `private MySQLJsonService $databaseJsonService;` property
3. Remove `$this->databaseJsonService = $mySQLJsonService;` assignment
4. Update Application.php registration (remove `mySQLJsonService` parameter)

### Phase 2: Delete Dead Files
1. Delete `lib/Service/MySQLJsonService.php`
2. Delete `lib/Service/IDatabaseJsonService.php`
3. Delete `lib/Service/MongoDbService.php`

### Phase 3: Test
1. Restart Docker container
2. Enable app
3. Test basic object operations
4. Verify no errors

## Verification

```bash
# Verify MySQLJsonService is never used
grep -r "databaseJsonService->" openregister/lib/Db/
# Result: 0 matches ✅

# Verify MongoDbService is never imported
grep -r "use.*MongoDbService" openregister/lib/
# Result: 0 matches ✅

# Verify IDatabaseJsonService is only used by MySQLJsonService
grep -r "IDatabaseJsonService" openregister/lib/
# Result: Only in MySQLJsonService.php ✅
```

## Conclusion

**YES, REMOVE THEM ALL!** These are textbook examples of dead code:
- Injected but never used (MySQLJsonService)
- Orphaned and forgotten (MongoDbService)
- Only used by dead code (IDatabaseJsonService)

Removing them will:
- **Reduce codebase by 920 lines** (539 + 94 + 287)
- **Simplify ObjectEntityMapper dependencies**
- **Make architecture clearer**
- **Eliminate confusion** about which JSON service to use

