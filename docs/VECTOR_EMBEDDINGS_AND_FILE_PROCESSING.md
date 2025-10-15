# Vector Embeddings & Document Chunking Strategy for OpenRegister

**Date:** October 13, 2025  
**Author:** AI Development Team  
**Status:** Planning Phase

## Executive Summary

This document outlines the strategy for implementing vector embeddings and intelligent document chunking in OpenRegister to enable:
- Semantic search across objects and files
- LLM integration via RAG (Retrieval Augmented Generation)
- Hybrid search combining keyword (SOLR) and semantic (vector) search
- Efficient file text extraction and processing

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                     OpenRegister Application                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  │
│  │  ObjectService   │  │   FileService    │  │  SearchService   │  │
│  └────────┬─────────┘  └────────┬─────────┘  └────────┬─────────┘  │
│           │                     │                      │            │
│           ▼                     ▼                      ▼            │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  │
│  │SolrObjectService │  │ SolrFileService  │  │  GuzzleSolrService│ │
│  │                  │  │                  │  │  (Core SOLR)      │  │
│  │ - Index objects  │  │ - Extract text   │  │  - Collections    │  │
│  │ - Search objects │  │ - Chunk files    │  │  - Queries        │  │
│  │ - Object vectors │  │ - Index files    │  │  - Admin          │  │
│  └────────┬─────────┘  │ - File vectors   │  └────────┬─────────┘  │
│           │            └────────┬─────────┘           │            │
│           │                     │                      │            │
│           ▼                     ▼                      ▼            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │              VectorEmbeddingService                          │   │
│  │  - LLPhant integration                                       │   │
│  │  - Document loading (PDF, DOCX, images via OCR)              │   │
│  │  - Text splitting with overlap                               │   │
│  │  - Embedding generation (OpenAI, Ollama, local)              │   │
│  │  - Vector storage and retrieval                              │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                       │
└───────────────────────────────────┬───────────────────────────────────┘
                                    │
        ┌───────────────────────────┴───────────────────────────┐
        │                                                       │
        ▼                                                       ▼
┌──────────────────┐                                  ┌──────────────────┐
│  SOLR Collections│                                  │  Vector Database │
│                  │                                  │                  │
│ ┌──────────────┐ │                                  │ ┌──────────────┐ │
│ │objectCollection│                                  │ │Object Vectors│ │
│ │ - Full-text  │ │                                  │ │              │ │
│ │ - Facets     │ │                                  │ │ - entity_id  │ │
│ │ - Metadata   │ │                                  │ │ - embedding  │ │
│ └──────────────┘ │                                  │ │ - metadata   │ │
│                  │                                  │ └──────────────┘ │
│ ┌──────────────┐ │                                  │                  │
│ │fileCollection│ │                                  │ ┌──────────────┐ │
│ │ - Text chunks│ │                                  │ │ File Vectors │ │
│ │ - Metadata   │ │                                  │ │ (Chunked)    │ │
│ │ - File info  │ │                                  │ │              │ │
│ └──────────────┘ │                                  │ │ - file_id    │ │
└──────────────────┘                                  │ │ - chunk_index│ │
                                                      │ │ - embedding  │ │
                                                      │ │ - text       │ │
                                                      │ └──────────────┘ │
                                                      └──────────────────┘
