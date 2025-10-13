# File Text Processing Pipeline Implementation

## Overview

Complete 3-stage file processing pipeline for SOLR indexing and AI/ML vector search:

### **Stage 1: Text Extraction** ✅
- Extract text from files (PDF, DOCX, XLSX, images with OCR)
- Store in `oc_openregister_file_texts` table
- Track extraction status and metadata

### **Stage 2: Text Chunking** ✅
- Split text into manageable chunks
- Preserve sentence/paragraph boundaries
- Store chunk metadata

### **Stage 3: Vectorization** 🔄 (Future AI enhancement)
- Generate embeddings for chunks
- Store in `oc_openregister_vectors` table
- Enable semantic search

---

## Database Schema

### Table: `oc_openregister_file_texts`

**Purpose:** Store extracted text content from files as BLOB for fast search without re-parsing.

**Columns:**
- `id` (BIGINT) - Primary key
- `file_id` (BIGINT) - Nextcloud file ID from oc_filecache [UNIQUE]
- `file_path` (STRING) - Full path in Nextcloud
- `file_name` (STRING) - Filename with extension
- `mime_type` (STRING) - MIME type
- `file_size` (BIGINT) - File size in bytes
- `file_checksum` (STRING) - For change detection
- `text_content` (MEDIUMTEXT) - Extracted text (16MB max)
- `text_length` (INT) - Character count
- `extraction_method` (STRING) - text_extract, ocr, tika, api
- `extraction_status` (STRING) - pending, processing, completed, failed, skipped
- `extraction_error` (TEXT) - Error message if failed
- `chunked` (BOOLEAN) - Whether chunked
- `chunk_count` (INT) - Number of chunks
- `indexed_in_solr` (BOOLEAN) - In SOLR index
- `vectorized` (BOOLEAN) - Has embeddings
- `created_at` (DATETIME)
- `updated_at` (DATETIME)
- `extracted_at` (DATETIME)

**Indexes:**
- Primary: `id`
- Unique: `file_id`
- Performance: `extraction_status`, `mime_type`, `indexed_in_solr`, `vectorized`, `created_at`

---

## Services Architecture

### 1. **FileTextService** (NEW)

**Purpose:** Manage file text extraction and storage lifecycle.

**Methods:**
```php
// Extract and store
public function extractAndStoreFileText(int $fileId): array

// Get stored text
public function getFileText(int $fileId): ?array

// Check if file needs extraction
public function needsExtraction(int $fileId): bool

// Update extraction status
public function updateExtractionStatus(int $fileId, string $status, ?string $error = null): void

// Bulk process pending files
public function processPendingFiles(int $limit = 100): array
```

**Dependencies:**
- `SolrFileService` - For text extraction
- `FileMapper` - For Nextcloud file metadata
- `IDBConnection` - For database operations
- `LoggerInterface` - For logging

### 2. **SolrFileService** (UPDATED)

**Purpose:** Text extraction, chunking, and SOLR indexing.

**Existing Methods:**
```php
public function extractTextFromFile(string $filePath): string
public function chunkDocument(string $text, array $options = []): array
public function processFile(int $fileId, array $metadata = []): array
```

**New Integration:**
```php
// Now stores text in file_texts table before chunking
public function extractAndPersistText(int $fileId): bool

// Retrieves from file_texts table (Stage 1)
// Then chunks (Stage 2) 
// Then optionally vectorizes (Stage 3)
public function processFileWithPersistence(int $fileId): array
```

### 3. **GuzzleSolrService** (UPDATED)

**Purpose:** SOLR operations for file collection.

**New Methods:**
```php
// Index file text chunks in file collection
public function indexFileChunks(int $fileId, array $chunks, array $metadata): bool

// Bulk index files during warmup
public function indexFiles(array $fileIds, ?string $collection = null): array

// Get file indexing statistics
public function getFileIndexStats(): array
```

---

## Event Listener

### **FileChangeListener** (NEW)

**Purpose:** Automatically process files when created/updated.

**Events:**
- `\OCP\Files\Events\Node\NodeCreatedEvent`
- `\OCP\Files\Events\Node\NodeWrittenEvent`

**Flow:**
```
File Created/Updated
    ↓
Check if supported file type
    ↓
Extract text → Store in file_texts table (Stage 1)
    ↓
Chunk text → Store chunks (Stage 2)
    ↓
Index in SOLR file collection
    ↓
[Optional] Generate vectors (Stage 3 - AI)
```

**Registration:**
```php
// In lib/AppInfo/Application.php
$context->registerEventListener(
    NodeCreatedEvent::class,
    FileChangeListener::class
);
$context->registerEventListener(
    NodeWrittenEvent::class,
    FileChangeListener::class
);
```

---

## SOLR Warmup Integration

### Updated Warmup Options

**Objects Warmup** (Existing):
- Max objects
- Batch size
- Execution mode (serial/parallel/hyper)

**Files Warmup** (NEW):
- Max files to process
- File types to include
- Skip already indexed files
- Re-extract changed files

### New Warmup Endpoint

```
POST /api/solr/warmup/files
```

**Payload:**
```json
{
  "max_files": 1000,
  "batch_size": 100,
  "file_types": ["pdf", "docx", "txt"],
  "skip_indexed": true,
  "mode": "parallel"
}
```

**Response:**
```json
{
  "success": true,
  "files_processed": 847,
  "chunks_created": 4235,
  "indexed_in_solr": 4235,
  "failed": 3,
  "duration_seconds": 125,
  "stats": {
    "text_extracted": 847,
    "already_cached": 153,
    "new_extractions": 694
  }
}
```

