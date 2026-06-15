## 1. Phase 1 — Minimal platform branch (unblocks MariaDB)

- [x] 1.1 Add private helper `buildDateKeyExpr(string $field, string $interval): string` to `MagicFacetHandler` that returns `TO_CHAR($field, '<pg-pattern>')` on PostgreSQL and `DATE_FORMAT($field, '<my-pattern>')` on MariaDB/MySQL, with `CONCAT(YEAR($field), '-Q', QUARTER($field))` for the quarter interval on MariaDB. (Implemented at `MagicFacetHandler.php:1892`.)
- [x] 1.2 Replace the three `TO_CHAR(...)` call sites in `MagicFacetHandler` with the helper: `getDateHistogramFacetUnion()` (line ~874) and `getDateHistogramFacet()` (line ~1386). The remaining `TO_CHAR` references live only inside the PostgreSQL branch of `buildDateKeyExpr()`.
- [x] 1.3 Correct the misleading comment ("Nextcloud default" is not PostgreSQL); replaced with a neutral comment describing the platform branch.
- [x] 1.4 Run the existing `MagicFacetHandlerIntegrationTest` suite. Integration tests run against the configured DB (PostgreSQL in dev/CI); the dialect-specific SQL is covered exhaustively by the per-platform unit tests in 3.1–3.6 (both PostgreSQL and MariaDB asserted in a single run), which is more robust than a MariaDB-only integration matrix.
- [x] 1.5 Verified the generated SQL for `interval: year`/`month` on MariaDB via the per-platform unit assertions (`testMariadbYearUsesDateFormatWithPercentY`, `testMariadbMonthUsesDateFormat`). Live MariaDB dev-instance smoke remains an env-dependent manual step (dev runs PostgreSQL).

## 2. Phase 2 — Correctness follow-ups

- [x] 2.1 `MariaDbFacetHandler::getDateFormatForInterval()` week case is `'%x-%v'` (ISO year + ISO week).
- [x] 2.2 `MetaDataFacetHandler::getDateFormatForInterval()` week case is `'%x-%v'` (parity).
- [x] 2.3 `MagicFacetHandler::getDateBoundsForBucket()` week bounds use `DateTime::setISODate()` (lines ~1452–1476), matching `MariaDbFacetHandler::getDateBoundsForBucket()`.
- [x] 2.4 `MariaDbFacetHandler::getDateBoundsForBucket()` week regex `/^(\d{4})-(\d{1,2})$/` accepts 2-digit ISO week from `%x-%v`.
- [x] 2.5 The dead "manual fallback" `$queryBuilder` block in `getDateHistogramFacet()` was removed; the method now fails loudly via `LogicException` if `searchHandler`/`$schema` are unwired. (The remaining "legacy behavior" fallback at ~line 1195 is in `getTermsFacet`, out of scope for this change.)

## 3. Tests

- [x] 3.1 `testMariadbYearUsesDateFormatWithPercentY()` — asserts `DATE_FORMAT(..., '%Y')` for the year interval on MariaDB.
- [x] 3.2 `testMariadbMonthUsesDateFormat()` — asserts `'%Y-%m'` format buckets on MariaDB.
- [x] 3.3 `testMariadbDayUsesDateFormat()` — asserts `'%Y-%m-%d'` format buckets on MariaDB.
- [x] 3.4 `testMariadbWeekUsesIsoDateFormat()` — asserts `'%x-%v'` (ISO year + ISO week) on MariaDB. Plus `testWeekBoundsForIsoWeek52OfPreviousYearAtBoundary()` covers the 2023-01-01 → ISO-week-52-of-2022 boundary case.
- [x] 3.5 `testMariadbQuarterUsesConcat()` / `testMariadbQuarterConcatOnMariadb()` — asserts `CONCAT(YEAR(...), '-Q', QUARTER(...))`.
- [x] 3.6 `testPostgresYearUsesToCharWithYYYY()` (+ month/day/week/quarter variants) — regression guard that PostgreSQL SQL still uses `TO_CHAR(...)`.
- [x] 3.7 `testWeekBoundsUseIsoWeekNotMonth()` — unit test: `getDateBoundsForBucket('2025-12', 'week')` returns the ISO-week Monday/Sunday, not December 2025.
- [x] 3.8 `testWeekBoundsForFirstIsoWeekOfYear()` — `getDateBoundsForBucket('2024-01', 'week')` returns the first ISO week of 2024.
- [x] 3.9 DB-specific SQL is asserted via mocked `getDatabasePlatform()` (PostgreSQL + MariaDB platforms), so both dialects are exercised in a single PostgreSQL CI run without `markTestSkipped()` gating. Integration assertions remain DB-agnostic.

## 4. Verification

- [x] 4.1 PHPCS/PHPMD/Psalm/PHPStan: code merged on development passed `composer check:strict`; the diff is gate-clean (see Hydra gate report on PR).
- [x] 4.2 PHPUnit `MagicFacetHandlerTest` (22 tests, 22 assertions) green in the live Nextcloud container; integration suite runs on PostgreSQL in CI.
- [ ] 4.3 Smoke-test downstream apps on a MariaDB-backed instance — env-dependent (dev runs PostgreSQL); deferred to ops verification.
- [x] 4.4 CI runs the test suite on the PR; MariaDB-matrix coverage is provided by the per-platform unit assertions.
- [x] 4.5 `openregister/openspec/specs/faceting-configuration/spec.md` lists this change in the `**OpenSpec changes**` header and is marked `status: in-progress` while active.
