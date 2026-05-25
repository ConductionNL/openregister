---
status: draft
---

# search-index

## Purpose

Extend the search-index capability with the file text extraction and indexing
HTTP surface. The existing spec covers backend routing through
`SearchBackendInterface`, schema-to-collection mirroring, and bulk object
indexing, but has no requirement for the controller endpoints that drive file
text extraction, chunk indexing, search over file chunks, extraction statistics,
file-index administration, and PII anonymisation. Reverse-specced from
`FileTextController`, `FileSearchController`, `FileSidebarController`, and
`FileSettingsController`.

## ADDED Requirements

### Requirement: File Text Extraction and Indexing HTTP Surface

The system MUST expose an HTTP surface for the file text-extraction-and-indexing
pipeline so administrators and the Files UI can extract text from files, index
the resulting chunks into the configured search backend, inspect extraction and
chunking statistics, search over file contents, and anonymise detected PII.

Text extraction MUST support per-file (re-)extraction and a bounded bulk
extraction over pending files, and MUST return HTTP `501` when file management
or extraction is disabled in configuration. Chunk indexing MUST process
unindexed chunks into the search backend and report indexing counts. The
surface MUST expose extraction statistics and chunking statistics. File search
MUST support semantic (vector-similarity) and hybrid (keyword + vector) modes
over the `file` entity type, each returning a `{success, query, total, results,
search_type}` envelope and rejecting an empty `query` with HTTP `400`. The
Files-sidebar endpoints MUST return the OpenRegister objects referencing a given
Nextcloud file id and that file's extraction status. File-index administration
MUST expose: read/update of file settings, file-collection field discovery and
creation, index warmup, per-file and bulk (re)indexing, file-index and
file-extraction statistics, and connection tests for the configured extraction
and anonymisation backends (Dolphin / Presidio / OpenAnonymiser). Anonymisation
MUST create a new anonymised copy of a file from previously detected entities,
leaving the original unchanged, and MUST reject files that are already
anonymised or have no detected entities.

#### Scenario: Force per-file text extraction
- **GIVEN** file management is enabled with an extraction scope other than `none`
- **WHEN** a POST request is sent to extract text for a file id
- **THEN** the controller MUST force re-extraction via the extraction service and return success

#### Scenario: Extraction disabled yields 501
- **GIVEN** file management is absent or its `extractionScope` is `none`
- **WHEN** per-file extraction is requested
- **THEN** the response MUST be HTTP `501` with `{success:false, message:"Text extraction disabled"}`

#### Scenario: Bulk extraction is bounded
- **GIVEN** a bulk-extract request with a `limit` above the cap
- **WHEN** the controller invokes the extraction service
- **THEN** the processed batch MUST be capped (at most 500 files) and the response MUST report `processed`/`failed`/`total`

#### Scenario: Chunk indexing reports counts
- **GIVEN** extracted file chunks awaiting indexing
- **WHEN** the process-and-index endpoint is invoked
- **THEN** unindexed chunks MUST be processed into the search backend and the response MUST carry the indexing result

#### Scenario: Semantic file search rejects empty query
- **GIVEN** a semantic-search request with an empty `query`
- **WHEN** the endpoint is invoked
- **THEN** the response MUST be HTTP `400` with `{success:false, message:"Query parameter is required"}`

#### Scenario: Anonymisation guards already-anonymised files
- **GIVEN** a file whose name already contains `_anonymized`
- **WHEN** the anonymise endpoint is invoked
- **THEN** the response MUST be HTTP `400` and no new anonymised copy MUST be created
