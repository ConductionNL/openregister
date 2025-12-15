# SettingsService Refactoring Status

## ✅ Completed (4/7 Handler Files)

### 1. SearchBackendHandler.php ✅
- **Lines**: ~170
- **Methods**: 2
- **Status**: COMPLETE
- **File**: `lib/Service/Settings/SearchBackendHandler.php`

### 2. LlmSettingsHandler.php ✅
- **Lines**: ~230
- **Methods**: 2
- **Status**: COMPLETE
- **File**: `lib/Service/Settings/LlmSettingsHandler.php`

### 3. FileSettingsHandler.php ✅
- **Lines**: ~190
- **Methods**: 2
- **Status**: COMPLETE
- **File**: `lib/Service/Settings/FileSettingsHandler.php`

## ⏳ Remaining (4 large handlers + facade refactoring)

Due to the complexity and size, the remaining handlers need to be created:

### 4. CacheSettingsHandler.php ⏳
- **Estimated Lines**: ~600
- **Methods**: 12
- **Methods to Extract**:
  - `getCacheStats()`
  - `getCachedObjectStats()`
  - `calculateHitRate()`
  - `getDistributedCacheStats()`
  - `getCachePerformanceMetrics()`
  - `clearCache()`
  - `clearObjectCache()`
  - `clearNamesCache()`
  - `warmupNamesCache()`
  - `clearSchemaCache()`
  - `clearFacetCache()`
  - `clearDistributedCache()`

### 5. SolrSettingsHandler.php ⏳
- **Estimated Lines**: ~700
- **Methods**: 10
- **Methods to Extract**:
  - `getSolrSettings()`
  - `getSolrSettingsOnly()`
  - `updateSolrSettingsOnly()`
  - `getSolrDashboardStats()`
  - `transformSolrStatsToDashboard()`
  - `formatBytesForDashboard()`
  - `getSolrFacetConfiguration()`
  - `updateSolrFacetConfiguration()`
  - `validateFacetConfiguration()`
  - `warmupSolrIndex()` (deprecated)

### 6. ValidationSettingsHandler.php ⏳
- **Estimated Lines**: ~800
- **Methods**: 6
- **Note**: Already exists at `lib/Service/Settings/ValidationOperationsHandler.php`
- **Action**: May need to be moved/renamed for consistency

### 7. ConfigurationSettingsHandler.php ⏳
- **Estimated Lines**: ~900 lines
- **Methods**: 19
- **Methods to Extract**:
  - Core settings: `getSettings()`, `updateSettings()`, `updatePublishingOptions()`
  - RBAC: `getRbacSettingsOnly()`, `updateRbacSettingsOnly()`, `isMultiTenancyEnabled()`
  - Organisation: `getOrganisationSettingsOnly()`, `updateOrganisationSettingsOnly()`, `getDefaultOrganisationUuid()`, `setDefaultOrganisationUuid()`, `getTenantId()`, `getOrganisationId()`
  - Multitenancy: `getMultitenancySettingsOnly()`, `updateMultitenancySettingsOnly()`
  - Object: `getObjectSettingsOnly()`, `updateObjectSettingsOnly()`
  - Retention: `getRetentionSettingsOnly()`, `updateRetentionSettingsOnly()`
  - Version: `getVersionInfoOnly()`, `convertToBoolean()`

## 📋 Next Steps

1. Create CacheSettingsHandler (extract 12 methods, ~600 lines)
2. Create SolrSettingsHandler (extract 10 methods, ~700 lines)
3. Check/rename ValidationOperationsHandler  
4. Create ConfigurationSettingsHandler (extract 19 methods, ~900 lines)
5. Refactor SettingsService into thin facade (delegate to handlers)
6. Update Application.php with DI registrations
7. Run phpcbf on all files
8. Test all endpoints

## 🎯 Strategy Going Forward

**Approach**: Create the remaining handlers in a batch script to maintain consistency and speed. Each handler will:
- Have proper PHPDoc
- Follow PSR-2 standards
- Inject only required dependencies
- Be under 1,000 lines

**Estimated Total Time**: 2-3 hours
**Risk**: Medium (large refactoring but clear boundaries)
**Benefit**: High (eliminate 3,708-line God Object)

