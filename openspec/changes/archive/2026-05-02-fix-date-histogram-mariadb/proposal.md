## Why

`date_histogram` facets on schema-backed ("magic") tables return wrong or empty buckets on MariaDB/MySQL because `MagicFacetHandler` calls `TO_CHAR()` with PostgreSQL-specific format patterns (`YYYY`, `IYYY-IW`, `YYYY-"Q"Q`) unconditionally. Nextcloud's default database is MariaDB, so this path is silently broken in most installations — `_facets=<date-field>` requests return empty `buckets` on MariaDB < 10.6 (no `TO_CHAR`) and wrong keys for `week`/`quarter` on MariaDB ≥ 10.6. The existing `mariadb-ci-matrix` spec already requires dual-DB code paths in `MagicFacetHandler`; this change brings the date-histogram path into compliance.

## What Changes

**Phase 1 — minimal fix (unblocks year/month/day/quarter on MariaDB)**

- Branch on `$this->db->getDatabasePlatform() instanceof PostgreSQLPlatform` in `MagicFacetHandler` when building the date-key SQL expression.
- On MariaDB/MySQL: use `DATE_FORMAT($field, '<pattern>')` with native patterns (`%Y`, `%Y-%m`, `%Y-%m-%d`, `%x-%v`), and `CONCAT(YEAR($field), '-Q', QUARTER($field))` for quarter.
- On PostgreSQL: keep existing `TO_CHAR($field, '<pattern>')` behavior.
- Introduce a private helper `buildDateKeyExpr(string $field, string $interval): string` used at all three call sites (two in `getDateHistogramFacet()`, one in `getDateHistogramFacetUnion()`).
- Fix comment at line 1308 that incorrectly claims "Nextcloud default" is PostgreSQL.

**Phase 2 — correctness follow-ups**

- Align weekly bucket keys across DBs: use ISO year + ISO week on both (`IYYY-IW` on Postgres, `%x-%v` on MariaDB). Update `MariaDbFacetHandler::getDateHistogramFacet()` from `%Y-%u` to `%x-%v` for parity.
- Fix `MagicFacetHandler::getDateBoundsForBucket()` week branch: it currently calls `strtotime('2025-12')` which parses as *December 2025*, not ISO week 12. Port the `setISODate()` logic from `MariaDbFacetHandler`.
- Remove the dead "legacy" `QueryBuilder` block at lines 1306–1325 of `MagicFacetHandler` (unconditionally overwritten at line 1328 when `searchHandler` and `schema` are non-null — the normal path).

**Tests**

- Add integration tests to `MagicFacetHandlerIntegrationTest` covering year/month/day/week/quarter intervals over a `datetime`-typed column. Run suite on both PostgreSQL and MariaDB per `mariadb-ci-matrix`.

## Capabilities

### New Capabilities
None — no new capability introduced.

### Modified Capabilities
- `faceting-configuration`: Add explicit cross-DB correctness requirements for `date_histogram` facets (currently the spec only mentions "Backend-agnostic faceting across PostgreSQL and Solr" without specifying bucket-key format or interval coverage on MariaDB). Add requirements for ISO-week alignment and correct week-bucket `from`/`to` bounds.

## Impact

**Code:**
- `openregister/lib/Db/MagicMapper/MagicFacetHandler.php` — new helper, three call-site replacements, week-bounds fix, dead-code removal.
- `openregister/lib/Db/ObjectHandlers/MariaDbFacetHandler.php` — week format change `%Y-%u` → `%x-%v`, matching week-bounds helper uses `setISODate()`.
- `openregister/lib/Db/ObjectHandlers/MetaDataFacetHandler.php` — same week format alignment (uses identical `getDateFormatForInterval` pattern).

**Tests:**
- `openregister/tests/Db/MagicFacetHandlerIntegrationTest.php` — add date-histogram scenarios per interval.

**APIs:**
- Public behavior change on MariaDB only: `_facets=<date-field>` requests that currently return `{ buckets: [] }` will start returning populated buckets. Not a breaking change — callers already handle empty buckets.
- Week-bucket `key` format changes on MariaDB (from `%Y-%u` to `%x-%v`). **BREAKING** for any MariaDB consumer that already relied on the `%Y-%u` output — unlikely given the broader path was non-functional, but flagged here. PostgreSQL consumers unaffected (already `IYYY-IW`).

**Dependencies:**
- No new runtime dependencies.
- CI matrix (`mariadb-ci-matrix`) already covers both DBs — new tests piggyback on existing jobs.

**Dependent apps:**
- `opencatalogi`, `softwarecatalog`, and any app consuming the faceting API via `ObjectService::getSimpleFacets()` benefit transparently. No caller-side changes required.
