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
`preloadInverseRelationships([$entity], $extendList)` and serves from the cache — identical
machinery, query shape and result semantics as the list path (register-scoped, target-schema-scoped,
GIN-indexed). The old cross-table scan is kept below it as a resilience fallback for the cases the
batch preload cannot handle (unresolvable `$ref`, batch query failure) so no configuration that
worked before can break. The normalized extend list is passed down as a new trailing
`?array $_extendList = null` parameter (null → preload all inverse properties, preserving the
behaviour of direct invocations e.g. in tests).

Consequence, intentional: a single read now resolves inverse properties with LIST semantics (only
extended inverse properties are populated; scoping by the entity's register). This is the canonical
shape — the list path is what production traffic overwhelmingly exercises — and is pinned by the
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

- **Behavioural delta on stale-scope + inverse-extended single reads**: response values for
  non-extended inverse properties on single reads change from "populated anyway" to "empty unless
  extended" (list parity). Accepted: extending is the documented way to request inverse data, and
  the previous behaviour was an artefact of the slow path.
- **PHPUnit mock materialization**: adding a trailing parameter to `find()` changes the argument
  count mocks receive; `willReturnMap` rows (exact-arity matching) in `MergeServiceTest` were
  extended. `with()`-style expectations are prefix-matched and unaffected.
