## 1. Database migration

- [ ] 1.1 Add a new migration class under `lib/Migration/` (next-available version, e.g. `Version1Date20260512<HHMMSS>.php`) that adds two columns to `oc_openregister_entity_relations`:
  - `bases` — JSON (or platform-equivalent JSON column type), `notnull => false`, no default.
  - `skip_anonymization` — BOOLEAN, `notnull => true`, `default => false`.
  Use `hasColumn` guards so the migration is idempotent. Match the JSON column type used elsewhere in OR (`OCP\DB\Types::JSON`).
- [ ] 1.2 Verify the migration is idempotent: running it twice on the same database is a no-op (`hasColumn` guards ensure it).
- [ ] 1.3 Smoke-test the migration on a populated dev database: confirm existing `EntityRelation` rows are read correctly post-migration — `bases` reads as `null` and `skipAnonymization` reads as `false` for all of them.

## 2. EntityRelation entity + mapper

- [ ] 2.1 Add `protected ?array $bases = null;` and `protected bool $skipAnonymization = false;` to `lib/Db/EntityRelation.php`.
- [ ] 2.2 Add `addType(fieldName: 'bases', type: 'json');` and `addType(fieldName: 'skipAnonymization', type: 'boolean');` in `EntityRelation::__construct()`.
- [ ] 2.3 Add the `getBases(): ?array`, `setBases(?array $bases): void`, `getSkipAnonymization(): bool`, `setSkipAnonymization(bool $skip): void` magic-method docblocks to the class header (mirroring the existing pattern for other fields). Getters/setters are auto-provided by Nextcloud's `Entity` base class once `addType` is registered.
- [ ] 2.4 Update `jsonSerialize()` to include `bases` and `skipAnonymization` in the returned array. Order them after `anonymizedValue` for consistency.
- [ ] 2.5 Update the psalm/phpdoc return type on `jsonSerialize` to include both new fields.
- [ ] 2.6 Confirm `EntityRelationMapper` (`lib/Db/EntityRelationMapper.php`) does not need changes to existing read methods — Nextcloud's `QBMapper` automatically handles columns registered via `addType`. If the mapper has any manual `select(...)` lists, update those to include both new columns.

## 3. Decision-metadata write path

- [ ] 3.1 Add method `EntityRelationMapper::updateDecisionMetadata(int $id, array $fields, ?IUser $actingUser = null): EntityRelation`. Behaviour:
  - Resolve the row by `$id`; throw `DoesNotExistException` if missing.
  - Enforce whitelist: keys MUST be a subset of `{ 'bases', 'skipAnonymization' }`. Any other key → typed validation exception (mapped to HTTP 400 at the controller).
  - Validate field shape: `bases` MUST be `null` or `array<string>`; `skipAnonymization` MUST be a `bool`. Anything else → typed validation exception.
  - Compute diff against current row state; if no field actually changes, return the row and SKIP audit emission (semantic no-op).
  - Otherwise apply changes, persist via `QBMapper::update`, emit one audit-trail entry via OR's existing audit subsystem (see 3.2). Persist + audit MUST follow the same transactional/error-handling pattern as existing audit-traced mapper writes.
  - Return the updated row.
