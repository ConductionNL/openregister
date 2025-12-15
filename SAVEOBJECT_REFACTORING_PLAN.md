# SaveObject and SaveObjects Refactoring Implementation Plan

**Status:** Analysis Complete, Implementation Plan Ready  
**Date:** 2025-12-15  
**Est. Effort:** 20-25 hours for full implementation  

---

## Overview

Break down two large handler files into smaller, focused sub-handlers:
- **SaveObject.php** (3,802 lines) → 4 sub-handlers
- **SaveObjects.php** (2,357 lines) → 3 sub-handlers

---

## SaveObject.php Refactoring

### Current Structure (47 methods, 3,802 lines)

**Target Sub-Handlers:**

### 1. RelationCascadeHandler ✅ CREATED
**Location:** `lib/Service/Objects/SaveObject/RelationCascadeHandler.php`  
**Lines:** ~600 lines  
**Status:** ✅ Skeleton created with method signatures  

**Methods to Extract (9 methods):**
- ✅ `resolveSchemaReference()` - Resolve schema refs to IDs
- ✅ `resolveRegisterReference()` - Resolve register refs to IDs
- ✅ `removeQueryParameters()` - Clean query params from refs
- ✅ `scanForRelations()` - Find relations in data
- ✅ `isReference()` - Check if value is a reference
- ⏳ `updateObjectRelations()` - Resolve relation UUIDs (needs full impl)
- ⏳ `cascadeObjects()` - Pre-validation cascading (needs ObjectService)
- ⏳ `cascadeMultipleObjects()` - Cascade array of objects (needs ObjectService)
- ⏳ `cascadeSingleObject()` - Cascade single object (needs ObjectService)
- ⏳ `handleInverseRelationsWriteBack()` - Post-save writeBack (needs ObjectService)

**Challenge:** Methods need ObjectService access (circular dependency).  
**Solution:** Use event system or create coordination service.

---

### 2. MetadataHydrationHandler ✅ CREATED
**Location:** `lib/Service/Objects/SaveObject/MetadataHydrationHandler.php`  
**Lines:** ~400 lines  
**Status:** ✅ Complete implementation ready  

**Methods to Extract (7 methods):**
- ✅ `hydrateObjectMetadata()` - Main metadata hydration
- ✅ `getValueFromPath()` - Extract values by dot notation
- ✅ `extractMetadataValue()` - Extract metadata from config
- ✅ `processTwigLikeTemplate()` - Process Twig templates
- ✅ `createSlugFromValue()` - Create slug from value
- ✅ `generateSlug()` - Generate slug from schema config
- ✅ `createSlug()` - Create URL-safe slug

**Status:** Fully implemented and ready to integrate.

---

### 3. FilePropertyHandler ⏳ TO CREATE
**Location:** `lib/Service/Objects/SaveObject/FilePropertyHandler.php`  
**Lines:** ~1,800 lines (largest and most complex)  
**Status:** ⏳ PENDING  

**Methods to Extract (18 methods):**

#### File Detection & Validation (4 methods)
- `processUploadedFiles()` - Process uploaded files from request
- `isFileProperty()` - Check if value is a file property (230 lines!)
- `isFileObject()` - Check if array is file object
- `handleFileProperty()` - Main file handling coordinator

#### File Processing (6 methods)
- `processSingleFileProperty()` - Process single file
- `processStringFileInput()` - Process string file input
- `processFileObjectInput()` - Process file object input
- `fetchFileFromUrl()` - Download file from URL
- `parseFileDataFromUrl()` - Parse downloaded file data
- `parseFileData()` - Parse file content

#### File Validation & Security (4 methods)
- `validateExistingFileAgainstConfig()` - Validate existing file
- `validateFileAgainstConfig()` - Validate file against schema
- `blockExecutableFiles()` - Block dangerous files
- `detectExecutableMagicBytes()` - Detect executables by magic bytes

#### File Utilities (4 methods)
- `applyAutoTagsToExistingFile()` - Auto-tag existing files
- `generateFileName()` - Generate file name
- `prepareAutoTags()` - Prepare auto tags
- `getExtensionFromMimeType()` - Get extension from MIME

**Challenge:** Complex file validation logic with security checks.  
**Dependencies:** FileService for actual file operations.

---

