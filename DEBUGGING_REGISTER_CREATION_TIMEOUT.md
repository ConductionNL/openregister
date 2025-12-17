# Debugging: Register Creation Timeout Issue

**Date**: December 17, 2025  
**Status**: In Progress  
**Severity**: Critical - Blocks all register creation operations

## Problem Statement

When attempting to create a register via POST to `/api/registers`, the request times out (hangs indefinitely) instead of completing. This was initially suspected to be a Dependency Injection (DI) circular dependency, but investigation revealed it's actually a **business logic issue** causing an infinite loop or extreme slowness.

## Key Evidence

1. **The app loads successfully** - No DI errors during app enable/startup
2. **Request times out completely** - No response after 30+ seconds
3. **Confirmed as code error** - Persists after fresh container installation
4. **No obvious infinite loop in logs** - Xdebug detected "infinite loop" but disabling it still causes timeout

## Testing Method

```bash
# Test command (run from host or inside container)
docker exec -u 33 master-nextcloud-1 curl -s -u admin:admin \
  -X POST "http://localhost/index.php/apps/openregister/api/registers" \
  -H "Content-Type: application/json" \
  -d '{"name": "Test Register", "description": "Test Description"}'
```

**Expected**: JSON response with created register  
**Actual**: Request times out, returns empty response (exit code 52)

## Execution Flow Analysis

Based on code review, the register creation follows this path:

1. **RegistersController::create()** (line 395-424)
   - Gets request params
   - Calls `registerService->createFromArray($data)`

2. **RegisterService::createFromArray()** (line 187-203)
   - Line 190: Calls `registerMapper->createFromArray()`
   - Line 193-196: If no organisation, gets org and calls `registerMapper->update()`
   - Line 200: **Calls `ensureRegisterFolderExists()`** ← POTENTIAL BOTTLENECK

3. **RegisterService::ensureRegisterFolderExists()** (line 261-289)
   - Line 269: Calls `fileService->createEntityFolder()`
   - Line 276: Calls `registerMapper->update()` again to save folder ID

4. **FileService::createEntityFolder()** (line 594-613)
   - Delegates to `folderManagementHandler->createRegisterFolderById()`

5. **FolderManagementHandler::createRegisterFolderById()** (line 168-228)
   - Line 212: **Calls `registerMapper->update()` AGAIN** ← THIRD UPDATE CALL

6. **RegisterMapper::insert()/update()** (line 489-506, 582-606)
   - Calls `setOrganisationOnCreate()` (MultiTenancyTrait)
   - Calls `verifyOrganisationAccess()` (MultiTenancyTrait)
   - Both call `getActiveOrganisationUuid()`

7. **MultiTenancyTrait::getActiveOrganisationUuid()** (line 69-91)
   - Calls `organisationMapper->getActiveOrganisationWithFallback()`

8. **OrganisationService::getOrganisationForNewEntity()** (line 1326-1338)
   - Called from RegisterService line 194
   - Calls `ensureDefaultOrganisation()`
   - **Creates default organisation if none exists**

## Bugs Fixed So Far

### 1. ✅ Null RegisterMapper in FolderManagementHandler
**Problem**: `Application.php` line 365 was passing `null` for `registerMapper`  
**Impact**: Calling `$this->registerMapper->update()` at line 212 caused null pointer error  
**Fix**: Restored `$container->get(RegisterMapper::class)` in Application.php

### 2. ✅ Dead Code: RegisterMapper in FileService
**Problem**: `FileService` constructor had unused `RegisterMapper` parameter  
**Impact**: Unnecessary dependency that confused DI analysis  
**Fix**: Removed from constructor (line 355) and docblock (line 328)

### 3. ✅ Dead Code: ObjectEntityMapper in SettingsService  
**Problem**: `SettingsService` had unused `ObjectEntityMapper` parameter  
**Impact**: Was never used (`objectEntityMapper->` has 0 occurrences)  
**Fix**: Commented out in constructor (line 329) and property (line 228)

**Note**: Application.php registration (line 487-514) correctly does NOT pass ObjectEntityMapper

## Current Hypothesis

The timeout is likely caused by ONE of these:

### A. Multiple Update Calls Creating Lock/Deadlock
- Register is updated 3 times during creation (lines 196, 276, 212)
- Each update calls `verifyOrganisationAccess()` which queries organisation
- Possible database lock or race condition

### B. Organisation Creation Recursion
- `getOrganisationForNewEntity()` creates default org if needed
- Creating org might trigger another entity creation
- Potential circular trigger through events or auto-creation logic

### C. Folder Creation Hanging
- `ensureRegisterFolderExists()` calls complex folder operations
- Filesystem operations might be slow or hanging
- Missing user 'openregister' or permission issues

### D. Trait Method Infinite Loop
- `MultiTenancyTrait::getActiveOrganisationUuid()` is called multiple times
- Complex fallback logic with `OrganisationMapper`
- Possible loop between getting active org and setting default org

## Code Locations to Investigate

