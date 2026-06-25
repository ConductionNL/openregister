---
status: done
---

# text-extraction Specification

## Purpose

@e2e exclude backend text extraction service — covered by PHPUnit
TBD - created by archiving change retrofit-2026-05-25-bw-svc-mid2. Update Purpose after archive.
## Requirements
### Requirement: Source handlers MUST implement a common extraction contract (REQ-001)
The system MUST expose a `TextExtractionHandlerInterface` that every
source-type handler implements, so the orchestrating service can extract text
from any registered source type without knowing its concrete type. The
interface MUST declare at minimum `extractText(int $sourceId, array
$sourceMeta, bool $force): array` and `getSourceMetadata(int $sourceId):
array`. The `extractText` return array MUST carry a stable, normalised shape
across all handlers, including the keys `source_type`, `source_id`, `text`,
`length`, `checksum`, `method`, `owner`, `organisation`, `language`,
`language_level`, `language_confidence`, `detection_method`, and `metadata`.
`getSourceMetadata` MUST throw `DoesNotExistException` when the source cannot
be resolved.

#### Scenario: Handler conforms to the interface contract
- **GIVEN** a concrete handler such as `FileHandler` or `ObjectHandler`
- **WHEN** it is registered with the orchestrating `TextExtractionService`
- **THEN** it MUST implement `TextExtractionHandlerInterface`
- **AND** `extractText()` MUST return an array containing every normalised key
  (`source_type`, `source_id`, `text`, `length`, `checksum`, `method`,
  `owner`, `organisation`, `language`, `language_level`,
  `language_confidence`, `detection_method`, `metadata`)

#### Scenario: Missing source metadata raises a typed exception
- **GIVEN** a source ID that does not resolve to a stored source
- **WHEN** `getSourceMetadata($sourceId)` is called
- **THEN** the handler MUST throw `DoesNotExistException`

### Requirement: Handlers MUST extract normalised text and a stable checksum from their source (REQ-002)
Each handler's `extractText()` MUST load the source by ID, derive a plain-text
projection of its content, and return the normalised result array. The `text`
field MUST be a non-empty string — when no text can be derived the handler MUST
throw rather than return an empty result. The `checksum` field MUST be a
SHA-256 hash of the extracted text so callers can detect unchanged content. The
`metadata` field MUST carry source-specific identifying detail (e.g. file path
/ name / MIME type / size for files; uuid / schema_id / register_id / version
for objects). Object extraction MUST recursively flatten nested object data
into `key: value` lines with a bounded recursion depth.

#### Scenario: File extraction returns normalised result with checksum
- **GIVEN** a Nextcloud file resolvable by ID with extractable text content
- **WHEN** `FileHandler::extractText($fileId, $meta, false)` is called
- **THEN** the result `source_type` MUST be `"file"` and `method` MUST be
  `"file_extraction"`
- **AND** `checksum` MUST equal `hash('sha256', $text)`
- **AND** `metadata` MUST include `file_path`, `file_name`, `mime_type`, and
  `file_size`

#### Scenario: Object extraction flattens nested data within a depth bound
- **GIVEN** a register object whose `object` payload contains nested arrays
- **WHEN** `ObjectHandler::extractText($objectId, $meta, false)` is called
- **THEN** the `text` MUST include the object UUID, resolved schema/register
  titles, and flattened `key: value` lines from the object data
- **AND** recursion into nested arrays MUST stop beyond a fixed depth bound

#### Scenario: No extractable text throws rather than returning empty
- **GIVEN** a source from which no text can be derived
- **WHEN** `extractText()` is called
- **THEN** the handler MUST throw an exception naming the source ID rather than
  returning an empty `text`

### Requirement: Handlers MUST gate re-extraction on source freshness (REQ-003)
Each handler MUST expose `needsExtraction(int $sourceId, int $sourceTimestamp,
bool $force): bool` and `getSourceTimestamp(int $sourceId): int` so the
orchestrator can skip work when previously-produced chunks are still
up-to-date. `needsExtraction` MUST return `true` when `force` is set, `true`
when no chunks exist yet for the source, and otherwise `true` only when the
latest chunk timestamp predates the supplied source timestamp.
`getSourceTimestamp` MUST return the source's last-modified Unix timestamp,
falling back to the current time when the source cannot be resolved.

#### Scenario: Force always triggers re-extraction
- **GIVEN** any source with up-to-date chunks
- **WHEN** `needsExtraction($sourceId, $ts, true)` is called
- **THEN** it MUST return `true`

#### Scenario: Stale chunks trigger re-extraction
- **GIVEN** a source whose latest chunk timestamp is older than the source's
  current modification timestamp
- **WHEN** `needsExtraction($sourceId, $sourceTimestamp, false)` is called
- **THEN** it MUST return `true`

#### Scenario: Up-to-date chunks skip re-extraction
- **GIVEN** a source whose latest chunk timestamp is at or after the source's
  modification timestamp
- **WHEN** `needsExtraction($sourceId, $sourceTimestamp, false)` is called
- **THEN** it MUST return `false`

#### Scenario: Timestamp falls back to now on unresolved source
- **GIVEN** a source ID that does not resolve
- **WHEN** `getSourceTimestamp($sourceId)` is called
- **THEN** it MUST return the current Unix timestamp instead of throwing

### Requirement: File and Object Chunk-Extraction Lifecycle
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

