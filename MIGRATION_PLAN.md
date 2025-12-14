# Legacy Solr Services Migration Plan

## Current Situation

Legacy services still exist and are being used:

### GuzzleSolrService (23 usages)
- Controllers: SettingsController, SearchController, SolrController, FileSearchController
- Services: SettingsService, ObjectService, ObjectCacheService, ChatService, VectorEmbeddingService
- Commands: SolrDebugCommand, SolrManagementCommand
- BackgroundJobs: SolrNightlyWarmupJob, SolrWarmupJob
- Setup: SolrSetup
- Tests: Multiple test files

### SolrFileService (7 usages)
- Application.php (DI)
- VectorEmbeddingService
- FileTextController
- SolrController
- TextExtraction/FileHandler.php
- Tests

### SolrObjectService (6 usages)
- Application.php (DI)
- ObjectService
- VectorEmbeddingService
- SolrController
- Tests

### SolrSchemaService (5 usages)
- Application.php (DI)
- SettingsController
- GuzzleSolrService
- SolrManagementCommand

---

## Migration Strategy

### Option A: Aggressive (Recommended)
**Delete all legacy services and migrate all usages to IndexService**

**Pros:**
- Clean codebase immediately
- Forces proper architecture
- No technical debt

**Cons:**
- More changes at once
- Need to update many files

### Option B: Conservative
**Keep GuzzleSolrService as thin wrapper around IndexService**

**Pros:**
- Less breaking changes
- Gradual migration
- Backwards compatible

**Cons:**
- Maintains technical debt
- Two ways to do same thing
- Confusing for developers

---

## Recommended Approach: Option A (Aggressive)

### Phase 1: Remove Small Services (SolrFile, SolrObject, SolrSchema)
These have fewer usages and should be removed first.

### Phase 2: Update GuzzleSolrService Usages
Replace all GuzzleSolrService usages with IndexService.

### Phase 3: Remove GuzzleSolrService
Delete the file once no usages remain.

---

## Detailed Migration Steps

### Step 1: Update Application.php (Dependency Injection)

**Remove:**
```php
$context->registerService(SolrFileService::class, ...);
$context->registerService(SolrObjectService::class, ...);
$context->registerService(SolrSchemaService::class, ...);
```

**Already have:**
```php
$context->registerService(IndexService::class, ...);
```

### Step 2: Update Controllers

**SettingsController:**
- Replace `GuzzleSolrService` → `IndexService`
- Replace `SolrSchemaService` → `IndexService`

**SolrController:**
- Replace `GuzzleSolrService` → `IndexService`
- Replace `SolrFileService` → `IndexService` (or TextExtractionService)
- Replace `SolrObjectService` → Remove (vectorization should use VectorizationService)

**SearchController:**
- Replace `GuzzleSolrService` → `IndexService`

**FileSearchController:**
- Replace `GuzzleSolrService` → `IndexService`

**FileTextController:**
- Replace `SolrFileService` → `TextExtractionService` (text extraction, not indexing)

### Step 3: Update Services

**SettingsService:**
- Replace `GuzzleSolrService` → `IndexService`

**ObjectService:**
- Replace `GuzzleSolrService` → `IndexService`
- Replace `SolrObjectService` → Remove (or use IndexService for search)

**VectorEmbeddingService:**
- Replace `SolrFileService` → Remove (shouldn't do file operations)
- Replace `SolrObjectService` → Remove (shouldn't do object operations)
- Replace `GuzzleSolrService` → `IndexService` (only if needed for search)

**ObjectCacheService:**
- Replace `GuzzleSolrService` → `IndexService`

**ChatService:**
- Replace `GuzzleSolrService` → `IndexService`

### Step 4: Update Commands

**SolrDebugCommand:**
- Replace `GuzzleSolrService` → `IndexService`

**SolrManagementCommand:**
- Replace `GuzzleSolrService` → `IndexService`
- Replace `SolrSchemaService` → `IndexService`

### Step 5: Update Background Jobs

**SolrNightlyWarmupJob:**
- Replace `GuzzleSolrService` → `IndexService`

**SolrWarmupJob:**
- Replace `GuzzleSolrService` → `IndexService`

### Step 6: Update Setup

**SolrSetup:**
- Replace `GuzzleSolrService` → `IndexService`

### Step 7: Update Tests

Update all test files to use IndexService and new handlers.

### Step 8: Delete Legacy Files

```bash
rm lib/Service/SolrFileService.php
rm lib/Service/SolrObjectService.php
rm lib/Service/SolrSchemaService.php
rm lib/Service/GuzzleSolrService.php
```

---

## Special Considerations

### TextExtraction/FileHandler.php
This file uses `SolrFileService` but should probably use `TextExtractionService` instead since it's in the TextExtraction folder.

### VectorEmbeddingService
This service uses SolrFile and SolrObject services but probably shouldn't - vectorization is separate from indexing.

---

## Execution Plan

1. ✅ Create IndexService and handlers
2. ⏳ Update Application.php (remove old DI)
3. ⏳ Update all controllers
4. ⏳ Update all services
5. ⏳ Update all commands
6. ⏳ Update all background jobs
7. ⏳ Update setup files
8. ⏳ Update tests
9. ⏳ Delete legacy files
10. ⏳ Run full test suite
11. ⏳ Update documentation


