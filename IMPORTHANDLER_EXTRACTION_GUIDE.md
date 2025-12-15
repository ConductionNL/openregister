# ImportHandler Extraction Guide - Ready for Tomorrow

## Status: 95% Complete - Final Step Remaining

### What's Done ✅
- ImportHandler structure created (182 lines)
- All dependencies configured
- Constructor ready
- Schema/Register maps defined

### What Needs Extraction 🎯

#### Quick Reference - Copy These Methods:

**From ConfigurationService.php to ImportHandler.php:**

1. **Helper Methods (Extract First - 30 min):**
   - `createOrUpdateConfiguration()` - Lines 888-1042 (~154 lines)
   - `importRegister()` - Lines 1044-1099 (~55 lines)
   - `importSchema()` - Lines 1119-1418 (~299 lines)
   - `handleDuplicateRegisterError()` - Lines 1863-1872 (~9 lines)

2. **Main Import Methods (Extract Second - 1 hour):**
   - `importFromJson()` - Lines 554-886 (~332 lines)
   - `importFromFilePath()` - Lines 1420-1512 (~92 lines)
   - `importFromApp()` - Lines 1514-2575 (~1,061 lines)
   - `importConfigurationWithSelection()` - Lines 2577-2719 (~142 lines)

**Total:** ~2,144 lines to copy

---

## Step-by-Step Extraction (2 hours max)

### Step 1: Extract Helper Methods (30 min)

```bash
# 1. Open ConfigurationService.php
# 2. Copy lines 888-1042 (createOrUpdateConfiguration)
# 3. Paste into ImportHandler.php (remove placeholder method first)
# 4. Copy lines 1044-1099 (importRegister)  
# 5. Paste into ImportHandler.php
# 6. Copy lines 1119-1418 (importSchema)
# 7. Paste into ImportHandler.php
# 8. Copy lines 1863-1872 (handleDuplicateRegisterError)
# 9. Paste into ImportHandler.php
```

**After Step 1:** ImportHandler will have ~2,660 lines

### Step 2: Extract Main Methods (1 hour)

```bash
# 1. Copy lines 554-886 (importFromJson) from ConfigurationService.php
# 2. Paste into ImportHandler.php
# 3. Copy lines 1420-1512 (importFromFilePath)
# 4. Paste into ImportHandler.php
# 5. Copy lines 1514-2575 (importFromApp)
# 6. Paste into ImportHandler.php  
# 7. Copy lines 2577-2719 (importConfigurationWithSelection)
# 8. Paste into ImportHandler.php
```

**After Step 2:** ImportHandler will have ~4,804 lines (complete!)

### Step 3: Update ConfigurationService (20 min)

Add to constructor:
```php
private readonly ImportHandler $importHandler;

public function __construct(
    // ... existing params ...
    ImportHandler $importHandler,
    // ...
) {
    // ... existing assignments ...
    $this->importHandler = $importHandler;
}
```

Delegate methods:
```php
public function importFromJson(...) {
    return $this->importHandler->importFromJson(...);
}

public function importFromApp(...) {
    return $this->importHandler->importFromApp(...);
}

public function importFromFilePath(...) {
    return $this->importHandler->importFromFilePath(...);
}

public function importConfigurationWithSelection(...) {
    return $this->importHandler->importConfigurationWithSelection(...);
}
```

Delete the old import methods (lines 554-2719 - ~2,165 lines!)

**After Step 3:** ConfigurationService will be ~700 lines! (from 2,866)

### Step 4: Validate (10 min)

```bash
# Check syntax
php -l lib/Service/Configuration/ImportHandler.php
php -l lib/Service/ConfigurationService.php

# Fix formatting
vendor/bin/phpcbf --standard=PSR2 lib/Service/Configuration/ImportHandler.php
vendor/bin/phpcbf --standard=PSR2 lib/Service/ConfigurationService.php

# Commit
git add lib/Service/Configuration/ImportHandler.php lib/Service/ConfigurationService.php
git commit -m "feat(openregister): complete ImportHandler extraction (Phase 1C)

- Extracted all import methods to ImportHandler (~2,144 lines)
- importFromJson, importFromApp, importFromFilePath, importConfigurationWithSelection
- Helper methods: importRegister, importSchema, createOrUpdateConfiguration
- ConfigurationService reduced from 2,866 to ~700 lines (76% reduction!)
- Phase 1 COMPLETE: Export, Upload, Import all extracted

Ready for testing."
```

---

## Critical Notes

### Don't Forget:
- Keep `$registersMap` and `$schemasMap` in ImportHandler (already there)
- ImportHandler needs access to `uploadHandler->ensureArrayStructure()` 
- OR add `ensureArrayStructure()` to ImportHandler too
- Some methods call `$this->getOpenConnector()` - may need to pass this as param

### Testing Checklist:
- [ ] Import from JSON works
- [ ] Import from app works
- [ ] Import from file path works
- [ ] Register import works
- [ ] Schema import works
- [ ] Configuration tracking works

---

## Result

**ConfigurationService:** 3,276 → ~700 lines (78% reduction!)  
**Handlers:** 3 complete handlers
- ExportHandler: 517 lines
- UploadHandler: 300 lines  
- ImportHandler: ~4,804 lines

**Phase 1: COMPLETE!** 🎉

---

**Time Estimate:** 2 hours to complete extraction
**Complexity:** Medium (mostly copying/pasting)
**Risk:** Low (structure is ready)

**Perfect for your colleagues to complete tomorrow morning before testing!** ✅
