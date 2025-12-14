# IndexService Architecture

## Overview

`IndexService` is the main entry point for all search indexing operations in OpenRegister. It acts as a **facade** that coordinates specialized handlers.

---

## Architecture Layers

```
┌─────────────────────────────────────────────────────────────┐
│                     CONTROLLERS / OTHER SERVICES             │
│  - SettingsController                                       │
│  - ObjectsController                                        │
│  - SearchController                                         │
│  - Event Listeners                                          │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ calls
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        IndexService                          │
│                    (Facade / Coordinator)                    │
│                                                              │
│  Provides unified API:                                      │
│  - indexFileChunks()                                        │
│  - processUnindexedChunks()                                 │
│  - searchObjects()                                          │
│  - mirrorSchemas()                                          │
│  - getDashboardStats()                                      │
│  - etc.                                                     │
└─────────────────────────────────────────────────────────────┘
            │                  │                  │
            │ delegates        │ delegates        │ delegates
            ▼                  ▼                  ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│   FileHandler    │  │  ObjectHandler   │  │  SchemaHandler   │
│                  │  │                  │  │                  │
│ - File/chunk     │  │ - Object search  │  │ - Schema mgmt    │
│   indexing       │  │ - Commit         │  │ - Field types    │
│ - Stats          │  │ - Query building │  │ - Mirroring      │
└──────────────────┘  └──────────────────┘  └──────────────────┘
            │                  │                  │
            │ uses             │ uses             │ uses
            ▼                  ▼                  ▼
┌─────────────────────────────────────────────────────────────┐
│              SearchBackendInterface                          │
│         (Solr / Elasticsearch / PostgreSQL)                  │
└─────────────────────────────────────────────────────────────┘
```

---

## IndexService Responsibilities

### 1. **Facade Pattern**
Provides a simplified, unified API for indexing operations:

```php
// Instead of:
$fileHandler = $container->get(FileHandler::class);
$result = $fileHandler->processUnindexedChunks(100);

// Use:
$indexService = $container->get(IndexService::class);
$result = $indexService->processUnindexedChunks(100);
```

### 2. **Delegation**
Delegates to specialized handlers:
- **File operations** → `FileHandler`
- **Object operations** → `ObjectHandler`
- **Schema operations** → `SchemaHandler`

### 3. **Unified Statistics**
Combines statistics from all handlers:

```php
$stats = $indexService->getDashboardStats();
// Returns:
// - Backend stats (Solr health, document count)
// - File stats (indexed files, file collection)
// - Chunk stats (total chunks, indexed chunks)
```

### 4. **Error Handling**
Provides consistent error handling across all operations:

```php
try {
    $result = $indexService->mirrorSchemas();
} catch (Exception $e) {
    // Consistent error format
}
```

---

## API Overview

### File Operations

```php
// Index file chunks
$indexService->indexFileChunks(
    fileId: 123,
    chunks: $chunks,
    metadata: ['file_name' => 'document.pdf']
);

// Process unindexed chunks
$indexService->processUnindexedChunks(limit: 100);

// Get file statistics
$stats = $indexService->getFileStats();

// Get chunking statistics
$chunkStats = $indexService->getChunkingStats();
```

### Object Operations

```php
// Search objects
$results = $indexService->searchObjects(
    query: ['q' => 'search term'],
    rbac: true,
    multitenancy: true
);

// Commit changes
$indexService->commit();
```

### Schema Operations

```php
// Ensure vector field type
$indexService->ensureVectorFieldType(
    collection: 'objects',
    dimensions: 4096,
    similarity: 'cosine'
);

// Mirror schemas
$result = $indexService->mirrorSchemas(force: false);

// Get field status
$status = $indexService->getCollectionFieldStatus('objects');

// Create missing fields
$result = $indexService->createMissingFields(
    collection: 'objects',
    missingFields: $fields,
    dryRun: true
);
```

