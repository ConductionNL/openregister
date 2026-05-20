## 1. Value objects and constants

- [x] 1.1 Create `lib/Service/File/ChunkTextMatcher.php` value-object-ish utility class. Public methods:
    - `match(array $chunks, string $needle, bool $wholeWord, bool $caseSensitive, int $chunkOverlap): array` returning `[['chunkId' => int, 'positionStart' => int, 'positionEnd' => int, 'context' => string], ...]` in document order (sorted by absolute position).
    - Algorithm: per-chunk `preg_match_all` with `u` flag + optional `\b...\b` + optional `i`. For each match compute `absoluteStart = chunk.startOffset + chunkRelativeOffset`. Dedup matches across chunks by `(absoluteStart, absoluteEnd)`, keeping the entry from the LOWEST `chunkIndex` containing each unique absolute position. Sort by absoluteStart. Extract context window of ~30 chars on each side from the canonical chunk's text.
    - Reject needles where `strlen(needle) > chunkOverlap` with a typed `ChunkMatcherException(reason: 'value_too_long')`. Message MUST NOT contain the needle.
    - MUST NOT concatenate chunk text into a single string for the regex pass (the overlap regions would produce duplicate matches that the dedup would have to undo anyway, plus much higher memory cost).
    - PII-redacted error handling: regex-compile failure (malformed Unicode in needle) throws typed `ChunkMatcherException(reason: 'regex_compile_failure')` with a message that does NOT contain the needle.
- [x] 1.2 Create `lib/Exception/ManualEntityException.php` extending `\Exception`. Used for orchestration-layer failures with typed `reason` codes: `file_not_extracted`, `unsupported_entity_type`, `regex_compile_failure`, `internal_error`. Messages MUST NOT contain the operator's `value`. **Implementation note:** also created `lib/Exception/ChunkMatcherException.php` for the matcher-layer failures (value too long, regex compile) — service translates matcher exceptions into orchestration exceptions.
- [x] 1.3 Add `DetectionMethod` constants. Either a new `lib/Db/DetectionMethod.php` class with `const PRESIDIO = 'presidio'; const OPENANONYMISER = 'openanonymiser'; const PATTERN = 'pattern'; const MANUAL = 'manual';` OR a const block on `EntityRelationMapper`. Mention via PhpDoc on the `EntityRelation::$detectionMethod` property docblock. **Implemented as standalone class** `lib/Db/DetectionMethod.php`.

## 2. Mapper extensions

- [x] 2.1 `lib/Db/GdprEntityMapper.php` — add `findOneByValueAndType(string $value, string $type): ?GdprEntity`. SELECT WHERE value = :value AND type = :type LIMIT 2 — if 2 rows return, the dedup invariant is violated, log a warning, return the first; never throw. **Implementation note:** added a nullable `LoggerInterface` constructor param so legacy direct-construction callers stay working; the warning is silently dropped when no logger is wired.
- [x] 2.2 `lib/Db/EntityRelationMapper.php` — add `existsForFileAtPosition(int $fileId, int $entityId, int $chunkId, int $positionStart, int $positionEnd): bool`. SELECT 1 FROM oc_openregister_entity_relations WHERE all five values match. Single-row probe.
- [x] 2.3 `lib/Db/EntityRelationMapper.php` — add `insertBatch(array $rows): array` accepting an array of associative arrays each carrying the relation field set. Validates required fields, calls `insert` per row inside the caller's transaction (caller manages BEGIN/COMMIT). Returns the array of inserted `EntityRelation` entities with their generated ids. **Implementation note:** the `openregister_entity_relations` table has no `uuid` column (verified against the create-migration); the row payload omits `uuid` and the response excludes it.

## 3. ManualEntityService orchestrator

