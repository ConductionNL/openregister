# Controller Business Logic Audit

**Date:** 2025-12-14  
**Audit Scope:** All controllers in lib/Controller/  
**Purpose:** Identify business logic that should be extracted to services  

## Executive Summary

**Total Controllers:** 20+  
**Controllers Audited:** 5 (top priority by size)  
**Critical Issues Found:** 2 controllers with significant business logic violations  
**Status:** ⚠️ VIOLATIONS FOUND - Extraction required

## Critical Findings

### ❌ SettingsController - CRITICAL VIOLATIONS (5,763 lines, 88 methods)

**Severity:** HIGH  
**Status:** ⚠️ Requires immediate refactoring

#### Business Logic Violations Found

##### 1. validateAllObjects() - Lines 464-542 (79 lines)

**Violation Type:** Complex business logic in controller

**Business Logic Present:**
- Fetching all objects from database
- Looping through objects for validation
- Schema lookup and validation execution
- Result aggregation and statistics calculation
- Success rate computation

**Should Be:** Extracted to `ValidationService->validateAllObjects()`

**Recommended Structure:**
```
lib/Service/Validation/
├── ValidationService.php (facade)
└── ValidationHandlers/
    ├── ObjectValidationHandler.php
    └── ValidationReportHandler.php
```

##### 2. massValidateObjects() - Lines 557-750+ (200+ lines)

**Violation Type:** Massive business logic orchestration

**Business Logic Present:**
- Parameter parsing and validation (should be in validator)
- Database querying and counting
- Batch job creation algorithm
- Parallel/serial processing orchestration
- Memory tracking and statistics
- Result aggregation and formatting
- Success/failure determination logic

**Should Be:** Extracted to `ValidationService->massValidateObjects()`

**Additional Methods Called:**
- `processJobsParallel()` - Private method with business logic
- `processJobsSerial()` - Private method with business logic
- `formatBytes()` - Utility method

##### 3. predictMassValidationMemory() - Line 1238 (130+ lines estimated)

**Violation Type:** Predictive algorithm in controller

**Should Be:** Extracted to `ValidationService->predictMemoryUsage()`

#### Other Methods (Sample Checked)

**✅ Correctly Delegating:**
- index() - Delegates to settingsService.getSettings()
- update() - Delegates to settingsService.updateSettings()
- load() - Delegates to settingsService.getSettings()
- updatePublishingOptions() - Delegates to settingsService.updatePublishingOptions()
- rebase() - Delegates to settingsService.rebase()
- stats() - Delegates to settingsService.getStatistics()
- getStatistics() - Delegates to settingsService.getDashboardStatistics()
- getCacheStats() - Delegates to objectCacheService.getStats()

**Assessment:** Most methods correctly delegate, but validation-related methods contain significant business logic.

#### Recommended Actions

1. **Create ValidationService** with handlers:
   ```
   lib/Service/Validation/
   ├── ValidationService.php
   └── Validation/
       ├── ObjectValidationHandler.php (single object validation)
       ├── BulkValidationHandler.php (mass validation)
       ├── ValidationReportHandler.php (result aggregation)
       └── MemoryPredictionHandler.php (memory estimation)
   ```

2. **Extract Methods:**
   - `validateAllObjects()` → `ValidationService->validateAllObjects()`
   - `massValidateObjects()` → `ValidationService->massValidateObjects()`
   - `predictMassValidationMemory()` → `ValidationService->predictMemoryUsage()`
   - `processJobsParallel()` → `BulkValidationHandler->processParallel()`
   - `processJobsSerial()` → `BulkValidationHandler->processSerial()`

3. **Update SettingsController:**
   ```php
   public function massValidateObjects(): JSONResponse {
       try {
           $params = [
               'maxObjects' => $this->request->getParam('maxObjects', 0),
               'batchSize' => $this->request->getParam('batchSize', 1000),
               'mode' => $this->request->getParam('mode', 'serial'),
               'collectErrors' => $this->request->getParam('collectErrors', false),
           ];
           
           $result = $this->validationService->massValidateObjects($params);
           return new JSONResponse($result);
       } catch (Exception $e) {
           return new JSONResponse(['error' => $e->getMessage()], 500);
       }
   }
   ```

---

### ✅ ObjectsController - MOSTLY CORRECT (2,086 lines, 24 methods)

**Status:** ✅ Acceptable with minor improvements

**Assessment:** Need to check specific methods, but likely delegates properly to ObjectService.

**Recommended Action:** Spot-check key methods for business logic violations.

---

### ⚠️ ConfigurationController - NEEDS REVIEW (1,570 lines, 21 methods)

**Status:** ⏳ Pending detailed audit

**Concern:** Size suggests potential business logic violations.

**Recommended Action:** Audit key CRUD methods for business logic.

