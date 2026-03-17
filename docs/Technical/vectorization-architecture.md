---
title: Vectorization Architecture
sidebar_position: 2
description: Unified vectorization architecture using Strategy Pattern for files and objects
keywords:
  - Open Register
  - Vectorization
  - Embeddings
  - Strategy Pattern
---

# Vectorization Architecture

## Overview

OpenRegister uses a unified vectorization architecture based on the **Strategy Pattern** to eliminate code duplication and provide a consistent API for vectorizing different entity types (files, objects, etc.).

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

**Location:** `lib/Service/VectorizationService.php`

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

**Location:** `lib/Service/Vectorization/VectorizationStrategyInterface.php`

**Contract:**
```php
interface VectorizationStrategyInterface
{
    // Fetch entities to process
    public function fetchEntities(array $options): array;
    
    // Extract text items from entity (1 for objects, N for file chunks)
    public function extractVectorizationItems($entity): array;
    
    // Prepare metadata for vector storage
    public function prepareVectorMetadata($entity, array $item): array;
    
    // Get entity identifier (for logging)
    public function getEntityIdentifier($entity);
}
```

### 3. Strategies

#### FileVectorizationStrategy

**Location:** `lib/Service/Vectorization/FileVectorizationStrategy.php`

**File-specific logic:**
- Fetches files with `status='completed'` and chunks
- Filters by MIME types
- Extracts chunks from `chunks_json`
- Prepares metadata with file path, offsets, etc.

#### ObjectVectorizationStrategy

**Location:** `lib/Service/Vectorization/ObjectVectorizationStrategy.php`

**Object-specific logic:**
- Fetches objects by views/schemas
- Serializes object data to text
- Prepares metadata with object schema, relations, etc.

## Benefits

### 1. Code Reduction
- **Before:** ~820 lines across two services
- **After:** ~350 lines core + ~150 per strategy
- **Savings:** ~40% less code for 2 entity types, more as we add types

### 2. Consistency
- Same batch processing logic
- Same error handling
- Same progress tracking
- Same API structure

### 3. Extensibility

Adding new entity types is straightforward:

```php
// 1. Create strategy
class ChatMessageStrategy implements VectorizationStrategyInterface {
    public function fetchEntities($options) { /* fetch emails */ }
    public function extractVectorizationItems($email) { /* extract subject + body */ }
    public function prepareVectorMetadata($email, $item) { /* email metadata */ }
    public function getEntityIdentifier($email) { return $email->getId(); }
}

// 2. Register
$strategy = new ChatMessageStrategy($chatMapper, $logger);
$vectorizationService->registerStrategy('chat_message', $strategy);

// 3. Use
$result = $vectorizationService->vectorizeBatch('chat_message', [
    'conversation_id' => 123,
    'batch_size' => 50,
]);
```

### 4. Testability
- Test generic logic once
- Test strategies independently
- Mock strategies easily

## Usage Examples

### File Vectorization

```php
// FileExtractionController
public function vectorizeBatch(): JSONResponse
{
    $result = $this->vectorizationService->vectorizeBatch('file', [
        'mode' => 'parallel',
        'max_files' => 100,
        'batch_size' => 50,
        'file_types' => ['application/pdf'],
    ]);
    return new JSONResponse(['success' => true, 'data' => $result]);
}
```

### Object Vectorization

```php
// ObjectsController
public function vectorizeBatch(): JSONResponse
{
    $result = $vectorizationService->vectorizeBatch('object', [
        'mode' => 'serial',
        'views' => [1, 2, 3],
        'batch_size' => 25,
    ]);
    return new JSONResponse(['success' => true, 'data' => $result]);
}
```

## Vector Storage

Vectors are stored in the `oc_openregister_vectors` table with the following structure:

### Database Schema

