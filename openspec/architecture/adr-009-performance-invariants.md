# ADR-009: Performance invariants for the object hot path and write side

**Status**: accepted (codifies performance rules the object read/write path must
hold; see the optimization changes under `openspec/changes/` that bring the code
into compliance)

**Date**: 2026-07-07

## Context

OpenRegister is the foundation app for ~15 consuming apps. Its object read
(list/search/render) and write (save/bulk) paths run on every request those apps
make, so a constant-factor inefficiency there is multiplied across the whole
fleet. A performance review at HEAD found a consistent class of problem: work
that is a function of the *schema* (or of the *request*) is being recomputed per
*object*, and cache-invalidation is instance-wide ("nuclear clear") on every
write. Individually cheap operations — a property scan, a group lookup, a JSON
round-trip, a `Validator` construction — become O(objects × N) because they sit
inside the per-object loop instead of being hoisted or memoized.

There was no written performance contract, so these regressions passed review
individually. This ADR establishes the invariants the hot path must satisfy so
future changes can be checked against them.

## Decision

**Per-object work is bounded to what genuinely varies per object. Schema-derived
and request-derived values are computed once and reused. Cache invalidation is
scoped, never instance-wide. Outbound I/O never blocks the write response.**

### Numbered rules

#### Rule 1 — Schema-derived values are computed once per schema, not per object

Anything that depends only on the schema — `hasPropertyAuthorization()`,
`getPropertiesWithAuthorization()`, `hasComputedProperties()`, the cleaned
validation schema, the compiled validator — MUST be computed once per schema per
render/save pass and reused across all objects of that schema. The correct
pattern already exists in `lib/Service/Object/QueryHandler.php:221`
(`$checkedSchemas[$schemaId] = …`); it MUST be applied on the render and
validate paths too.

#### Rule 2 — Request-invariant values are memoized per request

Values that cannot change within a request — the current user's group ids,
admin status, resolved registers/schemas — MUST be resolved once and cached for
the request's duration. Repeated `getUserGroupIds()`/`isAdmin()` calls per
property per object are prohibited.

#### Rule 3 — One request-scoped cache, not many ad-hoc ones

There is exactly one request-scoped entity cache. The parallel per-request caches
(`SchemaMapper::$findCache`, `RegisterMapper::$findCache`,
`RenderObject::$registersCache`/`$schemasCache`) MUST be consolidated onto the
single `RequestScopedCache` built for this purpose (currently dead code), or that
class MUST be removed — but three uncoordinated caches for the same entities is
not permitted.

#### Rule 4 — Cache invalidation is scoped

A write MUST NOT clear the entire distributed query/aggregation cache. Cache keys
MUST carry a `register:schema` (or finer) prefix so invalidation targets only the
affected bucket. `queryCache->clear()` / `AggregationCache::evictForSchema()`
doing an instance-wide wipe on every CUD is prohibited; bulk operations MUST
collapse invalidation to one call per affected bucket, not one per item.

#### Rule 5 — Outbound I/O is asynchronous to the write

Webhook delivery and any other outbound HTTP triggered by an object write MUST be
dispatched to a background job, including the first attempt. A write response's
latency MUST NOT depend on a third party's availability. Cheap "is anything
subscribed?" checks MUST short-circuit *before* serializing payloads.

#### Rule 6 — No unbounded full-table load on a user-facing request

Full-table warmups/scans (e.g. `warmupNameCache()`) MUST run only in background
jobs, never be auto-triggered synchronously inside a request.

## Consequences

- (+) Object list/search cost scales with page size, not with schema width ×
  page size; write latency is decoupled from webhook targets.
- (+) The cache actually serves scoped hits instead of behaving as a global cache
  wiped on every write.
- (−) Requires a memoization/caching discipline that a mechanical gate should
  eventually enforce (e.g. flag `getUserGroupIds` inside a per-object loop).
- Follow-up changes: `optimize-object-render-hot-path`,
  `scope-cache-invalidation`, `async-webhook-delivery`,
  `consolidate-request-scoped-cache` under `openspec/changes/`.