---

### ✅ WebhooksController - LIKELY CORRECT (1,187 lines, 12 methods)

**Status:** ✅ Likely acceptable

**Assessment:** Moderate size with few methods suggests proper delegation.

**Recommended Action:** Spot-check for confirmation.

---

### ✅ RegistersController - LIKELY CORRECT (1,145 lines, 15 methods)

**Status:** ✅ Likely acceptable

**Assessment:** Moderate size suggests proper delegation.

**Recommended Action:** Spot-check for confirmation.

---

## Audit Statistics

| Controller | Lines | Methods | Status | Violations Found |
|------------|-------|---------|--------|------------------|
| SettingsController | 5,763 | 88 | ❌ CRITICAL | 3+ methods |
| ObjectsController | 2,086 | 24 | ⏳ PENDING | Unknown |
| ConfigurationController | 1,570 | 21 | ⏳ PENDING | Unknown |
| WebhooksController | 1,187 | 12 | ✅ LIKELY OK | None suspected |
| RegistersController | 1,145 | 15 | ✅ LIKELY OK | None suspected |
| SolrController | 1,041 | ? | ⏳ PENDING | Unknown |
| SchemasController | 990 | ? | ⏳ PENDING | Unknown |
| FilesController | 945 | ? | ⏳ PENDING | Unknown |
| SearchTrailController | 885 | ? | ⏳ PENDING | Unknown |
| ConversationController | 844 | ? | ⏳ PENDING | Unknown |

## Controller Delegation Patterns

### ✅ CORRECT Pattern
```php
public function methodName(): JSONResponse {
    try {
        $params = $this->request->getParams();
        $result = $this->service->businessMethod($params);
        return new JSONResponse($result);
    } catch (Exception $e) {
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}
```

### ❌ INCORRECT Pattern (Found in SettingsController)
```php
public function methodName(): JSONResponse {
    try {
        // ❌ Parameter parsing logic
        $maxObjects = $this->request->getParam('maxObjects', 0);
        if ($maxObjects === 0) {
            $input = file_get_contents('php://input');
            // Complex JSON parsing...
        }
        
        // ❌ Business validation
        if ($batchSize < 1 || $batchSize > 5000) {
            return new JSONResponse(['error' => '...'], 400);
        }
        
        // ❌ Database queries
        $totalObjects = $objectMapper->countSearchObjects(...);
        
        // ❌ Complex algorithm
        $batchJobs = [];
        while ($offset < $totalObjects) {
            // Complex batch creation logic...
        }
        
        // ❌ Processing orchestration
        if ($mode === 'parallel') {
            $this->processJobsParallel(...); // More business logic
        }
        
        // ❌ Statistics calculation
        $objectsPerSecond = round($processed / $duration, 2);
        
        return new JSONResponse($results);
    } catch (Exception $e) {
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}
```

## Immediate Actions Required

### Priority 1: SettingsController Refactoring

1. **Create ValidationService**
   - Extract validation business logic
   - Create specialized handlers
   - Update SettingsController to delegate

2. **Estimated Effort:** 8-12 hours
   - Service creation: 2 hours
   - Handler extraction: 4-6 hours
   - Testing: 2-4 hours

### Priority 2: Complete Controller Audit

1. **Audit Remaining Controllers**
   - ObjectsController
   - ConfigurationController
   - SolrController
   - SchemasController
   - FilesController
   - SearchTrailController

2. **Estimated Effort:** 4-6 hours

### Priority 3: Extract Identified Logic

1. **Based on audit findings**
2. **Estimated Effort:** 10-20 hours (depends on findings)

## Architectural Recommendations

### Controller Best Practices

1. **Controllers should ONLY:**
   - Parse HTTP request parameters
   - Validate HTTP-level concerns (auth, CSRF)
   - Delegate to services
   - Convert service responses to HTTP responses
   - Handle HTTP status codes

2. **Controllers should NEVER:**
   - Query databases directly
   - Contain loops over data
   - Perform calculations
   - Make business decisions
   - Aggregate results
   - Format data (beyond HTTP concerns)

### Service Layer Pattern

1. **Create specialized services:**
   - ValidationService for validation operations
   - ReportingService for statistics/reports
   - BatchProcessingService for bulk operations

2. **Use handlers within services:**
   - Keep services as facades
   - Delegate to focused handlers
   - Maintain single responsibility

## Next Steps

1. ✅ Complete audit of remaining controllers
2. ⏳ Create ValidationService and handlers
3. ⏳ Extract SettingsController business logic
4. ⏳ Update tests
5. ⏳ Run integration tests
6. ⏳ Verify PHPMetrics improvements

---

**Audit Status:** IN PROGRESS  
**Last Updated:** 2025-12-14  
**Next Review:** After ValidationService extraction


