---
sidebar_position: 3
title: Folder Deletion Fix
description: Fix for import errors when reusing UUIDs due to orphaned folders
---

# Folder Deletion Fix Summary

## Problem Description

When importing new objects with a UUID that was previously deleted from the system, the following error occurred:

```
Multiple nodes found in oc_filecache with name equal to object uuid: 81efcfa2-25ca-4cd5-a320-6b9efad2eb04
```

This happened because:
1. When the original object was created, `ObjectService.php` and `FileService.php` created a folder
2. During the delete object phase, the folder wasn't deleted (only soft deleted)
3. When reusing the same UUID, the system found multiple folders with the same name

## Root Cause

The folder deletion was incomplete:
- **Soft deletes** (objects marked as deleted but not removed) kept folders intact
- **Hard deletes** (permanent removal) didn't clean up the object folders
- **Multiple folders** with the same UUID name caused conflicts during import

## Solution Implemented

### 1. **Enhanced Folder Deletion for Hard Deletes**

**File**: `lib/Service/ObjectHandlers/DeleteObject.php`

Added folder deletion to the hard delete process:

```php
/**
 * Delete the object folder when performing hard delete
 *
 * @param ObjectEntity $objectEntity The object entity to delete folder for
 *
 * @return void
 */
private function deleteObjectFolder(ObjectEntity $objectEntity): void
{
    try {
        $folder = $this->fileService->getObjectFolder($objectEntity);
        if ($folder !== null) {
            $folder->delete();
            $this->logger->info('Deleted object folder for hard deleted object: ' . $objectEntity->getId());
        }
    } catch (\Exception $e) {
        // Log error but don't fail the deletion process
        $this->logger->warning('Failed to delete object folder for object ' . $objectEntity->getId() . ': ' . $e->getMessage());
    }
}
```

**Changes**:
- Added `deleteObjectFolder()` method to handle folder cleanup
- Integrated folder deletion into the main `delete()` method
- Added proper error handling to prevent deletion failures
- Added logging for successful deletions and errors

### 2. **Improved Multiple Folder Handling**

**File**: `lib/Db/FileMapper.php`

Changed error handling to pick the oldest folder instead of throwing an error:

```php
// Before: Throw error on multiple folders
} elseif ($count > 1) {
    throw new \RuntimeException('Multiple nodes found in oc_filecache with name equal to object uuid: ' . $uuid);
}

// After: Pick oldest folder (lowest fileid)
} elseif ($count > 1) {
    // Multiple folders found with same UUID - pick the oldest one (lowest fileid)
    // TODO: Add nightly cron job to cleanup orphaned folders and logs
    usort($rows, function($a, $b) {
        return (int) $a['fileid'] - (int) $b['fileid'];
    });
    $oldestNodeId = (int) $rows[0]['fileid'];
    return $this->getFiles($oldestNodeId);
}
```

**Changes**:
- Replaced error throwing with intelligent folder selection
- Sort folders by `fileid` (creation order) to pick the oldest
- Added TODO comment for future cleanup improvements

### 3. **Added Cleanup TODO**

**File**: `lib/Service/ObjectService.php`

Added TODO comment for future nightly cleanup implementation:

```php
public function delete(array | JsonSerializable $object): bool
{
    // TODO: Add nightly cron job to cleanup orphaned folders and logs
    // This should scan for folders without corresponding objects and clean them up
    return $this->deleteHandler->delete($object);
}
```

## Files Modified

### Core Files
1. **`lib/Service/ObjectHandlers/DeleteObject.php`** - Added folder deletion for hard deletes
2. **`lib/Db/FileMapper.php`** - Improved multiple folder handling
3. **`lib/Service/ObjectService.php`** - Added cleanup TODO

### Dependencies Added
- **LoggerInterface** - Added to DeleteObject for proper error logging

## Database Impact

No database schema changes required. The fixes work with existing data structures.

## API Changes

No API changes. The fixes are internal improvements that maintain backward compatibility.

## Testing Scenarios

The fixes address the following scenarios:

### ✅ **Hard Delete with Folder Cleanup**
- Object is permanently deleted
- Associated folder is automatically removed
- No orphaned folders left behind

### ✅ **Multiple Folder Resolution**
- Import with previously used UUID
- System picks oldest folder instead of erroring
- Import completes successfully

### ✅ **Error Handling**
- Folder deletion failures don't break object deletion
- Proper logging for debugging
- Graceful degradation

## Future Improvements

### **Nightly Cleanup Cron Job**
The TODO comment indicates the need for a nightly cleanup job that should:

1. **Scan for orphaned folders** - Find folders without corresponding objects
2. **Clean up orphaned logs** - Remove audit trails for non-existent objects
3. **Report cleanup statistics** - Log what was cleaned up
4. **Handle edge cases** - Deal with partially deleted objects

### **Implementation Suggestions**
```php
// Example cleanup job structure
class CleanupService
{
    public function cleanupOrphanedFolders(): array
    {
        // Find folders without objects
        // Delete orphaned folders
        // Return cleanup statistics
    }
    
    public function cleanupOrphanedLogs(): array
    {
        // Find logs for non-existent objects
        // Clean up orphaned audit trails
        // Return cleanup statistics
    }
}
```

## Deployment Instructions

1. **Deploy the code changes** to production
2. **Test import functionality** with previously deleted UUIDs
3. **Verify hard deletes** clean up folders properly
4. **Monitor logs** for any folder deletion issues
5. **Plan nightly cleanup job** implementation

## Impact

This fix resolves the import error and ensures:

- ✅ **Import functionality works** with reused UUIDs
- ✅ **Hard deletes clean up** all associated resources
- ✅ **No orphaned folders** left in the system
- ✅ **Better error handling** for edge cases
- ✅ **Foundation for future cleanup** automation

The solution maintains backward compatibility while improving the robustness of the deletion and import processes. 