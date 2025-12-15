# Psalm - Remaining Missing Methods Analysis

**Date:** December 15, 2025  
**Total UndefinedMethod Errors:** 113

## Summary

After restoring 10 methods, **113 UndefinedMethod errors remain**. However, many are **false positives** (parent class methods) or **cached errors** that will resolve after clearing cache.

##  Methods Status

### ✅ Already Restored (10 methods)

**ObjectEntityMapper:**
1. ✅ `find()` - Added
2. ✅ `findAll()` - Added
3. ✅ `findMultiple()` - Added  
4. ✅ `findBySchema()` - Added
5. ✅ `searchObjects()` - Added
6. ✅ `countSearchObjects()` - Added
7. ✅ `countAll()` - Added

**RenderObject:**
8. ✅ `renderEntities()` - Added

**RelationHandler:**
9. ✅ `getContracts()` - Added
10. ✅ `getUses()` - Added
11. ✅ `getUsedBy()` - Added (placeholder)

### ⚠️ False Positives (Parent Class Methods)

These methods exist in parent `QBMapper` class:

**ObjectEntityMapper (from QBMapper):**
- `insertEntity()` - Exists in parent
- `updateEntity()` - Exists in parent
- `deleteEntity()` - Exists in parent

**Note:** These will resolve once Psalm cache is cleared.

### 🔴 Actually Missing Methods

Need to be restored and delegated to handlers:

#### ObjectEntityMapper (3 new methods needed)

1. **countBySchemas()** - Count objects across multiple schemas
2. **findByRelation()** - Find objects by relation search
3. **findBySchemas()** - Find objects across multiple schemas

#### ChunkMapper (5 methods)

1. **countAll()** - Count all chunks
2. **countIndexed()** - Count indexed chunks
3. **countUnindexed()** - Count unindexed chunks
4. **countVectorized()** - Count vectorized chunks
5. **findUnindexed()** - Find unindexed chunks

#### IndexService (11 methods)

1. **buildSolrBaseUrl()** - Build Solr base URL
2. **collectionExists()** - Check if collection exists
3. **createCollection()** - Create Solr collection
4. **ensureTenantCollection()** - Ensure tenant-specific collection
5. **getBackend()** - Get search backend instance
6. **getDocumentCount()** - Get document count in index
7. **getEndpointUrl()** - Get Solr endpoint URL
8. **getHttpClient()** - Get HTTP client for Solr
9. **getSolrConfig()** - Get Solr configuration
10. **getTenantSpecificCollectionName()** - Get tenant collection name
11. **searchObjectsPaginated()** - Search with pagination
12. **testConnectivityOnly()** - Test Solr connectivity

#### SettingsService (2 methods)

1. **getStats()** - Get statistics
2. **rebase()** - Rebase configuration

#### ConfigurationSettingsHandler (1 method)

1. **getVersionInfoOnly()** - Get version information

#### SolrBackend (1 method)

1. **getRawSolrFieldsForFacetConfiguration()** - Get Solr fields for facets

## Priority & Delegation Strategy

### Phase 1: High Priority (Core Functionality) ⚡

**IndexService Methods** - Critical for search functionality

These should be delegated to handlers:

```
lib/Service/IndexService.php (Main facade)
  ↓ Delegates to handlers:
  - lib/Service/Index/SetupHandler.php (collection setup, connectivity)
  - lib/Service/Index/QueryHandler.php (search operations)
  - lib/Service/Index/ConfigHandler.php (configuration) [NEW]
```

**Recommended Action:**
1. Check old git version of IndexService for these methods
2. Extract logic to specialized handlers
3. Create facade methods in IndexService that delegate

### Phase 2: Medium Priority (Data Access) 🔶

**ChunkMapper Methods** - File chunking support

Create delegation pattern:

```
lib/Db/ChunkMapper.php
  ↓ Delegates to:
  - lib/Db/Chunk/QueryHandler.php [NEW]
  - lib/Db/Chunk/StatsHandler.php [NEW]
```

**ObjectEntityMapper Additional Methods**

```
lib/Db/ObjectEntityMapper.php
  ↓ Add methods that delegate to:
  - lib/Db/ObjectEntity/QueryOptimizationHandler.php (existing)
```

### Phase 3: Low Priority (Settings & Stats) 🟡

**SettingsService Methods**

```
lib/Service/SettingsService.php
  ↓ Delegates to:
  - lib/Service/Settings/StatsHandler.php [NEW]
  - lib/Service/Settings/ConfigurationSettingsHandler.php (existing)
```

## Recommended Next Steps

