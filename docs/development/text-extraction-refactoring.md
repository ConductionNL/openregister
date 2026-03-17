---
title: Text Extraction Refactoring Summary
sidebar_position: 85
---

# Text Extraction Refactoring Summary

## Overview

Text extraction has been refactored to be **completely independent of SOLR**. Previously, text extraction was tightly coupled with SOLR indexing, which created unnecessary dependencies and made the extraction process less flexible.

## Changes Made

### 1. TextExtractionService Enhancement

**File:** `lib/Service/TextExtractionService.php`

#### Added Chunking Logic
- **Moved chunking from `SolrFileService` to `TextExtractionService`**
- Added constants for chunk configuration:
  - `DEFAULT_CHUNK_SIZE`: 1000 characters
  - `DEFAULT_CHUNK_OVERLAP`: 200 characters
  - `MIN_CHUNK_SIZE`: 100 characters
  - `MAX_CHUNKS_PER_FILE`: 1000 chunks
  - Chunking strategies: `RECURSIVE_CHARACTER`, `FIXED_SIZE`

#### New Chunking Methods
- `chunkDocument()` - Main chunking interface
- `chunkFixedSize()` - Fixed-size chunking with overlap
- `chunkRecursive()` - Smart chunking that tries different separators (paragraphs, sentences, words)
- `recursiveSplit()` - Recursive text splitting algorithm
- `cleanText()` - Text normalization before chunking

#### Removed SOLR Dependency
- Removed `GuzzleSolrService` from constructor
- Removed automatic SOLR indexing after extraction
- Text extraction now stores chunks independently

### 2. FileText Entity Update

**File:** `lib/Db/FileText.php`

#### Added New Property
- `chunks_json` (LONGTEXT) - Stores JSON-encoded array of text chunks
- Each chunk contains:
  ```json
  {
    "text": "chunk content",
    "start_offset": 0,
    "end_offset": 1000
  }
  ```

### 3. Database Migration

**File:** `lib/Migration/Version1Date20251107170000.php`

- Created migration to add `chunks_json` column to `oc_openregister_file_texts` table
- Migration ran successfully on 2025-11-07

### 4. GuzzleSolrService Update

**File:** `lib/Service/GuzzleSolrService.php`

#### Updated `indexFiles()` Method
- **Before**: Generated chunks on-the-fly during SOLR indexing
- **After**: Reads pre-generated chunks from `chunks_json` field
- Benefits:
  - SOLR indexing is faster (no need to re-chunk)
  - Consistent chunking across all services
  - Chunks can be reused for embeddings, search, etc.

## Benefits of the Refactoring

### 1. Separation of Concerns
- **Text Extraction**: Handles file parsing and chunking
- **SOLR Service**: Only responsible for indexing pre-chunked data
- Each service has a single, well-defined responsibility

### 2. Improved Performance
- Chunks are generated once during extraction
- SOLR indexing no longer needs to re-chunk text
- Chunks are cached in database for reuse

### 3. Flexibility
- Chunks can be used by multiple services:
  - SOLR for full-text search
  - Vector databases for embeddings
  - AI services for text analysis
  - Export/download features
- Easy to adjust chunking strategies without affecting SOLR

### 4. Better Data Integrity
- Chunks are stored with the extracted text
- No risk of different services generating inconsistent chunks
- Chunks are versioned with the file (re-extraction updates chunks)

## Test Results

**File Tested**: `Nextcloud_Server_Administration_Manual.pdf` (8.8 MB)

**Results**:
- ✅ PDF extraction successful
- ✅ Text extracted: 416,657 characters
- ✅ Chunks generated: 551 chunks
- ✅ Average chunk size: ~756 characters
- ✅ Chunks stored in `chunks_json` field
- ✅ SOLR indexing uses pre-generated chunks

## Migration Notes

### For Existing Installations

1. **Run Migration**: The migration adds the `chunks_json` column automatically
2. **Re-extract Files**: Existing files need to be re-extracted to generate chunks
3. **SOLR Re-index**: After re-extraction, re-index files in SOLR to use new chunks

### For New Installations

- No migration needed - new installations include chunking from the start

## Related Documentation

- [Text Extraction Implementation](../technical/text-extraction-implementation.md) - Current text extraction implementation
- [LLPhant Refactor](./llphant-refactor.md) - Future refactoring plan using LLPhant