```

## Service Responsibilities

### 1. GuzzleSolrService (Core SOLR Operations)

**Purpose:** Low-level SOLR infrastructure management

**Responsibilities:**
- SOLR connection management
- Collection creation/deletion/management
- ConfigSet management
- HTTP client configuration
- Authentication
- Base URL building
- Availability checking
- Admin operations

**Does NOT Handle:**
- Object-specific indexing logic
- File-specific processing
- Business logic

### 2. SolrObjectService (Object Operations)

**Purpose:** Object-specific SOLR operations

**Responsibilities:**
- Index ObjectEntity instances to `objectCollection`
- Convert ObjectEntity to SOLR documents
- Search objects with filtering
- Object-specific faceting
- Schema-aware field mapping
- Bulk object indexing
- Object deletion from index
- Object warmup operations

**Key Methods:**
```php
public function indexObject(ObjectEntity $object): bool
public function bulkIndexObjects(array $objects): array
public function searchObjects(array $query): array
public function deleteObject(string $objectId): bool
public function warmupObjects(array $schemaIds = []): array
public function getObjectStats(): array
```

### 3. SolrFileService (File Operations)

**Purpose:** File-specific SOLR operations with text extraction and chunking

**Responsibilities:**
- Extract text from files (PDF, DOCX, images)
- Chunk large documents intelligently
- Index file chunks to `fileCollection`
- Maintain chunk metadata (file_id, chunk_index, total_chunks)
- Search across file contents
- File-specific faceting (type, size, date)
- OCR for images
- File deletion from index

**Key Methods:**
```php
public function processAndIndexFile(string $filePath, array $metadata): array
public function extractTextFromFile(string $filePath): string
public function chunkDocument(string $text, array $options = []): array
public function indexFileChunks(string $fileId, array $chunks): bool
public function searchFiles(array $query): array
public function deleteFile(string $fileId): bool
public function getFileStats(): array
```

### 4. VectorEmbeddingService (Vector Operations)

**Purpose:** Vector embedding generation and storage using LLPhant

**Responsibilities:**
- Document loading via LLPhant
- Text extraction from multiple formats
- Intelligent text splitting
- Embedding generation (OpenAI, Ollama, local models)
- Vector storage in database
- Semantic similarity search
- Vector database management

**Key Methods:**
```php
public function generateEmbedding(string $text): array
public function generateBatchEmbeddings(array $texts): array
public function storeVector(string $entityId, string $entityType, array $embedding, array $metadata): bool
public function semanticSearch(string $query, int $limit = 10, array $filters = []): array
public function hybridSearch(string $query, array $solrFilters = []): array
```

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

### LLPhant Text Splitting Implementation

```php
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

$splitter = new DocumentSplitter();

// Configure the splitter
$chunks = $splitter->splitText(
    text: $extractedText,
    maxLength: 1000,  // tokens
    overlap: 200      // tokens
);

// Each chunk maintains context and metadata
foreach ($chunks as $index => $chunk) {
    $enrichedChunk = [
        'text' => $chunk,
        'chunk_index' => $index,
        'total_chunks' => count($chunks),
        'file_id' => $fileId,
        'file_type' => $fileType,
        'file_name' => $fileName,
        'created_at' => time()
    ];
}
```

## Vector Database Schema

### Table: `oc_openregister_vectors`

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

## File Processing Pipeline

### Step 1: File Upload
```
User uploads file → FileService validates → Store in Nextcloud files
```

### Step 2: Text Extraction
```php
// Using LLPhant Document Loaders
$loader = new FileDataReader();
$documents = $loader->getDocuments($filePath);
$fullText = $this->extractFullText($documents);
```

### Step 3: Chunking
```php
$chunks = $splitter->splitText(
    text: $fullText,
    maxLength: 1000,
    overlap: 200
);
```

### Step 4: Parallel Processing
```php
// Process chunks in parallel
$promises = [];
foreach ($chunks as $index => $chunk) {
    $promises[] = async(function() use ($chunk, $index) {
        // Generate embedding
        $embedding = $this->embeddingGenerator->embedText($chunk);
        
        // Store vector
        $this->vectorStore->addDocument([
            'text' => $chunk,
            'embedding' => $embedding,
            'metadata' => [...]
        ]);
        
        // Index in SOLR fileCollection
        $this->solrFileService->indexChunk([
            'text' => $chunk,
            'chunk_index' => $index,
            ...
        ]);
    });
}

await(all($promises));
```

### Step 5: Linking
```
Store relationships:
- File → Chunks (1:N)
- Object → Files (1:N)
- Chunks → Vectors (1:1)
```

## Hybrid Search Architecture

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

### Implementation

```php
public function hybridSearch(string $query, array $filters = [], int $limit = 20): array
{
    // 1. SOLR keyword search
    $solrResults = $this->solrFileService->searchFiles(array_merge([
        '_search' => $query,
        '_limit' => $limit * 2  // Get more for merging
    ], $filters));
    
    // 2. Vector semantic search
    $vectorResults = $this->vectorService->semanticSearch(
        query: $query,
        limit: $limit,
        filters: ['entity_type' => 'file']
    );
    
    // 3. Merge and rank
    $merged = $this->mergeResults(
        solrResults: $solrResults['results'],
        vectorResults: $vectorResults,
        weights: ['solr' => 0.5, 'vector' => 0.5]
    );
    
    return array_slice($merged, 0, $limit);
}
```

## LLPhant Integration

### Installation

```bash
composer require llphant/llphant
```

### Configuration

```php
// config/llphant.php
return [
    'embedding' => [
        'provider' => env('EMBEDDING_PROVIDER', 'openai'),
        'model' => env('EMBEDDING_MODEL', 'text-embedding-ada-002'),
        'api_key' => env('OPENAI_API_KEY'),
        'dimensions' => 1536,
    ],
    
    'document_loader' => [
        'supported_types' => ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'md', 'html', 'jpg', 'png'],
        'ocr_enabled' => true,
        'tesseract_path' => '/usr/bin/tesseract',
    ],
    
    'chunking' => [
        'default_size' => 1000,
        'default_overlap' => 200,
        'max_chunks' => 1000,
        'min_chunk_size' => 100,
    ],
    
    'vector_store' => [
        'driver' => 'doctrine',  // or 'redis', 'milvus', 'qdrant'
        'table' => 'oc_openregister_vectors',
    ],
];
```

### Key LLPhant Components

#### 1. Document Loaders
```php
use LLPhant\Embeddings\DataReader\FileDataReader;

