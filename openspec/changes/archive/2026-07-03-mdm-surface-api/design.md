## Context

The `mdm-foundation` change (archived, merged) gave OpenRegister the MDM engine:
`QualityScorer` (pure per-object scoring + `status()` bucketing), the
`QualityScoreOnSaveListener` that materialises `qualityScore` / `qualityStatus`
onto every object at create/update, `DuplicateDetectionService::findDuplicates`,
and `SimilarityCalculator`. What is missing — and what ADR-045 assigns to
OpenRegister rather than each leaf app — is the steward-facing **surface** that
turns those scored objects into a governance view.

This change ships the first slice of that surface: a read-only REST
aggregation/query API scoped to `(register, schema)`. It reads what the engine
already wrote; it does not add scoring, survivorship, trust, merge, or sync.
Those are later ADR-045 changes (`mdm-survivorship-engine`, `mdm-frontend`, GDPR
generalisation, and the pipelinq `mdm-consume-or-surface` migration).

Current constraints:
- All object reads MUST flow through `ObjectService::findAll` / `count`, which
  apply RBAC and multitenancy scoping. The surface MUST NOT bypass them
  (ADR-022, ADR-045).
- Reuse the existing primitives; do not reimplement scoring or bucketing
  (ADR-011). `QualityScorer::status()` owns the good/fair/poor thresholds.
- Endpoints are authenticated steward endpoints (`@NoAdminRequired`), never
  `@PublicPage` (ADR-005, ADR-029).

Stakeholders: the OR team (owns the surface), `mdm-frontend` (consumes these
endpoints), pipelinq (reference migrator, later).

## Goals / Non-Goals

**Goals:**
- A `QualityStatisticsService` returning, for `(register, schema)`: average
  `qualityScore`, per-status bucket counts, a score-distribution histogram, and
  the lowest-N objects by score — computed from already-materialised fields.
- A `QualityController` with two GET endpoints (schema statistics; filterable,
  sortable, paginated lowest-quality list).
- A `DuplicateController` with one GET endpoint (paginated duplicate candidates
  via `DuplicateDetectionService::findDuplicates`).
- Correct route registration with explicit auth annotations; RBAC + tenant
  scoping inherited from `ObjectService`.

**Non-Goals:**
- No merge / survivorship / golden-record / trust-tier logic (later changes).
- No frontend / Vue (that is `mdm-frontend`).
- No new schemas, no re-scoring, no writes of any kind — read-only surface.
- No new outbound-sync / queue subsystem (ADR-045 reuses OR webhooks; out of
  scope here anyway).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

ADR-031 prefers schema-declarative business logic over service classes for
**materialised, per-object derived state, lifecycle transitions, and
notifications**. The behaviours in this change are none of those: they are
**read-time aggregations and queries computed on request** over a filtered,
RBAC-scoped object set. Therefore they are implemented **imperatively** as read
services + controllers, and this is the correct altitude:

- A quality **statistic** (average across a schema, bucket counts, a histogram)
  is a cross-object aggregate. It is not a property of any single object, so
  there is nowhere to materialise it. The existing declarative mechanism —
  `x-openregister-aggregations` — materialises a computed value onto **one**
  object's payload; it cannot express "average score across every object of this
  schema for this caller's RBAC scope", which is exactly what a dashboard needs.
- The lowest-N list and the duplicate-candidate list are **queries** (filter +
  sort + paginate, and pairwise similarity respectively), not stored derived
  fields. Queries are inherently request-time and caller-scoped.
- Nothing here is a lifecycle transition or a notification, so the
  declarative-lifecycle / declarative-notification dialects do not apply.

The declarative layer already did its job: the per-object `qualityScore` /
`qualityStatus` **are** materialised declaratively (via `x-openregister-quality`
+ the on-save listener). This change consumes that materialised field at read
time and aggregates it. Aggregation-on-read over a filtered set is a query
surface, not a materialised calculation — so an imperative read-service is the
right tool, and adding a declarative construct here would be a mis-fit.

### Reuse existing primitives (ADR-011)
- `QualityScorer::status(score, thresholds)` is reused verbatim for bucket
  assignment so the surface's buckets are identical to the materialised
  `qualityStatus`. The service reads the schema's `x-openregister-quality`
  `thresholds` (same source the listener uses) and passes them through.
- `DuplicateDetectionService::findDuplicates(register, schema, matchRules?,
  threshold?)` is reused as-is for the duplicate endpoint. The controller passes
  through an optional `threshold` and lets the service default from the
  annotation. No new dedup code.
- Alternatives considered: re-scoring objects at read time (rejected — wasteful
  and could diverge from the materialised value the listener wrote); a second
  bucketing implementation in the statistics service (rejected — duplicates
  `QualityScorer::status()` thresholds, ADR-011 violation).

