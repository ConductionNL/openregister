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
- The job is queued from the file create/modify path so extraction never blocks the user request. Extracted text is persisted in the chunks table; it is not pushed to any external search index.

### Requirement: File Text Extraction HTTP Surface

The system MUST expose an HTTP surface for the file text-extraction pipeline so
administrators and the Files UI can extract text from files, inspect extraction
statistics, search over file contents, and anonymise detected PII. Extracted
chunks stay in the database; there is no endpoint that pushes chunks into an
external search backend, and no file-collection field discovery, index warmup
or file (re)indexing endpoint.

Text extraction MUST support per-file (re-)extraction and a bounded bulk
extraction over pending files, and MUST return HTTP `501` when file management
or extraction is disabled in configuration. The surface MUST expose extraction
statistics. File search MUST support semantic (vector-similarity) and hybrid
(keyword + vector) modes over the `file` entity type, each returning a
`{success, query, total, results, search_type}` envelope and rejecting an empty
`query` with HTTP `400`. The Files-sidebar endpoints MUST return the
OpenRegister objects referencing a given Nextcloud file id and that file's
extraction status. File administration MUST expose read/update of file
settings, file-extraction statistics, and connection tests for the configured
extraction and anonymisation backends (Dolphin / Presidio / OpenAnonymiser).
Anonymisation MUST create a new anonymised copy of a file from previously
detected entities, leaving the original unchanged, and MUST reject files that
are already anonymised or have no detected entities.

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

#### Scenario: No chunk-indexing endpoint is routed
- **GIVEN** extracted file chunks in the database
- **WHEN** a client calls a former chunk process-and-index endpoint
- **THEN** the response MUST be HTTP 404
- **AND** the chunks MUST remain searchable through the file search endpoints

#### Scenario: Semantic file search rejects empty query
- **GIVEN** a semantic-search request with an empty `query`
- **WHEN** the endpoint is invoked
- **THEN** the response MUST be HTTP `400` with `{success:false, message:"Query parameter is required"}`

#### Scenario: Anonymisation guards already-anonymised files
- **GIVEN** a file whose name already contains `_anonymized`
- **WHEN** the anonymise endpoint is invoked
- **THEN** the response MUST be HTTP `400` and no new anonymised copy MUST be created