$loader = new FileDataReader();
$documents = $loader->getDocuments('/path/to/file.pdf');
```

Supports:
- PDF (via pdftotext or Smalot\PdfParser)
- Word (via PhpOffice\PhpWord)
- Excel (via PhpOffice\PhpSpreadsheet)
- PowerPoint (via PhpOffice\PhpPresentation)
- Images (via Tesseract OCR)
- Text files (direct reading)

#### 2. Text Splitters
```php
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;

$splitter = new DocumentSplitter();
$chunks = $splitter->splitText($text, 1000, 200);
```

#### 3. Embedding Generators
```php
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;

$generator = new OpenAI3LargeEmbeddingGenerator();
$embedding = $generator->embedText('Your text here');
```

Supports:
- OpenAI (text-embedding-ada-002, text-embedding-3-small, text-embedding-3-large)
- Ollama (local embeddings)
- Custom providers

#### 4. Vector Stores
```php
use LLPhant\Embeddings\VectorStores\Doctrine\DoctrineVectorStore;

$vectorStore = new DoctrineVectorStore($entityManager);
$vectorStore->addDocument($document);
$results = $vectorStore->similaritySearch($queryEmbedding, 10);
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

## Migration Path

### Phase 1: Foundation (Week 1-2)
1. Install LLPhant
2. Create `VectorEmbeddingService`
3. Create database schema
4. Implement basic document loading

### Phase 2: Service Refactoring (Week 2-3)
1. Create `SolrObjectService`
2. Create `SolrFileService`
3. Refactor `GuzzleSolrService` to core operations only
4. Update all callers

### Phase 3: File Processing (Week 3-4)
1. Implement text extraction
2. Implement chunking logic
3. Connect to vector database
4. Index files in `fileCollection`

### Phase 4: Vector Integration (Week 4-5)
1. Generate embeddings for files
2. Store vectors in database
3. Implement semantic search
4. Create hybrid search

### Phase 5: Object Vectorization (Week 5-6)
1. Convert objects to searchable text
2. Generate object embeddings
3. Store in vector database
4. Enhance object search

### Phase 6: LLM Integration (Week 6+)
1. Implement RAG query interface
2. Create chat UI
3. Context-aware responses
4. User feedback loop

## Testing Strategy

### Unit Tests
- Text extraction accuracy
- Chunking boundary conditions
- Embedding generation
- Vector storage/retrieval

### Integration Tests
- Full file processing pipeline
- Hybrid search accuracy
- Performance under load
- Error handling

### Performance Tests
- Large file processing (>100MB)
- Bulk embedding generation
- Concurrent user searches
- Database query performance

## Security Considerations

1. **File Validation**: Scan uploaded files for malware
2. **Content Filtering**: Sanitize extracted text
3. **Access Control**: Respect Nextcloud permissions
4. **API Key Security**: Encrypt OpenAI keys in database
5. **Rate Limiting**: Prevent embedding API abuse

## Monitoring & Metrics

Track:
- Files processed per day
- Average chunks per file
- Embedding generation success rate
- Search latency (keyword vs. semantic)
- Storage growth
- API costs

## References

- [LLPhant GitHub](https://github.com/LLPhant/LLPhant)
- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [Chunking Strategies](https://www.pinecone.io/learn/chunking-strategies/)
- [RAG Best Practices](https://www.pinecone.io/learn/retrieval-augmented-generation/)

