# Correct OpenRegister Architecture

## Service Separation and Responsibilities

### Three Independent Flows

OpenRegister has **three independent services** with their own flows, listeners, and configurations:

```
┌─────────────────────────────────────────────────────────────────┐
│                     DATABASE (Source of Truth)                   │
│  - oc_openregister_objects (Object data)                        │
│  - oc_openregister_chunks (Extracted text chunks)               │
│  - oc_openregister_vectors (Embeddings)                         │
└─────────────────────────────────────────────────────────────────┘
                               ▲ ▲ ▲
                               │ │ │
          ┌────────────────────┘ │ └────────────────────┐
          │                      │                       │
          ▼                      ▼                       ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ TextExtraction  │    │ Vectorization   │    │  IndexService   │
│    Service      │    │    Service      │    │  (Solr/Elastic) │
├─────────────────┤    ├─────────────────┤    ├─────────────────┤
│ - Own listeners │    │ - Own listeners │    │ - Own listeners │
│ - Own config    │    │ - Own config    │    │ - Own config    │
│ - Own flow      │    │ - Own flow      │    │ - Own flow      │
│                 │    │                 │    │                 │
│ Extracts text   │    │ Creates vectors │    │ Builds search   │
│ from files      │    │ from text       │    │ index from DB   │
│ & objects       │    │                 │    │                 │
│                 │    │                 │    │                 │
│ Stores in DB ───┤    │ Stores in DB ───┤    │ Reads from DB ──┤
│ (chunks table)  │    │ (vectors table) │    │ Indexes to      │
└─────────────────┘    └─────────────────┘    │ Solr/Elastic    │
                                               └─────────────────┘
```

---

## 1. TextExtractionService

### Purpose
Extract text from files and objects and store chunks in the database.

### Flow
1. **Listen** to file upload events, object create/update events
2. **Extract** text from files (PDF, DOCX, HTML, etc.)
3. **Chunk** text into manageable pieces
4. **Store** chunks in `oc_openregister_chunks` table
5. **Mark** chunks with metadata (language, owner, etc.)

### Configuration
- Chunk size, overlap, strategy
- Supported file types
- Extraction methods per file type

### Database Storage
```sql
oc_openregister_chunks:
- id, uuid
- source_type (file/object)
- source_id (file_id/object_id)
- chunk_index
- text_content
- indexed (false initially)
- vectorized (false initially)
```

### Listeners
- `FileCreatedListener` - Extract text when file uploaded
- `ObjectChangedListener` - Extract text when object updated

### Independence
- Runs completely independently
- Other services don't call it - they listen to its output (chunks in DB)

---

## 2. VectorizationService

### Purpose
Create vector embeddings from text and store them in the database.

### Flow
1. **Listen** to chunk creation events or run scheduled jobs
2. **Fetch** text from chunks or objects
3. **Generate** embeddings using provider (OpenAI, Cohere, etc.)
4. **Store** vectors in `oc_openregister_vectors` table
5. **Mark** chunks as vectorized

### Configuration
- Vector provider (OpenAI, Cohere, local models)
- Vector dimensions
- Batch size

### Database Storage
```sql
oc_openregister_vectors:
- id, uuid
- entity_type (object/chunk)
- entity_id
- embedding (vector data)
- model, dimensions
- chunk_index
```

### Listeners
- `ChunkCreatedListener` - Vectorize when chunk created
- `ObjectChangedListener` - Vectorize when object updated
- `ScheduledVectorizationJob` - Batch vectorization

### Independence
- Runs completely independently
- Other services don't call it - they read its output (vectors in DB)

---

## 3. IndexService (Solr/Elasticsearch)

### Purpose
Build and maintain search indexes in Solr/Elasticsearch from database data.

### Flow
1. **Listen** to database events (object create/update/delete, chunk changes)
2. **Read** data from database (objects, chunks, vectors)
3. **Transform** data to Solr/Elastic format
4. **Index** to search backend
5. **Keep** index in sync with database

### Configuration
- Solr/Elastic endpoint
- Collection names
- Schema mappings
- Field types

### Handlers

#### FileHandler
**Reads**: Chunks from `oc_openregister_chunks` (via ChunkMapper)  
**Indexes**: Chunks to Solr fileCollection  
**Does NOT**: Extract text (that's TextExtractionService's job)

```php
// FileHandler ONLY indexes existing chunks from database
public function processUnindexedChunks(?int $limit): array
{
    // Read chunks from database where indexed=false
    $chunks = $this->chunkMapper->findUnindexed('file', $limit);
    
    // Index to Solr
    $result = $this->searchBackend->index($documents);
    
    // Mark as indexed
    foreach ($chunks as $chunk) {
        $chunk->setIndexed(true);
        $this->chunkMapper->update($chunk);
    }
}
```

#### ObjectHandler
**Reads**: Objects from `oc_openregister_objects` (via ObjectEntityMapper)  
**Indexes**: Objects to Solr objectCollection  
**Searches**: Objects in Solr  
**Does NOT**: Extract text or vectorize (those are separate services)

