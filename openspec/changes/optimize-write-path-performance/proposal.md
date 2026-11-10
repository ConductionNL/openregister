---
kind: code
---

## Why

Write-path profiling identified four verified performance findings, and
re-verification against a live instance uncovered that the worst one is not
merely slow but broken:

1. **Bulk-save side effects were dead code at runtime.** `SaveObjects::
   buildChunkResults()` gated database-computed classification on
   `isset($firstItem['created'], $firstItem['updated'])`, but the magic-table
   bulk mapper returns raw rows whose metadata columns are underscore-prefixed
   (`_created` / `_updated`) with an `object_status` field. The gate never
   matched, every bulk result fell into the legacy path, and the saved/updated
   buckets stayed empty. Live-verified on the dev instance: a 2-object
   `POST /api/bulk/{register}/{schema}/save` persisted both rows but returned
   `saved_count: 0` with empty buckets and wrote **zero** audit-trail rows and
   dispatched **zero** lifecycle events. The per-object
   `createAuditTrail()` / `dispatchTyped()` loop in `emitChunkSideEffects()`
   (the original finding: N audit INSERTs + N×3 hash-chain queries per chunk)
   never executed at all.
2. **Bulk update audits had no real diff.** The bulk path passed the same
   entity as `old` and `new` (`writeBulkAuditTrail(old: $entity, new:
   $entity)`), so even when repaired, update audit rows would record an empty
   changeset — despite the mapper already reading the pre-update rows for its
   create-vs-update classification.
3. **Every save runs ~16 synchronous post-save listeners inline.** Each was
   read and classified (see design.md). Most are cheap early-outs, already
   internally deferred per ADR-009 Rule 5, or session-entangled (they resolve
   the acting user's RBAC scope, calendar, or identity from the request
   session — a background job has no session, so deferring them would change
   behaviour). One genuine hot-path cost was found and fixed:
   `SourceRecordChangeListener` loads EVERY schema (a
   `SchemaMapper::findAll()` over thousands of rows on large instances) to
   build its reverse-FK index on the first object event of every request.
4. **The JSON-Schema validator was rebuilt per validation.** `ValidateObject::
   validateObject()` constructed a new Opis `Validator`, re-registered three
   custom formats and the `$ref` resolver protocol, and re-ran the whole
   schema-preparation pipeline (transform, clean, computed-strip, null-type
   widening, `json_encode`/`json_decode` cloning) for every single object —
   N times for a bulk validation of N objects — violating the existing
   `objects-crud` requirement "Schema-derived and request-invariant values are
   computed once".

## What Changes

- **Repair + batch bulk-save side effects** (`lib/Service/Object/
  SaveObjects.php`): gate classification on the mapper's actual contract
  (`object_status`), convert raw rows through the canonical
  `MagicMapper::convertRowToObjectEntity()`, and emit side effects from the
  classified entities: audit rows for the whole chunk are written with ONE
  batched multi-row INSERT (`AuditTrailMapper::insertAuditTrails()`) and
  events are dispatched per object with the REAL pre-update entity.
- **Bulk audit-trail insert API** (`lib/Db/AuditTrailMapper.php`): the
  row-building logic of `createAuditTrail()` is extracted into a private
  `buildAuditTrail()` used by both the single (`createAuditTrail()`) and the
  new batched (`insertAuditTrails()`, chunked at 100 rows/INSERT) paths, so
  bulk rows are byte-identical to single rows.
- **Batched hash-chain sealing** (`lib/Service/AuditHashService.php`): new
  `sealRows()` seals a batch with 2 SELECTs + 1 CASE-based UPDATE instead of
  3 queries per row, preserving `verifyChain()` semantics even when foreign
  rows interleave with the batch.
- **Real pre-update state** (`lib/Db/MagicMapper/MagicBulkHandler.php`): the
  pre-upsert existence check now selects the full rows (same query count) and
  attaches each updated object's pre-update row (`_pre_update_row`) to the
  bulk result for audit diffing and `ObjectUpdatedEvent(oldObject: …)`.
- **Reverse-FK index caching** (`lib/Listener/SourceRecordChangeListener.php`
  + `lib/AppInfo/Application.php`): the listener's reverse index is stored in
  the distributed cache (TTL 1h) and invalidated eagerly on Schema
  created/updated/deleted events (the listener is now also registered for
  those); the all-schemas `findAll()` runs only on a cold cache.
- **Validator memoization** (`lib/Service/Object/ValidateObject.php`): one
  request-scoped Opis `Validator` (formats + resolver registered once) and a
  prepared-schema cache keyed `schemaId:version` (mirroring `SchemaMapper`'s
  findCache invalidation-by-version pattern). Also fixes previously-dead
  computed-property stripping: the cleaning step removed the per-property
  `computed` marker before the stripping loop looked for it, so computed
  fields were never actually excluded from validation as documented.
- **No listener is moved to a background job** — every candidate was read and
  the heavy ones are session-entangled (design.md has the full classification
  table with per-listener rationale).

## Capabilities

### objects-crud (delta)
Bulk saves classify persisted rows by the mapper's `object_status` contract
and emit audit trails + lifecycle events with batched writes and real update
diffs. The existing "Schema-derived and request-invariant values are computed
once" requirement gains an explicit versioned-cache scenario.

### audit-hash-chain (delta)
Batched audit inserts are sealed into the hash chain in a single pass with
identical verifiability to per-row sealing.

## Impact

- Affected code: `lib/Service/Object/SaveObjects.php`,
  `lib/Db/AuditTrailMapper.php`, `lib/Service/AuditHashService.php`,
  `lib/Db/MagicMapper/MagicBulkHandler.php`,
  `lib/Service/Object/ValidateObject.php`,
  `lib/Listener/SourceRecordChangeListener.php`, `lib/AppInfo/Application.php`.
- Query-count effect per bulk chunk of N created/updated objects: audit path
  goes from `4N` statements (N INSERTs + N×(row SELECT + prev-hash SELECT +
  UPDATE)) — or, as actually deployed, from 0 rows written at all — to
  `~4 per 100 rows` (1 multi-row INSERT + 1 id SELECT + 1 range SELECT +
  1 prev-hash SELECT + 1 CASE UPDATE). Validation of N objects resolves and
  prepares the schema once instead of N times. The first object write of a
  request no longer loads every schema for the reverse-FK index (warm cache).
- Behavioural repair: bulk saves now return correct `saved`/`updated`/
  `unchanged` buckets and statistics, write audit rows, and (when events are
  enabled) dispatch `ObjectCreatedEvent`/`ObjectUpdatedEvent` with a real
  `oldObject` — restoring parity with the single-object save path.