### General Operations

```php
// Check availability
$available = $indexService->isAvailable();

// Test connection
$testResults = $indexService->testConnection();

// Get statistics
$stats = $indexService->getStats();

// Get dashboard stats (unified)
$dashboardStats = $indexService->getDashboardStats();

// Optimize index
$indexService->optimize();

// Clear index
$indexService->clearIndex('objects');

// Get configuration
$config = $indexService->getConfig();
```

---

## Usage Examples

### Example 1: Index New File Chunks

```php
use OCA\OpenRegister\Service\IndexService;

class FileUploadListener
{
    public function __construct(
        private readonly IndexService $indexService,
        private readonly ChunkMapper $chunkMapper
    ) {}
    
    public function handle(FileUploadedEvent $event): void
    {
        $fileId = $event->getFileId();
        
        // TextExtractionService has already created chunks in database
        // Now we just index them
        $chunks = $this->chunkMapper->findBySource('file', $fileId);
        
        $result = $this->indexService->indexFileChunks(
            fileId: $fileId,
            chunks: $chunks,
            metadata: [
                'file_name' => $event->getFileName(),
                'file_type' => $event->getMimeType(),
            ]
        );
        
        // Log result
        if ($result['success']) {
            $this->logger->info("Indexed {$result['indexed']} chunks");
        }
    }
}
```

### Example 2: Search Objects from Controller

```php
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Http\JSONResponse;

class SearchController extends Controller
{
    public function __construct(
        private readonly IndexService $indexService
    ) {}
    
    public function search(string $q): JSONResponse
    {
        try {
            $results = $this->indexService->searchObjects(
                query: ['q' => $q, 'rows' => 20],
                rbac: true,
                multitenancy: true,
                published: true
            );
            
            return new JSONResponse($results);
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}
```

### Example 3: Dashboard Statistics

```php
use OCA\OpenRegister\Service\IndexService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly IndexService $indexService
    ) {}
    
    public function indexStats(): JSONResponse
    {
        $stats = $this->indexService->getDashboardStats();
        
        return new JSONResponse([
            'search_backend_available' => $stats['available'],
            'total_documents' => $stats['backend']['document_count'] ?? 0,
            'file_documents' => $stats['files']['document_count'] ?? 0,
            'total_chunks' => $stats['chunks']['total_chunks'] ?? 0,
            'indexed_chunks' => $stats['chunks']['indexed_chunks'] ?? 0,
        ]);
    }
}
```

### Example 4: Schema Synchronization

```php
use OCA\OpenRegister\Service\IndexService;

class SchemaChangedListener
{
    public function __construct(
        private readonly IndexService $indexService
    ) {}
    
    public function handle(SchemaChangedEvent $event): void
    {
        // Mirror updated schemas to search backend
        $result = $this->indexService->mirrorSchemas(force: false);
        
        $this->logger->info(
            'Schema mirrored',
            [
                'schemas_processed' => $result['stats']['schemas_processed'],
                'fields_created' => $result['stats']['fields_created'],
            ]
        );
    }
}
```

---

## Benefits of IndexService Facade

### 1. **Single Entry Point**
Controllers and services only need to inject `IndexService`, not multiple handlers.

**Before (without facade):**
```php
public function __construct(
    private readonly FileHandler $fileHandler,
    private readonly ObjectHandler $objectHandler,
    private readonly SchemaHandler $schemaHandler
) {}
```

**After (with facade):**
```php
public function __construct(
    private readonly IndexService $indexService
) {}
```

### 2. **Simplified API**
One service provides all indexing operations with consistent interface.

### 3. **Easier Testing**
Mock one service instead of three:

```php
$mockIndexService = $this->createMock(IndexService::class);
$mockIndexService->method('searchObjects')
    ->willReturn(['results' => []]);
```

### 4. **Flexibility**
Can add new handlers without changing client code:

```php
// Add new VectorHandler in the future
class IndexService {
    public function __construct(
        private readonly FileHandler $fileHandler,
        private readonly ObjectHandler $objectHandler,
        private readonly SchemaHandler $schemaHandler,
        private readonly VectorHandler $vectorHandler  // New handler
    ) {}
}
```

### 5. **Unified Error Handling**
Consistent error handling and logging across all operations.

### 6. **Cross-Cutting Concerns**
Easy to add logging, metrics, caching at the facade level:

```php
public function searchObjects(array $query): array
{
    $startTime = microtime(true);
    
    try {
        $results = $this->objectHandler->searchObjects($query);
        
        $this->metrics->recordSearchTime(
            microtime(true) - $startTime
        );
        
        return $results;
    } catch (Exception $e) {
        $this->logger->error('Search failed', ['error' => $e]);
        throw $e;
    }
}
```

---

## Dependency Injection

### appinfo/info.xml
```xml
<dependencies>
    <lib>openregister/index-service</lib>
</dependencies>
```

### lib/AppInfo/Application.php
```php
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\FileHandler;
use OCA\OpenRegister\Service\Index\ObjectHandler;
use OCA\OpenRegister\Service\Index\SchemaHandler;

public function register(IRegistrationContext $context): void
{
    // Register handlers
    $context->registerService(FileHandler::class, function ($c) {
        return new FileHandler(
            $c->get(SettingsService::class),
            $c->get(LoggerInterface::class),
            $c->get(ChunkMapper::class),
            $c->get(SearchBackendInterface::class)
        );
    });
    
    $context->registerService(ObjectHandler::class, function ($c) {
        return new ObjectHandler(
            $c->get(SettingsService::class),
            $c->get(SchemaMapper::class),
            $c->get(RegisterMapper::class),
            $c->get(LoggerInterface::class),
            $c->get(SearchBackendInterface::class)
        );
    });
    
    $context->registerService(SchemaHandler::class, function ($c) {
        return new SchemaHandler(
            $c->get(SchemaMapper::class),
            $c->get(SettingsService::class),
            $c->get(LoggerInterface::class),
            $c->get(IConfig::class),
            $c->get(SearchBackendInterface::class)
        );
    });
    
    // Register IndexService (facade)
    $context->registerService(IndexService::class, function ($c) {
        return new IndexService(
            $c->get(FileHandler::class),
            $c->get(ObjectHandler::class),
            $c->get(SchemaHandler::class),
            $c->get(SearchBackendInterface::class),
            $c->get(LoggerInterface::class)
        );
    });
}
```

---

## Migration Path

### Phase 1: Current State ✅
- Created FileHandler, ObjectHandler, SchemaHandler
- Created IndexService facade
- Handlers properly separated

### Phase 2: Update GuzzleSolrService (Next)
- Inject IndexService into GuzzleSolrService
- Replace GuzzleSolrService methods with IndexService calls
- Keep GuzzleSolrService as thin wrapper during migration

### Phase 3: Direct Usage
- Update controllers to use IndexService directly
- Remove GuzzleSolrService wrapper
- Update event listeners to use IndexService

### Phase 4: Complete
- All code uses IndexService
- Clean, unified API
- GuzzleSolrService removed or renamed

---

## Summary

**IndexService** is the main facade for search indexing operations:

- ✅ **Single entry point** for all indexing operations
- ✅ **Delegates** to specialized handlers
- ✅ **Unified API** for controllers and services
- ✅ **Consistent error handling**
- ✅ **Simplified dependency injection**
- ✅ **Easy to test** (mock one service)
- ✅ **Flexible** (add handlers without breaking clients)

**Usage Pattern:**
```php
// Inject IndexService
$indexService = $container->get(IndexService::class);

// Use unified API
$indexService->processUnindexedChunks(100);
$indexService->searchObjects(['q' => 'test']);
$indexService->mirrorSchemas();
```