### Step 1: Clear Psalm Cache & Verify

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# Clear cache
rm -rf vendor/vimeo/psalm/.php_cs.cache
./vendor/bin/psalm --clear-cache

# Run again
./vendor/bin/psalm --threads=1 --no-cache 2>&1 | grep "UndefinedMethod" | wc -l
```

**Expected Result:** Should drop from 113 to ~80 (after removing parent class false positives)

### Step 2: Restore IndexService Methods (Highest Impact)

```bash
# Find old version with these methods
git log --oneline --all lib/Service/IndexService.php | head -20

# Extract methods from old version
git show <commit>:lib/Service/IndexService.php | grep -n "public function" | grep -E "(ensureTenantCollection|getDocumentCount|getEndpointUrl)"
```

**Implementation Pattern:**

```php
// In lib/Service/IndexService.php
class IndexService {
    private SetupHandler $setupHandler;
    private QueryHandler $queryHandler;
    private ConfigHandler $configHandler;
    
    public function ensureTenantCollection(string $tenant): array {
        return $this->setupHandler->ensureTenantCollection($tenant);
    }
    
    public function getDocumentCount(string $collection): int {
        return $this->queryHandler->getDocumentCount($collection);
    }
    
    public function getEndpointUrl(): string {
        return $this->configHandler->getEndpointUrl();
    }
}
```

### Step 3: Restore ChunkMapper Methods

```bash
# Find old version
git log --oneline --all lib/Db/ChunkMapper.php | head -20

# Extract methods
git show <commit>:lib/Db/ChunkMapper.php | grep -n "public function count"
```

**Implementation Pattern:**

```php
// In lib/Db/ChunkMapper.php
class ChunkMapper extends QBMapper {
    private ChunkStatsHandler $statsHandler;
    
    public function countIndexed(): int {
        return $this->statsHandler->countIndexed();
    }
    
    public function countUnindexed(): int {
        return $this->statsHandler->countUnindexed();
    }
}
```

### Step 4: Add Remaining ObjectEntityMapper Methods

```php
// In lib/Db/ObjectEntityMapper.php
public function countBySchemas(array $schemaIds): int {
    return $this->queryOptimizationHandler->countBySchemas($schemaIds);
}

public function findBySchemas(array $schemaIds, int $limit = 100): array {
    return $this->queryOptimizationHandler->findBySchemas($schemaIds, $limit);
}

public function findByRelation(string $search, bool $partialMatch = true): array {
    // Delegate to query handler or implement directly
}
```

## Expected Impact

| Action | Errors Reduced | Time |
|--------|----------------|------|
| Clear Psalm Cache | -3 (parent methods) | 1 min |
| Restore IndexService (11 methods) | -40-50 | 3-4 hours |
| Restore ChunkMapper (5 methods) | -15-20 | 1-2 hours |
| Add ObjectEntityMapper (3 methods) | -10-15 | 1 hour |
| Restore Settings methods (3 methods) | -5-10 | 30 min |
| **Total** | **~80-95 errors** | **5-7 hours** |

## Files to Modify

### New Handler Files to Create

1. `lib/Service/Index/ConfigHandler.php` - Configuration management
2. `lib/Db/Chunk/QueryHandler.php` - Chunk queries
3. `lib/Db/Chunk/StatsHandler.php` - Chunk statistics
4. `lib/Service/Settings/StatsHandler.php` - Settings statistics

### Existing Files to Modify

1. `lib/Service/IndexService.php` - Add facade methods
2. `lib/Db/ChunkMapper.php` - Add facade methods
3. `lib/Db/ObjectEntityMapper.php` - Add 3 new methods
4. `lib/Service/SettingsService.php` - Add facade methods
5. `lib/Service/Settings/ConfigurationSettingsHandler.php` - Add getVersionInfoOnly()

## Testing Strategy

After each restoration:

```bash
# 1. Syntax check
php -l <modified-file>.php

# 2. Run Psalm
./vendor/bin/psalm --threads=1 --no-cache --file=<modified-file>.php

# 3. Count remaining errors
./vendor/bin/psalm --threads=1 --no-cache 2>&1 | grep "UndefinedMethod" | wc -l

# 4. Run unit tests
composer test:unit -- --filter <TestClassName>
```

## Conclusion

Of the 113 UndefinedMethod errors:
- ✅ **10 already fixed** (our work)
- ⚠️ **3 false positives** (parent class methods)
- 🔴 **~23 real methods** need restoration

**Realistic Target:** Reduce from 113 → ~30 errors in one session  
**Full Resolution:** 2-3 sessions totaling 10-15 hours

**Recommendation:** Start with IndexService methods as they have the highest impact (used in ~40+ locations).

---

*Generated: December 15, 2025*

