---
kind: code
depends_on: []
---

## Why

OpenBuilt's `openbuilt-app-detail-overview` spec needs an "Active users" KPI scoped to a virtual app's per-version register. The KPI counts distinct user IDs (`actors`) in the audit trail across a set of schema IDs over a configurable time window (7d / 30d / 90d). OR's existing `AuditTrailMapper` exposes `getStatistics()` (totals by action), `getActionChartData()` (timeline), `getDetailedStatistics()`, `getActionDistribution()`, and `getMostActiveObjects()` — but no method returns a distinct-actor count, and none of the existing methods can be combined to derive it without N round-trips over the audit log.

Without this aggregation, the openbuilt insights endpoint either has to (a) pull every audit row and de-duplicate in PHP (unbounded payload, doesn't scale), or (b) ship a custom SQL query inside openbuilt that bypasses the OR data layer — the same anti-pattern ADR-022 explicitly rejects ("Apps consume OpenRegister abstractions"). Adding the method to `AuditTrailMapper` keeps audit-trail query authority where it belongs and gives openbuilt a single typed call.

## What Changes

- **NEW** `AuditTrailMapper::getDistinctActorCount(array $schemaIds, int $hours): int` — returns the count of distinct `user_id` values in the `audit_trail` table where `schema_id IN (:schemaIds)` and `created >= (NOW() - :hours hours)`.
- **NEW** Unit test `AuditTrailMapperTest::testGetDistinctActorCount` covering happy path, empty `$schemaIds` (returns 0 without hitting DB), zero / negative `$hours` (rejected via `InvalidArgumentException`), and multi-schema fan-out.
- **NEW** Index suggestion (documented in design.md; index migration itself is out of scope and lands in a follow-up if real-world query performance shows a need).
- No API endpoint, no controller, no route changes. The method is consumed in-process by other apps (openbuilt) and by future internal callers; exposing it via REST is a separate concern.

## Capabilities

### New Capabilities

- `audit-trail-distinct-actors`: A typed audit-trail aggregation answering "how many distinct users acted on objects in these schemas over the last N hours?". Lives on `AuditTrailMapper` alongside the existing aggregations. In-process only (no REST endpoint).

### Modified Capabilities

<!-- None: the existing audit-trail-statistics capability is unchanged. -->

## Impact

- **Modified PHP**: `lib/Db/AuditTrailMapper.php` (add one public method + one private SQL helper if needed).
- **New tests**: `tests/Unit/Db/AuditTrailMapperTest.php` (extend existing or add a new test case).
- **OpenRegister floor bump**: callers that depend on this method (e.g. openbuilt's insights endpoint) MUST declare `openregister: ^<next-version>` in their `info.xml`. The next OR semver tag carries the method; openbuilt's spec B says "depends_on this".
- **No DB migration required**: the `audit_trail` table already has `user_id`, `schema_id`, and `created` columns. A composite index on `(schema_id, created, user_id)` is recommended for production scale; documented in design.md, deferred to follow-up if measurement justifies.
- **No breaking changes**: the method is purely additive.
