# Solr Services Integration Plan

**Date:** 2025-12-14  
**Objective:** Consolidate Solr services into unified Index handler architecture  
**Target:** Prepare for GuzzleSolrService → IndexService rename  

---

## Current State

### Existing Solr Services

| Service | Lines | Purpose | Target Handler |
|---------|-------|---------|----------------|
| GuzzleSolrService | 11,728 | Core Solr operations | Multiple handlers (already planned) |
| SolrFileService | 1,289 | File text extraction & chunking | FileHandler |
| SolrObjectService | 596 | Object vectorization | VectorizationHandler |
| SolrSchemaService | 1,865 | Schema field management | SchemaHandler |
| **TOTAL** | **15,478** | | **Unified Index structure** |

---

## Integration Strategy

### Phase 1: Analyze Service Responsibilities

#### SolrFileService (1,289 lines)
**Methods:**
- `extractTextFromFile()` - Extract text from various file formats
- `extractFromPdf()`, `extractFromDocx()`, etc. - Format-specific extraction
- `chunkDocument()` - Intelligent text chunking
- `chunkFixedSize()`, `chunkRecursive()` - Chunking strategies
- `indexFileChunks()` - Index chunks to Solr
- `processExtractedFiles()` - Bulk file processing
- `getFileStats()`, `getChunkingStats()` - Statistics

**Target:** `lib/Service/Index/FileHandler.php`

#### SolrObjectService (596 lines)
**Methods:**
- `convertObjectToText()` - Convert ObjectEntity to searchable text
- `convertObjectsToText()` - Bulk conversion
- `extractTextFromArray()` - Extract text from nested arrays
- `vectorizeObject()` - Generate embeddings for object
- `vectorizeObjects()` - Bulk vectorization
- `searchObjects()` - Search with vectorization context
- `commit()` - Commit changes

**Target:** Split between:
- `lib/Service/Index/VectorizationHandler.php` (vectorization methods)
- `lib/Service/Index/IndexingHandler.php` (text conversion for indexing)

#### SolrSchemaService (1,865 lines)
**Methods:**
- `ensureVectorFieldType()` - Ensure vector field support
- `mirrorSchemas()` - Sync OpenRegister schemas to Solr
- `analyzeAndResolveFieldConflicts()` - Handle field type conflicts
- `determineSolrFieldType()` - Map OpenRegister types to Solr types
- `ensureCoreMetadataFields()` - Ensure standard fields exist
- `getObjectCollectionFieldStatus()` - Check field status
- `createMissingFields()` - Add missing fields to Solr
- `addOrUpdateSolrField()` - Field CRUD operations

**Target:** `lib/Service/Index/SchemaHandler.php` (already planned in inventory)

---

## Proposed Handler Structure

```
lib/Service/Index/
├── ConfigurationHandler.php        ✅ (Already created - 21 methods)
├── AdminHandler.php                ⏳ (Planned - 28 methods from GuzzleSolrService)
├── QueryHandler.php                ⏳ (Planned - 38 methods from GuzzleSolrService)
├── IndexingHandler.php             ⏳ (Planned - 32 methods from GuzzleSolrService)
│   └── + Object text conversion from SolrObjectService
├── SchemaHandler.php               ⏳ (Planned - 35 methods from GuzzleSolrService)
│   └── + Schema management from SolrSchemaService
├── WarmupHandler.php               ⏳ (Planned - 14 methods from GuzzleSolrService)
├── FileHandler.php                 🆕 (NEW - File operations from SolrFileService)
└── VectorizationHandler.php        🆕 (NEW - Vectorization from SolrObjectService)
```

---

## Integration Steps

### Step 1: Create FileHandler

**Source:** SolrFileService (1,289 lines)  
**Target:** `lib/Service/Index/FileHandler.php`  
**Effort:** 3-4 hours  