- [x] 3.1 Create `lib/Service/File/ManualEntityService.php`. Constructor injects:
    - `GdprEntityMapper`
    - `EntityRelationMapper`
    - `ChunkTextMatcher`
    - `AuditTrailMapper`
    - `IUserSession` (for actor UID + write-access check)
    - `LoggerInterface`
    - The handler/service that resolves chunks for a fileId (likely `TextExtractionService` or a chunk-specific reader)
    - `IDBConnection` (for explicit transaction control)
- [x] 3.2 Implement public `addManualEntity(int $fileId, string $value, string $type, ?string $category, bool $wholeWord, bool $caseSensitive, IUser $actor): ManualEntityResult`. Steps:
    1. Resolve the file's chunks. Empty array → throw `ManualEntityException(reason: 'file_not_extracted')`.
    2. `BEGIN TRANSACTION`.
    3. `lookup-or-create` entity via `GdprEntityMapper::findOneByValueAndType` + (insert if missing).
    4. Run the matcher.
    5. For each match: probe `existsForFileAtPosition`. If exists → bump `matchesSkipped`. Otherwise → buffer for batch insert.
    6. `insertBatch` the buffered rows.
    7. Write audit-trail rows (entity_create when new, entity_relations_batch_create always).
    8. `COMMIT`. On any exception during steps 3-7, `ROLLBACK` and re-throw.
- [x] 3.3 Implement private `writeAuditTrails(GdprEntity $entity, bool $entityWasNew, int $fileId, string $value, string $type, array $insertedRelations, int $matchesSkipped, IUser $actor): void`. Two action types per the spec. **Implementation note:** signature also threads `?string $category` so the `entity_create` audit row carries it.
- [x] 3.4 Implement private `assertFileWriteAccess(int $fileId, IUser $actor): void`. Reuses the same helper that `markAsAnonymized` and `PATCH /api/entity-relations/{id}` already use. Throws on denial; the caller translates the exception to HTTP 403. **Implementation note:** inlined the user-folder + `isUpdateable()` check (mirrors `EntityRelationsController::canWriteFile`) since there's no shared helper service today; throws `ManualEntityException` with a `forbidden:` message prefix so the controller maps it to 403.
- [x] 3.5 Create a `ManualEntityResult` DTO carrying `{entity: GdprEntity, entityWasNew: bool, relations: EntityRelation[], matchCount: int, matchesSkipped: int}`. Used as the service's return value; the controller maps it to the JSON response body.

## 4. Controller endpoint

- [x] 4.1 In `lib/Controller/FileTextController.php`, add `public function addManualEntity(int $fileId): JSONResponse`. Steps:
    1. Validate content-type header is `application/json`. Otherwise 415.
    2. Parse the JSON body. Missing `value` or `type` → 400 with `{ "error": "invalid_request", "field": "value" }` (no PII in the response).
    3. Read body fields: `value`, `type`, optional `category`, optional `wholeWord` (default true), optional `caseSensitive` (default true).
    4. Resolve actor via `IUserSession::getUser()`. If null → 401.
    5. Wrap the service call in a try/catch on `ManualEntityException`. Per the typed reason, translate to 403 / 404 / 422 / 500 with the matching response body.
    6. On `IAccessForbiddenException` (or equivalent from `assertFileWriteAccess`) → 403 with `{ "error": "forbidden" }`.
    7. On success: format the response per the proposal. HTTP 201 when `matchCount > 0`; HTTP 200 when `matchCount == 0` (with the `message` field).
- [x] 4.2 Add the route entry in `appinfo/routes.php`:
    ```php
    [
        'name' => 'fileText#addManualEntity',
        'url'  => '/api/files/{fileId}/manual-entities',
        'verb' => 'POST',
        'requirements' => ['fileId' => '\\d+'],
    ],
    ```