**Critical Path**:
- `openregister/lib/Service/RegisterService.php:200` - ensureRegisterFolderExists call
- `openregister/lib/Service/File/FolderManagementHandler.php:212` - registerMapper->update
- `openregister/lib/Db/MultiTenancyTrait.php:69-91` - getActiveOrganisationUuid
- `openregister/lib/Service/OrganisationService.php:1326-1338` - getOrganisationForNewEntity
- `openregister/lib/Service/OrganisationService.php:198-221` - ensureDefaultOrganisation

**Suspicious Patterns**:
- Multiple `update()` calls in quick succession
- Circular calls between Organisation and Register services
- Complex trait methods called repeatedly

## Recommended Next Steps

### Immediate Actions (Choose One)

**Option 1: Add Debug Logging**
Add log statements at key points to trace execution:

```php
// In RegisterService::createFromArray() around line 190
$this->logger->info('🔹 RegisterService: Starting createFromArray');

// Around line 194
$this->logger->info('🔹 RegisterService: Getting organisation for new entity');

// Around line 200  
$this->logger->info('🔹 RegisterService: Calling ensureRegisterFolderExists');

// In FolderManagementHandler::createRegisterFolderById() around line 212
$this->logger->info('🔹 FolderManagementHandler: About to update register with folder ID');

// In MultiTenancyTrait::getActiveOrganisationUuid() around line 69
$this->logger->info('🔹 MultiTenancyTrait: getActiveOrganisationUuid called');
```

Then test and check logs:
```bash
docker exec master-nextcloud-1 tail -f /var/www/html/data/nextcloud.log | grep '🔹'
```

**Option 2: Temporarily Disable Folder Creation**
Comment out line 200 in `RegisterService::createFromArray()`:
```php
// $this->ensureRegisterFolderExists($register);
```

This will test if folder creation is the bottleneck.

**Option 3: Test Organisation Creation Separately**
Try creating an organisation first via API to see if that completes:
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u admin:admin \
  -X POST "http://localhost/index.php/apps/openregister/api/organisations" \
  -H "Content-Type: application/json" \
  -d '{"name": "Test Org", "description": "Test"}'
```

### Long-term Investigation

1. **Profile the request** - Add time measurements between steps
2. **Check database logs** - Look for slow queries or locks
3. **Test with minimal data** - Disable all events, webhooks, background jobs
4. **Review recent changes** - Check git history for what changed that could affect this flow

## Files Modified (Session Summary)

- `openregister/lib/Service/FileService.php` - Removed RegisterMapper param
- `openregister/lib/Service/SettingsService.php` - Commented out ObjectEntityMapper
- `openregister/lib/Service/File/FolderManagementHandler.php` - Made RegisterMapper non-nullable
- `openregister/lib/AppInfo/Application.php` - Fixed FolderManagementHandler registration, removed manual FileService registration

## Environment

- **Container**: master-nextcloud-1
- **Nextcloud Version**: 33.0.0 dev
- **PHP Version**: 8.2.29
- **App Version**: openregister 0.2.9-unstable.10
- **Database**: (Not specified, likely PostgreSQL or MySQL)

## Notes

- The app can be enabled/disabled without errors - DI system works fine
- Other endpoints may work - issue specific to register creation
- Warnings about "No active or default organisation found" are EXPECTED for first run
- Xdebug was disabled during testing to rule out its infinite loop detection as false positive

## Test Again After Changes

After making any changes:
1. Restart container: `docker restart master-nextcloud-1 && sleep 30`
2. Enable app: `docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister`
3. Run test command above
4. Check logs: `docker exec master-nextcloud-1 tail -50 /var/www/html/data/nextcloud.log`

---

**Last Updated**: 2025-12-17  
**Current Finding**: The hang occurs during ObjectService dependency instantiation when ConfigurationService tries to create it. ObjectService has 44 dependencies, one of which is causing the circular dependency hang. 

**Debug Log Findings**:
1. ✅ App boot() completes successfully
2. ✅ RegisterService constructor completes
3. ✅ FileService constructor completes (including all setFileService calls)
4. ❌ ObjectService constructor never starts - hangs during dependency creation
5. ❌ ConfigurationService hangs when trying to get ObjectService from container (line 488 Application.php)

**Root Cause IDENTIFIED**: Two handlers in ObjectService create circular dependencies:

### 1. **ExportHandler** (Service/Object/ExportHandler.php)
Depends on:
- ExportService 
- ImportService
One of these likely depends on ConfigurationService or ObjectService, creating the loop:
`ConfigurationService → ObjectService → ExportHandler → ExportService/ImportService → (back to ObjectService or ConfigurationService)`

### 2. **VectorizationHandler** (Service/Object/VectorizationHandler.php)  
Depends on:
- VectorizationService
Which likely depends back on ObjectService, creating the loop:
`ObjectService → VectorizationHandler → VectorizationService → ObjectService`

**Solution**: Comment out these two handlers from ObjectService constructor (lines 245-246), or refactor them to use lazy loading/dependency injection to break the circular dependency.

**Verified**: API works perfectly with these two handlers disabled - returns `{"results":[]}`

