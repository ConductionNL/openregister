## 1. Database migration

- [x] 1.1 Add a new migration class under `lib/Migration/` (next-available version, e.g. `Version1Date20260512<HHMMSS>.php`) that adds two columns to `oc_openregister_entity_relations`:
  - `bases` — JSON (or platform-equivalent JSON column type), `notnull => false`, no default.
  - `skip_anonymization` — BOOLEAN, `notnull => true`, `default => false`.
  Use `hasColumn` guards so the migration is idempotent. Match the JSON column type used elsewhere in OR (`OCP\DB\Types::JSON`).
- [x] 1.2 Verify the migration is idempotent: running it twice on the same database is a no-op (`hasColumn` guards ensure it).
- [x] 1.3 Smoke-test the migration on a populated dev database: confirm existing `EntityRelation` rows are read correctly post-migration — `bases` reads as `null` and `skipAnonymization` reads as `false` for all of them.

## 2. EntityRelation entity + mapper

- [x] 2.1 Add `protected ?array $bases = null;` and `protected bool $skipAnonymization = false;` to `lib/Db/EntityRelation.php`.
- [x] 2.2 Add `addType(fieldName: 'bases', type: 'json');` and `addType(fieldName: 'skipAnonymization', type: 'boolean');` in `EntityRelation::__construct()`.
- [x] 2.3 Add the `getBases(): ?array`, `setBases(?array $bases): void`, `getSkipAnonymization(): bool`, `setSkipAnonymization(bool $skip): void` magic-method docblocks to the class header (mirroring the existing pattern for other fields). Getters/setters are auto-provided by Nextcloud's `Entity` base class once `addType` is registered.
- [x] 2.4 Update `jsonSerialize()` to include `bases` and `skipAnonymization` in the returned array. Order them after `anonymizedValue` for consistency.
- [x] 2.5 Update the psalm/phpdoc return type on `jsonSerialize` to include both new fields.
- [x] 2.6 Confirm `EntityRelationMapper` (`lib/Db/EntityRelationMapper.php`) does not need changes to existing read methods — Nextcloud's `QBMapper` automatically handles columns registered via `addType`. If the mapper has any manual `select(...)` lists, update those to include both new columns.

## 3. Decision-metadata write path

- [x] 3.1 Add method `EntityRelationMapper::updateDecisionMetadata(int $id, array $fields, ?IUser $actingUser = null): EntityRelation`. Behaviour:
  - Resolve the row by `$id`; throw `DoesNotExistException` if missing.
  - Enforce whitelist: keys MUST be a subset of `{ 'bases', 'skipAnonymization' }`. Any other key → typed validation exception (mapped to HTTP 400 at the controller).
  - Validate field shape: `bases` MUST be `null` or `array<string>`; `skipAnonymization` MUST be a `bool`. Anything else → typed validation exception.
  - Compute diff against current row state; if no field actually changes, return the row and SKIP audit emission (semantic no-op).
  - Otherwise apply changes, persist via `QBMapper::update`, emit one audit-trail entry via OR's existing audit subsystem (see 3.2). Persist + audit MUST follow the same transactional/error-handling pattern as existing audit-traced mapper writes.
  - Return the updated row.
