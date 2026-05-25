---
status: proposed
retrofit_extensions:
  - Asynchronous file text extraction MUST run as a queued background job
---

# Search Index — asynchronous file text extraction (delta)

**Cross-references**: [search-index main spec](../../../../specs/search-index/spec.md).

## Purpose of this delta

The `search-index` capability covers Solr/document indexing, bulk re-indexing, and backend configuration. It does not yet describe the feeder pipeline that turns uploaded binary files into searchable text. This delta retroactively captures the observed behaviour of `FileTextExtractionJob` — the queued, one-time background job that extracts text from a single file via `TextExtractionService` so the extraction cost is paid off the user request path.

## ADDED Requirements

### Requirement: Asynchronous file text extraction MUST run as a queued background job

When file text extraction is enabled, the system MUST extract text from uploaded files asynchronously via a one-time `QueuedJob` (`FileTextExtractionJob`) rather than blocking the user request that created or modified the file. The job MUST be a no-op when extraction is disabled in configuration, MUST validate that a `file_id` argument is present, and MUST delegate the actual extraction to `TextExtractionService::extractFile()`. Failures MUST be logged and MUST NOT propagate as uncaught exceptions out of the job.

#### Scenario: Extraction is skipped when disabled
- **GIVEN** the `fileManagement` app-config either has no value or declares `extractionScope === 'none'`
- **WHEN** `FileTextExtractionJob::run()` executes
- **THEN** the job MUST log an info message that extraction is disabled
- **AND** it MUST return without calling `TextExtractionService`

#### Scenario: Missing file_id is rejected
- **GIVEN** the job is queued without a `file_id` argument
- **WHEN** `run()` executes
- **THEN** it MUST log an error naming the missing argument
- **AND** it MUST return without attempting extraction

#### Scenario: Text is extracted for a valid file id
- **GIVEN** extraction is enabled and the job argument carries a valid `file_id`
- **WHEN** `run()` executes
- **THEN** it MUST call `TextExtractionService::extractFile(fileId: <id>, forceReExtract: false)`
- **AND** it MUST log start and successful-completion entries including a processing-time metric

#### Scenario: Extraction failure is contained
- **GIVEN** `TextExtractionService::extractFile()` throws an exception
- **WHEN** `run()` executes
- **THEN** the exception MUST be caught and logged at error level with the file id and the error message
- **AND** the job MUST NOT re-throw (the failure does not crash the cron worker)

#### Notes
- The job is queued from the file create/modify path so extraction never blocks the user request — this is the asynchronous complement to the synchronous Solr indexing covered by the bulk-indexing REQ.
