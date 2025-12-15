# SettingsService Refactoring Plan

## Current State
- **File**: `lib/Service/SettingsService.php`
- **Lines**: 3,708
- **Methods**: 66
- **Status**: ❌ God Object (3.7x over 1000-line limit)

## Goal
Split into domain-specific handler classes matching the controller structure,
with SettingsService becoming a thin facade that delegates to handlers.

## Method Analysis & Handler Assignment

### 1. SolrSettingsHandler (~600 lines, 10 methods)
**Purpose**: SOLR configuration, dashboard stats, facet management

Methods:
- `getSolrSettings()` - Get SOLR configuration
- `getSolrSettingsOnly()` - Get focused SOLR settings
- `updateSolrSettingsOnly()` - Update SOLR settings
- `getSolrDashboardStats()` - Get dashboard statistics
- `transformSolrStatsToDashboard()` - Transform stats structure
- `formatBytesForDashboard()` - Format bytes for display
- `getSolrFacetConfiguration()` - Get facet configuration
- `updateSolrFacetConfiguration()` - Update facet configuration
- `validateFacetConfiguration()` - Validate facet config
- `warmupSolrIndex()` - Deprecated warmup method

### 2. LlmSettingsHandler (~300 lines, 2 methods)
**Purpose**: LLM provider configuration

Methods:
- `getLLMSettingsOnly()` - Get LLM configuration
- `updateLLMSettingsOnly()` - Update LLM configuration

### 3. FileSettingsHandler (~300 lines, 2 methods)
**Purpose**: File management configuration

Methods:
- `getFileSettingsOnly()` - Get file management settings
- `updateFileSettingsOnly()` - Update file management settings

### 4. CacheSettingsHandler (~400 lines, 12 methods)
**Purpose**: Cache statistics, clearing, warmup operations

Methods:
- `getCacheStats()` - Get cache statistics
- `getCachedObjectStats()` - Get cached object statistics
- `calculateHitRate()` - Calculate cache hit rate
- `getDistributedCacheStats()` - Get distributed cache stats
- `getCachePerformanceMetrics()` - Get performance metrics
- `clearCache()` - Clear cache with granular control
- `clearObjectCache()` - Clear object cache
- `clearNamesCache()` - Clear names cache
- `warmupNamesCache()` - Warmup names cache
- `clearSchemaCache()` - Clear schema cache
- `clearFacetCache()` - Clear facet cache
- `clearDistributedCache()` - Clear distributed cache

### 5. ValidationSettingsHandler (~500 lines, 6 methods)
**Purpose**: Object validation operations

Methods:
- `validateAllObjects()` - Validate all objects
- `massValidateObjects()` - Mass validate objects
- `createBatchJobs()` - Create batch jobs
- `processJobsSerial()` - Process jobs serially
- `processJobsParallel()` - Process jobs in parallel
- `processBatchDirectly()` - Process single batch

### 6. ConfigurationSettingsHandler (~700 lines, 19 methods)
**Purpose**: RBAC, multitenancy, retention, organisation, object settings

Methods:
- `getSettings()` - Get all settings
- `updateSettings()` - Update settings
- `updatePublishingOptions()` - Update publishing options
- `getRbacSettingsOnly()` - Get RBAC settings
- `updateRbacSettingsOnly()` - Update RBAC settings
- `getOrganisationSettingsOnly()` - Get organisation settings
- `updateOrganisationSettingsOnly()` - Update organisation settings
- `getDefaultOrganisationUuid()` - Get default organisation UUID
- `setDefaultOrganisationUuid()` - Set default organisation UUID
- `getTenantId()` - Get tenant ID
- `getOrganisationId()` - Get organisation ID
- `getMultitenancySettingsOnly()` - Get multitenancy settings
- `updateMultitenancySettingsOnly()` - Update multitenancy settings
- `getObjectSettingsOnly()` - Get object settings
- `updateObjectSettingsOnly()` - Update object settings
- `getRetentionSettingsOnly()` - Get retention settings
- `updateRetentionSettingsOnly()` - Update retention settings
- `getVersionInfoOnly()` - Get version information
- `convertToBoolean()` - Convert to boolean
- `isMultiTenancyEnabled()` - Check if multitenancy is enabled

### 7. SearchBackendHandler (~100 lines, 2 methods)
**Purpose**: Search backend configuration

Methods:
- `getSearchBackendConfig()` - Get search backend configuration
- `updateSearchBackendConfig()` - Update search backend configuration

### 8. Core SettingsService (~800 lines, 13 methods)
**Purpose**: Thin facade + utility methods + rebase operations

Methods to keep:
- `__construct()` - Constructor with handler injection
- `rebaseObjectsAndLogs()` - Rebase operations
- `rebase()` - Rebase alias
- `getStats()` - General statistics
- `getAvailableGroups()` - Get available groups (private helper)
- `getAvailableOrganisations()` - Get available organisations (private helper)
- `getAvailableUsers()` - Get available users (private helper)
- `formatBytes()` - Format bytes helper
- `convertToBytes()` - Convert to bytes helper
- `maskToken()` - Mask token helper
- `getExpectedSchemaFields()` - Get expected schema fields
- `compareFields()` - Compare fields helper

## New File Structure

```
lib/Service/
├── SettingsService.php (facade, ~800 lines)
└── Settings/
    ├── SolrSettingsHandler.php (~600 lines)
    ├── LlmSettingsHandler.php (~300 lines)
    ├── FileSettingsHandler.php (~300 lines)
    ├── CacheSettingsHandler.php (~400 lines)
    ├── ValidationSettingsHandler.php (~500 lines)
    ├── ConfigurationSettingsHandler.php (~700 lines)
    └── SearchBackendHandler.php (~100 lines)
```

## Implementation Steps

1. ✅ Create refactoring plan
2. ⏳ Create handler classes with appropriate methods
3. ⏳ Refactor SettingsService to delegate to handlers
4. ⏳ Update Application.php DI registrations
5. ⏳ Run phpcbf on all new files
6. ⏳ Verify all files < 1000 lines
7. ⏳ Test endpoints to ensure functionality

## Expected Results

- **Before**: 1 file, 3,708 lines, 66 methods
- **After**: 8 files, average ~475 lines per file
- **Reduction**: 100% compliance with 1000-line limit
- **Maintainability**: High cohesion, single responsibility per handler

