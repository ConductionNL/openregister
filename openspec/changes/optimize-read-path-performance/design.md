## Context

Line references at base commit 40378fa37 (origin/development, includes PR #388's batched schema
resolution in serializeMany — adjacent but non-overlapping territory).

**Double render.** `ObjectsController::show()` calls `ObjectService::find()` (which renders via
`renderHandler->renderEntity()` with the request's `$_extend`) and then calls
`objectService->renderEntity()` on the returned entity with the same `$_extend` plus
filter/fields/unset. Every pass repeats `renderFileProperties()`,
`hydrateMetadataFromFileProperties()`, writeOnly redaction, translation resolution and — worst —
`handleInversedProperties()`.

**Inverse fallback.** `inverseRelationCache` is populated only by `preloadInverseRelationships()`
on the list path (`renderEntities()`). A single render therefore hits the fallback:
`objectEntityMapper->findByRelation($uuid)` — a reverse-reference scan across every magic table —
plus a per-property `findByRelationBatchInSchema` loop for multi-field `inversedBy`, plus
`\OC::$server->get(MagicMapper::class)` service-location inside the render (twice), despite
`MagicMapper` already being a constructor dependency (`$objectEntityMapper`).

**Scope cache.** `find()`'s cross-schema fallback (openregister#1520) is correct and must stay:
relations reference targets by globally-unique uuid while the URL scope can be stale. But the
fallback ran again for EVERY repeated resolution of the same uuid within one request.

## Decision: `_render: false` on find(), controller stays the render site

Two designs were considered for the double render:

1. **`find(_render: false)` used by `show()`** — the controller remains the single render site
   (chosen).
2. `show()` trusts the rendered entity from `find()` and skips its own render.

Option 1 wins because `show()`'s render is the more capable one: it applies `filter`, `fields` and
`unset` (which `find()`'s internal render never received) and its output feeds `@self` enrichment,
`_names` collection and JSON-LD negotiation. Making `show()` trust `find()`'s render would need the
filter/fields/unset plumbing pushed into `find()` (a wider signature change for all ~40 callers'
semantics to re-verify) and would leave the redaction inside a code path the controller does not
control. With option 1 the new parameter is trailing and defaulted, so every other caller of
`find()` — controllers, MergeService, TransitionEngine, MCP tools, listeners — is provably
unchanged. Retrieval, cross-schema fallback, `checkPermission()` and AVG read logging still run
inside `find()` in both modes.

Redaction stays exactly-once by construction: it lives inside `RenderObject::renderEntity()`
(openregister#385/#386), and the response path now contains exactly one `renderEntity()` call.
Pinned by `testShowFindsWithoutRenderAndRendersExactlyOnce` +
`RenderObjectWriteOnlyRedactionTest` (which now uses the real `PropertyRbacHandler` — the previous
bare mock returned `[]` and wiped the object, failing 2 tests at HEAD).

## Decision: single-entity inverse resolution = list machinery + legacy fallback

`handleInversedProperties()` now, on a cold cache, calls
`preloadInverseRelationships([$entity], $allInversePropertyNames)` and serves from the cache —
identical machinery and query shape as the list path (register-scoped, target-schema-scoped,
GIN-indexed). The old cross-table scan is kept below it as a resilience fallback for the cases the
batch preload cannot handle (unresolvable `$ref`, batch query failure) so no configuration that
worked before can break.

Shape preservation (review finding): the preload covers ALL of the schema's inverse properties, not
just the extended ones. A single read has always resolved every inverse property once any one of
them is extended; preloading only the extended subset would make `handleInversedPropertiesFromCache`
silently empty the others (cache miss → `[]`/null) — a consumer-visible regression on
`GET /objects/{id}?_extend=someInverseProp`. Cost: one batched query per inverse property on the
schema (each replacing what the fallback did with a full cross-table scan), so the perf win stands.
The extended property's value is identical to the list read; the complete shape is pinned by the
parity test.

## Decision: uuid scope cache is an array property, consult-populate-invalidate

`private array $uuidScopeCache` on `ObjectService` (one instance per request in NC DI). Populated
after every successful uuid-keyed `find()` with the re-anchored `(Register, Schema)` objects (not
ids — avoids re-lookup on hit). Consulted after the caller's scope is applied, so the cached true
context overrides a stale caller scope before the first lookup. Invalidated inside the
`DoesNotExistException` catch, where the existing openregister#1520 fallback then proceeds
unchanged. Cached only for `isUuidFormat()` identifiers — slugs and numeric ids are not globally
unique. No TTL, no size bound: the set of distinct uuids resolved in one request is small and the
array dies with the request.

## Risks / Trade-offs

- **Inverse response shape**: fully preserved. An earlier draft preloaded only the extended
  inverse properties (strict list parity), which review flagged as a consumer-visible regression —
  non-extended inverse properties would have been silently emptied. The final design preloads all
  inverse properties on the single path, keeping the legacy shape at batched-query cost.
- **PHPUnit mock materialization**: adding a trailing parameter to `find()` changes the argument
  count mocks receive; `willReturnMap` rows (exact-arity matching) in `MergeServiceTest` were
  extended. `with()`-style expectations are prefix-matched and unaffected.