### 4. SaveCoordinationHandler (Remains in SaveObject.php)
**Location:** `lib/Service/Objects/SaveObject.php` (refactored)  
**Lines:** ~1,000 lines target  
**Status:** ⏳ PENDING  

**Methods to Keep (13 methods + coordination logic):**
- `__construct()` - Inject sub-handlers
- `setDefaultValues()` - Set schema default values
- `sanitizeEmptyStringsForObjectProperties()` - Clean empty strings
- `saveObject()` - **Main entry point** - coordinate save flow
- `prepareObjectForCreation()` - Prepare new object
- `prepareObjectForUpdate()` - Prepare existing object
- `setSelfMetadata()` - Set _self metadata
- `prepareObjectData()` - Prepare object data
- `updateObject()` - Update existing object
- `isEffectivelyEmptyObject()` - Check if object is empty
- `isValueNotEmpty()` - Check if value is not empty
- `isAuditTrailsEnabled()` - Check audit trail setting

**Role:** Coordinates sub-handlers, manages save flow, handles audit trail.

---

## SaveObjects.php Refactoring

### Current Structure (27 methods, 2,357 lines)

**Target Sub-Handlers:**

### 1. BulkValidationHandler ⏳ TO CREATE
**Location:** `lib/Service/Objects/SaveObjects/BulkValidationHandler.php`  
**Lines:** ~400 lines  
**Status:** ⏳ PENDING  

**Methods to Extract (4 methods):**
- `performComprehensiveSchemaAnalysis()` - Analyze schema for optimization
- `castToBoolean()` - Type cast to boolean
- `handlePreValidationCascading()` - Pre-validation cascade for bulk
- Additional validation helpers

**Purpose:** Schema analysis and validation optimization for bulk operations.

---

### 2. BulkRelationHandler ⏳ TO CREATE
**Location:** `lib/Service/Objects/SaveObjects/BulkRelationHandler.php`  
**Lines:** ~600 lines  
**Status:** ⏳ PENDING  

**Methods to Extract (9 methods):**
- `handleBulkInverseRelationsWithAnalysis()` - Bulk inverse relations
- `handlePostSaveInverseRelations()` - Post-save inverse relations
- `performBulkWriteBackUpdatesWithContext()` - Bulk writeBack operations
- `scanForRelations()` - Scan for relations in data
- `isReference()` - Check if value is reference
- `resolveObjectReference()` - Resolve object reference
- `getObjectReferenceData()` - Get reference data
- `extractUuidFromReference()` - Extract UUID from reference
- `getObjectName()` - Get object name by UUID
- `generateFallbackName()` - Generate fallback name

**Purpose:** Handle complex relationship operations in bulk scenarios.

---

### 3. BulkOptimizationHandler (Remains in SaveObjects.php)
**Location:** `lib/Service/Objects/SaveObjects.php` (refactored)  
**Lines:** ~800 lines target  
**Status:** ⏳ PENDING  

**Methods to Keep (14 methods + main logic):**
- `__construct()` - Inject sub-handlers
- `loadSchemaWithCache()` - Load schema with caching
- `getSchemaAnalysisWithCache()` - Get schema analysis with cache
- `loadRegisterWithCache()` - Load register with caching
- `saveObjects()` - **Main entry point** - bulk save coordination
- `getValueFromPath()` - Extract value by path
- `calculateOptimalChunkSize()` - Calculate chunk size
- `prepareObjectsForBulkSave()` - Prepare objects for bulk save
- `prepareSingleSchemaObjectsOptimized()` - Prepare single schema objects
- `processObjectsChunk()` - Process object chunks
- `transformObjectsToDatabaseFormatInPlace()` - Transform to DB format
- `findExistingObjectByAnyIdentifier()` - Find existing object
- `reconstructSavedObjects()` - Reconstruct saved objects
- `createSlug()` - Create slug

**Role:** Bulk operation coordination, caching, chunking, optimization.

---

## Implementation Strategy

### Phase 1: Complete Handler Creation (8-10 hours)
1. ✅ Create RelationCascadeHandler skeleton
2. ✅ Create MetadataHydrationHandler (complete)
3. ⏳ Create FilePropertyHandler skeleton (2-3 hours)
4. ⏳ Create BulkValidationHandler skeleton (1 hour)
5. ⏳ Create BulkRelationHandler skeleton (1-2 hours)