### Object reads go through ObjectService (ADR-022 / ADR-045)
`QualityStatisticsService` reads via `ObjectService::findAll(config, _rbac:true,
_multitenancy:true)` with `filters.register` / `filters.schema` set, mirroring
how `DuplicateDetectionService::loadCandidates` already does it. RBAC and tenant
scoping are therefore inherited, not re-implemented. Prefer expressing the
lowest-N list as a `findAll` with `sort` on `qualityScore` + `limit`/`offset`
and an optional `qualityStatus` filter.

**ObjectService helper — add only if required (ADR-011):** if the average /
histogram / bucket counts can be derived by iterating a bounded `findAll` result
(or by combining `count()` with a status filter), no helper is added. A narrow
helper (e.g. `countByStatus` or a scoped stats query) is added to
`ObjectService` **only** if the aggregation genuinely cannot be expressed with
the existing `findAll` / `count` surface at acceptable cost for large registers.
The tasks reflect this as a conditional step, not a mandatory one.

### Endpoint shapes (ADR-002, REST / Common Ground)
Provisional register/schema-scoped GET routes, aligned with the existing
aggregation routes (`/api/objects/aggregations/{register}/{schema}/...`):
- `GET /api/objects/quality/{register}/{schema}/stats` → statistics envelope.
- `GET /api/objects/quality/{register}/{schema}` → lowest-quality list; accepts
  `qualityStatus` filter, `sort`/`order`, and pagination params (`limit`/`offset`
  or page params) consistent with the existing object-list conventions.
- `GET /api/objects/duplicates/{register}/{schema}` → duplicate candidates;
  accepts optional `threshold` and pagination params.

All three carry `@NoAdminRequired` + `@NoCSRFRequired` docblock annotations
(matching `AggregationController`), and every method is registered in
`appinfo/routes.php` (ADR-016, ADR-029). Exact URL shape is provisional — see
Open Questions.

### Histogram / lowest-N defaults
- Histogram: 10 equal-width buckets over `[0,1]` (0.0–0.1, …, 0.9–1.0),
  provisional — configurable via a request param is a possible extension but not
  required here.
- Lowest-N: default page size follows the existing object-list default;
  callers page for more. The "lowest-N" framing is served by ascending-score
  sort + pagination rather than a fixed N.

## Risks / Trade-offs

- [Large registers make full-set aggregation expensive] → Scope every read to
  `(register, schema)` and the caller's RBAC set; reuse `count()` with status
  filters where possible instead of loading every row; cap candidate loads the
  way `DuplicateDetectionService` already does (`MAX_CANDIDATES`). If a helper is
  needed, push the aggregation to the DB rather than iterating in PHP.
- [Bucket drift between surface and materialised status] → Mitigated by reusing
  `QualityScorer::status()` and the schema's own `thresholds`; the surface never
  invents its own boundaries.
- [Endpoint URL shape churn once `mdm-frontend` consumes it] → Shapes are marked
  provisional and mirror the aggregation routes to minimise surprise; frontend
  is a later change so the contract can still be adjusted before a consumer
  locks it in.
- [Read endpoint accidentally mutating] → Spec + review guardrail: duplicate
  endpoint is strictly side-effect-free; no write path is wired.

## Seed Data

This change adds **no new schemas** — it is a read/aggregation API over objects
that already carry materialised `qualityScore` / `qualityStatus`. Therefore
there is **no seed-data task**. Testing relies on **existing scored objects**:
any schema declaring `x-openregister-quality` (and `x-openregister-dedup` for
the duplicate endpoint) whose objects have been through the on-save
materialisation — pipelinq's `masterEntity` schema is the canonical reference,
and the archived `mdm-foundation` specs' example schemas also apply. Unit tests
run the CI way (php:8.3-cli + OCP stubs, no live Nextcloud), so they exercise
the statistics/aggregation logic against in-memory `ObjectService` doubles
rather than a seeded live register.

## Migration Plan

Additive only: new services, new controllers, new routes. No schema migrations,
no data backfill, no changes to existing endpoints. Rollback is removing the new
routes + classes; nothing else references them until `mdm-frontend` lands.

## Open Questions

- Endpoint URL shape: `/api/objects/quality/...` and `/api/objects/duplicates/...`
  (chosen provisionally to mirror the aggregation routes) vs a top-level
  `/api/mdm/...` namespace. Deferred to the parent for confirmation.
- Histogram bucket count: fixed at 10 vs request-configurable.
- Whether a dedicated `ObjectService` aggregation helper is warranted now or
  deferred until a large-register performance need is demonstrated.
