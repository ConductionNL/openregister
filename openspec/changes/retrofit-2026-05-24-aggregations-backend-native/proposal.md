# Retrofit — aggregations-backend-native

## Why

Describes observed behaviour of ~25 methods across the aggregation
runtime (`AggregationCache`, `AggregationRunner`,
`AggregationController`, `AggregationThresholdListener`) as 5 new REQs
under a new `aggregations-backend-native` capability spec. Code
already exists — this change retroactively specifies the
sub-behaviours that the in-flight `aggregations-backend-native`
change (delta on `zoeken-filteren`) does not surface as testable
contracts.

## What Changes

- Creates a new capability spec `aggregations-backend-native` with
  five REQs covering: cache-key composition (REQ-ABN-001), backend
  dispatch chain with non-fatal external errors (REQ-ABN-002), RBAC
  list-permission gate at runner entry (REQ-ABN-003), native SQL
  safety predicates + operator allow-list (REQ-ABN-004), and
  threshold listener rising-edge semantics (REQ-ABN-005).
- Annotates the four runtime files (`AggregationCache.php`,
  `AggregationRunner.php`, `AggregationController.php`,
  `AggregationThresholdListener.php`) with `@spec` tags pointing at
  this change's tasks. No behavioural code changes.

## Why a new capability spec

The in-flight `aggregations-backend-native` change adds four
requirements on the `zoeken-filteren` capability covering the
high-level dispatch contract (backend selection / operator
translation / 60s cache / backend attribution). Those requirements
state *what* the runner does; they do not specify the security,
fail-closed, and observable-edge behaviours the implementation
actually enforces. That observable surface is large enough — and
distinct enough from search/filter — that it warrants its own
capability spec rather than a 5th `zoeken-filteren` extension.

## Affected code units

- `lib/Service/Aggregation/AggregationCache.php` —
  `get` / `set` / `getAdhoc` / `setAdhoc` / `adhocName` /
  `evictForSchema` / `rbacScopeHash`
- `lib/Service/Aggregation/AggregationRunner.php` —
  `run` (external → native → fallback dispatch + RBAC gate +
  PHP fallback row cap + `truncated` surfacing) /
  `runAdhoc` / `tryNativeAggregation` (soft-delete predicate +
  multi-tenant predicate + operator allow-list)
- `lib/Listener/AggregationThresholdListener.php` —
  `handle` / `evaluate` / `compare` (rising-edge transition +
  30-day state TTL + `bypassRbac: true` on the listener-driven
  aggregation call)

## Approach

For each method: describe observed inputs, outputs, pre/postconditions,
failure modes. Draft REQs that match the behaviour, not aspirational
extensions. Each REQ carries at least one `WHEN … THEN …` scenario
that maps to a code path the unit/integration tests already exercise.

The five REQs cover, in order:

- **REQ-ABN-001** — cache key composition (register/schema/name + canonicalised
  filter hash + RBAC scope hash) + 60s TTL + fail-closed on backend down
- **REQ-ABN-002** — backend dispatch order (external → Postgres-native → PHP
  fallback) + non-fatal external-backend error → fall through + `backend`
  attribution always populated
- **REQ-ABN-003** — RBAC list-permission gate at runner entry +
  `bypassRbac` reserved for non-controller callers with authoritative
  reason (report renderer, threshold listener)
- **REQ-ABN-004** — native SQL path soft-delete predicate + multi-tenant
  `_organisation` predicate + closed operator allow-list (`in` /
  `gt` / `gte` / `lt` / `lte` / `ne`) → any other shape returns null
  and falls back to PHP
- **REQ-ABN-005** — threshold listener rising-edge fire (below → above
  transitions only) + state cache with 30-day TTL + listener bypasses
  RBAC because the write event already passed RBAC

## Notes / observed-but-suspicious

- **Cache eviction is coarse.** `AggregationCache::evictForSchema()`
  calls `ICache::clear()` on the whole `openregister_aggregations`
  namespace because ICache has no prefix-delete. Recorded as observed
  behaviour; the 60s TTL bounds staleness even when a write event is
  missed. Tracked in the in-flight change's task list.
- **`compare()` uses strict numeric equality** (`===` on floats).
  Threshold-state transitions on `eq` / `ne` operators are therefore
  brittle on floating-point inputs. Captured in REQ-ABN-005 notes; not
  silently "fixed" in the spec.
- **PHP fallback row cap is 10 000.** The cap is hard-coded
  (`PHP_FALLBACK_ROW_CAP`). Captured under REQ-ABN-002 as the source of
  the `truncated: true` flag; tightening it (e.g. 503 instead of
  partial result) is a future hardening step tracked in the in-flight
  change.

Source: openspec/coverage-report.md generated 2026-05-24. See
[retrofit playbook](../../../.github/docs/claude/retrofit.md).
