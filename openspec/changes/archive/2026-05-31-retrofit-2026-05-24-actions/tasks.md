# Tasks

## In-scope REQs (annotated this pass)

- [x] task-1: actions#REQ-006 — Admin-only gating for Actions mutation endpoints (retroactive annotation)
- [x] task-2: actions#REQ-007 — Dry-run test endpoint with match diagnostics (retroactive annotation)
- [x] task-3: actions#REQ-008 — Idempotent migration from inline schema hooks (retroactive annotation)
- [x] task-4: actions#REQ-009 — Per-action execution log retrieval with statistics (retroactive annotation)
- [x] task-5: actions#REQ-010 — Pagination, field-filter, and substring search on list endpoint (retroactive annotation)

## Method → task map (for annotation step)

- task-1 → `lib/Controller/ActionsController.php::requireAdmin`
- task-2 → `lib/Controller/ActionsController.php::test`
- task-2 → `lib/Service/ActionService.php::testAction`
- task-3 → `lib/Controller/ActionsController.php::migrateFromHooks`
- task-3 → `lib/Service/ActionService.php::migrateFromHooks`
- task-4 → `lib/Controller/ActionsController.php::logs`
- task-5 → `lib/Controller/ActionsController.php::index` (carries REQ-001 link from previous pass; this pass adds REQ-010)

## Deferred to `future-pass:next`

These behaviors are observable and worth pinning down, but defer to a subsequent retrofit pass to keep this PR ≤ 5 REQs:

- `future-pass:next` — `ActionService::updateStatistics` per-execution counter side effects (success vs failure vs abandoned) — currently bundled into REQ-001 mention only
- `future-pass:next` — `ActionService::HOOK_EVENT_MAP` legacy-key→event-class normalisation table (referenced by REQ-008 but not enumerated)
- `future-pass:next` — `ActionService::getNestedValue` dot-notation accessor contract (used by REQ-007 filter matching)
- `future-pass:next` — `ActionsController::index` exception-path observability (logger.error message format, error envelope shape) — REQ-010 only covers happy path
- `future-pass:next` — Soft-delete + re-create with same name interaction with REQ-008 idempotency

## DROP (out-of-cluster — sibling capability owns these)

The Phase-2 scan name-matched 78 methods that live under `lib/Service/TextExtraction/*`, `lib/Service/TextExtractionService.php`, `lib/Service/TextExtraction/EntityRecognitionHandler.php`, `lib/BackgroundJob/FileTextExtractionJob.php`, and two frontend files. None of these are workflow Actions. Most were already DROP-triaged by the coverage scan; this pass confirms and notes the proper owner:

### text-extraction-eml (existing capability)
- `lib/Service/TextExtraction/EmlAttachment.php::jsonSerialize`
- `lib/Service/TextExtraction/EmlBody.php::jsonSerialize`
- `lib/Service/TextExtraction/EmlStructure.php::jsonSerialize`
- `lib/Service/TextExtraction/EmlParser.php::stripAngleBrackets`, `splitAddressList`, `resolveFilename`, `sanitiseFilename`, `getParser`

### text-extraction-vectorization (uncovered — future cluster)
- `lib/Service/TextExtraction/FileHandler.php::__construct`, `extractText`, `needsExtraction`, `getSourceMetadata`, `getSourceTimestamp`, `performTextExtraction`, `detectLanguage`, `getSourceType`
- `lib/Service/TextExtraction/ObjectHandler.php::__construct`, `extractText`, `needsExtraction`, `extractTextFromArray`, `getSourceMetadata`, `getSourceTimestamp`, `getSourceType`
- `lib/Service/TextExtraction/TextExtractionHandlerInterface.php::extractText`, `getSourceMetadata`
- `lib/Service/TextExtractionService.php::__construct`, `extractFile`, `extractObject`, `extractSourceText`, `textToChunks`, `summarizeMetadataPayload`, `performTextExtraction`, `discoverUntrackedFiles`, `extractPendingFiles`, `retryFailedExtractions`, `getTableCountSafe`, `sanitizeText`, `extractPdf`, `extractWord`, `extractSpreadsheet`, `isWordDocument`, `isSpreadsheet`, `isSourceUpToDate`, `hydrateChunkEntity`, `persistMetadataChunk`, `chunkDocument`, `chunkFixedSize`, `recursiveSplit`, `getDetectionMethod`, `calculateAvgChunkSize`, `detectLanguageSignals`, `buildPositionReference`, `persistChunksForSource`, `cleanText`, `chunkRecursive`, `getStats`
- `lib/BackgroundJob/FileTextExtractionJob.php::__construct`, `run`
- `src/components/files-sidebar/ExtractionTab.vue::fetchExtractionStatus`

### pii-entity-recognition (uncovered — future capability, GDPR-adjacent)
- `lib/Service/TextExtraction/EntityRecognitionHandler.php::__construct`, `processSourceChunks`, `extractFromChunk`, `storeDetectedEntities`, `detectEntities`, `getRegexPatterns`, `buildAnalyzeRequestBody`, `postAnalyzeRequest`, `convertApiResultsToEntities`, `mapToPresidioEntityTypes`, `mapFromPresidioEntityType`, `populateObjectContextOnRelation`, `detectWithLLM`, `extractContext`, `detectWithRegex`, `detectWithPresidio`, `detectWithOpenAnonymiser`, `detectWithHybrid`, `findOrCreateEntity`, `getCategoryForType`

### mail-sidebar (frontend, sibling)
- `src/mail-sidebar/components/ActionsTab.vue::objectName`

### Plain DTO constructors (covered transitively by actions#REQ-001)
- `lib/Event/ActionCreatedEvent.php::__construct`
- `lib/Event/ActionDeletedEvent.php::__construct`
- `lib/Event/ActionUpdatedEvent.php::__construct`
