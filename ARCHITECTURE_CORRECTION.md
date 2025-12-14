# Architecture Correction: Index Handlers

## What Changed

During the initial implementation, I incorrectly designed the Index handlers to **call** `TextExtractionService` and `VectorizationService` directly. This was wrong.

The user correctly pointed out that OpenRegister has **three independent services** with their own flows, listeners, and configurations.

---

## ❌ Incorrect Architecture (Initial Attempt)

```
IndexService
    ├─ FileHandler
    │   └─ Calls TextExtractionService.extractText()  ❌ WRONG
    │   └─ Calls TextExtractionService.chunkDocument() ❌ WRONG
    │
    └─ ObjectHandler
        └─ Calls VectorizationService.vectorize()  ❌ WRONG
```

**Problems:**
- IndexService would be tightly coupled to extraction/vectorization services
- Creates circular dependencies
- Violates single responsibility principle
- Mixing indexing with extraction/vectorization

---

## ✅ Correct Architecture (After Correction)

```
┌─────────────────────────────────────────────────────────────┐
│                    DATABASE (Source of Truth)                │
│  - oc_openregister_objects                                  │
│  - oc_openregister_chunks (created by TextExtraction)       │
│  - oc_openregister_vectors (created by Vectorization)       │
└─────────────────────────────────────────────────────────────┘
         ▲ │                  ▲ │                  ▲ │
    write│ │read         write│ │read         read │ │write
         │ ▼                  │ ▼                  │ ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ TextExtraction  │  │ Vectorization   │  │  IndexService   │
│    Service      │  │    Service      │  │  (Solr/Elastic) │
├─────────────────┤  ├─────────────────┤  ├─────────────────┤
│ Own listeners   │  │ Own listeners   │  │ Own listeners   │
│ Own config      │  │ Own config      │  │ Own config      │
│ Own flow        │  │ Own flow        │  │ Own flow        │
│                 │  │                 │  │                 │
│ WRITES to DB    │  │ WRITES to DB    │  │ READS from DB   │
│ (stores chunks) │  │ (stores vectors)│  │ (indexes to     │
│                 │  │                 │  │  Solr/Elastic)  │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

**Key Principles:**
1. Services **don't call each other**
2. Services communicate via **database events**
3. Database is the **source of truth**
4. Each service has **independent listeners and config**

---

## Changes Made to Handlers

### FileHandler (Before → After)

**Before (Incorrect):**
```php
class FileHandler
{
    public function __construct(
        private readonly TextExtractionService $textExtractionService, // ❌
        // ...
    ) {}
    
    public function processFile(int $fileId): array
    {
        // ❌ WRONG: Calling extraction service
        $text = $this->textExtractionService->extractText($fileId);
        $chunks = $this->textExtractionService->chunkDocument($text);
        // ... index chunks
    }
}
```

**After (Correct):**
```php
class FileHandler
{
    public function __construct(
        private readonly ChunkMapper $chunkMapper, // ✅ Read from DB
        // No TextExtractionService!
    ) {}
    
    public function processUnindexedChunks(?int $limit): array
    {
        // ✅ CORRECT: Read chunks from database (created by TextExtractionService)
        $chunks = $this->chunkMapper->findUnindexed('file', $limit);
        
        // Index to Solr
        $this->searchBackend->index($documents);
        
        // Mark as indexed in database
        foreach ($chunks as $chunk) {
            $chunk->setIndexed(true);
            $this->chunkMapper->update($chunk);
        }
    }
}
```

### ObjectHandler (Before → After)

**Before (Incorrect):**
```php
class ObjectHandler
{
    public function __construct(
        private readonly VectorizationService $vectorizationService, // ❌
        // ...
    ) {}
    
    public function vectorizeObject(ObjectEntity $object): array
    {
        // ❌ WRONG: Calling vectorization service
        return $this->vectorizationService->vectorize($object);
    }
}
```

**After (Correct):**
```php
class ObjectHandler
{
    public function __construct(
        // No VectorizationService!
        // No TextExtractionService!
    ) {}
    
    public function searchObjects(array $query): array
    {
        // ✅ CORRECT: Just query Solr for objects
        return $this->searchBackend->search($query);
    }
    
