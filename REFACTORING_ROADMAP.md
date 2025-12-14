# OpenRegister Refactoring Roadmap

**Date:** 2025-12-14  
**Strategy:** Aggressive (Option A) - Delete legacy services, migrate all usages

---

## Overview

This roadmap outlines the complete refactoring of OpenRegister's service architecture:
1. **Phase 1:** Remove legacy Solr services → Replace with IndexService
2. **Phase 2:** Extract Object handlers (Cache, Revert)
3. **Phase 3:** Extract Schema handlers (FacetCache, PropertyValidator)

---

## Phase 1: Legacy Solr Services Migration

### Goal
Remove 15,480 lines of legacy Solr code, replace with 1,889 lines of clean IndexService architecture.

### Status: 5% Complete (1/20 files)

#### ✅ Completed
- [x] Application.php - Removed legacy imports, added IndexService

#### ⏳ Pending (19 files)

**High Priority - Controllers (5 files)**
- [ ] SettingsController - Replace GuzzleSolrService, SolrSchemaService → IndexService
- [ ] SearchController - Replace GuzzleSolrService → IndexService
- [ ] SolrController - Replace GuzzleSolrService, SolrFileService, SolrObjectService → IndexService
- [ ] FileSearchController - Replace GuzzleSolrService → IndexService
- [ ] FileTextController - Replace SolrFileService → TextExtractionService (NOT IndexService)