**Methods to migrate:**
```
Text Extraction (9 methods):
- extractTextFromFile()
- extractFromTextFile(), extractFromHtml(), extractFromPdf()
- extractFromDocx(), extractFromXlsx(), extractFromPptx()
- extractFromImage(), extractFromJson(), extractFromXml()
- jsonToText(), commandExists()

Chunking (5 methods):
- chunkDocument()
- cleanText(), calculateAvgChunkSize()
- chunkFixedSize(), chunkRecursive(), recursiveSplit()

Indexing & Stats (5 methods):
- indexFileChunks()
- processExtractedFiles(), processExtractedFile()
- getFileStats(), getChunkingStats()
```

### Step 2: Create VectorizationHandler

**Source:** SolrObjectService (596 lines)  
**Target:** `lib/Service/Index/VectorizationHandler.php`  
**Effort:** 2-3 hours  

**Methods to migrate:**
```
Text Conversion (3 methods):
- convertObjectToText()
- convertObjectsToText()
- extractTextFromArray()

Vectorization (3 methods):
- vectorizeObject()
- vectorizeObjects()
- getProviderOrDefault()

Search Integration (2 methods):
- searchObjects()
- commit()
```

### Step 3: Enhance SchemaHandler

**Source:** SolrSchemaService (1,865 lines)  
**Target:** `lib/Service/Index/SchemaHandler.php`  
**Effort:** 6-8 hours  

**Methods to add (on top of existing 35 from GuzzleSolrService):**
```
Vector Support (1 method):
- ensureVectorFieldType()

Schema Mirroring (3 methods):
- mirrorSchemas()
- analyzeAndResolveFieldConflicts()
- getMostPermissiveType()

Field Type Mapping (6 methods):
- generateSolrFieldName()
- determineSolrFieldType()
- isMultiValued()
- isCoreFieldMultiValued()
- isFileFieldMultiValued()
- shouldCoreFieldBeIndexed(), shouldFileFieldBeIndexed()
- shouldCoreFieldHaveDocValues(), shouldFileFieldHaveDocValues()

Core Fields (1 method):
- ensureCoreMetadataFields()

Field Status (2 methods):
- getObjectCollectionFieldStatus()
- getFileCollectionFieldStatus()

Field Operations (5 methods):
- getCurrentCollectionFields()
- applySolrFields()
- addOrUpdateSolrField()
- makeSolrSchemaRequest()
- createMissingFields()
- addFieldToCollection()
```

### Step 4: Update GuzzleSolrService/IndexService

**Actions:**
1. Inject new handlers (FileHandler, VectorizationHandler)
2. Add delegation methods for migrated functionality
3. Update existing code to use handlers
4. Mark old service references as deprecated

### Step 5: Update Dependencies

**Files to update:**
```
Controllers using Solr services:
- SolrController
- FilesController
- ObjectsController (if using vectorization)

Services depending on Solr services:
- FileService
- ObjectService
- Any vectorization consumers
```

### Step 6: Remove Old Services

**After migration complete:**
1. Verify all references updated
2. Run tests
3. Delete:
   - `lib/Service/SolrFileService.php`
   - `lib/Service/SolrObjectService.php`
   - `lib/Service/SolrSchemaService.php`

---

## Migration Priority

### High Priority (Do First)

1. **FileHandler** - Isolated, clear responsibility, no complex dependencies
2. **SchemaHandler enhancement** - Already planned, adds schema management
3. **VectorizationHandler** - Relatively small, clear purpose

### Medium Priority (After handlers)

4. **Update GuzzleSolrService** - Add handler delegation
5. **Update dependent code** - Controllers and services

### Low Priority (After everything works)

6. **Remove old services** - Clean up after migration verified

---

## Detailed Migration: FileHandler Example

### Create FileHandler

```php
// lib/Service/Index/FileHandler.php
namespace OCA\OpenRegister\Service\Index;

class FileHandler {
    public function __construct(
        private readonly GuzzleSolrService $guzzleSolrService,
        private readonly SettingsService $settingsService,
        private readonly IAppContainer $container,
        private readonly LoggerInterface $logger,
        private readonly ChunkMapper $chunkMapper,
    ) {}
    
    // All methods from SolrFileService migrate here
    public function extractTextFromFile(string $filePath): string { ... }
    public function chunkDocument(string $text, array $options=[]): array { ... }
    public function indexFileChunks(...): array { ... }
    // etc.
}
```

