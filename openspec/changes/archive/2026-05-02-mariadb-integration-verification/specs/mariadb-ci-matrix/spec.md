# MariaDB integration verification — delta on `mariadb-ci-matrix`

This delta extends the existing canonical `mariadb-ci-matrix` capability with the
verification suite that was previously scattered across three open changes
(`fix-date-histogram-mariadb`, `workflow-operations`, parts of `aggregations-backend-native`).
The implementation is already shipped under those changes; this delta names the
verification surface so it has one place to live + one PR to land when the
MariaDB-enabled dev container is plumbed in.

## ADDED Requirements

### Requirement: Date-histogram bucket coverage on MariaDB

The `MagicFacetHandlerIntegrationTest` suite MUST run on a MariaDB-enabled dev container with zero modifications and zero failures, and an additional set of MariaDB-specific date-histogram bucket tests MUST pass covering every interval the spec promises (year / month / day / week-ISO / quarter / hour) with explicit ISO 8601 week correctness at year boundaries — the regression that motivated the `'%Y-%u'` → `'%x-%v'` swap in `MariaDbFacetHandler`.

#### Scenario: ISO 8601 week buckets correct at year boundary on MariaDB

- **GIVEN** the MariaDB dev container is running
- **AND** an `events` schema with rows on `2024-12-30` (ISO week 2025-W01) and `2025-01-06` (ISO week 2025-W02)
- **WHEN** `GET /api/objects/events/all?_facets=startedAt&_facets[startedAt][interval]=week` is called
- **THEN** the response MUST include bucket keys `'2025-01'` and `'2025-02'` (not `'2024-52'` and `'2025-00'` — the old `'%Y-%u'` bug)
- **AND** counts per bucket MUST match the PostgreSQL output for the same dataset

#### Scenario: Year / month / day / quarter / hour buckets

- **GIVEN** rows spanning 3 calendar years with monthly density
- **WHEN** `interval: year` / `month` / `day` / `quarter` / `hour` are exercised in turn
- **THEN** bucket keys MUST match the format the spec promises (`'2024'` / `'2024-03'` / `'2024-03-15'` / `'2024-Q1'` / `'2024-03-15T14'`)
- **AND** the count + `from` / `to` per bucket MUST match the PostgreSQL output for the same dataset

#### Scenario: No regression on PostgreSQL when running the new MariaDB tests

- **GIVEN** the new MariaDB-flavoured tests
- **WHEN** the suite runs on the PostgreSQL job (matrix entry 1)
- **THEN** every test SHALL still pass (the helpers are platform-portable, not MariaDB-only)
- **AND** zero new test failures introduced

### Requirement: Workflow-operations migrations apply cleanly on MariaDB

Every `workflow-operations` Phase 1 migration (`Version1Date2026032*` family) MUST apply on a fresh MariaDB 10.11 container without errors and SHALL produce the same table column types as the PostgreSQL run for `oc_openregister_workflow_executions` / `oc_openregister_scheduled_workflows` / `oc_openregister_approval_chains` / `oc_openregister_approval_steps`. The verification MUST also confirm a representative round-trip (insert one row in each table via the existing API; read-back returns the same row) on the MariaDB instance. This re-uses the Doctrine-portable types declared in those migrations — no schema change is in scope; only verification.

#### Scenario: Migration sweep on a fresh MariaDB instance

- **GIVEN** a fresh MariaDB 10.11 container (per `docker-compose.mariadb-test.yml`)
- **WHEN** `occ upgrade` runs in the openregister-test image with the matrix MariaDB job
- **THEN** every migration listed under `lib/Migration/Version1Date2026032*.php` SHALL exit 0
- **AND** `SHOW CREATE TABLE oc_openregister_workflow_executions` SHALL contain the expected
  Doctrine-derived column types
- **AND** the same SHALL hold for `oc_openregister_scheduled_workflows` /
  `oc_openregister_approval_chains` / `oc_openregister_approval_steps`
