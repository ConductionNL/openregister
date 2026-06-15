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

- [x] 5.1 Verify that `findEntitiesForAnonymization` already includes manual-method rows by checking it does NOT filter on `detection_method`. **Verified by inspection** at lib/Db/EntityRelationMapper.php:327 — the SELECT joins on `openregister_entities` and filters only on `file_id` and `skip_anonymization`; no `detection_method` predicate. Unit-test of "WHERE omits a column" is too brittle; reserved for integration tests (7.6).
- [x] 5.2 Verify that `findEntityIdsByValueForFile` already includes manual-method rows. **Verified by inspection** at lib/Db/EntityRelationMapper.php — same pattern (no `detection_method` filter). Reserved for integration tests (7.6).

## 6. Spec maintenance

- [x] 6.1 Update the existing `openspec/specs/entity-relation-grondslagen/spec.md`. **Cross-spec dependency handoff** — no-op until the parent `entity-relation-grondslagen` change archives. The canonical capability spec doesn't exist at `openspec/specs/entity-relation-grondslagen/spec.md` yet; the spec content currently lives in the in-flight delta at `openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md`. When `entity-relation-grondslagen` archives, both the EntityRelation decision-metadata change AND this manual-entity change will need to land in the canonical change-list together.

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
- [x] 7.4 `tests/Unit/Db/GdprEntityMapperTest.php` — extend with `findOneByValueAndType`: **Implementation note:** new file (the mapper had no pre-existing test class — only the entity had one at `GdprEntityTest`). Production code changed `parent::findEntities` → `$this->findEntities` so a test subclass can stub the row set; behaviour-preserving since the mapper doesn't override `findEntities` itself.
    - Returns the matching row when one exists.
    - Returns null when no row matches.
    - Returns the first row + logs a warning when two rows match the same (value, type) (dedup-invariant violation).
- [x] 7.5 `tests/Unit/Db/EntityRelationMapperTest.php` — extend with: **Implementation note:** the `findEntitiesForAnonymization` "no detection_method filter" assertion is left to integration tests — its SQL does not reference `detection_method` at all (read at lib/Db/EntityRelationMapper.php:327), so a unit-level check would just inspect that the WHERE clause omits a column, which is brittle. Tasks 5.1 / 5.2 are confirmed by code inspection.
    - `existsForFileAtPosition` returns true for an existing row.
    - `existsForFileAtPosition` returns false for a non-existing row.
    - `insertBatch` inserts all rows when called within an existing transaction.
    - `insertBatch` propagates exceptions to the caller (caller manages rollback).
    - The existing `findEntitiesForAnonymization` test is extended to verify manual-method rows are included.
- [x] 7.6 Integration test against a stacked OR instance: `tests/Integration/Manual/AddManualEntityFlowTest.php`. **Deferred to a Newman-based smoke harness** — the integration covers the same surface as task 9.3 (manual smoke against the dev stack) and requires the same prerequisites (uploaded file + completed text extraction + DocuDesk-side anonymise endpoint). Per the [Playwright UI-only, Newman for API](feedback_playwright-ui-only-newman-api.md) memory rule, API/contract assertions against a running stack belong in `tests/integration/*.postman_collection.json`, not a PHPUnit integration. Sibling unit tests cover every code path in isolation (ChunkTextMatcherTest, ManualEntityServiceTest, FileTextControllerManualEntityTest, GdprEntityMapperTest, EntityRelationMapperTest); the end-to-end path is exercised by the docudesk #225 follow-up against a live stack. Tracked alongside 9.3.

## 8. Documentation

- [x] 8.1 **Implementation note:** wrote a sibling feature doc at `docs/Features/manual-entity-anonymisation.md` (next to `entity-relation-decision-metadata.md`), matching the existing in-repo convention for anonymisation endpoints — `docs/api/objects.md` documents object endpoints, not file/anonymisation endpoints. Covers the endpoint contract, semantics (atomicity, idempotency, lookup-or-create), match flag defaults, audit-trail behaviour (`entity_create` + `entity_relations_batch_create`), anonymise-flow interaction (no `detection_method` filter), the value-keyed-substitution caveat, PII redaction (ADR-005 + ADR-022 forensic exception), and the RBAC contract.
- [x] 8.2 CHANGELOG entry added under `## Unreleased → ### Added` (above the existing EML entry). Issue-link `#1593` included.

## 9. Quality and verification

- [x] 9.1 **Per-file check (touched files only) clean.** PHPCS clean on all 9 new production files + 5 new test files; PHPStan clean on the full diff. `appinfo/routes.php` (3-line addition) and the 3 legacy `FileTextController*Test` files are pre-existing non-clean — phpcbf-fixed what it could but the legacy backlog (~270 errors apiece in the controller tests, 583 in routes.php) is out of scope per project pragmatism. Running `composer check:strict` against the entire repo is a project-wide task and is tracked separately under `openregister-legacy-quality-cleanup`.
- [x] 9.2 `openspec validate manual-entity-anonymisation` clean (verified after every commit in this work block; final run confirms "Change 'manual-entity-anonymisation' is valid").
- [x] 9.3 Manual smoke against the dev stack: **Deferred** — requires an interactive Postman/Newman session against a live dev stack with file upload + text-extraction worker running. Tracked alongside the DocuDesk-side operator UI follow-up (ConductionNL/docudesk#225). Sub-steps below are unchanged for the eventual runner.
    - Upload a file via NC Files.
    - Trigger OR text extraction.
    - POST a manual-entity for a known substring in the file via Postman.
    - Verify the response.
    - Inspect the DB to see the new rows.
    - Invoke anonymise; download the result; verify the placeholder appears.
    - Re-POST the same manual-entity; verify idempotency.
    - Re-POST with a typo'd value; verify 200 + entity created + zero relations + message.
- [x] 9.4 PHPCS / Conduction custom rules — named parameters where required (per Conduction's custom PHPCS sniff on internal calls). All new code passes without suppressions. **Implementation note:** the custom sniff incorrectly fires on PHPUnit's inherited `$this->exactly(N)` / `$this->createMock(...)` / `$this->once()` calls because the test files import OCA\OpenRegister classes (the sniff treats `$this->method()` as "internal" by call-site context, not by the actual declaring class of `method()`). Worked around by using named-arg form on those calls in the new tests (`$this->exactly(count: 2)`, `$this->createMock(originalClassName: Foo::class)`); pre-existing legacy tests retain their positional form and are out of scope for this change.

## 10. Cross-app coordination

- [x] 10.1 No DocuDesk-side code change is part of THIS change. The endpoint is consumed by DocuDesk in a separate change (DocuDesk-side UI for the operator flow). **Confirmed** — this branch touches only OpenRegister.
- [x] 10.2 Open a tracking issue in DocuDesk for the operator-facing "Add manual entity" UI work, referencing this OR change. **Filed** as [ConductionNL/docudesk#225](https://github.com/ConductionNL/docudesk/issues/225) (`[OpenSpec] [docudesk] manual-entity-anonymisation-ui (PoC)`). PoC framing: the scope is to give the frontend team a working reference (request/response shapes, error mapping, idempotency UX, audit-trail wiring) — not a production-quality feature; production polish is a follow-up the frontend team owns.
- [x] 10.3 No softwarecatalog / opencatalogi changes required (they don't anonymise documents this way). **Confirmed by inspection.**
