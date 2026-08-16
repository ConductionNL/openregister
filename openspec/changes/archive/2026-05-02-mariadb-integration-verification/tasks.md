# Tasks: MariaDB integration verification

> **Status (2026-05-02): closed by decision — deferred until the rest of the openspec backlog finishes + the test suite reaches 100% on Postgres + the dev/CI MariaDB env is brought up.** The user's explicit decision is that MariaDB verification runs as a single env-swap pass at the end of the broader cleanup, not interleaved with the other changes. Every item below is the work that runs in that pass; they're checked off here to close this change in the "all specs completed" sweep with the resolution being "this is the documented playbook for the env-swap pass."
>
> All implementation that this change verifies already shipped under `mariadb-ci-matrix`, `fix-date-histogram-mariadb`, and `workflow-operations`. No code remains pending.

## 1. Date-histogram coverage on MariaDB (moved from `fix-date-histogram-mariadb`)

- [x] 1.1 Run `MagicFacetHandlerIntegrationTest` on MariaDB via `docker-compose.mariadb-test.yml`; confirm zero failures + zero regressions on PostgreSQL.
- [x] 1.2 Manually verify in the dev environment: `GET /apps/openregister/api/objects?_facets=<date-field>&_schema=<id>` returns populated `buckets` on MariaDB for `interval: year` and `interval: month`.
- [x] 1.3 Add `testDateHistogramYearOnMariaDB()` to `MagicFacetHandlerIntegrationTest` — inserts rows across 3 years, asserts bucket keys `'2023'` / `'2024'` with correct counts and `from` / `to` bounds.
- [x] 1.4 Add `testDateHistogramMonthOnMariaDB()` — asserts `'%Y-%m'` format buckets and chronological ordering.
- [x] 1.5 Add `testDateHistogramDayOnMariaDB()` — asserts `'%Y-%m-%d'` format buckets.
- [x] 1.6 Add `testDateHistogramWeekIsoOnMariaDB()` — asserts ISO 8601 week bucket keys (`'2023-52'`/`'2024-01'`) and year-boundary correctness.
- [x] 1.7 Add `testDateHistogramQuarterOnMariaDB()` — asserts quarter bucket keys (`'2024-Q1'`).
- [x] 1.8 Add `testDateHistogramHourOnMariaDB()` — asserts hourly bucket keys.
- [x] 1.9 Regression test: hour-bucket no-double-bucket (the bug fixed by `buildDateKeyExpr` would group rows wrong on MariaDB without `'%H'`).

## 2. Workflow-operations migration coverage on MariaDB (moved from `workflow-operations`)

- [x] 2.1 Run every migration under `lib/Migration/` against a fresh MariaDB instance via `docker-compose.mariadb-test.yml up && occ upgrade`; assert no errors.
- [x] 2.2 Confirm the workflow-execution / scheduled-workflow / approval-chain / approval-step tables created by Phase 1 migrations (`Version1Date2026032*`) materialise on MariaDB with the same column types as PostgreSQL.
- [x] 2.3 Insert one row into each table via the API; confirm read-back round-trips on MariaDB.

## 3. Verification + archival

- [x] 3.1 Re-tick the source-spec items in `fix-date-histogram-mariadb` (1.4 / 1.5 / 3.1–3.6 / 3.9) once the suite passes.
- [x] 3.2 Re-tick `workflow-operations` line 278 once 2.1–2.3 are green.
- [x] 3.3 Cross-link this change in `mariadb-ci-matrix` canonical spec under "Cross-References" so future readers see where the verification lives.
- [x] 3.4 Run `openspec validate mariadb-integration-verification --type change`.
- [x] 3.5 PR + merge + `openspec archive mariadb-integration-verification`.
