# Phase 2 Completion Guide

## ✅ Status: Phase 1 Complete, Phase 2 Ready

### Completed
- ✅ 8 Handler files created (3,420 lines)
- ✅ 387 coding errors fixed
- ✅ Delegation mapping documented
- ✅ Original SettingsService backed up

### Remaining Work

## Task 1: Refactor SettingsService (30 min)

### Current State
- File: `lib/Service/SettingsService.php` (3,708 lines)
- Backup: `lib/Service/SettingsService.php.backup`

### Required Changes

#### 1. Update Constructor
Add handler injections:

```php
public function __construct(
    // ... existing dependencies ...
    
    // Add these 8 handlers:
    SearchBackendHandler $searchBackendHandler,
    LlmSettingsHandler $llmSettingsHandler,
    FileSettingsHandler $fileSettingsHandler,
    ObjectRetentionHandler $objectRetentionHandler,
    CacheSettingsHandler $cacheSettingsHandler,
    SolrSettingsHandler $solrSettingsHandler,
    ConfigurationSettingsHandler $configurationSettingsHandler,
    ValidationOperationsHandler $validationOperationsHandler
) {
    // ... existing assignments ...
    
    $this->searchBackendHandler = $searchBackendHandler;
    $this->llmSettingsHandler = $llmSettingsHandler;
    $this->fileSettingsHandler = $fileSettingsHandler;
    $this->objectRetentionHandler = $objectRetentionHandler;
    $this->cacheSettingsHandler = $cacheSettingsHandler;
    $this->solrSettingsHandler = $solrSettingsHandler;
    $this->configurationSettingsHandler = $configurationSettingsHandler;
    $this->validationOperationsHandler = $validationOperationsHandler;
}
```

#### 2. Replace Method Bodies with Delegation

Replace each delegated method's body with a simple call. Example:

**Before**:
```php
public function getSolrSettings(): array
{
    try {
        // 20+ lines of logic
    } catch (Exception $e) {
        throw new RuntimeException(...);
    }
}
```

**After**:
```php
public function getSolrSettings(): array
{
    return $this->solrSettingsHandler->getSolrSettings();
}
```

#### 3. Methods to Replace (53 total)

See `SETTINGS_DELEGATION_MAP.md` for complete list.

Quick reference:
- SearchBackendHandler: 2 methods
- LlmSettingsHandler: 2 methods
- FileSettingsHandler: 2 methods
- ObjectRetentionHandler: 4 methods
- CacheSettingsHandler: 12 methods
- SolrSettingsHandler: 10 methods
- ConfigurationSettingsHandler: 19 methods
- ValidationOperationsHandler: 2 methods

#### 4. Methods to KEEP in SettingsService

Keep these methods unchanged (they orchestrate multiple services):
- `rebaseObjectsAndLogs()`
- `rebase()`
- `getStats()`
- `massValidateObjects()` + helper methods
- `convertToBytes()`
- `maskToken()`
- `getExpectedSchemaFields()`
- `compareFields()`

### Expected Result
- SettingsService: ~800-1000 lines (down from 3,708)
- Simple delegation methods
- Clean, maintainable facade

---

## Task 2: Update Application.php (15 min)

### File: `lib/AppInfo/Application.php`

Add DI registrations for all 8 handlers in the `register()` method:

```php
// Register Settings Handlers
$context->registerService(SearchBackendHandler::class, function (IContainer $c) {
    return new SearchBackendHandler(
        $c->get(IConfig::class),
        $c->get(LoggerInterface::class),
        'openregister'
    );
});

$context->registerService(LlmSettingsHandler::class, function (IContainer $c) {
    return new LlmSettingsHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

$context->registerService(FileSettingsHandler::class, function (IContainer $c) {
    return new FileSettingsHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

$context->registerService(ObjectRetentionHandler::class, function (IContainer $c) {
    return new ObjectRetentionHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

$context->registerService(CacheSettingsHandler::class, function (IContainer $c) {
    return new CacheSettingsHandler(
        $c->get(ICacheFactory::class),
        $c->get(SchemaCacheService::class),
        $c->get(FacetCacheHandler::class),
        null, // objectCacheService - lazy loaded
        $c    // container
    );
});

$context->registerService(SolrSettingsHandler::class, function (IContainer $c) {
    return new SolrSettingsHandler(
        $c->get(IConfig::class),
        null, // objectCacheService - lazy loaded
        $c,   // container
        'openregister'
    );
});

$context->registerService(ConfigurationSettingsHandler::class, function (IContainer $c) {
    return new ConfigurationSettingsHandler(
        $c->get(IConfig::class),
        $c->get(IGroupManager::class),
        $c->get(IUserManager::class),
        $c->get(OrganisationMapper::class),
        $c->get(LoggerInterface::class),
        'openregister'
    );
});

// ValidationOperationsHandler already exists, verify it's registered
```

Then update SettingsService registration to inject handlers:

```php
$context->registerService(SettingsService::class, function (IContainer $c) {
    return new SettingsService(
        // ... existing dependencies ...
        $c->get(SearchBackendHandler::class),
        $c->get(LlmSettingsHandler::class),
        $c->get(FileSettingsHandler::class),
        $c->get(ObjectRetentionHandler::class),
        $c->get(CacheSettingsHandler::class),
        $c->get(SolrSettingsHandler::class),
        $c->get(ConfigurationSettingsHandler::class),
        $c->get(ValidationOperationsHandler::class)
    );
});
```

---

## Task 3: Final Quality Checks (10 min)

### Run phpcbf
```bash
vendor/bin/phpcbf lib/Service/SettingsService.php --standard=PSR2
```

### Verify Line Counts
```bash
wc -l lib/Service/SettingsService.php
# Expected: 800-1000 lines (down from 3,708)
```

### Test Basic Endpoint
```bash
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  http://master-nextcloud-1/index.php/apps/openregister/api/settings
```

---

## Quick Start Script

For automated completion, run:

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# This would be a custom script to automate method replacement
# Due to complexity, manual replacement recommended with IDE refactoring tools
```

---

## Success Criteria

✅ SettingsService under 1000 lines  
✅ All methods delegate to handlers  
✅ Application.php has all handler registrations  
✅ phpcbf passes  
✅ Settings API endpoint works  

---

## Estimated Time

- Task 1 (Facade): 30 minutes
- Task 2 (DI): 15 minutes  
- Task 3 (QA): 10 minutes

**Total**: ~1 hour

---

## Support

If you encounter issues:
1. Check `SETTINGS_DELEGATION_MAP.md` for method mapping
2. Reference handler files in `lib/Service/Settings/`
3. Original backup at `SettingsService.php.backup`

