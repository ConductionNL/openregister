# MariaDB integration verification

## Why

Three open openspec changes carry tasks that are individually correct but collectively
blocked on the same missing infrastructure: a MariaDB-enabled dev container that lets us
exercise the database-specific code paths added by `mariadb-ci-matrix` (already canonical at
`openspec/specs/mariadb-ci-matrix/spec.md`).

Today's audit (`docs/development-notes/AUDIT_2026-05-01.md`) and the parallel-agent triage
rounds repeatedly bumped against the same three blocked clusters:

- `fix-date-histogram-mariadb` — Phase 1 helper `buildDateKeyExpr()` is in production and the
  `'%Y-%u'` literals were swapped to `'%x-%v'` in `MariaDbFacetHandler` + `MetaDataFacetHandler`,
  but 14 of 24 tasks need `MagicFacetHandlerIntegrationTest` to actually run on a MariaDB
  container.
- `workflow-operations` — line 278 ("All database migrations run without errors on both
  PostgreSQL and MariaDB") is the only DB-related item left; the migration code is type-portable
  but live-verified only on the active dev DB.
- `aggregations-backend-native` task set (Solr + Elasticsearch backends) is blocked on the same
  shape of "no dev container with the integration target available" — but on search backends, not
  databases. **Out of scope for this change** (different infrastructure family).

Consolidating the two database-blocked clusters into one change scoped explicitly to "MariaDB
integration coverage on the existing implementation" gives the work one place to live, one PR to
land when the dev container is available, and removes the two false-blocker items from the source
specs so they can archive when their non-DB tasks complete.

## What Changes

- New change: `mariadb-integration-verification`. Tracks the dependent verification work that
  reuses the implementation already shipped under `mariadb-ci-matrix` + `fix-date-histogram-mariadb`
  + `workflow-operations`. No new code paths — only test coverage and verification gates.
- Moves to this change:
  - `fix-date-histogram-mariadb` 1.4 (run `MagicFacetHandlerIntegrationTest` on MariaDB)
  - `fix-date-histogram-mariadb` 1.5 (manual `?_facets=...` verification on MariaDB)
  - `fix-date-histogram-mariadb` 3.1–3.4 (year / month / day / week ISO bucket tests on MariaDB)
  - `fix-date-histogram-mariadb` 3.5–3.6 (quarter / hour bucket tests on MariaDB)
  - `fix-date-histogram-mariadb` 3.9 (hour-bucket regression test on MariaDB)
  - `workflow-operations` line 278 (dual-DB migration smoke)
- Source specs tick the moved items as "Tracked in `mariadb-integration-verification`" so
  their counters reflect that the in-repo work is done.

The consolidated change ships ONE deliverable: a CI-runnable MariaDB suite that exercises every
date-histogram bucket type + the workflow-operations migrations + asserts no regressions on
PostgreSQL. When the dev-container side of `mariadb-ci-matrix` is plumbed end-to-end, this change
runs the suite and archives.
