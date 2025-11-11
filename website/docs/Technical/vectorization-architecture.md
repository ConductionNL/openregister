# Vectorization Architecture

## Overview

The OpenRegister app uses a unified vectorization architecture based on the **Strategy Pattern** to eliminate code duplication and provide a consistent API for vectorizing different entity types (files, objects, etc.).

## Architecture

### Core Components

```
VectorizationService (Generic Core)
    ├── VectorEmbeddingService (generates embeddings)
    └── Strategies (entity-specific logic):
        ├── FileVectorizationStrategy
        ├── ObjectVectorizationStrategy
        └── [Future strategies...]
```

### 1. VectorizationService

**Location:** 'lib/Service/VectorizationService.php'

**Responsibilities:**
- Batch processing with error handling
- Serial and parallel mode support
- Progress tracking
- Embedding generation coordination
- Vector storage coordination

**Key Method:**
```php
public function vectorizeBatch(string $entityType, array $options): array
```

### 2. VectorizationStrategyInterface

**Location:** 'lib/Service/Vectorization/VectorizationStrategyInterface.php'

**Contract:**
- 'fetchEntities()' - Get entities to vectorize
- 'extractVectorizationItems()' - Extract text items from entity
- 'prepareVectorMetadata()' - Prepare metadata for storage
- 'getEntityIdentifier()' - Get entity ID for logging

### 3. Strategies

#### FileVectorizationStrategy

**Location:** 'lib/Service/Vectorization/FileVectorizationStrategy.php'

**File-specific logic:**
- Fetches files with completed extractions
- Filters by MIME type
- Extracts pre-chunked text from 'chunks_json'
- Handles multiple chunks per file (1 file = N vectors)

**Options:**
- 'max_files' - Maximum files to process (0 = all)
- 'file_types' - Array of MIME types to filter
- 'batch_size' - Chunks per batch
- 'mode' - 'serial' or 'parallel'

#### ObjectVectorizationStrategy

**Location:** 'lib/Service/Vectorization/ObjectVectorizationStrategy.php'

**Object-specific logic:**
- Fetches objects by views and schemas
- Serializes object data to text
- Handles single vector per object (1 object = 1 vector)

**Options:**
- 'views' - Array of view IDs to filter (null = all)
- 'batch_size' - Objects per batch
- 'mode' - 'serial' or 'parallel'

## Usage

### File Vectorization

```php
// In FileExtractionController
$result = $this->vectorizationService->vectorizeBatch('file', [
    'mode' => 'parallel',
    'max_files' => 100,
    'batch_size' => 50,
    'file_types' => ['application/pdf', 'text/plain'],
]);
```

### Object Vectorization

```php
// In ObjectsController
$result = $this->vectorizationService->vectorizeBatch('object', [
    'mode' => 'serial',
    'views' => [1, 2, 3],
    'batch_size' => 25,
]);
```

## Adding New Entity Types

To add vectorization for a new entity type (e.g., emails, chat messages):

### 1. Create Strategy

```php
namespace OCA\OpenRegister\Service\Vectorization;

class EmailVectorizationStrategy implements VectorizationStrategyInterface
{
    public function fetchEntities(array $options): array
    {
        // Fetch emails to vectorize
        return $this->emailMapper->findUnvectorized($options['limit'] ?? 100);
    }
    
    public function extractVectorizationItems($email): array
    {
        // Extract text from email
        return [[
            'text' => $email->getSubject() . "\n\n" . $email->getBody(),
            'index' => 0,
        ]];
    }
    
    public function prepareVectorMetadata($email, array $item): array
    {
        return [
            'entity_type' => 'email',
            'entity_id' => (string) $email->getId(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'chunk_text' => substr($item['text'], 0, 500),
            'additional_metadata' => [
                'from' => $email->getFrom(),
                'to' => $email->getTo(),
                'subject' => $email->getSubject(),
                'date' => $email->getDate(),
            ],
        ];
    }
    
    public function getEntityIdentifier($email)
    {
        return $email->getId();
    }
}
```

### 2. Register Strategy

In 'lib/AppInfo/Application.php':

```php
// Register EmailVectorizationStrategy
$context->registerService(
    EmailVectorizationStrategy::class,
    function ($container) {
        return new EmailVectorizationStrategy(
            $container->get(EmailMapper::class),
            $container->get('Psr\Log\LoggerInterface')
        );
    }
);

// Register with VectorizationService
$service->registerStrategy('email', $container->get(EmailVectorizationStrategy::class));
```

