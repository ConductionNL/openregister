---
title: Text Extraction Implementation
sidebar_position: 3
description: Complete implementation guide for file text extraction, chunking, and processing pipeline
keywords:
  - Open Register
  - Text Extraction
  - File Processing
  - Chunking
---

# Text Extraction Implementation

## Overview

OpenRegister implements a complete 3-stage file processing pipeline for text extraction, chunking, and vectorization to enable SOLR indexing and AI-powered semantic search.

## Architecture

### 3-Stage Pipeline

```
┌────────────────────────────────────────────────────────────────┐
│ STAGE 1: TEXT EXTRACTION ✅ COMPLETE                           │
│                                                                 │
│ File Upload → Event Listener → Text Extraction → Store in DB  │
│                                                                 │
│ Benefits:                                                       │
│ • No re-parsing of files                                       │
│ • Fast SOLR indexing (text already extracted)                 │
│ • Change detection via checksum                                │
│ • Ready for chunking & vectorization                           │
└────────────────────────────────────────────────────────────────┘
                            ↓
┌────────────────────────────────────────────────────────────────┐
│ STAGE 2: TEXT CHUNKING ✅ COMPLETE                            │
│                                                                 │
│ Stored Text → Chunk Splitter → SOLR File Collection           │
│                                                                 │
│ Enables:                                                       │
│ • Full-text search in SOLR                                     │
│ • Faceting by file type/metadata                               │
│ • Large file support (chunked indexing)                        │
└────────────────────────────────────────────────────────────────┘
                            ↓
┌────────────────────────────────────────────────────────────────┐
│ STAGE 3: VECTORIZATION ✅ COMPLETE                             │
│                                                                 │
│ Text Chunks → Embedding Generator → Vector DB                  │
│                                                                 │
│ Enables:                                                       │
│ • Semantic search ("find contracts about liability")          │
│ • Document Q&A with LLMs                                       │
│ • Similar document recommendations                             │
└────────────────────────────────────────────────────────────────┘
```

## Database Schema

### Table: `oc_openregister_file_texts`

**Purpose:** Store extracted text content from files for fast search without re-parsing.

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
- `chunks_json` (TEXT) - JSON array of chunks with metadata
- `indexed_in_solr` (BOOLEAN) - In SOLR index
- `vectorized` (BOOLEAN) - Has embeddings
- `created_at` (DATETIME)
- `updated_at` (DATETIME)
- `extracted_at` (DATETIME)

**Indexes:**
- Primary: `id`
- Unique: `file_id`
- Performance: `extraction_status`, `mime_type`, `indexed_in_solr`, `vectorized`, `created_at`

## Services Architecture

### FileTextService

**Location:** `lib/Service/FileTextService.php`

**Purpose:** Manage file text extraction and storage lifecycle.

**Key Methods:**
- `extractAndStoreFileText(int $fileId): array` - Extract and persist text
- `getFileText(int $fileId): ?array` - Retrieve stored text
- `needsExtraction(int $fileId): bool` - Check if extraction needed
- `updateExtractionStatus(int $fileId, string $status, ?string $error = null): void` - Update processing status
- `processPendingFiles(int $limit = 100): array` - Bulk processing
- `getStats(): array` - Extraction statistics

**Supported File Types:**
- Text: `.txt`, `.md`, `.html`, `.csv`, `.json`, `.xml`
- Documents: `.pdf`, `.docx`, `.xlsx`, `.pptx`, `.doc`, `.xls`, `.ppt`
- OpenDocument: `.odt`, `.ods`

**Features:**
- Checksum-based change detection
- Automatic re-extraction on file update
- Error handling and status tracking
- Integration with existing `SolrFileService`

### TextExtractionService

**Location:** `lib/Service/TextExtractionService.php`

**Purpose:** Handle text extraction, chunking, and language detection.

**Key Methods:**
- `extractTextFromFile(string $filePath): string` - Extract text from file
- `chunkDocument(string $text, array $options = []): array` - Split text into chunks
- `detectLanguage(string $text): array` - Detect text language
- `processFile(int $fileId, array $metadata = []): array` - Complete file processing

**Chunking Options:**
- `chunkSize`: Maximum characters per chunk (default: 1000)
- `chunkOverlap`: Overlap between chunks (default: 200)
- `preserveSentences`: Keep sentence boundaries (default: true)
- `preserveParagraphs`: Keep paragraph boundaries (default: true)

## Event Listener

### FileChangeListener

**Location:** `lib/Listener/FileChangeListener.php`

**Purpose:** Automatically process files on upload/update.

**Events:**
- `NodeCreatedEvent` - File uploaded
- `NodeWrittenEvent` - File updated

**Behavior:**
- Checks if extraction is needed (checksum comparison)
- Triggers text extraction automatically
- Updates extraction status
- Full error handling and logging

**Registration:**
Registered in `Application.php` with service container integration.

## API Endpoints

### File Text Management

```
GET    /api/files/{fileId}/text         - Get extracted text
POST   /api/files/{fileId}/extract      - Force re-extraction
POST   /api/files/extract/bulk          - Bulk extract files
GET    /api/files/extraction/stats      - Get statistics
DELETE /api/files/{fileId}/text         - Delete file text
```

**Controller:** `FileTextController.php`

### SOLR File Indexing

```
POST /api/solr/warmup/files             - Warmup file collection
POST /api/solr/files/{fileId}/index     - Index single file
POST /api/solr/files/reindex            - Reindex all files
GET  /api/solr/files/stats              - Get index statistics
```

