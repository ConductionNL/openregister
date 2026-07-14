## 1. Eliminate the double render on show()

- [x] 1.1 Add `bool $_render = true` to `ObjectService::find()`; when false, return the raw entity
      after retrieval + cross-schema fallback + permission check + AVG read logging, skipping the
      render pass. Audit all other `find()` callers (controllers, services, listeners, MCP tools) —
      all keep the default and their behaviour is identical.
- [x] 1.2 `ObjectsController::show()` calls `find(..., _render: false)` and remains the single
      render site; its `renderEntity()` call applies extend/filter/fields/unset AND the writeOnly /
      read-authorization redaction exactly once on the response path.
- [x] 1.3 Tests: `testFindSkipsRenderingWhenRenderFalse` (render handler never invoked, permission
      check still runs), `testShowFindsWithoutRenderAndRendersExactlyOnce` (find called with
      `_render: false`, renderEntity called exactly once, its redacted output IS the response).

## 2. Single-read inverse properties through the batched machinery

- [x] 2.1 `handleInversedProperties()`: when the inverse cache is cold (single-entity render), call
      `preloadInverseRelationships([$entity], $extendList)` — the same schema-targeted batched path
      the list uses — then serve from `inverseRelationCache`. Keep the generic cross-table
      `findByRelation()` scan only as a resilience fallback when the preload cannot populate the
      cache.
- [x] 2.2 Pass the normalized extend list from `renderEntity()` into `handleInversedProperties()`
      (new trailing `?array $_extendList = null` parameter) so the single path preloads exactly the
      extended inverse properties, like the list path.
- [x] 2.3 Replace both `\OC::$server->get(MagicMapper::class)` service-locations with the
      constructor-injected `$this->objectEntityMapper` (same class; no constructor change needed —
      MagicMapper was already injected).
- [x] 2.4 Test: `testSingleReadResolvesInversePropertiesViaBatchedPreloadLikeListRead` —
      `findByRelation` never called, `findByRelationBatchInSchema` used on both paths, and the
      single-read result for the inverse property is identical to the list-read result.

## 3. Hot-path logging downgrades

- [x] 3.1 Downgrade both `[BATCH_PRELOAD]` `logger->info()` calls in
      `RenderObject::renderEntities()` to `debug`.
- [x] 3.2 Sweep the touched files for other per-request info-level logging on render/read/response
      paths: downgraded the three ObjectsController PATCH-path `logger->info()` calls
      (RBAC-settings, save-succeeded, preparing-response) to `debug`. Left as `info`: the bulk
      validate/delete operation logs (explicit admin batch operations, not per-request) and the
      RESTRICT-skip business event in `ObjectService`.

## 4. Request-scoped uuid → (register, schema) resolution cache

- [x] 4.1 Add `private array $uuidScopeCache` to `ObjectService`; populate it after every
      successful uuid resolution in `find()` (both direct and fallback paths); consult it before
      the scoped lookup; invalidate the entry inside the miss-catch so a stale entry falls back to
      the existing cross-table path (openregister#1520 semantics unchanged).
- [x] 4.2 Tests: `testFindUsesUuidScopeCacheOnRepeatedStaleScopeLookups` (second stale-scope read =
      exactly one scoped handler call targeting the resolved register/schema, no second cross-table
      scan) and `testFindInvalidatesUuidScopeCacheWhenCachedScopeMisses` (stale entry invalidated,
      fallback still resolves).

## 5. Test-suite honesty fixes encountered on the way

- [x] 5.1 Fix pre-existing `RenderObjectWriteOnlyRedactionTest` failures (2 tests red at HEAD): the
      bare `PropertyRbacHandler` mock returned `[]` from `filterReadableProperties()` and wiped the
      rendered object; replaced with the REAL `PropertyRbacHandler` (mocked session/group/condition
      collaborators) so the actual stripping logic is exercised.
- [x] 5.2 Extend the five `willReturnMap` rows in `MergeServiceTest` with the new 8th `_render`
      argument (PHPUnit materializes defaults for all declared mock parameters).

## 6. Verification

- [x] 6.1 `composer check:strict` — no new findings in touched files.
- [x] 6.2 PHPUnit unit suite green apart from failures already present at the base commit
      (verified by diffing the failing-test list against a HEAD baseline run in the same
      environment).

## Acceptance Criteria

- A `show()` read executes exactly one render pass; writeOnly / read-authorization redaction is
  applied exactly once, on the response path.
- A single read with an extended `inversedBy` property issues schema-targeted
  `findByRelationBatchInSchema` queries (no cross-table `findByRelation` scan) and returns the same
  inverse-property value a list read returns.
- No `logger->info()` fires per-request on the render/read path.
- Within one request, the second read of a uuid under a stale scope performs one scoped lookup
  against the object's true register/schema — no repeated cross-table scan; the openregister#1520
  fallback still resolves stale scopes correctly on first contact and after invalidation.