### 3. Use It

```php
$result = $vectorizationService->vectorizeBatch('email', [
    'mode' => 'serial',
    'limit' => 50,
]);
```

## Benefits

### Code Reduction
- **Before:** 820 lines across two separate services
- **After:** 350 lines core + ~150 lines per strategy
- **Savings:** 40% less code for 2 entity types, more as we add types

### Consistency
- Same batch processing for all entities
- Same error handling
- Same progress tracking
- Same API structure

### Extensibility
- New entity types require only ~150 lines
- No modification to core logic
- Easy to test independently

### Maintainability
- Single source of truth for vectorization
- Changes to core logic benefit all entities
- Clear separation of concerns

## Implementation Details

### Dependency Injection

All services and strategies are registered in 'lib/AppInfo/Application.php':

```php
// VectorEmbeddingService (low-level embedding generation)
$context->registerService(VectorEmbeddingService::class, ...);

// Strategies
$context->registerService(FileVectorizationStrategy::class, ...);
$context->registerService(ObjectVectorizationStrategy::class, ...);

// VectorizationService (unified API)
$context->registerService(VectorizationService::class, function ($container) {
    $service = new VectorizationService(
        $container->get(VectorEmbeddingService::class),
        $container->get('Psr\Log\LoggerInterface')
    );
    
    // Register all strategies
    $service->registerStrategy('file', $container->get(FileVectorizationStrategy::class));
    $service->registerStrategy('object', $container->get(ObjectVectorizationStrategy::class));
    
    return $service;
});
```

### Processing Modes

**Serial Mode:**
- Process one item at a time
- Lower memory usage
- More predictable performance
- Recommended for objects

**Parallel Mode:**
- Process items in batches
- Higher throughput
- More memory usage
- Recommended for files (many chunks)

## Migration from Old Services

The old separate services have been removed:
- ❌ 'FileVectorizationService.php' (355 lines) - DELETED
- ❌ 'ObjectVectorizationService.php' (465 lines) - DELETED

All functionality is now provided by:
- ✅ 'VectorizationService.php' (350 lines) - Generic core
- ✅ 'FileVectorizationStrategy.php' (150 lines) - File-specific
- ✅ 'ObjectVectorizationStrategy.php' (180 lines) - Object-specific

The API has changed slightly:

**Old API (deprecated):**
```php
$fileVectorizationService->startBatchVectorization($mode, $maxFiles, $batchSize, $fileTypes);
$objectVectorizationService->startBatchVectorization($views, $batchSize);
```

**New API (current):**
```php
$vectorizationService->vectorizeBatch('file', [
    'mode' => $mode,
    'max_files' => $maxFiles,
    'batch_size' => $batchSize,
    'file_types' => $fileTypes,
]);

$vectorizationService->vectorizeBatch('object', [
    'views' => $views,
    'batch_size' => $batchSize,
    'mode' => 'serial',
]);
```

## Testing

### Test Core Logic Once

```php
class VectorizationServiceTest extends TestCase {
    public function testBatchProcessing() {
        // Mock strategy
        $strategy = $this->createMock(VectorizationStrategyInterface::class);
        
        // Test batch processing, error handling, etc.
        $service->registerStrategy('test', $strategy);
        $result = $service->vectorizeBatch('test', []);
    }
}
```

### Test Strategies Independently

```php
class FileVectorizationStrategyTest extends TestCase {
    public function testFetchEntities() {
        // Test file filtering, MIME type handling, etc.
    }
    
    public function testExtractChunks() {
        // Test chunk extraction from JSON
    }
}
```

## Embedding Model Management

### ⚠️ CRITICAL: Changing Embedding Models

**When you change embedding models, ALL existing vectors become invalid** and must be deleted and regenerated. Vectors created with different embedding models have:
- Different dimensions
- Different semantic spaces
- Incompatible similarity metrics

### Model Tracking

As of version 0.2.7+, OpenRegister tracks which embedding model created each vector:

```sql
-- openregister_vectors table structure
CREATE TABLE openregister_vectors (
    id INT PRIMARY KEY,
    entity_type VARCHAR(255),
    entity_id VARCHAR(255),
    embedding BLOB,
    embedding_model VARCHAR(255),  -- e.g., 'text-embedding-ada-002', 'llama3.2'
    embedding_dimensions INT,
    created_at TIMESTAMP,
    -- ... other fields
);
```