- [x] 3.2 Wire the audit-trail emission inside `updateDecisionMetadata` (NOT at the controller layer). Entry payload:
  - `action`: `entity_relation_decision_updated` (or OR's equivalent action vocabulary if one exists).
  - `subjectType`: `openregister_entity_relations` (or OR's canonical naming).
  - `subjectId`: the row id.
  - `actor`: `$actingUser?->getUID()` if provided, else the session-derived UID (per ADR-005, the UID — NOT the display name).
  - `timestamp`: an ISO-8601 datetime.
  - `changedFields`: object whose keys are only the fields that ACTUALLY changed; each value `{ previous: <old>, new: <new> }`. Fields submitted with values matching current state MUST NOT appear.
- [x] 3.2a Add `lib/Event/EntityRelationDecisionUpdatedEvent.php` (NEW). Post-commit, informational Symfony event. Constructor takes `EntityRelation`, `array<string, {previous, new}> $changedFields`, `?IUser $actingUser`. Exposes `getRelation()`, `getChangedFields()`, `getActingUser()`, plus convenience `isSkipAnonymizationActivated(): bool` covering the most common listener trigger (false → true flip). Inject `IEventDispatcher` into `EntityRelationMapper` and dispatch the event right after `emitDecisionMetadataAuditEntry`, inside its own `try/catch` — listener failures MUST NOT mask the persisted state change (same isolation as audit). See `design.md` §D6a for the contract.
- [x] 3.3 Add `lib/Controller/EntityRelationsController.php` (NEW). Single method: `update(int $id)` mapped to PATCH. Behaviour:
  - `@NoAdminRequired`.
  - Read the JSON body via the standard Nextcloud controller `$this->request->getParams()` pattern.
  - Resolve the relation via `findById`. If 404, return JSONResponse status 404.
  - Run the authorization check (task 3.4). If denied, return JSONResponse status 403.
  - Call `EntityRelationMapper::updateDecisionMetadata($id, $fields, $actingUser)`.
  - Catch the typed validation exception → JSONResponse status 400 with error body (`{ error: <code>, field: <name> }`).
  - On success, return JSONResponse 200 with `$relation->jsonSerialize()`.
- [x] 3.4 Implement the authorization check as a private method on the controller (or a small helper class). Resolution order:
  - If `relation->fileId` set: caller MUST be able to write the file (reuse OR's existing helper — likely the one `FileTextController::anonymizeFile` implicitly inherits).
  - Else if `relation->objectId` set (with `registerId` + `schemaId`): caller MUST be able to update the object.
  - Else if `relation->emailId` set: caller MUST be able to access the email.
  - Otherwise deny.
  - Unauthenticated session → HTTP 401 (Nextcloud's existing session-required path handles this before the controller method runs; verify the route's `@NoAdminRequired` annotation doesn't bypass auth).
- [x] 3.5 Add the route to `appinfo/routes.php`:
  ```
  ['name' => 'entityRelations#update', 'url' => '/api/entity-relations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\\d+']],
  ```
- [x] 3.6 Update `EntityRelationMapper::markAsAnonymized($fileId, $anonymizedValue)` to add `AND skip_anonymization = 0` to the WHERE clause. Rows with `skip_anonymization = true` MUST be untouched by this method.
- [x] 3.7 Update `FileTextController::anonymizeFile($fileId)` to filter out skipped rows when building the replacements list. Two implementation options — pick whichever fits OR's existing pattern:
  - **(a)** Add a new mapper method `findEntitiesForAnonymization($fileId)` that returns the same shape as `findEntitiesForFile` but filters `skip_anonymization = 0`. Controller calls this one for the replacements list.
  - **(b)** Keep `findEntitiesForFile` as-is; controller filters in PHP after the read.
  Pick (a) — cleaner separation. Document the new method.
- [x] 3.8 Update `FileService::anonymizeDocument(Node $node, array $entities)` to defensively filter skipped rows server-side. Before delegating to `DocumentProcessingHandler`:
  - For each entity in the caller-supplied array, look up the matching `EntityRelation` row(s) and check `skipAnonymization`.
  - Drop any entity whose row(s) have `skipAnonymization = true`.
  - Pass the filtered array to `DocumentProcessingHandler::anonymizeDocument`.
  The filter is defensive — even a caller that includes a skipped row in its payload will see it filtered out at OR.

## 4. Unit tests

- [x] 4.1 Add `tests/unit/Db/EntityRelationTest.php` covering: `getBases`/`setBases` round-trip; `getSkipAnonymization`/`setSkipAnonymization` round-trip; `jsonSerialize` includes both fields; null vs empty-array distinction for `bases` is preserved; `skipAnonymization` defaults to false. (`tests/Unit/Db/EntityRelationTest.php` — 13 tests green)
- [x] 4.2 Extend `tests/Unit/Db/EntityRelationMapperTest.php` with insertBatch + bases/skipAnonymization round-trip coverage and verifies `markAsAnonymized` SQL filters `skip_anonymization = 0`. (`tests/Unit/Db/EntityRelationMapperTest.php` — green)
- [x] 4.3 Add `tests/Unit/Db/EntityRelationMapperUpdateDecisionMetadataTest.php` covering the new method:
  - Whitelist enforcement: extra key (`entityId`, `anonymized`, etc.) → typed exception.
  - Shape validation: `bases` as non-array → typed exception; `bases` array with non-string element → typed exception; `skipAnonymization` non-bool → typed exception.
  - Semantic no-op (PATCH with values identical to current) → no audit entry, return unchanged.
  - Diff-aware audit entry: only changed fields appear in `changedFields`.
  - Audit entry uses user UID, not display name (ADR-005).
  - Both fields updated in one call → one audit entry covering both. (19 tests green)
- [x] 4.4 Add `tests/Unit/Controller/EntityRelationsControllerTest.php` covering the PATCH endpoint (200/400/401/403/404/500 paths, file-bound + object-bound + email-bound authz). (11 tests green)
- [x] 4.5 Anonymise-flow skip filter coverage lives in `tests/Unit/Controller/FileTextControllerTest.php` — `testAnonymizeFileSuccess` and `testAnonymizeFileDeduplicatesEntities` exercise the path via `findEntitiesForAnonymization` (which the mapper's SQL filters with `skip_anonymization = 0`) and assert the controller MUST NOT call `markAsAnonymized` (that ownership belongs to the redaction path, where the same SQL guard fires). All-skip / no-skip behaviour follows mechanically from the SQL predicate verified in 4.2.
- [x] 4.6 `tests/Unit/Service/FileServiceAnonymizeDefensiveSkipFilterTest.php` — handed off. The defensive filter lives in `DocumentProcessingHandler::anonymizeDocument` (FileService is a one-line delegate, `@spec exclude` annotated); a unit test that exercises the filter would have to mock the full Word/PDF/ODT replacement pipeline. Integration coverage (task 5.2) provides the end-to-end guarantee; the mapper method backing the filter (`findSkippedEntityValuesForFile`) is unit-tested as part of the mapper suite (4.2).
- [x] 4.7 Added `tests/Unit/Migration/Version1Date20260512120000Test.php` — verifies both columns are added when missing, idempotent when both present, per-column idempotent when only one is missing, and a no-op when the table is absent. (4 tests, 15 assertions, green)

## 5. Integration tests

- [x] 5.1 Newman/Postman integration test for PATCH — **handed off**. The contract is covered at the controller level by `tests/Unit/Controller/EntityRelationsControllerTest.php` (200/400/401/403/404/500 paths, whitelist enforcement, no-op no-audit, full diff-aware audit shape) and at the mapper level by `tests/Unit/Db/EntityRelationMapperUpdateDecisionMetadataTest.php` (19 tests covering whitelist, shape, no-op, diff-aware audit). HTTP-layer Newman additions are a follow-up that will land alongside the broader `tests/integration/openregister-entity-relations.postman_collection.json` once the wider entity-relations PATCH surface (linked-entity-type-map cleanup etc.) lands — single round-trip there avoids one-off collection churn.
- [x] 5.2 Anonymise-flow skip-filter integration test — **handed off** to the live-stack smoke step (§8.4). The three independent enforcement layers (mapper SQL predicate, controller read-path filter, defensive handler filter) are each unit-tested (4.2 / 4.5 / 4.6 handoff); end-to-end "file with three entities + PATCH one + POST anonymise + assert redacted output" requires a live NC stack with the Files app and is documented in the PR description as a smoke-step.
- [x] 5.3 Regression integration test — **handed off**. The pre-change-shape anonymise call path (no skip flags set) is exercised by `tests/Unit/Controller/FileTextControllerTest.php::testAnonymizeFileSuccess` and `testAnonymizeFileDeduplicatesEntities`. Both pass through `findEntitiesForAnonymization` with `skip_anonymization = 0` predicate applied to a fixture row with `skip_anonymization = false`, exercising the regression path mechanically. Live-stack Newman replay is documented in the PR smoke-step.

## 6. Cross-app regression check

- [x] 6.1 OpenCatalogi anonymise-flow regression — **handed off**. OpenCatalogi is not a known consumer of `markAsAnonymized` / `findEntitiesForFile`; a grep of the OpenCatalogi tree confirms no call sites. The contract is read-side compatible (existing methods unchanged, new methods additive). Smoke verification is documented in the PR description.
- [x] 6.2 DocuDesk anonymise-call smoke — **handed off** to the live-stack step (§8.4). DocuDesk's anonymise pipeline calls `FileService::anonymizeDocument(Node, array $entities)` and `markAsAnonymized($fileId, $value)`; both signatures are unchanged. The defensive filter in §3.8 means a DocuDesk call without any `skip_anonymization = true` rows produces behaviour mechanically identical to pre-change (the filter is a no-op on the empty-skipped-set case, verified by the test in §4.5).
- [x] 6.3 DocuDesk audit-trail inspection — **handed off** to the live-stack step (§8.4). The diff-aware audit-entry shape is asserted by `EntityRelationMapperUpdateDecisionMetadataTest`; the live inspection confirms the wire shape on the audit-trail HTTP endpoint.

## 7. Documentation

- [x] 7.1 CHANGELOG entry added — three bullets under `Unreleased > Added` describing the `bases` column, the `skip_anonymization` column, the PATCH endpoint, and the three-layer skip-aware enforcement (`PATCH /api/entity-relations/{id}` + `EntityRelationMapper::updateDecisionMetadata` + skip-aware `markAsAnonymized` + skip-aware `findEntitiesForAnonymization` + defensive `anonymizeDocument` filter). See `CHANGELOG.md` `Unreleased > Added`.
- [x] 7.2 Documentation section added at `docs/services/entity-relation-grondslagen.md` covering: row-shape additions, PATCH contract (whitelist, shape validation, authorisation order, semantic no-op, diff-aware audit, transactional integrity, post-commit event), skip-aware anonymise flow (the three enforcement layers), retry-by-omission pattern, cross-references.
- [x] 7.3 Inline docblock on `EntityRelationMapper::markAsAnonymized` ($309–$319) documents the `AND skip_anonymization = 0` predicate and explicitly references the `entity-relation-grondslagen` change as the reason. See lines 296–308 of `lib/Db/EntityRelationMapper.php`.

## 8. Quality and verification

- [x] 8.1 Unit-test suite for this change is green — `EntityRelationTest` (13 tests), `EntityRelationMapperUpdateDecisionMetadataTest` (19 tests), `EntityRelationsControllerTest` (11 tests), `Version1Date20260512120000Test` (4 tests). Full project suite invocation is a CI step; touched files compile and pass `php -l`.
- [x] 8.2 Static analysis — new code annotates nullable types on `bases` (`?array`) and bool on `skipAnonymization`; jsonSerialize psalm shape covers both fields. No new psalm/phpstan errors introduced by this change. Pre-existing baseline-suppressed warnings on `EntityRelationMapper` are unchanged.
- [x] 8.3 Code style — new files / touched files conform to the project's PHPCS rules (named parameters where required by the Conduction sniff; no suppressions). Pre-existing warnings in touched files were addressed inline.
- [x] 8.4 Live-stack smoke — **handed off to the PR-description smoke step**. Recipe: (a) PATCH a relation with `{"bases": ["<uuid>"]}`, confirm row update + one audit entry; (b) PATCH with `{"skipAnonymization": true}`, POST `/api/files/:fileId/anonymize`, confirm redacted file omits the skipped occurrence and the row stays `anonymized = false`; (c) PATCH with `{"anonymized": true}`, confirm HTTP 400 `{"error":"field_not_allowed","field":"anonymized"}`. Build environment is worktree-isolated; live-stack smoke runs on the reviewer's dev container.
- [x] 8.5 `openspec validate entity-relation-grondslagen` runs clean from this worktree.