### Update GuzzleSolrService

```php
// lib/Service/GuzzleSolrService.php
class GuzzleSolrService {
    public function __construct(
        // ... existing deps ...
        private readonly FileHandler $fileHandler,
    ) {}
    
    // Delegation methods
    public function extractTextFromFile(string $filePath): string {
        return $this->fileHandler->extractTextFromFile($filePath);
    }
    
    public function chunkDocument(string $text, array $options=[]): array {
        return $this->fileHandler->chunkDocument($text, $options);
    }
}
```

### Deprecate SolrFileService

```php
// lib/Service/SolrFileService.php
/**
 * @deprecated Use GuzzleSolrService->fileHandler or Index\FileHandler directly
 */
class SolrFileService {
    public function __construct(
        private readonly GuzzleSolrService $guzzleSolrService,
    ) {}
    
    /**
     * @deprecated Use GuzzleSolrService->extractTextFromFile()
     */
    public function extractTextFromFile(string $filePath): string {
        return $this->guzzleSolrService->extractTextFromFile($filePath);
    }
}
```

### Later: Remove SolrFileService

After all references updated, delete the file.

---

## Benefits of Integration

### Code Organization
- ✅ All Solr operations in one place
- ✅ Clear handler responsibilities
- ✅ Consistent architecture pattern

### Maintainability
- ✅ Easier to find Solr-related code
- ✅ No confusion about which service to use
- ✅ Single source of truth for Solr operations

### Future Flexibility
- ✅ Easy to add new backends (Elasticsearch, PostgreSQL)
- ✅ Handlers can be swapped/extended
- ✅ Clean abstraction via SearchBackendInterface

### Testing
- ✅ Handlers are independently testable
- ✅ Can mock handlers for unit tests
- ✅ Integration tests focus on IndexService facade

---

## Risks & Mitigation

### Risk 1: Breaking Existing Code
**Impact:** High  
**Mitigation:**
- Keep old services initially as deprecated wrappers
- Update references incrementally
- Comprehensive testing before removal

### Risk 2: Complex Dependencies
**Impact:** Medium  
**Mitigation:**
- Map all dependencies before migration
- Update DI container configuration
- Use phased approach

### Risk 3: Lost Functionality
**Impact:** High  
**Mitigation:**
- Complete method inventory
- Test coverage for all methods
- Side-by-side comparison during migration

---

## Testing Strategy

### Unit Tests
- Test each handler independently
- Mock dependencies
- Cover all migrated methods

### Integration Tests
- Test GuzzleSolrService/IndexService facade
- Verify handler coordination
- End-to-end Solr operations

### Regression Tests
- Test existing functionality still works
- Compare results before/after migration
- Monitor performance metrics

---

## Timeline Estimate

| Phase | Effort | Duration |
|-------|--------|----------|
| FileHandler creation | 3-4 hrs | 1 day |
| VectorizationHandler creation | 2-3 hrs | 1 day |
| SchemaHandler enhancement | 6-8 hrs | 2 days |
| GuzzleSolrService updates | 4-6 hrs | 1 day |
| Dependency updates | 4-6 hrs | 1 day |
| Testing & verification | 8-10 hrs | 2 days |
| Old service removal | 2-3 hrs | 1 day |
| **TOTAL** | **29-40 hrs** | **1.5-2 weeks** |

---

## Success Criteria

- [ ] All Solr services integrated into Index handlers
- [ ] No direct usage of old Solr services
- [ ] All tests passing
- [ ] No regressions in functionality
- [ ] PHPMetrics shows improvement
- [ ] Documentation updated
- [ ] Old services removed

---

## Next Actions

1. **Immediate:** Create FileHandler as proof of concept
2. **Next:** Create VectorizationHandler
3. **Then:** Enhance SchemaHandler with SolrSchemaService methods
4. **Finally:** Update GuzzleSolrService, update references, remove old services

---

**Plan Status:** READY FOR IMPLEMENTATION  
**Start With:** FileHandler (lowest risk, highest clarity)  
**Last Updated:** 2025-12-14