### Required Actions When Changing Models

1. **Clear All Embeddings**: Use the "Clear All Embeddings" button in LLM Configuration
2. **Re-vectorize All Data**:
   - Click "Vectorize All Files" from the Actions menu
   - Click "Vectorize All Objects" from the Actions menu

### UI Workflow

```
Settings → Actions → LLM Configuration
    ↓
Change Embedding Model (OpenAI → Ollama)
    ↓
Click "Clear All Embeddings" (red button)
    ↓
Confirm deletion
    ↓
Save Configuration
    ↓
Settings → Actions → Vectorize All Files
    ↓
Settings → Actions → Vectorize All Objects
```

### API Endpoints

**Check for Model Mismatches:**
```bash
GET /apps/openregister/api/vectors/check-model-mismatch

Response:
{
  "has_vectors": true,
  "mismatch": true,
  "current_model": "llama3.2",
  "existing_models": ["text-embedding-ada-002"],
  "total_vectors": 598,
  "null_model_count": 0,
  "mismatched_models": ["text-embedding-ada-002"],
  "message": "Embedding model has changed. Please clear all vectors and re-vectorize."
}
```

**Clear All Embeddings:**
```bash
DELETE /apps/openregister/api/vectors/clear-all

Response:
{
  "success": true,
  "deleted": 598,
  "message": "Deleted 598 vectors successfully"
}
```

### Example: Switching from OpenAI to Ollama

```php
// 1. Current state: 500 vectors created with text-embedding-ada-002
$status = $vectorEmbeddingService->checkEmbeddingModelMismatch();
// Returns: mismatch = false (all vectors use text-embedding-ada-002)

// 2. Change configuration to Ollama (llama3.2)
$settingsService->updateLLMSettings([
    'embeddingProvider' => 'ollama',
    'ollamaConfig' => ['model' => 'llama3.2']
]);

// 3. Check again - now there is a mismatch!
$status = $vectorEmbeddingService->checkEmbeddingModelMismatch();
// Returns: mismatch = true (current: llama3.2, existing: text-embedding-ada-002)

// 4. Clear all embeddings
$result = $vectorEmbeddingService->clearAllEmbeddings();
// Returns: deleted = 500

// 5. Re-vectorize everything with new model (llama3.2)
$vectorizationService->vectorizeBatch('file', ['mode' => 'parallel']);
$vectorizationService->vectorizeBatch('object', ['mode' => 'serial']);
```

### Database Migration

The 'embedding_model' column was added in migration 'Version1Date20251111000000':

```php
// Existing vectors (before migration) will have NULL embedding_model
// New vectors automatically track their model
// System warns if any NULL or mismatched models exist
```

### Why This Matters

**Semantic Search Breaks:**
```php
// Vectors created with OpenAI text-embedding-ada-002 (1536 dimensions)
$oldVector = [0.023, -0.142, 0.891, ...]; // 1536 values

// Query vector created with Ollama llama3.2 (4096 dimensions)
$queryVector = [0.012, -0.234, 0.456, ...]; // 4096 values

// ❌ Cannot compare! Dimensions don't match, similarity is meaningless
```

**Even Same Dimensions Fail:**
```php
// Both models have 1536 dimensions, but:
$openaiVector = [0.5, 0.3, -0.2]; // "apple" in OpenAI semantic space
$ollamaVector = [0.2, -0.5, 0.8]; // "apple" in Ollama semantic space

// ❌ Cosine similarity between these is meaningless!
// They represent the same word in completely different semantic spaces
```

### Best Practices

1. **Choose your embedding model carefully** before vectorizing large datasets
2. **Track your model version** in application logs
3. **Use the built-in model tracking** to detect mismatches
4. **Always clear and re-vectorize** after model changes
5. **Test new models** on a small dataset first
6. **Document your model choice** for your team

## Future Enhancements

Potential new entity types to vectorize:
- 📧 **Emails** - Subject + body vectorization
- 💬 **Chat messages** - Conversation context vectorization
- 📝 **Comments** - User-generated content vectorization
- 🏷️ **Tags** - Semantic tag relationships
- 📊 **Reports** - Generated report content

Each requires only ~150 lines of strategy code!

