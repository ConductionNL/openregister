---
kind: code
---

## Why

Four verified inefficiencies make single-object reads (`GET /api/objects/{register}/{schema}/{id}`)
far more expensive than they need to be:

1. **Every `show()` renders the object twice.** `ObjectService::find()` already renders the entity
   (with the same `$_extend`) before returning it, and `ObjectsController::show()` then calls
   `renderEntity()` on that already-rendered entity. File hydration, metadata extraction, writeOnly
   redaction and — worst — inverse-property resolution all run twice per read.
2. **Single-entity renders resolve `inversedBy` properties via the slow cross-table fallback.**
   The `inverseRelationCache` is only populated by `preloadInverseRelationships()` on the LIST path
   (`renderEntities()`), so a single read falls back to `objectEntityMapper->findByRelation()` — a
   generic reverse-reference scan across ALL magic tables — plus a per-property
   `findByRelationBatchInSchema` loop and a `\OC::$server->get(MagicMapper::class)` service-location
   inside the render.
3. **Hot-path INFO logging.** Two `[BATCH_PRELOAD]` `logger->info()` calls (with context arrays)
   fire on EVERY extended list render, flooding production logs at default log level.
4. **Cross-schema fallback re-runs on every stale-context read.** When the URL scope does not match
   the object's true register/schema, the scoped find misses and an unscoped cross-table find runs
   (deliberate correctness fallback, openregister#1520). Within one request the SAME uuid is often
   resolved repeatedly (relation resolution), and each resolution re-missed the scoped path and
   re-ran the cross-table scan.

## What Changes

- `ObjectService::find()` gains a `bool $_render = true` parameter. `ObjectsController::show()`
  passes `_render: false` and stays the single render site; retrieval, the cross-schema fallback,
  the permission check and AVG read logging inside `find()` are unchanged. All other `find()`
  callers keep the default and are unaffected. writeOnly / read-authorization redaction
  (openregister#385/#386) provably still runs exactly once on the response path — inside the one
  `renderEntity()` call.
- `RenderObject::handleInversedProperties()` routes the single-entity path through the same
  schema-targeted batched machinery the list path uses (`preloadInverseRelationships()` →
  `findByRelationBatchInSchema`, GIN-indexed), populating `inverseRelationCache` and serving from
  it. The generic cross-table `findByRelation()` scan remains only as a resilience fallback when
  the batched preload cannot populate the cache. Single reads now resolve inverse properties
  identically to list reads.
- The two `\OC::$server->get(MagicMapper::class)` service-locations inside RenderObject are
  replaced with the already-injected `$this->objectEntityMapper` (same class, proper DI).
- The two `[BATCH_PRELOAD]` `logger->info()` calls in `renderEntities()` are downgraded to
  `debug`; three per-request `logger->info()` calls on the ObjectsController PATCH path
  (RBAC-settings dump, save-succeeded, preparing-response) are likewise downgraded to `debug`.
- `ObjectService` gains a request-scoped `uuidScopeCache` (plain array property) mapping
  uuid → resolved (register, schema). Repeated resolutions of the same uuid within one request go
  straight to the object's true magic table instead of re-missing the scoped path and re-running
  the cross-table scan. Fallback semantics are unchanged: the cache is consulted only after it has
  been populated by a successful resolution, and a stale entry is invalidated and falls back to the
  existing cross-table path (openregister#1520 behaviour preserved).

## Capabilities

### New Capabilities
<!-- None. Pure performance work on existing read behavior. -->

### Modified Capabilities
- `objects-crud`: single-object reads render exactly once, resolve inverse properties through the
  batched machinery (identical to list reads), and cache uuid → (register, schema) resolution for
  the duration of a request.
- `production-observability`: per-request render/read-path logging is emitted at `debug`, never
  `info`.

## Impact

- Code: `lib/Service/ObjectService.php` (`find()`, new `uuidScopeCache` property),
  `lib/Controller/ObjectsController.php` (`show()`, PATCH log levels),
  `lib/Service/Object/RenderObject.php` (`renderEntity()`, `handleInversedProperties()`,
  `batchLoadReferencingObjects()`, `renderEntities()` log levels).
- Tests: `tests/Unit/Service/ObjectServiceTest.php`, `tests/Unit/Controller/ObjectsControllerCoverageTest.php`,
  `tests/Unit/Service/Object/RenderObjectCoverageTest.php`,
  `tests/Unit/Service/Object/RenderObjectWriteOnlyRedactionTest.php` (pre-existing failure fixed:
  real `PropertyRbacHandler` instead of a wiping mock),
  `tests/Unit/Service/Merge/MergeServiceTest.php` (`willReturnMap` rows extended for the new
  `_render` parameter).
- No API contract change: response bodies are byte-identical for direct-scope reads; stale-scope
  reads and inverse-extended single reads now match the list-path result shape (which is the
  canonical one).