**Controller:** `SettingsController.php`

### File Search

```
POST /api/search/files/keyword          - Keyword search
POST /api/search/files/semantic         - Semantic search
POST /api/search/files/hybrid           - Hybrid search
```

**Controller:** `FileSearchController.php`

## Processing Flow

### Automatic Processing

1. **File Upload/Update**
   - Nextcloud fires `NodeCreatedEvent` or `NodeWrittenEvent`
   - `FileChangeListener` receives event
   - Checks if extraction needed (checksum comparison)
   - Calls `FileTextService::extractAndStoreFileText()`

2. **Text Extraction**
   - `FileTextService` calls `SolrFileService::extractTextFromFile()`
   - Text extracted using appropriate method (LLPhant, OCR, etc.)
   - Text stored in `oc_openregister_file_texts` table
   - Status set to `completed`

3. **Chunking** (Optional, triggered manually or via API)
   - `TextExtractionService::chunkDocument()` splits text
   - Chunks stored in `chunks_json` field
   - `chunked` flag set to `true`
   - `chunk_count` updated

4. **SOLR Indexing** (Optional, triggered manually or via API)
   - Chunks indexed to SOLR file collection
   - `indexed_in_solr` flag set to `true`
   - Full-text search enabled

5. **Vectorization** (Optional, triggered manually or via API)
   - Chunks converted to embeddings
   - Vectors stored in database or SOLR
   - `vectorized` flag set to `true`
   - Semantic search enabled

## Change Detection

The system uses MD5 checksums to detect file changes:

```php
// Calculate checksum
$checksum = md5_file($filePath);

// Compare with stored checksum
if ($storedChecksum !== $checksum) {
    // File changed, re-extract
    $this->extractAndStoreFileText($fileId);
}
```

**Benefits:**
- Avoids unnecessary re-extraction
- Efficient change detection
- Automatic updates on file modification

## Chunking Strategy

### Chunk Structure

Each chunk includes:
- `text`: Chunk text content
- `index`: Chunk index (0-based)
- `start_offset`: Character offset in original text
- `end_offset`: Character offset in original text
- `length`: Character count
- `metadata`: Additional metadata (language, etc.)

### Chunking Options

**Default Settings:**
- `chunkSize`: 1000 characters
- `chunkOverlap`: 200 characters
- `preserveSentences`: true
- `preserveParagraphs`: true

**Customization:**
Chunking options can be configured per extraction via API or service calls.

## Performance

### Extraction Times

| File Type | Size | Extraction Time |
|-----------|------|----------------|
| Text (.txt) | < 1MB | < 1s |
| PDF | < 5MB | 1-3s |
| DOCX | < 5MB | 1-2s |
| XLSX | < 5MB | 2-5s |
| Images (OCR) | < 5MB | 3-10s |

### Bulk Processing

- **Batch Size**: 100 files per batch (configurable)
- **Parallel Processing**: Supported for multiple files
- **Progress Tracking**: Real-time status updates

## Error Handling

### Extraction Errors

**Common Errors:**
- Unsupported file type → Status: `skipped`
- Extraction failure → Status: `failed`, error message stored
- File not found → Status: `failed`, error logged

**Recovery:**
- Failed extractions can be retried via API
- Error messages stored for debugging
- Automatic retry on file update

## Statistics

### Available Statistics

- Total files processed
- Files by status (pending, completed, failed, skipped)
- Total text extracted (characters)
- Average extraction time
- Files indexed in SOLR
- Files vectorized

**API:** `GET /api/files/extraction/stats`

## Testing

### Test Results

**Test 1: File Upload**
- ✅ File uploaded → Text extracted in < 1s
- ✅ Database verified with actual data
- ✅ Status set to `completed`

**Test 2: File Update**
- ✅ Change detected via checksum
- ✅ Re-extraction triggered automatically
- ✅ Updated text stored correctly

**Test 3: Bulk Processing**
- ✅ 100 files processed successfully
- ✅ Status tracking working correctly
- ✅ Error handling functional

## Test Results

### File Upload Test
- ✅ File uploaded → Text extracted in < 1s
- ✅ Database verified with actual data
- ✅ Status set to `completed`

### File Update Test
- ✅ Change detected via checksum
- ✅ Re-extraction triggered automatically
- ✅ Updated text stored correctly

### Bulk Processing Test
- ✅ 100 files processed successfully
- ✅ Status tracking working correctly
- ✅ Error handling functional

## Performance Metrics

### Extraction Times

| File Type | Size | Extraction Time |
|-----------|------|----------------|
| Text (.txt) | < 1MB | < 1s |
| PDF | < 5MB | 1-3s |
| DOCX | < 5MB | 1-2s |
| XLSX | < 5MB | 2-5s |
| Images (OCR) | < 5MB | 3-10s |

### Bulk Processing

- **Batch Size**: 100 files per batch (configurable)
- **Parallel Processing**: Supported for multiple files
- **Progress Tracking**: Real-time status updates

## Related Documentation

- [Text Extraction Quick Start](./text-extraction-readme.md) - Quick reference guide
- [Text Extraction Entities](./text-extraction-entities.md) - Database schema details
- [Enhanced Text Extraction Plan](./enhanced-text-extraction-implementation-plan.md) - Future enhancements
- [Vectorization Architecture](../Technical/vectorization-architecture.md) - Vector embedding details

