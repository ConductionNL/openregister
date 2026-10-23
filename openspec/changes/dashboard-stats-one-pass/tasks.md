## 1. Aggregate once per request

- [x] 1.1 Add `AuditTrailMapper::getStatisticsGroupedByRegisterSchema()`: one `GROUP BY register, schema` query.
- [x] 1.2 Add `MagicMapper::countObjectsPerPair()` (via `MagicStatisticsHandler`): one count per table, unreadable tables count as 0.
- [x] 1.3 `DashboardService::getStats()` sums the per-request object counts and audit groups for its scope, and reads the webhook log statistics once.
- [x] 1.4 `DashboardService::getOrphanedStats()` sums the tables and audit groups whose register-schema combination is not a valid one, instead of a query with one exclusion per valid pair.

## 2. Verify

- [x] 2.1 Unit test: each table is counted once and the audit trail grouped once, and the totals, register, schema and orphaned sums are right.
- [x] 2.2 Existing fail-soft tests still return the zero envelope when the mappers throw.