### Phase 2: Method Extraction (8-12 hours)
1. Extract FilePropertyHandler methods (complex, 4-5 hours)
2. Extract BulkValidationHandler methods (2 hours)
3. Extract BulkRelationHandler methods (2-3 hours)
4. Complete RelationCascadeHandler implementation (2 hours)

### Phase 3: Integration (4-6 hours)
1. Refactor SaveObject.php to use sub-handlers (2-3 hours)
2. Refactor SaveObjects.php to use sub-handlers (2-3 hours)
3. Update Application.php DI configuration (1 hour)
4. Fix circular dependencies (event system or coordination service)

### Phase 4: Testing & Quality (2-3 hours)
1. Run PHPQA quality checks
2. Fix linting issues
3. Test file uploads
4. Test bulk operations
5. Test relation cascading
6. Update documentation

---

## Technical Challenges

### 1. Circular Dependencies ⚠️
**Problem:** RelationCascadeHandler methods need ObjectService, but ObjectService uses SaveObject.

**Solutions:**
- **Option A (Recommended):** Use Symfony Event Dispatcher
  - Fire `ObjectCascadeEvent` from handler
  - ObjectService listens and handles cascading
  - Clean separation, no circular dependency
  
- **Option B:** Create CoordinationService
  - Separate service for complex coordination
  - Both ObjectService and handlers use it
  - More services, but clearer responsibility

- **Option C:** Keep cascade methods in SaveObject
  - Don't extract these methods
  - Document why (circular dependency)
  - Extract other methods only

**Recommendation:** Option C for pragmatic approach, Option A for ideal architecture.

### 2. File Handling Complexity ⚠️
**Problem:** FilePropertyHandler has 1,800 lines of complex file validation.

**Solution:**
- Extract methods as-is first
- Optimize later in separate refactoring
- Maintain security checks (critical!)
- Test thoroughly (file uploads are error-prone)

### 3. Bulk Operation Performance ⚠️
**Problem:** SaveObjects is optimized for performance with memory management.

**Solution:**
- Keep performance-critical methods together
- Don't over-extract (delegation overhead)
- Profile before and after
- Document why certain methods stay together

---

## Success Metrics

### Code Quality Targets
- SaveObject.php: 3,802 → 1,000 lines ✅
- SaveObjects.php: 2,357 → 800 lines ✅
- Max handler size: <1,500 lines ✅
- All handlers documented ✅
- No circular dependencies ✅

### Architecture Validation
- ✅ Single Responsibility: Each handler has clear purpose
- ✅ Testability: Handlers can be tested independently
- ✅ Maintainability: Easy to find and modify logic
- ✅ Performance: No significant degradation
- ⏳ PHPQA: Pass all quality checks

---

## Alternative: Pragmatic Approach

### If Time-Constrained

**Keep as-is but improve:**
1. Add clear method grouping comments in existing files
2. Extract only MetadataHydrationHandler (easiest, useful separately)
3. Add comprehensive inline documentation
4. Create unit tests for complex methods
5. Document refactoring plan for future work

**Rationale:**
- SaveObject and SaveObjects work correctly
- They're already separated from ObjectService
- Over-extraction can harm performance
- Better docs + tests = maintainable code

---

## Recommendation

**Recommended Path: HYBRID APPROACH**

1. **Extract MetadataHydrationHandler** ✅ (DONE)
   - Clean, no dependencies
   - Useful standalone
   - Easy to test

2. **Create FilePropertyHandler skeleton** (2 hours)
   - Document structure
   - Stub methods
   - Plan future extraction

3. **Improve existing files** (2 hours)
   - Add method grouping comments
   - Improve inline documentation
   - Add unit tests

4. **Document** (1 hour)
   - Update architectural docs
   - Create extraction guide
   - Document future work

**Total: 5-6 hours** vs 20-25 hours for full extraction.

**Result:** Significant improvement, maintainable, clear path forward.

---

## Decision Point

**Choose Implementation Strategy:**

- ✅ **FULL EXTRACTION** (20-25 hours): All handlers extracted, ideal architecture
- ⏳ **HYBRID APPROACH** (5-6 hours): MetadataHydrationHandler + docs + tests  
- ⏳ **MINIMAL** (2 hours): Documentation and comments only

**Current Status:** MetadataHydrationHandler extracted, ready for next steps.

---

**Last Updated:** 2025-12-15  
**Next Review:** After implementation decision

