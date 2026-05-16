## Context

`AuditTrailMapper` ships with several aggregations (`getStatistics`, `getActionChartData`, `getDetailedStatistics`, `getActionDistribution`, `getMostActiveObjects`, `getStatisticsGroupedBySchema`). None of them return a distinct-actor count, which is exactly the shape openbuilt's app-insights endpoint needs for its "Active users" KPI (scoped to a per-version register's schema set, over a 7d / 30d / 90d window).

This spec adds one method. It's not architectural — it's plugging a small gap in an existing aggregation surface. The interesting parts are (1) signature consistency with the neighbouring methods, (2) edge cases (empty schemaIds, invalid hours), and (3) the future index decision.

## Goals / Non-Goals

**Goals:**

- Add `AuditTrailMapper::getDistinctActorCount(array $schemaIds, int $hours): int`.
- Consistent shape with neighbouring aggregations: positional + typed parameters, returns a scalar (not an array), uses the same DBAL query builder pattern as the other methods.
- Edge cases handled explicitly: empty `$schemaIds` short-circuits to `0` without a DB round-trip; `$hours <= 0` raises `\InvalidArgumentException`.
- Unit-tested at the same level as the other aggregation methods.

**Non-Goals:**

- A REST endpoint exposing the count. The method is in-process only.
- A method variant that returns the actor UIDs themselves (just the count). A future `getDistinctActors(...)` returning the list is a separate addition if a real caller appears.
- Time bucketing (e.g. distinct actors per day). The activity timeline is already covered by `getActionChartData`; combining the two on the caller side is straightforward.
- Index migrations. Documented as a follow-up; this spec ships the method and the test, not a DDL change.

## Decisions

### Decision 1 — Signature shape mirrors neighbouring aggregations

The method signature is `getDistinctActorCount(array $schemaIds, int $hours): int`. Rationale: aligns with `getDetailedStatistics(?int $registerId, ?int $schemaId, ?int $hours)`, `getActionDistribution(?int $registerId, ?int $schemaId, ?int $hours)`, and `getMostActiveObjects(?int $registerId, ?int $schemaId, ?int $limit, ?int $hours)` — all carry an `$hours` window. The only intentional shape divergence: `$schemaIds` is a non-nullable `array<int>` instead of an optional `?int $schemaId` because the openbuilt caller always passes a multi-schema set (a virtual-app version's full schema set, walked from `manifest.pages[].config.schema`). Returning to single-schema variants would force the caller to make N calls and sum — defeating the point of an aggregation method.

We do not also accept a `$registerId` filter. Openbuilt's use case is "schemas across a specific per-version register"; passing the register ID would be redundant once the schema IDs are pinned. Future callers needing register-level filtering can add a sibling method.

### Decision 2 — `$schemaIds = []` short-circuits to `0`

Passing an empty array of schema IDs returns `0` without executing the SQL. Reason: PostgreSQL's `IN ()` is a syntax error; MySQL's accepts but always returns the empty set. Either way the result is `0`, so the short-circuit saves a query.

### Decision 3 — `$hours <= 0` raises `\InvalidArgumentException`

A non-positive `$hours` is a caller bug, not a valid input. We reject loudly rather than silently returning `0` (which would mask the bug) or returning the lifetime count (which would silently change semantics for callers expecting a windowed query). The other aggregation methods accept `$hours` with a sensible default (`24`); this method makes the parameter required so callers explicitly think about the window.

### Decision 4 — SQL shape

```sql
SELECT COUNT(DISTINCT a.user_id) AS distinct_actors
FROM   audit_trail a
WHERE  a.schema_id IN (:schemaIds)
  AND  a.created >= :since
  AND  a.user_id IS NOT NULL
```

`since` is computed in PHP as `(new \DateTimeImmutable())->modify("-{$hours} hours")` and bound as an `IQueryBuilder::PARAM_DATE` / `PARAM_STR` (matching the existing methods' bind style — verify the exact constant by reading `AuditTrailMapper::getActionChartData` at apply time). `:schemaIds` is bound with `IQueryBuilder::PARAM_INT_ARRAY`. The `user_id IS NOT NULL` guard excludes system/CLI events that have no acting user. We deliberately count distinct `user_id` values, not actor display names — UIDs are the canonical actor identity in NC.

### Decision 5 — Performance + index suggestion (follow-up, not in this spec)

`COUNT(DISTINCT user_id)` over a multi-schema window is `O(N)` over matching rows. On busy installs the dominant cost is the `WHERE` row scan, not the de-duplication. A composite index on `(schema_id, created, user_id)` would cover the predicate scan; whether this is worth a migration depends on real-world audit-trail volume. The spec ships the method; the index decision is deferred to follow-up. Document the recommendation in `lib/Db/AuditTrailMapper.php`'s method PHPDoc with a `@see` pointing back to this spec.

## Seed Data

This change ships no seed data. The method reads existing audit-trail rows produced by the rest of OR's lifecycle. The unit test seeds a small audit-trail fixture in `tests/Unit/Db/AuditTrailMapperTest.php` — purely test-local, not part of any repair step.

## Declarative-vs-imperative decision

| Concern | Declarative? | Imperative? | Decision |
|---------|--------------|-------------|----------|
| Distinct-actor aggregation | OR has no declarative aggregation vocabulary for cross-row SQL COUNT(DISTINCT) | Single method on `AuditTrailMapper`, a single SQL statement | **Imperative**. Falls under ADR-031 §Exceptions ("cross-system aggregations / SQL that the schema vocabulary does not express"). The method is a pure function of its parameters — no state, no side effects. |

This is the only behaviour the spec introduces. There is no lifecycle, calculation, notification, or relation to make declarative.

## Risks / Trade-offs

- **NULL user_id rows excluded.** System-initiated audit rows (e.g. CLI repair steps, background jobs running as no-user) are excluded from the count. This is intentional — the KPI represents *human* activity — but the docblock must say so explicitly. A future variant `getDistinctActorCountIncludingSystem(...)` can land if needed.
- **DB engine differences.** PostgreSQL distinguishes `NULL`-handling subtly from MySQL/MariaDB in `COUNT(DISTINCT)` — both engines exclude NULL from the count by default, which matches our intent. The explicit `user_id IS NOT NULL` guard is defensive against engine-quirks and makes the predicate self-documenting.
- **No `$registerId` filter.** Callers wanting "all schemas of register X" must pre-resolve those schema IDs themselves (one extra query). Acceptable cost; avoids adding two filters where one suffices.
- **Unit test reliance on a real DB.** OR's existing mapper tests use the real configured DB (PHPUnit fixture) — the new test follows that pattern, seeding 5–10 audit-trail rows in `setUp` and asserting the count. No new infrastructure.

## Migration Plan

Strictly additive. No data migration, no schema change, no repair step. The method's first commit ships in a patch-level OR release; callers (openbuilt's app-insights endpoint) declare the new floor in their `info.xml`.

Rollback: revert the file change. Zero data side effects.

## Open Questions

None at spec-write time. The signature, behaviour, and edge cases are pinned by the design decisions above; the index decision is explicit follow-up, not an open question for this spec.
