# OpenRegister Code Quality Analysis

**Generated**: $(date '+%Y-%m-%d %H:%M:%S')

## Executive Summary

After the successful refactoring of `GuzzleSolrService` and `SettingsController`, the codebase still contains **20 files exceeding 1,000 lines**, with several God Objects requiring attention.

## 🔴 Critical God Objects (>3,000 lines)

### 1. ObjectService.php ⚠️ LARGEST
- **Lines**: 5,305 (5.3x over limit!)
- **Methods**: 83
- **Status**: ❌ Critical God Object
- **Priority**: HIGHEST (but another agent is handling this)
- **Recommendation**: Skip for now per user instruction

### 2. ObjectEntityMapper.php
- **Lines**: 4,985 (5.0x over limit!)
- **Methods**: 68
- **Status**: ❌ Critical God Object
- **Type**: Database Mapper
- **Recommendation**: Split by entity type or operation type

### 3. SettingsService.php 🎯 RECOMMENDED TARGET
- **Lines**: 3,708 (3.7x over limit!)
- **Methods**: 66
- **Status**: ❌ God Object
- **Note**: Backend for the recently split SettingsController
- **Recommendation**: **HIGH PRIORITY** - Split into domain handlers to match the controller split

### 4. FileService.php 🎯 RECOMMENDED TARGET
- **Lines**: 3,712 (3.7x over limit!)
- **Methods**: 62
- **Status**: ❌ God Object
- **Recommendation**: **HIGH PRIORITY** - Split into handlers (extraction, indexing, metadata)

### 5. ConfigurationService.php
- **Lines**: 3,276 (3.3x over limit!)
- **Methods**: 39
- **Status**: ❌ God Object
- **Recommendation**: Split into handlers (schema, source, validation)

## 🟡 Major God Objects (2,000-3,000 lines)

### 6. SetupHandler.php
- **Lines**: 2,979
- **Status**: ❌ Large file
- **Recommendation**: Split into setup phases/steps

### 7. SaveObject.php
- **Lines**: 2,405
- **Methods**: 29
- **Status**: ❌ Large file
- **Recommendation**: Extract validation, hydration, relations into handlers

### 8. MagicMapper.php
- **Lines**: 2,403
- **Methods**: 46
- **Status**: ❌ God Object
- **Recommendation**: Split by mapping type

### 9. VectorEmbeddingService.php
- **Lines**: 2,392
- **Methods**: 35
- **Status**: ❌ Large file
- **Recommendation**: Split by provider (OpenAI, Ollama, Fireworks)

### 10. SaveObjects.php
- **Lines**: 2,287
- **Status**: ❌ Large file
- **Recommendation**: Extract bulk operations handlers

### 11. ChatService.php
- **Lines**: 2,156
- **Status**: ❌ Large file
- **Recommendation**: Split by provider

### 12. SchemaMapper.php
- **Lines**: 2,120
- **Methods**: 38
- **Status**: ❌ Large file
- **Recommendation**: Split by operation type

### 13. ObjectsController.php
- **Lines**: 2,084
- **Status**: ❌ Large controller
- **Recommendation**: Split like SettingsController

## 🟢 Moderate Files (1,000-2,000 lines)

14. TextExtractionService.php - 1,844 lines
15. ImportService.php - 1,759 lines
16. Schema.php (Entity) - 1,639 lines
17. CacheHandler.php - 1,615 lines
18. ConfigurationController.php - 1,570 lines
19. MariaDbFacetHandler.php - 1,550 lines
20. ValidateObject.php - 1,485 lines

## 🎯 Recommended Refactoring Priority

Based on:
- Size (lines of code)
- Complexity (method count)
- Impact (how often it's used)
- Feasibility (how easily it can be split)

### Priority 1: SettingsService.php
**Why**: 
- Just split the SettingsController, now the service needs matching cleanup
- 3,708 lines, 66 methods
- Already has logical domains from controller split
- High impact, medium difficulty

**Approach**:
```
SettingsService.php (3,708 lines, 66 methods)
  ↓
Split into domain handlers matching the controllers:
├── Settings/SolrSettingsHandler.php      (~500 lines)
├── Settings/LlmSettingsHandler.php       (~400 lines)
├── Settings/FileSettingsHandler.php      (~400 lines)
├── Settings/CacheSettingsHandler.php     (~200 lines)
├── Settings/ValidationSettingsHandler.php (~300 lines)
├── Settings/ConfigurationSettingsHandler.php (~400 lines)
└── SettingsService.php (thin facade)      (~500 lines)
```

### Priority 2: FileService.php
**Why**:
- 3,712 lines, 62 methods
- Clear separation: file extraction, text processing, indexing
- High impact on system performance
- Medium-high difficulty

**Approach**:
```
FileService.php (3,712 lines, 62 methods)
  ↓
Split into specialized handlers:
├── Files/FileExtractionHandler.php    (~800 lines)
├── Files/TextProcessingHandler.php    (~800 lines)
├── Files/FileIndexingHandler.php      (~800 lines)
├── Files/FileMetadataHandler.php      (~600 lines)
└── FileService.php (facade)           (~700 lines)
```

### Priority 3: ConfigurationService.php
**Why**:
- 3,276 lines, 39 methods
- Configuration management is critical
- Can split by config type
- Medium difficulty

**Approach**:
```
ConfigurationService.php (3,276 lines, 39 methods)
  ↓
Split by configuration domain:
├── Configuration/SchemaConfigHandler.php   (~800 lines)
├── Configuration/SourceConfigHandler.php   (~800 lines)
├── Configuration/ValidationConfigHandler.php (~600 lines)
└── ConfigurationService.php (facade)       (~1,000 lines)
```

### Priority 4: ObjectEntityMapper.php
**Why**:
- 4,985 lines, 68 methods (HUGE!)
- Database layer is critical
- Complex with many dependencies
- High difficulty - should be done carefully

### Priority 5: SetupHandler.php
**Why**:
- 2,979 lines
- SOLR setup is complex but isolated
- Can split by setup phases
- Medium difficulty

## Summary Statistics

### Files Over 1,000 Lines: 20 files
```
>5,000 lines: 1 file  (ObjectService.php)
>4,000 lines: 1 file  (ObjectEntityMapper.php)
>3,000 lines: 3 files (FileService, SettingsService, ConfigurationService)
>2,000 lines: 8 files
>1,000 lines: 7 files
```

### Total Technical Debt
- **Lines in God Objects (>1,000)**: ~45,000 lines
- **Average file size (top 20)**: 2,442 lines
- **Target average**: <800 lines
- **Potential reduction**: ~25,000 lines through proper splitting

## Recommendation

**Start with SettingsService.php** because:
1. ✅ Matches recent controller split (logical consistency)
2. ✅ Clear domain boundaries already established
3. ✅ High impact (used throughout the app)
4. ✅ Medium difficulty (well-understood domains)
5. ✅ Quick wins (can follow same pattern as controller split)

**Estimated effort**: 2-3 hours for SettingsService split
**Expected result**: 7 files averaging ~450 lines each

---

## Next Steps

1. **Immediate**: Start with `SettingsService.php`
2. **Week 1**: Complete `FileService.php`
3. **Week 2**: Tackle `ConfigurationService.php`
4. **Month 1**: Address remaining 2,000+ line files
5. **Month 2**: Clean up 1,000-2,000 line files

**Goal**: All files under 1,000 lines within 2 months

