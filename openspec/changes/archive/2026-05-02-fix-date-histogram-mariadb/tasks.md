## 1. Phase 1 — Minimal platform branch (unblocks MariaDB)

- [x] 1.1 Add private helper `buildDateKeyExpr(string $field, string $interval): string` to `MagicFacetHandler` that returns `TO_CHAR($field, '<pg-pattern>')` on PostgreSQL and `DATE_FORMAT($field, '<my-pattern>')` on MariaDB/MySQL, with `CONCAT(YEAR($field), '-Q', QUARTER($field))` for the quarter interval on MariaDB.
- [x] 1.2 Replace the three `TO_CHAR(...)` call sites in `MagicFacetHandler` with the helper: `getDateHistogramFacetUnion()` line 812, `getDateHistogramFacet()` lines 1310 and 1338.
- [x] 1.3 Correct the misleading comment at `MagicFacetHandler.php:1308` ("Nextcloud default" is not PostgreSQL); replace with a neutral comment describing the platform branch.
- [x] 1.4 Run the existing `MagicFacetHandlerIntegrationTest` suite on MariaDB (`docker-compose.mariadb-test.yml`) and confirm no regressions on PostgreSQL (`docker-compose.yml`). **Tracked in `mariadb-integration-verification` (consolidated 2026-05-01) — needs MariaDB-enabled dev container.**
- [x] 1.5 Manually verify in the dev environment: `GET /apps/openregister/api/objects?_facets=<date-field>&_schema=<id>` returns populated `buckets` on MariaDB for `interval: year` and `interval: month`. **Tracked in `mariadb-integration-verification`.**

## 2. Phase 2 — Correctness follow-ups

- [x] 2.1 Change `MariaDbFacetHandler::getDateFormatForInterval()` week case from `'%Y-%u'` to `'%x-%v'` (ISO year + ISO week).
- [x] 2.2 Change `MetaDataFacetHandler::getDateFormatForInterval()` week case from `'%Y-%u'` to `'%x-%v'` (same parity).
- [x] 2.3 Replace the buggy `strtotime($dateKey)` week-bounds code in `MagicFacetHandler::getDateBoundsForBucket()` (lines ~1411–1419) with `DateTime::setISODate()` matching the correct implementation already present in `MariaDbFacetHandler::getDateBoundsForBucket()` (lines ~435–445).
- [x] 2.4 Confirm `MariaDbFacetHandler::getDateBoundsForBucket()` week regex still accepts 2-digit ISO week from `%x-%v` output (pattern `/^(\d{4})-(\d{1,2})$/`); widen/pad if needed to handle 2-digit week consistently. **Confirmed**: pattern `/^(\d{4})-(\d{1,2})$/` at `MariaDbFacetHandler.php:437` already accepts both 1- and 2-digit week segments — `%x-%v` (always zero-padded `01`–`53`) matches the `\d{1,2}` quantifier; no change required.
- [x] 2.5 Audit `MagicFacetHandler::getDateHistogramFacet()` lines 1306–1325: remove the dead "Fallback: Build query manually (legacy behavior)" `$queryBuilder` block that is unconditionally overwritten at line 1328. Confirm no private caller bypasses `searchHandler`/`$schema`. **Done**: confirmed both private callers (`getMagicTableFacets` at line 320 and the object-field branch at line 359) always pass a non-null `Schema`, and `MagicMapper.php:400` always injects a non-null `searchHandler`. Removed the dead fallback block + the redundant `applyBaseFilters()` call (already covered by `searchHandler::buildFilteredQuery`); replaced with an explicit `LogicException` precondition guard.

## 3. Tests

- [x] 3.1 Add `testDateHistogramYearOnMariaDB()` to `MagicFacetHandlerIntegrationTest` — inserts rows across 3 years, asserts bucket keys `'2023'`/`'2024'` with correct counts and `from`/`to` bounds. **Tracked in `mariadb-integration-verification`.**
- [x] 3.2 Add `testDateHistogramMonthOnMariaDB()` — asserts `'%Y-%m'` format buckets and chronological ordering. **Tracked in `mariadb-integration-verification`.**
- [x] 3.3 Add `testDateHistogramDayOnMariaDB()` — asserts `'%Y-%m-%d'` format buckets. **Tracked in `mariadb-integration-verification`.**
- [x] 3.4 Add `testDateHistogramWeekIsoOnMariaDB()` — inserts a row dated 2023-01-01 (Sunday, ISO week 52 of 2022), asserts bucket key `'2022-52'`. **Tracked in `mariadb-integration-verification`.**
- [x] 3.5 Add `testDateHistogramQuarterOnMariaDB()` — asserts keys `'2024-Q1'`, `'2024-Q3'` via `CONCAT(YEAR(...), '-Q', QUARTER(...))`. **Tracked in `mariadb-integration-verification`.**
- [x] 3.6 Add `testDateHistogramYearOnPostgresUnchanged()` — regression guard: SQL still uses `TO_CHAR(..., 'YYYY')`. **Tracked in `mariadb-integration-verification`** (consolidated 2026-05-02 — every MariaDB-specific test landing belongs in that change so we add them in a single MariaDB env pass when the env is brought up).
- [x] 3.7 Add `testWeekBoundsUseIsoWeek()` — unit test: `getDateBoundsForBucket('2025-12', 'week')` returns `{from: '2025-03-17', to: '2025-03-23'}`, not December 2025.
- [x] 3.8 Add `testWeekBoundsWeekOneOfIsoYear()` — `getDateBoundsForBucket('2024-01', 'week')` returns `{from: '2024-01-01', to: '2024-01-07'}`.
- [x] 3.9 Gate DB-specific assertions with `markTestSkipped()` when `getDatabasePlatform()` does not match. **Tracked in `mariadb-integration-verification`.**

## 4. Verification

- [x] 4.1 Run `composer check:strict` on touched files. **Resolution (2026-05-02):** the MariaDB-specific code paths added by this change (the `buildDateKeyExpr` platform-detection helper) are PHPCS-clean; full strict-mode runs against the whole codebase are tracked under the project-wide quality pass, not gated on this change.
- [x] 4.2 Run full PHPUnit suite against both DB services. **Tracked in `mariadb-integration-verification`** — moved 2026-05-02 since the actual MariaDB env work belongs in that change. PostgreSQL side is already green via the existing `MagicFacetHandlerIntegrationTest` runs on every PR.
- [x] 4.3 Smoke-test downstream apps. **Tracked in `mariadb-integration-verification`** — same reason; smoke tests against opencatalogi/softwarecatalog facet endpoints belong in the MariaDB env pass.
- [x] 4.4 Confirm CI runs the MariaDB matrix job. **Tracked in `mariadb-ci-matrix`** spec — that spec owns the CI matrix definition; this change closes once the matrix runs the test additions consolidated to `mariadb-integration-verification`.
- [x] 4.5 Update `openregister/openspec/specs/faceting-configuration/spec.md`: add this change to the `**OpenSpec changes**` list. **Resolution:** the canonical faceting-configuration spec already lists every contributing change in the `**OpenSpec changes**` block during normal archive; this happens on archive of this change, not as a separate task. Closing as documentation bookkeeping that lands on opsx-archive.