**Medium Priority - Services (6 files)**
- [ ] ObjectCacheService - Replace GuzzleSolrService → IndexService
- [ ] SettingsService - Replace GuzzleSolrService → IndexService
- [ ] ObjectService - Replace GuzzleSolrService, SolrObjectService → IndexService
- [ ] ChatService - Replace GuzzleSolrService → IndexService
- [ ] VectorEmbeddingService - Review usage (shouldn't use Solr services)
- [ ] TextExtraction/FileHandler - Review usage (shouldn't use SolrFileService)

**Commands (2 files)**
- [ ] SolrDebugCommand - Replace GuzzleSolrService → IndexService
- [ ] SolrManagementCommand - Replace GuzzleSolrService, SolrSchemaService → IndexService

**Background Jobs (2 files)**
- [ ] SolrWarmupJob - Replace GuzzleSolrService → IndexService
- [ ] SolrNightlyWarmupJob - Replace GuzzleSolrService → IndexService

**Setup (1 file)**
- [ ] SolrSetup - Replace GuzzleSolrService → IndexService

**Tests (3 files)**
- [ ] SolrObjectServiceTest - DELETE or rewrite for IndexService
- [ ] SolrFileServiceTest - DELETE or rewrite for IndexService
- [ ] SolrApiIntegrationTest - Update for IndexService

**Final Cleanup**
- [ ] Delete `lib/Service/SolrFileService.php` (1,289 lines)
- [ ] Delete `lib/Service/SolrObjectService.php` (597 lines)
- [ ] Delete `lib/Service/SolrSchemaService.php` (1,866 lines)
- [ ] Delete `lib/Service/GuzzleSolrService.php` (11,728 lines!)

---

## Phase 2: Extract Object Handlers

### Goal
Move Object-related services to handler pattern under `lib/Service/Objects/`

### Current Structure
```
lib/Service/
├── ObjectService.php (FACADE - already good!)
├── ObjectCacheService.php (1,616 lines)
└── RevertService.php (129 lines)
```

### Target Structure
```
lib/Service/
├── ObjectService.php (FACADE)
└── Objects/
    ├── CacheHandler.php (was ObjectCacheService)
    ├── RevertHandler.php (was RevertService)
    ├── DeleteObject.php (existing)
    ├── GetObject.php (existing)
    ├── RenderObject.php (existing)
    ├── SaveObject.php (existing)
    ├── SaveObjects.php (existing)
    └── ValidateObject.php (existing)
```

### Tasks
- [ ] Move `ObjectCacheService.php` → `Objects/CacheHandler.php`
- [ ] Move `RevertService.php` → `Objects/RevertHandler.php`
- [ ] Update `ObjectService` to inject CacheHandler and RevertHandler
- [ ] Update all usages of ObjectCacheService to use ObjectService or CacheHandler
- [ ] Update all usages of RevertService to use ObjectService or RevertHandler

### Benefits
- ✅ Consistent handler pattern across Object operations
- ✅ Clear responsibility: cache management and reversion
- ✅ Easier to maintain and test
- ✅ Follows existing pattern (DeleteObject, GetObject, etc.)

---

## Phase 3: Extract Schema Handlers

### Goal
Move Schema-related services to handler pattern under `lib/Service/Schemas/`

### Current Structure
```
lib/Service/
├── SchemaService.php (DOESN'T EXIST - need to create!)
├── SchemaFacetCacheService.php (806 lines)
├── SchemaPropertyValidatorService.php (332 lines)
└── SchemaCacheService.php (746 lines - keep or extract?)
```

### Target Structure
```
lib/Service/
├── SchemaService.php (NEW FACADE)
└── Schemas/
    ├── FacetCacheHandler.php (was SchemaFacetCacheService)
    ├── PropertyValidatorHandler.php (was SchemaPropertyValidatorService)
    └── CacheHandler.php (was SchemaCacheService?)
```

### Tasks
- [ ] **Create SchemaService facade** - Central interface for schema operations
- [ ] Move `SchemaFacetCacheService.php` → `Schemas/FacetCacheHandler.php`
- [ ] Move `SchemaPropertyValidatorService.php` → `Schemas/PropertyValidatorHandler.php`
- [ ] Decide: Keep SchemaCacheService or extract to Schemas/CacheHandler.php?
- [ ] Update all usages to go through SchemaService
- [ ] Document SchemaService as the primary schema interface

### Benefits
- ✅ Creates consistent service architecture (ObjectService, SchemaService, IndexService)
- ✅ Centralizes schema-related operations
- ✅ Clear separation: facet caching, property validation, general caching
- ✅ Easier to add new schema handlers in the future

---

## Architecture: Before vs After

### Before (Current Mess)
```
Services (flat, inconsistent):
├── ObjectService (facade - GOOD!)
├── ObjectCacheService
├── RevertService
├── SchemaFacetCacheService
├── SchemaPropertyValidatorService
├── SchemaCacheService
├── GuzzleSolrService (11,728 lines!)
├── SolrFileService
├── SolrObjectService
└── SolrSchemaService
```

### After (Clean, Consistent)
```
Services (organized, handler-based):
├── ObjectService (facade)
│   └── Objects/
│       ├── CacheHandler
│       ├── RevertHandler
│       ├── DeleteObject
│       ├── GetObject
│       ├── RenderObject
│       ├── SaveObject
│       ├── SaveObjects
│       └── ValidateObject
├── SchemaService (NEW facade)
│   └── Schemas/
│       ├── FacetCacheHandler
│       ├── PropertyValidatorHandler
│       └── CacheHandler
└── IndexService (facade)
    └── Index/
        ├── FileHandler
        ├── ObjectHandler
        ├── SchemaHandler
        └── SearchBackendInterface
```

---

## Impact Summary

### Code Reduction
| Category | Before | After | Savings |
|----------|--------|-------|---------|
| **Solr Services** | 15,480 lines | 1,889 lines | **-88%** 🎉 |
| **God Classes** | 3 services | 0 services | **-100%** ✅ |
| **Avg Complexity** | 40 | 26.25 | **-34%** ✅ |
| **Predicted Bugs** | 2.1/class | 0.73/class | **-65%** ✅ |

### Architectural Improvements
- ✅ **3 Main Facades**: ObjectService, SchemaService, IndexService
- ✅ **Handler Pattern**: All complex logic in focused handlers
- ✅ **Clear Separation**: Objects, Schemas, Index are independent
- ✅ **Easy Testing**: Small, focused handlers are easy to test
- ✅ **Future-Proof**: Easy to add new handlers without touching facades

---

## Execution Order

### Week 1: Phase 1 (Legacy Solr Migration)
**Day 1-2: Controllers (5 files)**
- Update SettingsController, SearchController, SolrController
- Update FileSearchController, FileTextController

**Day 2-3: Services (6 files)**
- Update ObjectCacheService, SettingsService, ObjectService
- Update ChatService, review VectorEmbeddingService

**Day 3-4: Commands, Jobs, Setup (5 files)**
- Update SolrDebugCommand, SolrManagementCommand
- Update SolrWarmupJob, SolrNightlyWarmupJob
- Update SolrSetup

**Day 4-5: Cleanup**
- Delete legacy Solr service files
- Update tests
- Run full test suite
- Run PHPQA

### Week 2: Phase 2 (Object Handlers)
**Day 1-2:**
- Move ObjectCacheService → Objects/CacheHandler
- Move RevertService → Objects/RevertHandler
- Update ObjectService facade
- Update all usages

**Day 3:**
- Test Object handler architecture
- Run PHPQA
- Update documentation

### Week 3: Phase 3 (Schema Handlers)
**Day 1-2:**
- Create SchemaService facade
- Move SchemaFacetCacheService → Schemas/FacetCacheHandler
- Move SchemaPropertyValidatorService → Schemas/PropertyValidatorHandler

**Day 3:**
- Update all usages to go through SchemaService
- Test Schema handler architecture
- Run PHPQA

**Day 4-5:**
- Final testing
- Update documentation
- Create architecture diagrams

---

## Success Criteria

### Phase 1 Complete When:
- ✅ All 20+ files updated to use IndexService
- ✅ All 4 legacy Solr services deleted (15,480 lines removed!)
- ✅ All tests passing
- ✅ PHPQA reports 0 critical issues

### Phase 2 Complete When:
- ✅ ObjectCacheService and RevertService moved to Objects/
- ✅ ObjectService facade updated and tested
- ✅ All usages updated
- ✅ PHPQA reports 0 critical issues

### Phase 3 Complete When:
- ✅ SchemaService facade created
- ✅ Schema handlers moved to Schemas/
- ✅ All usages go through SchemaService
- ✅ PHPQA reports 0 critical issues
- ✅ Documentation updated with new architecture

### Final Success:
- ✅ Clean, consistent 3-facade architecture
- ✅ 88% less code than before
- ✅ 65% fewer predicted bugs
- ✅ Easy to maintain and extend
- ✅ Production-ready

---

## Risk Mitigation

### Risks
1. **Breaking existing functionality** - Many files to update
2. **Missing usages** - Might miss some references to legacy services
3. **Test failures** - Tests might depend on old structure

### Mitigations
1. **Systematic approach** - Update one category at a time (controllers, then services, etc.)
2. **Search thoroughly** - Use grep to find all usages before deleting
3. **Test frequently** - Run tests after each major group of changes
4. **Git safety** - Commit after each successful phase
5. **Rollback plan** - Keep git history clean for easy rollback

---

## Notes

- **Option A (Aggressive)** chosen for clean break from legacy code
- **No backward compatibility** - This is a breaking architectural change
- **Benefits justify effort** - 88% code reduction, much cleaner architecture
- **User expectation met** - "I no longer expect to see those files" ✅

---

## Next Steps

1. ✅ Create this roadmap
2. ✅ Create TODOs
3. ⏳ Start Phase 1 - Update ObjectCacheService (first service)
4. Continue systematically through all 20+ files
5. Delete legacy files when all usages removed
6. Move to Phase 2 and 3

Ready to execute! 🚀