```sql
CREATE TABLE oc_openregister_vectors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Entity information
    entity_type VARCHAR(50) NOT NULL,        -- 'object' or 'file'
    entity_id VARCHAR(255) NOT NULL,         -- UUID of object or file
    
    -- Chunk information (for files)
    chunk_index INT DEFAULT 0,               -- 0 for objects, N for file chunks
    total_chunks INT DEFAULT 1,              -- 1 for objects, N for files
    chunk_text MEDIUMTEXT,                   -- The actual text that was embedded
    
    -- Vector data
    embedding BLOB NOT NULL,                 -- Binary vector data
    embedding_model VARCHAR(100) NOT NULL,   -- 'text-embedding-ada-002', etc.
    embedding_dimensions INT NOT NULL,       -- 1536 for OpenAI ada-002
    
    -- Metadata
    metadata JSON,                           -- Additional searchable metadata
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_chunk (entity_id, chunk_index),
    INDEX idx_model (embedding_model),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Field Descriptions

- `id`: Primary key
- `entity_type`: 'file' or 'object'
- `entity_id`: ID of the source entity
- `chunk_index`: Index of chunk within entity (for files)
- `total_chunks`: Total number of chunks for entity
- `chunk_text`: Text content that was vectorized
- `embedding`: Vector embedding (stored as JSON or binary)
- `embedding_model`: Model used for embedding
- `embedding_dimensions`: Dimension count (e.g., 768, 1536, 4096)
- `metadata`: Additional metadata (JSON)

## Document Chunking Strategy

### Chunking Parameters

| Parameter | Value | Reasoning |
|-----------|-------|-----------|
| `chunk_size` | 1000 tokens (~750 words) | Balances context vs. specificity |
| `chunk_overlap` | 200 tokens (~150 words) | Preserves context across chunks |
| `max_chunks_per_file` | 1000 | Safety limit to prevent memory issues |
| `min_chunk_size` | 100 tokens | Skip tiny chunks that lack context |

### Chunking by Document Type

#### 1. **Technical Documentation** (Code, API docs)
```php
[
    'chunk_size' => 800,
    'chunk_overlap' => 200,
    'respect_code_blocks' => true,
    'split_on' => ['###', '##', '```', '\n\n']
]
```

#### 2. **Legal Documents** (Contracts, policies)
```php
[
    'chunk_size' => 600,
    'chunk_overlap' => 150,
    'respect_sections' => true,
    'split_on' => ['Article', 'Section', 'Clause', '\n\n']
]
```

#### 3. **Articles & Reports**
```php
[
    'chunk_size' => 1200,
    'chunk_overlap' => 200,
    'split_on' => ['##', '\n\n\n', '\n\n']
]
```

#### 4. **Spreadsheets & Tables**
```php
[
    'chunk_size' => 500,
    'chunk_overlap' => 50,
    'preserve_rows' => true,
    'include_headers' => true
]
```

## File Processing Pipeline

### Step 1: File Upload
```
User uploads file → FileService validates → Store in Nextcloud files
```

### Step 2: Text Extraction
Files are processed to extract text content using LLPhant document loaders, supporting:
- PDF (via pdftotext or Smalot\PdfParser)
- Word (via PhpOffice\PhpWord)
- Excel (via PhpOffice\PhpSpreadsheet)
- PowerPoint (via PhpOffice\PhpPresentation)
- Images (via Tesseract OCR)
- Text files (direct reading)

### Step 3: Chunking
Large documents are split into manageable chunks with overlap to preserve context:
- Chunks are sized based on document type
- Overlap ensures continuity between chunks
- Each chunk maintains metadata about its position

### Step 4: Vector Generation
- Generate embeddings for each chunk using configured embedding provider
- Store vectors in database with metadata
- Index chunks in Solr fileCollection for hybrid search

### Step 5: Linking
Store relationships:
- File → Chunks (1:N)
- Object → Files (1:N)
- Chunks → Vectors (1:1)

## Integration with Vector Search

Vectorized entities can be searched using semantic similarity:

1. **Generate query embedding** using VectorEmbeddingService
2. **Search vectors** using selected backend (PHP, PostgreSQL, or Solr)
3. **Return top N matches** sorted by similarity
4. **Retrieve source entities** using entity_type and entity_id

See [Vector Search Backends](../technical/vectorization.md#vector-search-backends) for details on search backends.

## Hybrid Search Architecture

OpenRegister supports hybrid search combining keyword (SOLR) and semantic (vector) search:

### Search Flow

```
User Query
    │
    ├─→ Keyword Search (SOLR)
    │   └─→ Full-text matching
    │       Faceting
    │       Filtering
    │
    ├─→ Semantic Search (Vectors)
    │   └─→ Generate query embedding
    │       Similarity search
    │       Return top K results
    │
    └─→ Merge & Rank Results
        └─→ Combine by relevance
            Deduplicate
            Re-rank using scores
            Return unified results
```

## Performance Considerations

### 1. **Token Limits**
- OpenAI ada-002: 8,191 tokens max
- OpenAI text-3: 8,191 tokens max
- Always chunk before limits

### 2. **API Costs**
- OpenAI ada-002: $0.0001 per 1K tokens
- For 1M tokens: ~$0.10
- Cache embeddings to avoid re-generation

### 3. **Processing Time**
- PDF extraction: ~1-2 seconds per page
- Chunking: < 100ms per document
- Embedding generation: ~200ms per chunk (OpenAI)
- Parallel processing: 4-8 chunks simultaneously

### 4. **Storage**
- Vector (1536 dimensions): ~6KB per embedding
- 1M file chunks: ~6GB vector storage
- Use compressed storage for large deployments

## Related Documentation

- [Vector Search Backends](../technical/vectorization.md#vector-search-backends) - Vector search backend options
- [Services Architecture](../development/services-architecture.md) - Overall service architecture

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