```php
// ObjectHandler ONLY indexes existing objects from database
public function searchObjects(array $query): array
{
    // Query Solr for objects
    $results = $this->searchBackend->search($solrQuery);
    return $results;
}
```

#### SchemaHandler
**Reads**: Schemas from `oc_openregister_schemas` (via SchemaMapper)  
**Manages**: Solr schema (field types, fields)  
**Does NOT**: Extract or vectorize anything

### Listeners
- `ObjectChangedListener` - Reindex when object changes
- `ChunkChangedListener` - Reindex when chunk changes
- `SchemaChangedListener` - Update Solr schema when OpenRegister schema changes

### Independence
- Does NOT call TextExtractionService
- Does NOT call VectorizationService
- ONLY reads from database and indexes to Solr/Elastic

---

## Why This Separation?

### 1. **Different Triggers**
- **TextExtraction**: Triggered by file uploads, object changes
- **Vectorization**: Triggered by chunk creation, scheduled jobs
- **Indexing**: Triggered by database changes

### 2. **Different Performance Characteristics**
- **TextExtraction**: Slow (parsing PDFs, OCR)
- **Vectorization**: Very slow (API calls to OpenAI/Cohere)
- **Indexing**: Fast (just reading DB and POSTing to Solr)

### 3. **Different Failure Modes**
- **TextExtraction**: File format errors, missing libraries
- **Vectorization**: API rate limits, network failures
- **Indexing**: Solr connection errors, schema mismatches

### 4. **Independent Configuration**
Each service has its own configuration:
- **TextExtraction**: Chunk size, file type support
- **Vectorization**: Provider, API keys, dimensions
- **Indexing**: Solr endpoint, collections, schema mappings

### 5. **Independent Scaling**
- Can scale TextExtraction workers independently
- Can scale Vectorization workers independently
- Can scale Indexing workers independently

---

## Event Flow Example

### File Upload → Full Processing

```
1. User uploads PDF file
   │
   ├─► TextExtractionService (immediate)
   │   - Listens to FileCreatedEvent
   │   - Extracts text from PDF
   │   - Creates 50 chunks
   │   - Stores in oc_openregister_chunks (indexed=false, vectorized=false)
   │
2. Chunks created in database
   │
   ├─► VectorizationService (scheduled job or immediate)
   │   - Listens to ChunkCreatedEvent or runs batch job
   │   - Reads chunks from database
   │   - Generates embeddings via OpenAI
   │   - Stores in oc_openregister_vectors
   │   - Marks chunks as vectorized=true
   │
3. Chunks marked as indexed=false
   │
   └─► IndexService (immediate or batch)
       - Listens to ChunkCreatedEvent
       - Reads chunks from database
       - Indexes to Solr fileCollection
       - Marks chunks as indexed=true
```

### Object Update → Reindexing

```
1. User updates object data
   │
   ├─► TextExtractionService (if needed)
   │   - Extracts text from updated object
   │   - Updates chunks in database
   │
2. Object changed in database
   │
   └─► IndexService (immediate)
       - Listens to ObjectChangedEvent
       - Reads object from database
       - Updates Solr objectCollection
       - Index stays in sync
```

---

## Key Principles

### ✅ DO

1. **TextExtractionService** → Store chunks in database
2. **VectorizationService** → Store vectors in database
3. **IndexService** → Read from database, index to Solr/Elastic
4. Each service listens to events independently
5. Each service has its own configuration
6. Database is the source of truth

### ❌ DON'T

1. **IndexService** should NOT call TextExtractionService
2. **IndexService** should NOT call VectorizationService
3. Services should NOT directly depend on each other
4. Services should NOT call each other's methods
5. Don't mix extraction/vectorization with indexing

---

## File Size After Corrections

### Before (Incorrect Architecture)
- `FileHandler.php`: 337 lines (with TextExtractionService injection)
- `ObjectHandler.php`: 280 lines (with VectorizationService injection)

### After (Correct Architecture)
- `FileHandler.php`: 295 lines (no TextExtractionService, no extraction methods)
- `ObjectHandler.php`: 188 lines (no VectorizationService, no vectorization methods)

**Result**: Even cleaner, more focused handlers!

---

## Summary

OpenRegister has **three independent services**:

1. **TextExtractionService** - Extracts text → Stores in database
2. **VectorizationService** - Creates vectors → Stores in database  
3. **IndexService** - Reads from database → Indexes to Solr/Elastic

Each service:
- Has its own event listeners
- Has its own configuration
- Runs independently
- Uses database as communication layer

The **IndexService** should ONLY:
- ✅ Read data from database
- ✅ Index to Solr/Elastic
- ✅ Keep index in sync with database
- ❌ NOT extract text
- ❌ NOT vectorize data
- ❌ NOT call other services

This architecture provides:
- **Clear separation of concerns**
- **Independent scaling**
- **Easier debugging** (each service logs independently)
- **Better resilience** (failures in one don't affect others)
- **Simpler testing** (test each service independently)


