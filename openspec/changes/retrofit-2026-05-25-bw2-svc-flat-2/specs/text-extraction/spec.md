---
retrofit: true
---

# Text Extraction

## Why

`TextExtractionService` consolidates the file→chunk extraction lifecycle
that feeds search and vector indexing: it extracts a file or object into
chunks, detects when re-extraction is needed, discovers files that have
never been extracted, drains a pending queue, retries failures, and
reports stats. No capability spec owns this lifecycle (the vector
embeddings capability begins downstream, once chunks exist). This change
establishes a `text-extraction` capability anchoring the observed
behavior.

## ADDED Requirements

### Requirement: REQ-001 The system MUST extract files and objects into chunks with re-extraction detection and a tracked extraction queue
The system MUST extract a file or object into stored chunks, MUST skip re-extraction when the source is unchanged unless re-extraction is forced, MUST be able to discover never-extracted (untracked) files, drain a bounded pending-extraction queue, retry previously failed extractions, chunk raw text into bounded segments, and report extraction statistics.

`TextExtractionService::extractFile()` MUST resolve the NC file, determine whether stored chunks are up to date relative to the source modification time, and skip extraction when up to date and not forced; a missing file MUST raise `NotFoundException`. `extractObject()` MUST extract an object's content the same way. `chunkDocument()` MUST split raw text into bounded chunks per the supplied options. `discoverUntrackedFiles()`, `extractPendingFiles()`, and `retryFailedExtractions()` MUST each operate over a bounded batch (`limit`) and return a per-run summary. `getStats()` MUST report aggregate extraction state (e.g. tracked / pending / failed counts).

#### Scenario: Unchanged file skips re-extraction
- **GIVEN** a file whose stored chunks are current relative to its modification time
- **WHEN** `extractFile()` is called without forcing re-extraction
- **THEN** extraction MUST be skipped and the existing chunks left intact

#### Scenario: Forced re-extraction overrides up-to-date chunks
- **GIVEN** a file whose chunks are current
- **WHEN** `extractFile()` is called with re-extraction forced
- **THEN** the file MUST be re-extracted regardless of the modification time

#### Scenario: Pending queue drains within the batch limit
- **GIVEN** more pending files than the requested batch limit
- **WHEN** `extractPendingFiles($limit)` runs
- **THEN** at most `$limit` files MUST be processed and a per-run summary returned