---

## API Endpoints Summary

### File Text Management

```
GET  /api/files/{fileId}/text              - Get extracted text
POST /api/files/{fileId}/extract           - Extract text on demand
POST /api/files/extract/bulk               - Bulk extract multiple files
GET  /api/files/extraction/stats           - Get extraction statistics
```

### SOLR File Indexing

```
POST /api/solr/warmup/files                - Warmup file collection
POST /api/solr/files/{fileId}/index        - Index specific file
POST /api/solr/files/reindex               - Reindex all files
GET  /api/solr/files/stats                 - Get file indexing stats
```

---

## Processing Stages Detail

### Stage 1: Text Extraction

**Input:** Nextcloud File ID
**Output:** Raw text stored in `file_texts` table

**Supported Formats:**
- **Text:** TXT, MD, JSON, XML, CSV
- **Documents:** PDF, DOCX, XLSX, PPTX, ODT, ODS
- **Code:** PHP, JS, TS, PY, JAVA, GO, etc.
- **Images:** JPG, PNG (with OCR if enabled)

**Storage:**
```sql
INSERT INTO oc_openregister_file_texts (
    file_id, file_path, text_content, extraction_status
) VALUES (
    123, '/Documents/report.pdf', 'Extracted text...', 'completed'
);
```

### Stage 2: Text Chunking

**Input:** Text from `file_texts` table
**Output:** Chunks ready for SOLR/vectors

**Chunking Strategies:**
- **Fixed Size:** Split every N characters
- **Recursive Character:** Preserve paragraphs
- **Semantic:** Split by sections/headings
- **Sentence-Based:** Keep sentences intact

**Chunk Metadata:**
```json
{
  "file_id": 123,
  "chunk_index": 0,
  "chunk_total": 5,
  "chunk_text": "First 1000 characters...",
  "chunk_start_offset": 0,
  "chunk_end_offset": 1000,
  "chunk_page_number": 1
}
```

### Stage 3: Vectorization (Future AI)

**Input:** Chunks from Stage 2
**Output:** Vector embeddings in `vectors` table

**Flow:**
```
Chunk Text
    ↓
Generate Embedding (OpenAI/Fireworks/Ollama)
    ↓
Store in oc_openregister_vectors
    ↓
Enable semantic search
```

---

## Configuration

### Settings UI

**File Management Modal:**
- Enable/disable auto-processing
- Select file types to process
- Set extraction method (text/OCR)
- Configure chunking strategy
- Set max file size limit

**SOLR Warmup Dialog:**
- Toggle "Include Files" checkbox
- Set max files and batch size
- Choose file types
- Select execution mode

---

## Benefits

### For SOLR (Stages 1 & 2 Only)

✅ **Fast Full-Text Search**
- Search file contents without AI
- Instant results from SOLR index
- Faceting by file type, date, owner

✅ **No Re-Processing**
- Text stored in table (Stage 1)
- No need to re-extract on every search
- Only re-extract if file changes

✅ **Large File Support**
- Extract once, chunk smartly (Stage 2)
- Index chunks in SOLR file collection
- Fast retrieval via pagination

### With AI (Stage 3 Added)

✅ **Semantic Search**
- Find files by meaning, not just keywords
- "contracts about liability" finds relevant docs
- Cross-language understanding

✅ **Hybrid Search**
- Combine keyword (SOLR) + semantic (vectors)
- Best of both worlds
- Reciprocal Rank Fusion (RRF)

✅ **AI-Powered Features**
- Document summarization
- Question answering over documents
- Similar document recommendations
- Auto-categorization

---

## Migration Path

**Phase 1: Database + Storage** ✅
- Create `file_texts` table
- Create Entity/Mapper
- Create `FileTextService`

**Phase 2: Event Listener** 🔄
- Listen for file create/update
- Extract text automatically
- Store in table

**Phase 3: SOLR Integration** 🔄
- Update warmup to include files
- Index file chunks
- Add file search endpoints

**Phase 4: UI Updates** 🔜
- File warmup UI
- File search results
- Extraction status indicators

**Phase 5: AI Enhancement** 🔜
- Add vectorization (Stage 3)
- Semantic search UI
- Hybrid search results

---

## Implementation Files

### Created:
- ✅ `lib/Migration/Version002006000Date20251013000000.php` - Database migration
- 🔄 `lib/Db/FileText.php` - Entity
- 🔄 `lib/Db/FileTextMapper.php` - Mapper
- 🔄 `lib/Service/FileTextService.php` - Text storage service
- 🔄 `lib/Listener/FileChangeListener.php` - Event listener
- 🔄 `lib/Controller/FileTextController.php` - API endpoints

### Updated:
- 🔄 `lib/AppInfo/Application.php` - Register event listener
- 🔄 `lib/Service/SolrFileService.php` - Integrate with FileTextService
- 🔄 `lib/Service/GuzzleSolrService.php` - Add file indexing methods
- 🔄 `lib/Controller/SettingsController.php` - Add warmup endpoints
- 🔄 `appinfo/routes.php` - Add new routes

---

## Next Steps

1. ✅ Run migration: `php occ migrations:execute openregister 002006000`
2. 🔄 Create Entity and Mapper
3. 🔄 Create FileTextService
4. 🔄 Create FileChangeListener
5. 🔄 Update SolrFileService integration
6. 🔄 Add warmup endpoints
7. 🔄 Update UI modals
8. 🔄 Test with sample files

**Estimated Implementation Time:** 2-3 hours
**Database Storage:** ~1KB per page of text
**Performance:** ~100 files/minute extraction

