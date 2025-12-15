# SettingsService Delegation Mapping

## Methods Delegated to Handlers

### SearchBackendHandler (2 methods)
- `getSearchBackendConfig()` → `$this->searchBackendHandler->getSearchBackendConfig()`
- `updateSearchBackendConfig()` → `$this->searchBackendHandler->updateSearchBackendConfig()`

### LlmSettingsHandler (2 methods)
- `getLLMSettingsOnly()` → `$this->llmSettingsHandler->getLLMSettingsOnly()`
- `updateLLMSettingsOnly()` → `$this->llmSettingsHandler->updateLLMSettingsOnly()`

### FileSettingsHandler (2 methods)
- `getFileSettingsOnly()` → `$this->fileSettingsHandler->getFileSettingsOnly()`
- `updateFileSettingsOnly()` → `$this->fileSettingsHandler->updateFileSettingsOnly()`

### ObjectRetentionHandler (4 methods)
- `getObjectSettingsOnly()` → `$this->objectRetentionHandler->getObjectSettingsOnly()`
- `updateObjectSettingsOnly()` → `$this->objectRetentionHandler->updateObjectSettingsOnly()`
- `getRetentionSettingsOnly()` → `$this->objectRetentionHandler->getRetentionSettingsOnly()`
- `updateRetentionSettingsOnly()` → `$this->objectRetentionHandler->updateRetentionSettingsOnly()`

### CacheSettingsHandler (12 methods)
- `getCacheStats()` → `$this->cacheSettingsHandler->getCacheStats()`
- `clearCache()` → `$this->cacheSettingsHandler->clearCache()`
- `warmupNamesCache()` → `$this->cacheSettingsHandler->warmupNamesCache()`

### SolrSettingsHandler (10 methods)
- `getSolrSettings()` → `$this->solrSettingsHandler->getSolrSettings()`
- `getSolrSettingsOnly()` → `$this->solrSettingsHandler->getSolrSettingsOnly()`
- `updateSolrSettingsOnly()` → `$this->solrSettingsHandler->updateSolrSettingsOnly()`
- `getSolrDashboardStats()` → `$this->solrSettingsHandler->getSolrDashboardStats()`
- `getSolrFacetConfiguration()` → `$this->solrSettingsHandler->getSolrFacetConfiguration()`
- `updateSolrFacetConfiguration()` → `$this->solrSettingsHandler->updateSolrFacetConfiguration()`
- `warmupSolrIndex()` → `$this->solrSettingsHandler->warmupSolrIndex()`

### ConfigurationSettingsHandler (19 methods)
- `getSettings()` → `$this->configurationSettingsHandler->getSettings()`
- `updateSettings()` → `$this->configurationSettingsHandler->updateSettings()`
- `updatePublishingOptions()` → `$this->configurationSettingsHandler->updatePublishingOptions()`
- `isMultiTenancyEnabled()` → `$this->configurationSettingsHandler->isMultiTenancyEnabled()`
- `getRbacSettingsOnly()` → `$this->configurationSettingsHandler->getRbacSettingsOnly()`
- `updateRbacSettingsOnly()` → `$this->configurationSettingsHandler->updateRbacSettingsOnly()`
- `getOrganisationSettingsOnly()` → `$this->configurationSettingsHandler->getOrganisationSettingsOnly()`
- `updateOrganisationSettingsOnly()` → `$this->configurationSettingsHandler->updateOrganisationSettingsOnly()`
- `getDefaultOrganisationUuid()` → `$this->configurationSettingsHandler->getDefaultOrganisationUuid()`
- `setDefaultOrganisationUuid()` → `$this->configurationSettingsHandler->setDefaultOrganisationUuid()`
- `getTenantId()` → `$this->configurationSettingsHandler->getTenantId()`
- `getOrganisationId()` → `$this->configurationSettingsHandler->getOrganisationId()`
- `getMultitenancySettingsOnly()` → `$this->configurationSettingsHandler->getMultitenancySettingsOnly()`
- `updateMultitenancySettingsOnly()` → `$this->configurationSettingsHandler->updateMultitenancySettingsOnly()`
- `getVersionInfoOnly()` → `$this->configurationSettingsHandler->getVersionInfoOnly()`

### ValidationOperationsHandler (2 methods via existing handler)
- `validateAllObjects()` → `$this->validationOperationsHandler->validateAllObjects()`
- `massValidateObjects()` → Keep in SettingsService (uses multiple services)

## Methods Kept in SettingsService

These methods remain in SettingsService because they:
1. Orchestrate multiple handlers
2. Are core to SettingsService responsibility
3. Use dependencies not appropriate for handlers

### Core Methods (13 methods)
- `rebaseObjectsAndLogs()` - Orchestrates multiple operations
- `rebase()` - Alias for rebaseObjectsAndLogs
- `getStats()` - Database statistics aggregation
- `massValidateObjects()` - Complex orchestration with ObjectService
- `convertToBytes()` - Helper method
- `maskToken()` - Helper method
- `getExpectedSchemaFields()` - Schema analysis
- `compareFields()` - Schema comparison

**Total in SettingsService**: ~800-900 lines (down from 3,708)

