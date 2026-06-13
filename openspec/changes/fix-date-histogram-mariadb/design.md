## Context

`MagicFacetHandler` (`openregister/lib/Db/MagicMapper/MagicFacetHandler.php`) computes `date_histogram` facets against schema-backed "magic" tables where date properties are stored in typed `datetime` columns (per `MagicMapper::mapStringProperty()` at line 2304–2312, which maps JSON schema `format: date|date-time` to a SQL `DATETIME`/`TIMESTAMP` column).

The current implementation uses PostgreSQL's `TO_CHAR()` unconditionally at three call sites:

```
getDateHistogramFacetUnion()  line  812   "TO_CHAR({$field}, '{$dateFormat}')"
getDateHistogramFacet()       line 1310   "TO_CHAR($field, '$dateFormat')"
                              line 1338   "TO_CHAR(t.{$field}, '{$dateFormat}')"
```

`getDateFormatForInterval()` returns PostgreSQL patterns: `YYYY-MM-DD`, `IYYY-IW`, `YYYY-MM`, `YYYY`, `YYYY-"Q"Q`.

**Impact by database:**

| Platform        | Behavior                                                              |
|-----------------|-----------------------------------------------------------------------|
| PostgreSQL      | Works correctly (the format patterns are native).                     |
| MariaDB < 10.6  | No `TO_CHAR()` function — SQL error caught by the handler, returns `{buckets: []}`. |
| MariaDB ≥ 10.6  | Oracle-compatible `TO_CHAR` understands `YYYY`, `MM`, `DD`; year/month/day sort-of work. `IYYY-IW` is not supported (no ISO output), and `YYYY-"Q"Q` (quoted literals) is not a valid MariaDB TO_CHAR pattern — week and quarter return malformed keys or errors. |

The sibling `MariaDbFacetHandler` (legacy JSON-blob path on `openregister_objects`) uses `DATE_FORMAT()` correctly and is the reference for the MariaDB/MySQL code path.