- [ ] 3.2 Wire the audit-trail emission inside `updateDecisionMetadata` (NOT at the controller layer). Entry payload:
  - `action`: `entity_relation_decision_updated` (or OR's equivalent action vocabulary if one exists).
  - `subjectType`: `openregister_entity_relations` (or OR's canonical naming).
  - `subjectId`: the row id.
  - `actor`: `$actingUser?->getUID()` if provided, else the session-derived UID (per ADR-005, the UID — NOT the display name).
  - `timestamp`: an ISO-8601 datetime.
  - `changedFields`: object whose keys are only the fields that ACTUALLY changed; each value `{ previous: <old>, new: <new> }`. Fields submitted with values matching current state MUST NOT appear.
- [ ] 3.2a Add `lib/Event/EntityRelationDecisionUpdatedEvent.php` (NEW). Post-commit, informational Symfony event. Constructor takes `EntityRelation`, `array<string, {previous, new}> $changedFields`, `?IUser $actingUser`. Exposes `getRelation()`, `getChangedFields()`, `getActingUser()`, plus convenience `isSkipAnonymizationActivated(): bool` covering the most common listener trigger (false → true flip). Inject `IEventDispatcher` into `EntityRelationMapper` and dispatch the event right after `emitDecisionMetadataAuditEntry`, inside its own `try/catch` — listener failures MUST NOT mask the persisted state change (same isolation as audit). See `design.md` §D6a for the contract.
- [ ] 3.3 Add `lib/Controller/EntityRelationsController.php` (NEW). Single method: `update(int $id)` mapped to PATCH. Behaviour:
  - `@NoAdminRequired`.
  - Read the JSON body via the standard Nextcloud controller `$this->request->getParams()` pattern.
  - Resolve the relation via `findById`. If 404, return JSONResponse status 404.
  - Run the authorization check (task 3.4). If denied, return JSONResponse status 403.
  - Call `EntityRelationMapper::updateDecisionMetadata($id, $fields, $actingUser)`.
  - Catch the typed validation exception → JSONResponse status 400 with error body (`{ error: <code>, field: <name> }`).
  - On success, return JSONResponse 200 with `$relation->jsonSerialize()`.
- [ ] 3.4 Implement the authorization check as a private method on the controller (or a small helper class). Resolution order:
  - If `relation->fileId` set: caller MUST be able to write the file (reuse OR's existing helper — likely the one `FileTextController::anonymizeFile` implicitly inherits).
  - Else if `relation->objectId` set (with `registerId` + `schemaId`): caller MUST be able to update the object.
  - Else if `relation->emailId` set: caller MUST be able to access the email.
  - Otherwise deny.
  - Unauthenticated session → HTTP 401 (Nextcloud's existing session-required path handles this before the controller method runs; verify the route's `@NoAdminRequired` annotation doesn't bypass auth).
- [ ] 3.5 Add the route to `appinfo/routes.php`:
  ```
  ['name' => 'entityRelations#update', 'url' => '/api/entity-relations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\\d+']],
  ```
- [ ] 3.6 Update `EntityRelationMapper::markAsAnonymized($fileId, $anonymizedValue)` to add `AND skip_anonymization = 0` to the WHERE clause. Rows with `skip_anonymization = true` MUST be untouched by this method.
- [ ] 3.7 Update `FileTextController::anonymizeFile($fileId)` to filter out skipped rows when building the replacements list. Two implementation options — pick whichever fits OR's existing pattern:
  - **(a)** Add a new mapper method `findEntitiesForAnonymization($fileId)` that returns the same shape as `findEntitiesForFile` but filters `skip_anonymization = 0`. Controller calls this one for the replacements list.
  - **(b)** Keep `findEntitiesForFile` as-is; controller filters in PHP after the read.
  Pick (a) — cleaner separation. Document the new method.
- [ ] 3.8 Update `FileService::anonymizeDocument(Node $node, array $entities)` to defensively filter skipped rows server-side. Before delegating to `DocumentProcessingHandler`:
  - For each entity in the caller-supplied array, look up the matching `EntityRelation` row(s) and check `skipAnonymization`.
  - Drop any entity whose row(s) have `skipAnonymization = true`.
  - Pass the filtered array to `DocumentProcessingHandler::anonymizeDocument`.
  The filter is defensive — even a caller that includes a skipped row in its payload will see it filtered out at OR.

## 4. Unit tests

- [ ] 4.1 Add `tests/unit/Db/EntityRelationTest.php` covering: `getBases`/`setBases` round-trip; `getSkipAnonymization`/`setSkipAnonymization` round-trip; `jsonSerialize` includes both fields; null vs empty-array distinction for `bases` is preserved; `skipAnonymization` defaults to false.
- [ ] 4.2 Add `tests/unit/Db/EntityRelationMapperTest.php` (or extend an existing suite) covering: insert with `bases`; insert without `bases` (defaults to null); insert without `skipAnonymization` (defaults to false); update either field via `updateDecisionMetadata` on an existing row; non-UUID strings in `bases` are accepted; `markAsAnonymized` does NOT flip `anonymized=true` on rows where `skipAnonymization=true`.
- [ ] 4.3 Add `tests/unit/Db/EntityRelationMapperUpdateDecisionMetadataTest.php` covering the new method specifically:
  - Whitelist enforcement: extra key (`entityId`, `anonymized`, etc.) → typed exception.
  - Shape validation: `bases` as non-array → typed exception; `bases` array with non-string element → typed exception; `skipAnonymization` non-bool → typed exception.
  - Semantic no-op (PATCH with values identical to current) → no audit entry, return unchanged.
  - Diff-aware audit entry: only changed fields appear in `changedFields`.
  - Audit entry uses user UID, not display name (ADR-005).
  - Both fields updated in one call → one audit entry covering both.
- [ ] 4.4 Add `tests/unit/Controller/EntityRelationsControllerTest.php` covering the PATCH endpoint:
  - 200 on success.
  - 400 with offending-field identification for whitelist violations (`anonymized`, `entityId`, `anonymizedValue`).
  - 400 for shape violations (`bases` as non-array, etc.).
  - 404 for non-existent id.
  - 403 for caller without write-access to the parent file/object.
  - 401 for unauthenticated session (or verify Nextcloud's session-required path handles it pre-method).
- [ ] 4.5 Add `tests/unit/Controller/FileTextControllerAnonymizeSkipTest.php` covering the anonymise-flow filter:
  - Anonymise with mixed skip/non-skip relations: skipped rows NOT in replacements list; skipped rows' `anonymized` stays `false`; non-skipped rows' `anonymized` flips to `true`.
  - All rows skipped: no replacements; markAsAnonymized doesn't flip any row.
  - No rows skipped: behaviour identical to pre-change.
- [ ] 4.6 Add `tests/unit/Service/FileServiceAnonymizeDefensiveSkipFilterTest.php` covering the DI-path defensive filter: even when the caller's `entities[]` array includes a relation flagged `skipAnonymization=true`, OR filters it out server-side; the resulting redacted file does NOT contain that row's placeholder.
- [ ] 4.7 Add `tests/unit/Migration/Version1Date20260512<HHMMSS>Test.php` (or extend the migration test pattern OR uses) — smoke test that the migration adds both columns idempotently and existing rows read with the correct defaults.

## 5. Integration tests

- [ ] 5.1 Add a Newman/Postman integration test for the new PATCH endpoint. Cover: PATCH `bases` succeeds + audit entry created; PATCH `skipAnonymization` succeeds + audit entry created; PATCH with `anonymized` returns 400; PATCH with semantic no-op returns 200 without audit entry; PATCH on non-existent id returns 404.
- [ ] 5.2 Add an integration test for the anonymise-flow skip filter: create file with three detected entities, PATCH one with `skipAnonymization=true`, POST `/api/files/:fileId/anonymize`, confirm the redacted file contains placeholders for the two non-skipped entities but not the skipped one.
- [ ] 5.3 Add an integration test for the regression path: pre-change-shape anonymise call (no skip flags set anywhere) still works, returns identical behaviour.

## 6. Cross-app regression check

- [ ] 6.1 Manually run the opencatalogi anonymise flow (if any — opencatalogi is not a known anonymise consumer, but per OR project rules: regression-test it). Confirm no break.
- [ ] 6.2 Smoke-test against DocuDesk's existing anonymise calls (without PATCH-set skip or bases). Confirm no break.
- [ ] 6.3 Inspect the audit-trail entries written during DocuDesk's review flow on a live stack — confirm each PATCH produces exactly one entry, semantic no-ops produce none.

## 7. Documentation

- [ ] 7.1 Add an entry to `CHANGELOG.md` under Added describing the new optional `bases` column, the boolean `skip_anonymization` column, and the new PATCH endpoint on entity-relations.
- [ ] 7.2 Add a section to `docs/` (extend an existing anonymisation-related doc or create one) describing the decision-metadata PATCH contract — fields, semantics, audit-trail behaviour, retry-by-omission pattern.
- [ ] 7.3 Add a one-line inline comment on `EntityRelationMapper::markAsAnonymized` noting the `AND skip_anonymization = 0` predicate and pointing to this change as the reason.

## 8. Quality and verification

- [ ] 8.1 Run the full unit test suite — clean.
- [ ] 8.2 Run static analysis (Psalm / PHPStan at the project's configured strictness) — clean. Pay attention to the new mapper method's nullable types and to the `EntityRelation` jsonSerialize psalm shape.
- [ ] 8.3 Run code style (PHPCS at project config) — clean. Fix any pre-existing warnings in touched files per project policy.
- [ ] 8.4 Manual smoke against a live stack: PATCH a relation with `bases`, confirm row + audit entry; PATCH with `skipAnonymization=true`, run anonymise, confirm the skipped row is not redacted; PATCH with `anonymized: true` and confirm 400.
- [ ] 8.5 Run `openspec validate entity-relation-grondslagen` — clean.