- [x] 4.3 PII-redacted request logging: at the top of `addManualEntity`, log the request with `value` replaced by `valueLength: strlen($value)`. Permitted log payload: `fileId`, `type`, `wholeWord`, `caseSensitive`, `valueLength`. No `value`, no `category` (categories don't carry PII but are also not useful in logs). **Implementation note:** also logs the actor UID alongside (ADR-005 allows UID).

## 5. DocumentProcessingHandler — no changes required

- [ ] 5.1 Verify that `findEntitiesForAnonymization` already includes manual-method rows by checking it does NOT filter on `detection_method`. Add a test confirming a manual-method relation is returned by this method.
- [ ] 5.2 Verify that `findEntityIdsByValueForFile` already includes manual-method rows. Add a test confirming a manual entity's `entity_id` is in the resulting map.

## 6. Spec maintenance

- [ ] 6.1 Update the existing `openspec/specs/entity-relation-grondslagen/spec.md` (the canonical capability spec, NOT the in-flight change delta). Add this change to the change-list. Set `**Status**: in-progress`. Apply the spec-history grouping rule from `.claude/docs/writing-specs.md` if the list exceeds 15 entries.

## 7. Tests

- [x] 7.1 `tests/Unit/Service/File/ChunkTextMatcherTest.php`. Covers:
    - Single-chunk match (one chunk, one occurrence).
    - Single-chunk multi-match (one chunk, three occurrences of the same needle).
    - **Overlap-region dedup**: needle present in chunk N's tail AND chunk N+1's overlap head at the same absolute position → exactly ONE match returned, with the canonical chunkId being the lower chunkIndex (chunk N).
    - Distinct matches in non-overlap regions across different chunks → both kept.
    - Determinism: re-running the matcher on the same chunks + needle returns the same chunkId selection (lowest chunkIndex containing each unique absolute position).
    - Whole-word default rejects substring (`"Jan"` inside `"Janitor"`).
    - Whole-word false finds substring.
    - Case-sensitive default rejects mismatched case.
    - Case-insensitive option finds mismatched case.
    - Non-overlapping matches (`"Jan Jan Jansen"` matches `"Jan"` three times, not overlapping).
    - Unicode word boundary (Dutch text with diacritics + apostrophes).
    - Zero matches returns empty array (no exception).
    - Empty chunk list returns empty array.
    - Needle length exceeding `$chunkOverlap` throws `ChunkMatcherException(reason: 'value_too_long')` with no PII in message.
    - Malformed Unicode in needle throws typed exception with no PII in message.
- [x] 7.2 `tests/Unit/Service/File/ManualEntityServiceTest.php`. Covers:
    - Happy path: new entity + 2 new relations.
    - Reuse path: existing entity, 2 new relations.
    - Idempotent path: existing entity + already-existing relations, 2 matches skipped, no new rows.
    - Zero matches: entity created, no relations, no exception.
    - File-not-extracted: throws `ManualEntityException` with `reason = 'file_not_extracted'`.
    - File-write denial: `assertFileWriteAccess` throws → service rethrows.
    - Audit-write failure mid-transaction: the entity + relation rows are rolled back.
    - Audit rows are written with the expected `action`, `user`, `object`, `changed` fields.
- [x] 7.3 `tests/Unit/Controller/FileTextControllerTest.php` — extend with cases for the new endpoint: **Implementation note:** new tests live in sibling `tests/Unit/Controller/FileTextControllerManualEntityTest.php` (matches the existing `Coverage` / `Deep` / `Gap` naming pattern); 404 case is omitted because the controller doesn't reach a 404 branch for the new endpoint (missing-file is surfaced as 422 / 403 via the service layer per the spec's no-oracle rule). The three sibling test files (`Test`, `CoverageTest`, `DeepTest`) were updated to pass the two new constructor params + the stale `findEntitiesForFile` mocks were corrected to `findEntitiesForAnonymization` (pre-existing failure picked up by this change).
    - 415 on non-JSON content-type.
    - 400 on missing `value` / `type` (response does NOT echo the supplied value).
    - 401 on no session user.
    - 403 on file-write denial.
    - 404 on missing file.
    - 422 on file-not-extracted (`ManualEntityException` with that reason).
    - 201 on matches found, with the full response body structure.
    - 200 on zero matches, with the `message` field present.
    - 500 on internal error (`ManualEntityException` with `reason = 'internal_error'`), response body has no PII.
    - Request-log line is captured via mock logger and asserted not to contain the test value.
