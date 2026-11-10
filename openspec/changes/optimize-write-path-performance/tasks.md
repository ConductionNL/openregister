# Tasks — optimize-write-path-performance

## 1. Bulk audit-trail batching (finding 1)

- [x] 1.1 Extract `createAuditTrail()`'s row-building into private `buildAuditTrail()`; `createAuditTrail()` delegates to it + `insertHashChained()` — `lib/Db/AuditTrailMapper.php`.
- [x] 1.2 Add `insertAuditTrails(array $entries, int $chunkSize=100)`: builds rows via `buildAuditTrail()`, persists each chunk with ONE multi-row INSERT (QBMapper-identical json/datetime value conversion), reads generated ids back by row uuid, seals via `AuditHashService::sealRows()` (fail-soft) — `lib/Db/AuditTrailMapper.php`.
- [x] 1.3 Add `AuditHashService::sealRows(array $ids)`: 1 range SELECT + 1 prev-hash SELECT + 1 CASE-based UPDATE; unsealed rows in the range are chained in id order, sealed rows contribute their stored hash — `lib/Service/AuditHashService.php`.
- [x] 1.4 `SaveObjects::emitChunkSideEffects()` writes the whole chunk's audit entries through one `insertAuditTrails()` call (create entries old=null; update entries carry the real pre-update entity) instead of per-object `createAuditTrail()`.

## 2. Real update diffs in bulk audits (finding 2)

- [x] 2.1 Widen the pre-upsert classification query from `SELECT _uuid` to `SELECT *` (same query count) and retain the rows — `lib/Db/MagicMapper/MagicBulkHandler.php::executeUpsertChunk()`.
- [x] 2.2 Attach `_pre_update_row` to each bulk-result row classified `updated` — same file.
- [x] 2.3 `SaveObjects` converts `_pre_update_row` to an `ObjectEntity` and passes it as `old` to the audit entry AND as `oldObject` to `ObjectUpdatedEvent`; the key is stripped before rows reach the API response.
- [x] 2.4 Repair the classification gate that made all of the above dead code: gate on `object_status` (the mapper's actual contract), convert raw rows via `MagicMapper::convertRowToObjectEntity()` (register/schema via the existing static caches), populate saved/updated/unchanged buckets with serialized entities — `lib/Service/Object/SaveObjects.php` (`buildChunkResults()`, `classifyDatabaseComputedResults()`, new `convertBulkRowToEntity()`; removed dead `hydrateResultEntity()`/`writeBulkAuditTrail()`).

## 3. Listener classification (finding 3)

- [x] 3.1 Read and classify every ObjectCreated/Updated/Deleted listener (16) plus the 5 pre-save `-ing` listeners; full table with per-listener rationale in design.md D5.
- [x] 3.2 Decision recorded: NO listener moved to a background job — the heavy candidates (SourceRecordChange, TranslationProjection, AnnotationNotification, ObjectCleanup, FlowAction via CalendarEventService) are session-entangled (RBAC scope / user calendar / actor attribution); deferring them would change behaviour. Follow-up actor-forwarding contract noted in design.md D5.
- [x] 3.3 Fix the one unconditional hot-path cost found: `SourceRecordChangeListener` reverse-FK index cached in the distributed cache (TTL 1h) — `lib/Listener/SourceRecordChangeListener.php`.
- [x] 3.4 Eager cache invalidation: listener also registered for SchemaCreated/Updated/DeletedEvent and drops the cache key on any of them — `lib/AppInfo/Application.php`.

## 4. Validator memoization (finding 4)

- [x] 4.1 Request-scoped `getValidator()`: one Opis Validator, formats (bsn/semver/date-time) + `http` resolver protocol registered once — `lib/Service/Object/ValidateObject.php`.
- [x] 4.2 Extract the schema-only preparation pipeline into `prepareSchemaForValidation()` and cache its result keyed `schemaId:schemaVersion` (SchemaMapper findCache pattern; version bump = new key); custom schemaObjects bypass the cache.
- [x] 4.3 Resolve `int|string` schema arguments to the entity once up front; skip the unique-field check for null schemas (previously a TypeError path).
- [x] 4.4 Fix dead computed-property stripping: collect computed names BEFORE `cleanSchemaForValidation()` strips the marker; computed fields are now actually removed from user input and from `required`.

## 5. Tests

- [x] 5.1 `tests/Unit/Service/Object/SaveObjectsBulkSideEffectsTest.php` (4 tests): raw-row classification, one batched `insertAuditTrails()` call, create entries old=null + ObjectCreatedEvent per object, update entries + ObjectUpdatedEvent carry the REAL pre-update entity, unchanged rows get no audit/event, `_events=false` suppresses events but not audits, `_pre_update_row`/`object_status` never leak into the response.
- [x] 5.2 `tests/Unit/Db/AuditTrailMapperBulkInsertTest.php` (3 tests): single multi-row INSERT shape + QBMapper-identical value conversion + real old→new changeset in the update row's `changed` JSON; chunking (5 entries @ size 2 → 3 INSERTs); id readback onto returned entities + one `sealRows()` per chunk; empty input = no DB interaction.
- [x] 5.3 `tests/Unit/Service/AuditHashSealRowsTest.php` (3 tests): genesis→h1→h2 chain math equals `computeHash()`, single CASE UPDATE with exact params, sealed rows adopted-not-rewritten, empty/invalid ids = no-op.
- [x] 5.4 `tests/Unit/Service/Object/ValidateObjectMemoizationTest.php` (4 tests): one cache entry + one Validator across repeat validations with valid AND invalid input; version bump creates a fresh entry (new required rule enforced); custom schemaObject bypasses cache; computed properties stripped on cold and warm paths.
- [x] 5.5 Update `SourceRecordChangeListenerTest` for the new `ICacheFactory` constructor argument (cache-less fallback path).
- [x] 5.6 Fix 14 pre-existing errors in `SaveObjectsTest` (tests reflected the removed `isReference()`; retargeted to `RelationDetectionTrait::isRecordableReference()` with expectations aligned to the spec'd tightened heuristic, + new schema-declared-reference case).

## 6. Verification

- [x] 6.1 `composer check:strict` components green on changed files (phpcs/phpstan/phpmd clean; psalm per-file artifact + full-run results recorded in the change report).
- [x] 6.2 Full unit suite in php:8.3 container: 14432 tests — 15 red, ALL pre-existing (base commit 40378fa37 has 29 red incl. the 14 fixed here; every remaining failure also fails on the pristine base export).
- [ ] 6.3 Live end-to-end re-verification of the bulk endpoint against a deployed instance (deferred: shared dev instance must not receive this branch's code until it merges; the pre-change live probe that proved the broken behaviour is documented in proposal.md).
