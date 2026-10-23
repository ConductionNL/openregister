## Why

`GET /api/dashboard` took 125 s on a development instance with 56 registers, 1,713 register-schema tables and about 1 million audit trail rows. The schema page waits on it, so it never left "Loading schema statistics…".

The rollup asked the mappers again for every scope: the system totals, each register, each schema and the orphaned row. That made about 1,760 audit trail aggregates and three counts of every table. The orphaned audit trail query alone took 95 s. It excluded every valid register-schema pair with a separate `OR` group (1,662 groups), which the database evaluates against every row.

## What Changes

- The audit trail is aggregated once per request, grouped by register and schema (0.23 s on the same data). Totals, register, schema and orphaned statistics are sums over those groups.
- Each register-schema table is counted once per request. The totals, each register and each schema read the same counts.
- The webhook log statistics are read once per request.
- The response is unchanged: the same envelope, the same values.

## Impact

- `DashboardService::getStats()` and `getOrphanedStats()` read the per-request aggregates.
- New `AuditTrailMapper::getStatisticsGroupedByRegisterSchema()` and `MagicMapper::countObjectsPerPair()`.
- The existing `getStatistics()` mapper methods stay for their other callers.
