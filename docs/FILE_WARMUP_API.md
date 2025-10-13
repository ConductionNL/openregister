# File Warmup API - Documentation

## Overview

API endpoints for bulk file processing, text extraction, chunking, and SOLR indexing.

---

## Endpoints

### 1. Warmup Files
**POST** `/api/solr/warmup/files`

Bulk process and index files in SOLR file collection.

**Request Body:**
```json
{
  "max_files": 1000,
  "batch_size": 100,
  "file_types": ["application/pdf", "text/plain"],
  "skip_indexed": true,
  "mode": "parallel"
}
```

**Response:**
```json
{
  "success": true,
  "message": "File warmup completed",
  "files_processed": 847,
  "indexed": 844,
  "failed": 3,
  "errors": ["File 123: No extracted text available"],
  "mode": "parallel"
}
```

---

### 2. Index Specific File
**POST** `/api/solr/files/{fileId}/index`

Index a single file in SOLR.

**Response:**
```json
{
  "success": true,
  "message": "File indexed successfully",
  "file_id": 5213
}
```

---

### 3. Reindex All Files
**POST** `/api/solr/files/reindex`

Reindex all files that have completed text extraction.

**Request Body:**
```json
{
  "max_files": 1000,
  "batch_size": 100
}
```

**Response:**
```json
{
  "success": true,
  "message": "Reindex completed",
  "files_processed": 500,
  "indexed": 497,
  "failed": 3,
  "errors": []
}
```

---

### 4. Get File Index Statistics
**GET** `/api/solr/files/stats`

Get statistics about indexed files.

**Response:**
```json
{
  "success": true,
  "total_chunks": 4235,
  "unique_files": 847,
  "mime_types": {
    "application/pdf": 500,
    "text/plain": 200,
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": 147
  },
  "collection": "openregister_files"
}
```

---

## Implementation Details

### Controller Methods (SettingsController.php)

1. **`warmupFiles()`**
   - Gets files that need indexing
   - Filters by MIME type if specified
   - Processes in batches
   - Returns comprehensive results

2. **`indexFile(int $fileId)`**
   - Indexes a single file
   - Returns success/failure

3. **`reindexFiles()`**
   - Gets all completed file texts
   - Reindexes in batches
   - Returns statistics

4. **`getFileIndexStats()`**
   - Queries SOLR for statistics
   - Returns chunk counts and file counts

---

## Usage Examples

### cURL: Warmup Files
```bash
curl -X POST -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -d '{
    "max_files": 500,
    "batch_size": 50,
    "file_types": ["application/pdf"],
    "skip_indexed": true
  }' \
  http://localhost/index.php/apps/openregister/api/solr/warmup/files
```

### cURL: Index Specific File
```bash
curl -X POST -u 'admin:admin' \
  http://localhost/index.php/apps/openregister/api/solr/files/5213/index
```

### cURL: Get Stats
```bash
curl -u 'admin:admin' \
  http://localhost/index.php/apps/openregister/api/solr/files/stats
```

---

## Error Handling

All endpoints return proper HTTP status codes:
- **200**: Success
- **422**: Unprocessable (e.g., file has no extracted text)
- **500**: Internal server error

Error responses include:
```json
{
  "success": false,
  "message": "Error description here"
}
```

---

## Integration with Frontend

These endpoints will be used by:
1. **SOLR Configuration Modal** - File warmup UI
2. **File Management Dialog** - Individual file indexing
3. **Dashboard** - Statistics display

---

**Date:** 2025-10-13  
**Status:** ✅ COMPLETE