    // No vectorization methods - that's VectorizationService's job!
}
```

---

## Removed Dependencies

### From FileHandler:
- ❌ Removed: `TextExtractionService` injection
- ❌ Removed: Any text extraction methods
- ✅ Kept: `ChunkMapper` (to read chunks from database)

### From ObjectHandler:
- ❌ Removed: `TextExtractionService` injection
- ❌ Removed: `VectorizationService` injection
- ❌ Removed: `vectorizeObject()` method
- ❌ Removed: `vectorizeObjects()` method
- ✅ Kept: Search and commit methods only

---

## Event Flow (Correct)

### Example: File Upload

```
1. User uploads PDF
   │
   ▼
2. TextExtractionService Listener
   - Listens to FileCreatedEvent
   - Extracts text from PDF
   - Creates chunks
   - Stores in oc_openregister_chunks (indexed=false)
   │
   ▼
3. Database writes chunks
   │
   ▼
4. IndexService Listener (or batch job)
   - Listens to ChunkCreatedEvent or runs scheduled
   - Reads chunks where indexed=false from database
   - Indexes to Solr
   - Marks chunks as indexed=true
   │
   ▼
5. VectorizationService Listener (or batch job)
   - Listens to ChunkCreatedEvent or runs scheduled
   - Reads chunks from database
   - Generates embeddings
   - Stores in oc_openregister_vectors
   - Marks chunks as vectorized=true
```

**Key Point**: Services work **in parallel** via database events, not by calling each other!

---

## Benefits of Correct Architecture

### 1. **True Independence**
- TextExtractionService can be updated without affecting IndexService
- VectorizationService can be updated without affecting IndexService
- Each service scales independently

### 2. **No Circular Dependencies**
- Services only depend on database mappers
- Clean dependency graph
- Easier testing

### 3. **Event-Driven**
- Services react to database events
- Asynchronous processing
- Better performance

### 4. **Database as Source of Truth**
- All data in database
- Easy to rebuild indexes (just read from DB)
- Easy to debug (check database state)

### 5. **Simpler Handlers**
- **FileHandler**: 295 lines (vs 337 before correction)
- **ObjectHandler**: 188 lines (vs 280 before correction)
- Cleaner, more focused code

---

## File Size Comparison

### Before Correction
- `FileHandler.php`: 337 lines (with TextExtractionService injection)
- `ObjectHandler.php`: 280 lines (with VectorizationService injection)
- **Total**: 617 lines

### After Correction
- `FileHandler.php`: 295 lines (no TextExtractionService)
- `ObjectHandler.php`: 188 lines (no VectorizationService)
- **Total**: 483 lines

**Result**: 22% smaller, much cleaner!

---

## Documentation Created

1. **`CORRECT_ARCHITECTURE.md`** - Comprehensive explanation of correct architecture
2. **`ARCHITECTURE_CORRECTION.md`** (this file) - What changed and why
3. Updated `SESSION_SOLR_INTEGRATION.md` - Corrected session summary

---

## Key Takeaways

### ✅ DO

1. **IndexService** reads from database
2. **IndexService** indexes to Solr/Elastic
3. **IndexService** listens to database events
4. Services communicate via database events
5. Database is source of truth

### ❌ DON'T

1. **IndexService** should NOT call TextExtractionService
2. **IndexService** should NOT call VectorizationService
3. Services should NOT directly call each other
4. Don't mix responsibilities (extraction vs indexing vs vectorization)

---

## Correct Mental Model

Think of the services as **three independent workers**:

```
TextExtraction Worker:
"I extract text from files/objects and store chunks in the database."

Vectorization Worker:
"I generate embeddings from text and store vectors in the database."

Index Worker:
"I read data from the database and keep Solr/Elastic in sync."
```

They don't talk to each other directly - they communicate through the database!

---

## Summary

The correction was simple but important:

**Before**: Handlers tried to orchestrate extraction and vectorization ❌  
**After**: Handlers only index data that already exists in database ✅

This matches the existing OpenRegister architecture where:
- **TextExtractionService** has its own flow with listeners
- **VectorizationService** has its own flow with listeners
- **IndexService** has its own flow with listeners

All three services are **independent** and communicate via **database events**.