- [ ] 7.4 `tests/Unit/Db/GdprEntityMapperTest.php` — extend with `findOneByValueAndType`:
    - Returns the matching row when one exists.
    - Returns null when no row matches.
    - Returns the first row + logs a warning when two rows match the same (value, type) (dedup-invariant violation).
- [ ] 7.5 `tests/Unit/Db/EntityRelationMapperTest.php` — extend with:
    - `existsForFileAtPosition` returns true for an existing row.
    - `existsForFileAtPosition` returns false for a non-existing row.
    - `insertBatch` inserts all rows when called within an existing transaction.
    - `insertBatch` propagates exceptions to the caller (caller manages rollback).
    - The existing `findEntitiesForAnonymization` test is extended to verify manual-method rows are included.
- [ ] 7.6 Integration test against a stacked OR instance: `tests/Integration/Manual/AddManualEntityFlowTest.php`. Steps:
    1. Upload a sample text file via OR's standard file API.
    2. Trigger text extraction so chunks exist.
    3. POST a manual-entity for a known string in the file.
    4. Assert the response shape + DB state.
    5. Invoke `/api/files/{id}/anonymize` (the existing endpoint).
    6. Assert the output file contains `[PERSON: <entity_id>]` at the original string's positions.
    7. Re-POST the same manual-entity — assert `matchesSkipped` equals the original `matchCount`.

## 8. Documentation

- [ ] 8.1 Extend `docs/api/objects.md` (or the equivalent file documenting anonymisation endpoints) with a new section "Adding manual anonymisable entities to a file". Document: the endpoint, the request/response shape, the match-behaviour flags, the zero-match-returns-200 semantics, the audit-trail behaviour, the RBAC requirement, and the value-keyed-substitution caveat.
- [ ] 8.2 Add CHANGELOG entry under `### Added`: "Manual anonymisable entities: new POST /api/files/{id}/manual-entities endpoint for operator-supplied text. Performs chunk-aware exact-string matching against the file's extracted chunks (whole-word + case-sensitive defaults), creates a catalogue entry (or reuses an existing one for the same value+type pair), and creates one EntityRelation per occurrence found with detectionMethod=manual. Idempotent: re-calling for the same value on the same file does not create duplicates. Atomic per call. Audit-trail entries on entity_create and entity_relations_batch_create. RBAC: caller must have write access to the file. Zero-match responses return 200 with the catalogue entry created and a message."

## 9. Quality and verification

- [ ] 9.1 `composer check:strict` clean (lint, phpcs, phpmd, psalm, phpstan, tests).
- [ ] 9.2 `openspec validate manual-entity-anonymisation` clean.
- [ ] 9.3 Manual smoke against the dev stack:
    - Upload a file via NC Files.
    - Trigger OR text extraction.
    - POST a manual-entity for a known substring in the file via Postman.
    - Verify the response.
    - Inspect the DB to see the new rows.
    - Invoke anonymise; download the result; verify the placeholder appears.
    - Re-POST the same manual-entity; verify idempotency.
    - Re-POST with a typo'd value; verify 200 + entity created + zero relations + message.
- [ ] 9.4 PHPCS / Conduction custom rules — named parameters where required (per Conduction's custom PHPCS sniff on internal calls). All new code passes without suppressions.

## 10. Cross-app coordination

- [ ] 10.1 No DocuDesk-side code change is part of THIS change. The endpoint is consumed by DocuDesk in a separate change (DocuDesk-side UI for the operator flow).
- [ ] 10.2 Open a tracking issue in DocuDesk for the operator-facing "Add manual entity" UI work, referencing this OR change.
- [ ] 10.3 No softwarecatalog / opencatalogi changes required (they don't anonymise documents this way).
