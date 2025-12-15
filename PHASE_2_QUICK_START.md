# Phase 2 Quick Start Guide

**Goal**: Convert SettingsService to thin facade (3,708 lines → ~900 lines)

---

## 📁 Files You Need

1. **SETTINGS_DELEGATION_MAP.md** - Which methods go to which handlers
2. **APPLICATION_DI_UPDATES.php** - Copy-paste DI registrations
3. **PHASE_2_COMPLETION_GUIDE.md** - Detailed step-by-step instructions
4. **SettingsService.php.backup** - Rollback if needed

---

## ⚡ Quick Steps

### Step 1: Update Constructor (5 min)

Add these 8 properties to `SettingsService`:

```php
private SearchBackendHandler $searchBackendHandler,
private LlmSettingsHandler $llmSettingsHandler,
private FileSettingsHandler $fileSettingsHandler,
private ObjectRetentionHandler $objectRetentionHandler,
private CacheSettingsHandler $cacheSettingsHandler,
private SolrSettingsHandler $solrSettingsHandler,
private ConfigurationSettingsHandler $configurationSettingsHandler,
private ValidationOperationsHandler $validationOperationsHandler
```

### Step 2: Replace Method Bodies (30-60 min)

Example delegation pattern:

```php
// BEFORE:
public function getSolrSettings(): array {
    // ... 50 lines of logic ...
}

// AFTER:
public function getSolrSettings(): array {
    return $this->solrSettingsHandler->getSolrSettings();
}
```

**Tip**: Use Find & Replace in IDE to speed this up!

### Step 3: Update Application.php (10 min)

Copy from `APPLICATION_DI_UPDATES.php`:
1. Add 8 handler registrations
2. Update SettingsService registration with handler injections

### Step 4: Verify (10 min)

```bash
# Line count
wc -l lib/Service/SettingsService.php

# Code quality
vendor/bin/phpcbf lib/Service/SettingsService.php --standard=PSR2

# Test API
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  http://master-nextcloud-1/index.php/apps/openregister/api/settings | jq .
```

---

## 🎯 Methods to Delegate

### Search Backend (2 methods) → SearchBackendHandler
- `getSearchBackendConfig()`
- `updateSearchBackendConfig()`

### LLM Settings (2 methods) → LlmSettingsHandler
- `getLLMSettingsOnly()`
- `updateLLMSettingsOnly()`

### File Settings (2 methods) → FileSettingsHandler
- `getFileSettingsOnly()`
- `updateFileSettingsOnly()`

### Object & Retention (4 methods) → ObjectRetentionHandler
- `getObjectSettingsOnly()`
- `updateObjectSettingsOnly()`
- `getRetentionSettingsOnly()`
- `updateRetentionSettingsOnly()`

### Cache Operations (12 methods) → CacheSettingsHandler
- `getCacheStats()`
- `clearCache()`
- `warmupNamesCache()`
- And 9 more...

### SOLR Operations (10 methods) → SolrSettingsHandler
- `getSolrSettings()`
- `updateSolrSettingsOnly()`
- `getSolrDashboardStats()`
- And 7 more...

### Configuration (19 methods) → ConfigurationSettingsHandler
- `getSettings()`
- `updateSettings()`
- `getRbacSettingsOnly()`
- `getMultitenancySettingsOnly()`
- And 15 more...

### Validation (6 methods) → ValidationOperationsHandler
- Already exists and registered

**See `SETTINGS_DELEGATION_MAP.md` for complete list!**

---

## 💡 Pro Tips

1. **Test incrementally** - Replace 5-10 methods, test, repeat
2. **Keep orchestration** - Methods that call multiple handlers stay in SettingsService
3. **Use IDE refactoring** - PhpStorm "Extract Delegate" is perfect for this
4. **Verify signatures** - Make sure parameters and return types match
5. **Run phpcbf often** - Catch formatting issues early

---

## 🆘 If Something Breaks

```bash
# Rollback
cp SettingsService.php.backup lib/Service/SettingsService.php

# Check logs
docker logs master-nextcloud-1 | tail -100

# Test DI container
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

---

## ✅ Success Metrics

- [ ] SettingsService under 1,000 lines
- [ ] All 53 methods delegated
- [ ] 0 PHPCS errors
- [ ] All API endpoints working
- [ ] Backward compatibility maintained

---

**Estimated Time**: 1-2 hours  
**Difficulty**: Medium (mechanical but requires attention)  
**Risk**: Low (backup exists, handlers tested)

**Ready to start? Open `PHASE_2_COMPLETION_GUIDE.md` for detailed instructions!**