The existing `mariadb-ci-matrix` spec (requirement: *"Database-aware branching for all Magic* handlers\"*) already requires dual-DB code paths in `MagicFacetHandler`; the terms-facet path complies (lines 567–571) but the date-histogram path does not.

**Stakeholders:** every app consuming `_facets=<date-field>` on MariaDB-hosted Nextcloud installs — primarily OpenRegister itself, opencatalogi, and softwarecatalog which all expose year-based timeline filters.

## Goals / Non-Goals

**Goals:**
- `date_histogram` facets MUST return correct, populated buckets on MariaDB for all five intervals (`day`, `week`, `month`, `quarter`, `year`).
- Bucket keys MUST be identical across PostgreSQL and MariaDB for the same input data (cross-DB parity).
- Weekly buckets MUST use ISO 8601 year + ISO 8601 week numbering on both databases, and weekly `from`/`to` bounds MUST be ISO-aligned.
- PostgreSQL behavior MUST remain unchanged (no regressions for the majority platform where this currently works).
- Single source of truth for the date-key SQL expression across the three MagicFacetHandler call sites — no duplicated inline branching.

**Non-Goals:**
- No changes to the Solr facet path (`SolrFacetProcessor`) — already backend-agnostic at the API level.
- No changes to the facet configuration API, response format, or caching layer.
- No optimization of JSON-blob faceting (`MariaDbFacetHandler::getDateHistogramFacet()` on `openregister_objects.object`). That path already works; only its week format changes for cross-DB parity.
- No introduction of new histogram intervals (hour, minute, etc.) — those are a separate enhancement.
- Not refactoring the three separate `getDateFormatForInterval()` implementations into a shared helper class — deferred; would expand blast radius.

## Decisions

### Decision 1: One platform-branching helper on `MagicFacetHandler`, not an injected utility

Add a private method `buildDateKeyExpr(string $field, string $interval): string` on `MagicFacetHandler` that returns the full SQL expression (including the `TO_CHAR(...)` or `DATE_FORMAT(...)` wrapper) rather than returning just a format string.

**Rationale:** Returning the full expression centralizes the *entire* platform split — including the quarter special case (`CONCAT(YEAR(), '-Q', QUARTER())` on MariaDB) — in one place. Returning just a format string would force each call site to also know which function to wrap it with.

**Alternatives considered:**
- *Extract to `lib/Db/Platform/` utility class used by all three handlers.* Rejected for this change — cleaner, but widens blast radius and invites coordination with `MariaDbFacetHandler`/`MetaDataFacetHandler` refactors. Captured as an Open Question.
- *Branch inline at each call site.* Rejected — three places to get wrong, violates ADR-011 (deduplication).

### Decision 2: Keep PostgreSQL as the unchanged default

The platform check uses positive detection of PostgreSQL; MariaDB/MySQL is the "else" branch. No existing PG call sites change format strings.

**Rationale:** Pattern already used in this file (lines 569, 1769); minimizes diff; PG users see zero change.

### Decision 3: Use `%x-%v` (ISO) for week format on MariaDB, not `%Y-%u`

`MariaDbFacetHandler::getDateFormatForInterval()` currently returns `%Y-%u` for `week`:
- `%Y` = 4-digit year from the date itself (not ISO year)
- `%u` = week number, Monday-starting, but year boundaries don't align with ISO

The PostgreSQL `IYYY-IW` produces ISO year + ISO week. These disagree around year boundaries (e.g., Jan 1 2023 is ISO week 52 of 2022). Cross-DB parity requires ISO on both: MariaDB `%x-%v` (ISO year + ISO week).

**Rationale:** The spec requirement (see `specs/faceting-configuration/spec.md`) names ISO 8601 as the contract. Callers rendering a timeline expect stable keys across databases.

**Alternative considered:** Switch PostgreSQL to non-ISO and match existing MariaDB. Rejected — PG's ISO behavior has been in production longer; the MariaDB path is the broken one to bring into line. Also, ISO is the documented week convention for data exchange.

**Breaking-change note:** MariaDB consumers already using `%Y-%u` keys will see different keys around Jan 1 of some years (e.g., `2024-00` or `2024-01` may become `2023-52`). Since the broader MariaDB path was non-functional for most configurations, real-world impact is expected to be zero — but the change is flagged in the proposal for transparency.

### Decision 4: Fix week-bounds alongside key alignment

`MagicFacetHandler::getDateBoundsForBucket()` week branch (lines 1411–1419) calls `strtotime($dateKey)` on a string like `"2025-12"`. PHP parses that as *December 2025*, not ISO week 12. Port the already-correct implementation from `MariaDbFacetHandler::getDateBoundsForBucket()` which uses `DateTime::setISODate()`.

**Rationale:** The bounds bug is independent of the platform-branch bug but hits the same code paths; fixing both at once keeps reviewers focused on one area and avoids stacking change boundaries. Small diff.

### Decision 5: Remove the dead "legacy" QueryBuilder block

Lines 1306–1325 of `MagicFacetHandler::getDateHistogramFacet()` build a `QueryBuilder` that is unconditionally overwritten at line 1328 when `$this->searchHandler !== null && $schema !== null` — which is always true in the runtime path. The comment at line 1305 mislabels it "Fallback: Build query manually (legacy behavior)" but there is no branch that would ever execute it.

**Rationale:** Dead code pretending to be a fallback is worse than no fallback — reviewers must reason about a branch that never runs. Remove it.

**Risk:** If a caller invokes `getDateHistogramFacet()` without a `searchHandler` or without a `$schema`, the method currently *appears* to work (via the dead block). Check with static analysis before removing:
- `searchHandler` is `private readonly` and assigned from constructor; constructor sets it from the same `MagicMapper` used everywhere. Never null in production.
- `$schema` parameter is nullable; grep all callers. The method is private — single file to audit.

### Decision 6: Align `MariaDbFacetHandler` and `MetaDataFacetHandler` week format for parity

Changing only `MagicFacetHandler` leaves two other code paths producing `%Y-%u`. For the cross-DB parity requirement to hold end-to-end, update both other handlers to `%x-%v` as well, and ensure their `getDateBoundsForBucket()` uses `setISODate()` (already the case in `MariaDbFacetHandler`).

**Risk:** `MariaDbFacetHandler` is the path used by the legacy `openregister_objects` JSON-blob table. There may be existing clients consuming the old `%Y-%u` keys. Deemed low-risk because:
1. Both paths already produce slightly wrong bounds (same December-2025 bug); the output has not been heavily relied upon.
2. Changing both in the same PR ensures consistent behavior.
3. Flagged in proposal's **Impact** as a non-breaking-but-observable change.

## Risks / Trade-offs

- **Risk:** MariaDB consumers who persisted `date_histogram` bucket keys (e.g., cached UI state) will see them change around year boundaries. → **Mitigation:** The facet API is not guaranteed to be cache-stable across releases; facets are server-rendered per-request. Flagged in proposal's `## Impact` section.
- **Risk:** ``%x-%v`` is MariaDB/MySQL 5.x+; all supported Nextcloud MariaDB versions include it → **Mitigation:** The `mariadb-ci-matrix` spec pins MariaDB 10.11 minimum; `%x-%v` has been present since MySQL 4.1.1. No version gate needed.
- **Risk:** Removing the dead fallback block breaks a non-production test that bypasses `searchHandler`. → **Mitigation:** Run the full test suite on both DBs before merging; static-analyze all call sites.
- **Trade-off:** Centralizing the helper only within `MagicFacetHandler` (not across all three facet handlers) means the `MariaDbFacetHandler` and `MetaDataFacetHandler` still have their own `getDateFormatForInterval()` methods. This is accepted for scope control — the future Open Question captures the unification.
- **Trade-off:** Adding the ISO-alignment requirement for MariaDB changes observable output. We explicitly choose correctness and cross-DB parity over preserving an arguably-wrong existing behavior.

## Migration Plan

No runtime migration needed — this is a stateless query-path fix. Deploy is a simple code update.

**Rollback strategy:** Standard git revert. The change touches three files with additive helper methods and small call-site replacements; revertable in isolation. Cached facet responses from before the change will expire within the handler's existing TTL (1 hour, `FACET_CACHE_TTL`).

**Deployment order:**
1. Merge code changes.
2. Let existing facet cache entries expire naturally (max 1 hour) or flush via `occ cache:clear` for immediate effect.

## Test Strategy

Add to `openregister/tests/Db/MagicFacetHandlerIntegrationTest.php`:

| Test case | Interval | Assertions |
|-----------|----------|------------|
| `testDateHistogramYearOnMariaDB`   | year    | SQL contains `DATE_FORMAT(..., '%Y')`; buckets grouped by year |
| `testDateHistogramMonthOnMariaDB`  | month   | SQL uses `'%Y-%m'`; buckets sorted ASC |
| `testDateHistogramDayOnMariaDB`    | day     | SQL uses `'%Y-%m-%d'` |
| `testDateHistogramWeekIsoOnMariaDB`| week    | SQL uses `'%x-%v'`; Jan 1 2023 row lands in `'2022-52'` bucket |
| `testDateHistogramQuarterOnMariaDB`| quarter | SQL uses `CONCAT(YEAR(...), '-Q', QUARTER(...))`; bucket `'2024-Q1'` exists |
| `testDateHistogramYearOnPostgres`  | year    | Regression guard: unchanged `TO_CHAR(..., 'YYYY')` on PG |
| `testWeekBoundsUseIsoWeek`         | —       | `getDateBoundsForBucket('2025-12', 'week')` returns `2025-03-17` / `2025-03-23`, not December 2025 |

Tests MUST run on both CI matrix lines per `mariadb-ci-matrix` spec. Platform-specific tests use `markTestSkipped()` when the active DB does not match.

## Open Questions

1. **Should `MagicFacetHandler`, `MariaDbFacetHandler`, and `MetaDataFacetHandler` eventually share a single `FacetDateFormatter` utility class?** Three nearly-identical `getDateFormatForInterval()` implementations exist. Unifying would reduce drift risk but expands scope. **Provisional answer:** Defer to a follow-up change; this fix keeps the existing three-copy structure but aligns their outputs.

2. **Do downstream apps (opencatalogi, softwarecatalog) hard-code any expected bucket-key format?** If so, they would need coordination. **Provisional answer:** Grep suggests they consume the normalized `FacetHandler.transformFacetsToStandardFormat()` output and don't parse keys themselves, but a follow-up pass against dependent repos during apply phase is warranted.

3. **Should `%Y-%u` remain supported via a deprecated flag for one release?** Avoids the observable-change risk in §Risks. **Provisional answer:** No — the existing MariaDB path is almost entirely non-functional in practice, so there is no real installed base consuming `%Y-%u` keys reliably. Clean break.
