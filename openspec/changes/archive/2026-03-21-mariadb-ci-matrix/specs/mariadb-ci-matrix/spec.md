---
status: implemented
---

# MariaDB Support & Dual-Database CI Matrix

## Purpose

OpenRegister SHALL be fully tested on both PostgreSQL and MariaDB through a cost-efficient 2-line CI matrix that piggybacks the database difference onto the PHP version split, ensuring that database-specific code paths (JSONB vs JSON, GIN indexes vs B-tree, pg_trgm vs LIKE, PostgreSQL containment operators vs JSON_CONTAINS) are exercised in CI rather than only discovered in production. Blob storage (Normal mode) is removed — only MagicMapper (dedicated SQL tables per schema) is supported.

## Current State

- CI runs Newman integration tests against **PostgreSQL only** (single job)
- PHPUnit runs on PHP 8.2 + 8.3 but both use **SQLite** (no real DB testing)
- `MariaDbFacetHandler` and MySQL JSON functions exist in code but are **never tested in CI**
- `run-dual-storage-tests.sh` tests Normal + MagicMapper modes — blob storage (Normal) is being dropped
- All jobs pin to a single Nextcloud version (`stable32`)
- `MagicSearchHandler` uses PostgreSQL-specific syntax (`::jsonb`, `@>`, `jsonb_typeof`, `jsonb_each_text`, `to_jsonb`) without MariaDB/MySQL fallbacks — these will fail on MariaDB until database-aware branching is added
- `MagicFacetHandler` has some MariaDB branches but `MagicSearchHandler` and `MagicBulkHandler` have incomplete coverage

## Requirements

### Requirement: 2-Line CI Matrix Covering Both Databases and Nextcloud Versions

The CI SHALL run exactly **2 parallel integration test jobs**, each combining a unique PHP version, Nextcloud version, and database:

| Job | PHP | Nextcloud | Database | Storage |
|-----|-----|-----------|----------|---------|
| 1 | 8.3 | stable32 (latest) | PostgreSQL 16 | MagicMapper |
| 2 | 8.2 | stable31 (latest - 1) | MariaDB 10.11 | MagicMapper |

#### Scenario: PostgreSQL job (PHP 8.3, Nextcloud stable32)

- **GIVEN** the CI pipeline triggers on push or pull request
- **AND** linting has passed
- **WHEN** integration test job 1 runs
- **THEN** it SHALL use PHP 8.3
- **AND** start a PostgreSQL 16 service container with pg_trgm and pgvector extensions
- **AND** checkout Nextcloud `stable32`
- **AND** install Nextcloud with `--database pgsql`
- **AND** run all Newman collections against the running instance
- **AND** report results independently from job 2

#### Scenario: MariaDB job (PHP 8.2, Nextcloud stable31)

- **GIVEN** the CI pipeline triggers on push or pull request
- **AND** linting has passed
- **WHEN** integration test job 2 runs
- **THEN** it SHALL use PHP 8.2
- **AND** start a MariaDB 10.11 service container with `--transaction-isolation=READ-COMMITTED --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci`
- **AND** checkout Nextcloud `stable31`
- **AND** install Nextcloud with `--database mysql`
- **AND** run the same Newman collections as job 1
- **AND** report results independently from job 1

#### Scenario: Both jobs MUST pass for merge

- **GIVEN** a pull request targeting main or development
- **WHEN** the CI matrix completes
- **THEN** both matrix entries SHALL be required status checks
- **AND** the PR MUST NOT be mergeable unless both PostgreSQL and MariaDB jobs pass
- **AND** `fail-fast` SHALL be set to `false` so both jobs always run to completion

### Requirement: PHPUnit Tests Use the Same Database Matrix

The PHPUnit `php-tests` job SHALL use the same 2-line matrix instead of the current PHP-only matrix with SQLite, ensuring that unit tests exercise the actual database-specific code paths in `MagicMapper`, `MagicSearchHandler`, `MagicBulkHandler`, and `MagicFacetHandler`.

#### Scenario: PHPUnit on PostgreSQL (PHP 8.3, stable32)

- **GIVEN** linting has passed
- **WHEN** PHPUnit job 1 runs
- **THEN** it SHALL use PHP 8.3 with PostgreSQL 16
- **AND** checkout Nextcloud `stable32`
- **AND** install Nextcloud with `--database pgsql`
- **AND** run `phpunit -c phpunit.xml`
- **AND** collect coverage on the PHP 8.3 run (primary coverage job)

#### Scenario: PHPUnit on MariaDB (PHP 8.2, stable31)

- **GIVEN** linting has passed
- **WHEN** PHPUnit job 2 runs
- **THEN** it SHALL use PHP 8.2 with MariaDB 10.11
- **AND** checkout Nextcloud `stable31`
- **AND** install Nextcloud with `--database mysql`
- **AND** run `phpunit -c phpunit.xml`

#### Scenario: Coverage guard applies to PostgreSQL run only

- **GIVEN** both PHPUnit matrix jobs complete
- **WHEN** coverage is evaluated
- **THEN** the coverage guard SHALL apply to the PostgreSQL/PHP 8.3 run only
- **AND** the MariaDB run SHALL report coverage but not block on threshold
- **AND** both runs SHALL upload their coverage artifacts separately

### Requirement: Matrix Strategy Configuration in quality.yml

The `integration-tests` and `php-tests` jobs SHALL use a matrix strategy with explicit `include` entries:

```yaml
strategy:
  matrix:
    include:
      - php-version: '8.3'
        nextcloud-ref: stable32
        database: pgsql
        db-image: postgres:16
        db-port: 5432
        db-health-cmd: pg_isready
        db-user: nextcloud
        db-password: nextcloud
        db-name: nextcloud
        php-extensions: pgsql, pdo_pgsql
      - php-version: '8.2'
        nextcloud-ref: stable31
        database: mysql
        db-image: mariadb:10.11
        db-port: 3306
        db-health-cmd: "mariadb-admin ping -h 127.0.0.1 -u root --password=nextcloud"
        db-user: nextcloud
        db-password: nextcloud
        db-name: nextcloud
        php-extensions: mysql, pdo_mysql
  fail-fast: false
```

#### Scenario: Matrix variables propagate to all steps

- **GIVEN** the matrix strategy is defined with `include` entries
- **WHEN** any step in the job references `${{ matrix.database }}` or `${{ matrix.db-image }}`
- **THEN** the correct value SHALL be substituted for each matrix entry
- **AND** job names SHALL include the matrix label (e.g., "Integration Tests (pgsql/8.3/NC32)")

#### Scenario: Matrix is extensible for future databases

- **GIVEN** the matrix uses `include` entries rather than cross-product
- **WHEN** a new database needs to be added (e.g., MySQL 8.0 for cloud provider compatibility)
- **THEN** a new `include` entry can be appended without changing existing entries
- **AND** the CI cost increases linearly (one additional parallel job)

#### Scenario: PHP extension installation matches database

- **GIVEN** a matrix entry specifies `php-extensions`
- **WHEN** the PHP environment is set up
- **THEN** only the extensions for the selected database SHALL be installed
- **AND** the PostgreSQL job SHALL NOT install `pdo_mysql`
- **AND** the MariaDB job SHALL NOT install `pdo_pgsql`

### Requirement: Dynamic Service Containers

GitHub Actions `services` do not support matrix variables directly. The workflow SHALL use a container start step instead.

#### Scenario: Start database container from matrix

- **GIVEN** a matrix entry with `db-image` and `db-port`
- **WHEN** the job starts
- **THEN** it SHALL run the database image as a Docker container
- **AND** expose the port on `127.0.0.1`
- **AND** wait for the health check to pass before continuing

#### Scenario: PostgreSQL container includes required extensions

- **GIVEN** the PostgreSQL matrix entry
- **WHEN** the container starts
- **THEN** it SHALL load `pg_trgm` via `shared_preload_libraries`
- **AND** pg_trgm SHALL be available for similarity search testing
- **AND** the container SHALL match the `pgvector/pgvector:pg16` image used in `docker-compose.yml`

#### Scenario: MariaDB container matches docker-compose configuration

- **GIVEN** the MariaDB matrix entry
- **WHEN** the container starts
- **THEN** it SHALL use `mariadb:10.11` (LTS version)
- **AND** set `--transaction-isolation=READ-COMMITTED` matching Nextcloud requirements
- **AND** use `utf8mb4` character set and `utf8mb4_unicode_ci` collation
- **AND** match the configuration in `docker-compose.yml` `db-mariadb` service

### Requirement: Parameterized Nextcloud Installation
The CI pipeline MUST parameterize the Nextcloud installation step to use database credentials from the matrix configuration.

#### Scenario: Install Nextcloud with matrix database

- **GIVEN** the matrix provides `database`, `db-user`, `db-password`, `db-name`, and `db-port`
- **WHEN** `php occ maintenance:install` runs
- **THEN** it SHALL use `--database ${{ matrix.database }}`
- **AND** `--database-host 127.0.0.1`
- **AND** the correct port, user, password, and database name from the matrix

#### Scenario: Database connection verified before test execution

- **GIVEN** Nextcloud is installed with the matrix database
- **WHEN** the app is enabled
- **THEN** the install step SHALL verify the database connection succeeds
- **AND** OpenRegister migrations SHALL run without errors on both PostgreSQL and MariaDB
- **AND** any migration that uses database-specific syntax (e.g., `Version1Date20250908180000` with MySQL-specific `ON UPDATE CURRENT_TIMESTAMP`) SHALL handle both platforms

#### Scenario: MagicMapper table creation works on both databases

- **GIVEN** a schema is created via the API
- **WHEN** MagicMapper creates the dynamic table
- **THEN** it SHALL use `BIGSERIAL` for auto-increment on PostgreSQL
- **AND** `AUTO_INCREMENT` on MariaDB
- **AND** `JSONB` column type on PostgreSQL
- **AND** `JSON` column type on MariaDB
- **AND** `TIMESTAMP` for datetime on PostgreSQL
- **AND** `DATETIME` on MariaDB

### Requirement: Database-Specific Query Compatibility in MagicMapper

All database-specific query code in `MagicMapper`, `MagicSearchHandler`, `MagicBulkHandler`, and `MagicFacetHandler` SHALL have working code paths for both PostgreSQL and MariaDB/MySQL. Platform detection MUST use `$this->db->getDatabasePlatform() instanceof PostgreSQLPlatform`.

#### Scenario: JSONB containment operator has MariaDB fallback

- **GIVEN** `MagicSearchHandler::applyJsonArrayFilter()` uses `::jsonb @>` for PostgreSQL
- **WHEN** running on MariaDB
- **THEN** it SHALL use `JSON_CONTAINS(column, value)` instead
- **AND** `COALESCE(column, '[]')::jsonb` SHALL become `COALESCE(column, JSON_ARRAY())`
- **AND** the CI MariaDB job SHALL exercise this code path via array property filters in Newman tests

#### Scenario: Relations containment filter has MariaDB fallback

- **GIVEN** `MagicSearchHandler::applyRelationsContainsFilter()` uses `jsonb_typeof()`, `@>`, `to_jsonb()`, and `jsonb_each_text()`
- **WHEN** running on MariaDB
- **THEN** it SHALL use `JSON_TYPE()`, `JSON_CONTAINS()`, and `JSON_EXTRACT()` equivalents
- **AND** array format: `JSON_CONTAINS(_relations, JSON_QUOTE(uuid))` instead of `_relations @> to_jsonb(uuid::text)`
- **AND** object format: `JSON_SEARCH(_relations, 'one', uuid) IS NOT NULL` instead of `EXISTS (SELECT 1 FROM jsonb_each_text(...))`

#### Scenario: Full-text search degrades gracefully on MariaDB

- **GIVEN** `MagicSearchHandler::applyFullTextSearch()` uses `LOWER()` and `ILIKE` patterns
- **WHEN** running on MariaDB
- **THEN** it SHALL use `LOWER()` with `LIKE` (MariaDB does not support `ILIKE`)
- **AND** pg_trgm similarity scoring SHALL be skipped (hasPgTrgm returns false)
- **AND** search results SHALL still be correct, only without fuzzy relevance ranking

#### Scenario: Bulk upsert uses correct syntax per database

- **GIVEN** `MagicBulkHandler` uses `INSERT...ON CONFLICT DO UPDATE` for PostgreSQL
- **WHEN** running on MariaDB
- **THEN** it SHALL use `INSERT...ON DUPLICATE KEY UPDATE`
- **AND** column existence checks SHALL use `SHOW COLUMNS` for MariaDB
- **AND** `information_schema` queries for PostgreSQL

#### Scenario: Date histogram facets use correct date functions

- **GIVEN** `MagicFacetHandler` uses `TO_CHAR(field, format)` for PostgreSQL date formatting
- **WHEN** running on MariaDB
- **THEN** it SHALL use `DATE_FORMAT(field, format)` with MySQL format strings
- **AND** the format mapping SHALL convert PostgreSQL patterns (e.g., `'YYYY-MM'`) to MySQL patterns (e.g., `'%Y-%m'`)

### Requirement: GIN Index Optimization Has MariaDB Equivalent

PostgreSQL GIN indexes on JSONB columns provide O(log n) containment queries. MariaDB does not support GIN indexes, so the system SHALL use alternative indexing strategies.

#### Scenario: _relations index on PostgreSQL uses GIN

- **GIVEN** `MagicMapper::createTableIndexes()` creates indexes for dynamic tables
- **WHEN** running on PostgreSQL
- **THEN** it SHALL create a GIN index on `_relations` for fast `@>` containment lookups
- **AND** create GIN indexes on array-of-object-reference columns with `inversedBy`

#### Scenario: _relations index on MariaDB uses generated column or functional index

- **GIVEN** the same table creation runs on MariaDB
- **WHEN** creating indexes
- **THEN** it SHALL skip GIN index creation (MariaDB does not support GIN)
- **AND** MAY create a regular B-tree index on `_relations` for basic lookups
- **AND** `JSON_CONTAINS` queries SHALL still function correctly without GIN (sequential scan on JSON column)

#### Scenario: Index creation errors are non-fatal

- **GIVEN** index creation runs during schema provisioning
- **WHEN** an index type is unsupported on the current database platform
- **THEN** the error SHALL be caught and logged at warning level
- **AND** table creation SHALL NOT fail
- **AND** the system SHALL degrade to full-scan queries for that column

### Requirement: Migration Testing Across Databases

All Nextcloud migrations in `lib/Migration/` SHALL execute cleanly on both PostgreSQL and MariaDB without database-specific syntax errors.

#### Scenario: Standard Doctrine migrations work on both databases

- **GIVEN** migrations use Nextcloud's `ISchemaWrapper` for schema changes
- **WHEN** migrations run on MariaDB
- **THEN** they SHALL complete without errors
- **AND** column types SHALL be mapped by Doctrine DBAL to the correct platform types
- **AND** `Types::JSON` SHALL become `LONGTEXT` on MariaDB and `JSONB` on PostgreSQL

#### Scenario: Raw SQL migrations have platform guards

- **GIVEN** `Version1Date20250908180000` uses MySQL-specific `ON UPDATE CURRENT_TIMESTAMP` syntax
- **WHEN** this migration runs on PostgreSQL
- **THEN** it SHALL detect the platform and skip MySQL-specific raw SQL
- **AND** use a trigger-based approach or skip the auto-update feature on PostgreSQL
- **AND** log which platform-specific features were applied or skipped

#### Scenario: Migration key length limits are respected

- **GIVEN** `Version1Date20250813140000` skips complex index creation due to MySQL key length issues
- **WHEN** running on PostgreSQL
- **THEN** the index creation MAY proceed (PostgreSQL has no 767-byte key length limit)
- **AND** a platform check SHALL determine whether to apply the optimization

### Requirement: Remove Blob Storage Testing

Blob storage (Normal mode -- all objects in `oc_openregister_objects` as JSON) is being dropped. Only MagicMapper (dedicated SQL tables per schema) SHALL be tested.

#### Scenario: MagicMapper-only in CI

- **GIVEN** the Newman test collections
- **WHEN** they run in CI
- **THEN** they SHALL always set `ENABLE_MAGIC_MAPPER=true`
- **AND** `run-dual-storage-tests.sh` SHALL be removed or deprecated in favour of `run-tests.sh`

#### Scenario: Single Newman run per job

- **GIVEN** an integration test job
- **WHEN** Newman runs
- **THEN** it SHALL execute once per job (MagicMapper only)
- **AND** the two jobs run in parallel (one per database)

#### Scenario: Old dual-storage references are cleaned up

- **GIVEN** `run-dual-storage-tests.sh` exists in `tests/integration/`
- **WHEN** the CI matrix is fully implemented
- **THEN** the script SHALL be marked deprecated with a comment pointing to the matrix workflow
- **AND** no CI job SHALL reference it
- **AND** documentation SHALL note that Normal mode is no longer supported

### Requirement: Docker-Compose Profiles for Local Database Testing

The `docker-compose.yml` SHALL provide profiles for each supported database so developers can replicate CI conditions locally.

#### Scenario: PostgreSQL is the default profile

- **GIVEN** a developer runs `docker compose up`
- **WHEN** no profile is specified
- **THEN** the `db` service SHALL start with `pgvector/pgvector:pg16`
- **AND** pg_trgm and pgvector extensions SHALL be loaded via `shared_preload_libraries`
- **AND** the configuration SHALL match CI job 1

#### Scenario: MariaDB is available via profile

- **GIVEN** a developer runs `docker compose --profile mariadb up`
- **WHEN** the mariadb profile is activated
- **THEN** the `db-mariadb` service SHALL start with `mariadb:11.2` (local) or `mariadb:10.11` (CI)
- **AND** transaction isolation SHALL be set to `READ-COMMITTED`
- **AND** Nextcloud SHALL be configured with `--database mysql`
- **AND** the configuration SHALL match CI job 2

#### Scenario: Database volumes are separate

- **GIVEN** both database profiles exist
- **WHEN** switching between profiles
- **THEN** PostgreSQL and MariaDB SHALL use separate volume names
- **AND** switching databases SHALL require a clean Nextcloud install (`php occ maintenance:install`)

### Requirement: Update Nextcloud Versions on Each Stable Release
The CI matrix MUST be updated to track the latest and previous stable Nextcloud releases on each new stable release.

#### Scenario: New Nextcloud stable release

- **GIVEN** Nextcloud releases a new stable version (e.g., stable33)
- **WHEN** the CI matrix is updated
- **THEN** job 1 (PHP 8.3, PostgreSQL) SHALL move to the new latest stable (stable33)
- **AND** job 2 (PHP 8.2, MariaDB) SHALL move to the previous stable (stable32)
- **AND** this SHALL be documented as a manual step in the testing docs

#### Scenario: PHP version matrix aligns with Nextcloud requirements

- **GIVEN** a new Nextcloud stable drops support for PHP 8.2
- **WHEN** the matrix is updated
- **THEN** the MariaDB job SHALL update its PHP version to the minimum supported
- **AND** the PostgreSQL job SHALL use the latest PHP version supported by Nextcloud

#### Scenario: Database version updates follow LTS schedule

- **GIVEN** MariaDB 10.11 reaches end of life
- **WHEN** the CI matrix is reviewed
- **THEN** the MariaDB version SHALL be updated to the next LTS release
- **AND** the docker-compose MariaDB service SHALL be updated to match
- **AND** PostgreSQL SHALL track the version used by `pgvector/pgvector` image

### Requirement: CI Failure Reporting Per Database
The CI summary job MUST report test results per matrix entry so that database-specific failures are clearly identifiable.

#### Scenario: Matrix-aware PR comments

- **GIVEN** the CI summary job
- **WHEN** it generates the quality report
- **THEN** it SHALL show results per matrix entry (e.g., "Newman (PG/8.3/NC32)" and "Newman (MariaDB/8.2/NC31)")
- **AND** the PR comment SHALL include both job results

#### Scenario: Database-specific failure is clearly identified

- **GIVEN** a Newman test passes on PostgreSQL but fails on MariaDB
- **WHEN** the CI summary is generated
- **THEN** the failing database SHALL be prominently labeled
- **AND** the failure message SHALL indicate whether it is a query compatibility issue (e.g., unsupported JSONB operator on MariaDB)
- **AND** the job SHALL upload test artifacts including the Newman HTML report and database logs

#### Scenario: Parallel execution does not mask failures

- **GIVEN** `fail-fast: false` is set on the matrix
- **WHEN** one database job fails
- **THEN** the other job SHALL still run to completion
- **AND** the overall CI status SHALL be "failed"
- **AND** both job results SHALL be visible in the GitHub Actions UI

### Requirement: Feature Flags for Database-Specific Capabilities

The application SHALL expose which database-specific features are available so that code paths can be conditionally enabled.

#### Scenario: pg_trgm availability is detected at runtime

- **GIVEN** `MagicMapper::hasPgTrgm()` checks for the pg_trgm extension
- **WHEN** running on PostgreSQL with pg_trgm loaded
- **THEN** fuzzy search via `similarity()` function SHALL be available
- **AND** the result SHALL be cached for the request lifetime

#### Scenario: Fuzzy search is disabled on MariaDB

- **GIVEN** `hasPgTrgm()` returns false on non-PostgreSQL platforms
- **WHEN** a search request includes `_fuzzy=true`
- **THEN** the system SHALL fall back to substring matching only
- **AND** SHALL NOT return an error
- **AND** MAY log a debug message indicating fuzzy search is unavailable

#### Scenario: GIN index availability affects query strategy

- **GIVEN** GIN indexes are only available on PostgreSQL
- **WHEN** running containment queries on MariaDB
- **THEN** the system SHALL use `JSON_CONTAINS` without assuming index support
- **AND** query performance MAY be slower for large datasets on MariaDB
- **AND** this trade-off SHALL be documented in performance notes

### Requirement: Update Testing Documentation

The testing documentation SHALL be updated to reflect:

1. The 2-line matrix strategy and its rationale (cost efficiency)
2. Which database is tested on which PHP/Nextcloud combination
3. That blob storage (Normal mode) testing is removed -- MagicMapper only
4. How to run tests locally against MariaDB (using docker-compose `--profile mariadb`)
5. The version update procedure when a new Nextcloud stable is released

#### Scenario: Local MariaDB testing instructions

- **GIVEN** a developer wants to test against MariaDB locally
- **WHEN** they read the testing documentation
- **THEN** they SHALL find instructions to:
  - Start the MariaDB profile: `docker compose --profile mariadb up -d`
  - Configure Nextcloud to use MariaDB during install
  - Run Newman tests against the MariaDB-backed instance

#### Scenario: Database compatibility checklist for contributors

- **GIVEN** a contributor adds new database query code
- **WHEN** they read the testing documentation
- **THEN** they SHALL find a checklist requiring:
  - Platform detection via `getDatabasePlatform()` for any raw SQL
  - MariaDB/MySQL fallback for any PostgreSQL-specific operators (`@>`, `::jsonb`, `jsonb_typeof`)
  - No use of `ILIKE` without platform guard (use `LOWER() LIKE` for MariaDB)
  - Test verification on both database CI jobs

#### Scenario: Documentation references database-specific code paths

- **GIVEN** the testing documentation
- **WHEN** listing database-specific handlers
- **THEN** it SHALL reference:
  - `lib/Db/ObjectHandlers/MariaDbSearchHandler.php` -- legacy blob-mode MariaDB search
  - `lib/Db/ObjectHandlers/MariaDbFacetHandler.php` -- legacy blob-mode MariaDB facets
  - `lib/Db/MagicMapper/MagicSearchHandler.php` -- MagicMapper search (needs MariaDB paths)
  - `lib/Db/MagicMapper/MagicBulkHandler.php` -- MagicMapper bulk ops (has platform branching)
  - `lib/Db/MagicMapper/MagicFacetHandler.php` -- MagicMapper facets (partial platform branching)
  - `lib/Db/MagicMapper/MagicStatisticsHandler.php` -- statistics (has platform detection)

## Estimated Scope

| Change | Files Affected |
|--------|---------------|
| Rewrite `integration-tests` job to matrix | `.github/workflows/quality.yml` |
| Rewrite `php-tests` job to matrix | `.github/workflows/quality.yml` |
| Update summary job for matrix labels | `.github/workflows/quality.yml` |
| Add MariaDB fallbacks to MagicSearchHandler | `lib/Db/MagicMapper/MagicSearchHandler.php` |
| Add MariaDB fallbacks to MagicFacetHandler | `lib/Db/MagicMapper/MagicFacetHandler.php` |
| Platform-guard GIN index creation | `lib/Db/MagicMapper.php` |
| Platform-guard raw SQL migrations | `lib/Migration/Version1Date20250908180000.php` |
| Remove/deprecate `run-dual-storage-tests.sh` | `tests/integration/run-dual-storage-tests.sh` |
| Update testing documentation | `docs/testing.md` |
| Update development testing docs | `docs/development/testing.md` |

## Current Implementation Status

- **Implemented -- CI matrix workflow**: `.github/workflows/database-tests.yml` implements the 2-line matrix with PHPUnit and Newman jobs running against both PostgreSQL 16 (PHP 8.3, NC stable32) and MariaDB 10.11 (PHP 8.2, NC stable31). Uses Docker containers started dynamically from matrix variables with health-check polling.
- **Implemented -- quality.yml updated**: PHPUnit and Newman disabled in the shared quality workflow call (`quality.yml`) since they now run in `database-tests.yml` with real database backends instead of SQLite.
- **Implemented -- MagicMapper-only testing**: Newman runs once per matrix job (MagicMapper only); no dual-storage testing in CI.
- **Implemented -- MagicMapper table creation**: `MagicMapper::createTable()` and `mapColumnTypeToSQL()` have full PostgreSQL/MariaDB branching for column types (JSONB vs JSON, TIMESTAMP vs DATETIME, BIGSERIAL vs AUTO_INCREMENT).
- **Implemented -- MagicBulkHandler platform branching**: `MagicBulkHandler` detects `PostgreSQLPlatform` and uses `INSERT...ON CONFLICT DO UPDATE` vs `INSERT...ON DUPLICATE KEY UPDATE`, plus platform-specific column introspection.
- **Implemented -- MariaDB code support**: `MariaDbFacetHandler` (`lib/Db/ObjectHandlers/MariaDbFacetHandler.php`) and `MariaDbSearchHandler` (`lib/Db/ObjectHandlers/MariaDbSearchHandler.php`) exist with MySQL JSON functions for the legacy blob storage mode.
- **Partially implemented -- MagicFacetHandler**: Has some platform detection (`getDatabasePlatform()` checks) for date formatting and search, but not all paths are covered.
- **Not yet implemented -- MagicSearchHandler MariaDB paths**: `applyJsonArrayFilter()`, `applyRelationsContainsFilter()`, and `buildArrayPropertyConditionSql()` use PostgreSQL-only syntax (`::jsonb @>`, `jsonb_typeof`, `jsonb_each_text`, `to_jsonb`) without MariaDB fallbacks. These will fail on MariaDB.
- **Exists but deprecated -- dual storage testing**: `run-dual-storage-tests.sh` still exists but is no longer used in CI.
- **Not yet implemented -- documentation updates**: `docs/testing.md` does not yet exist; local MariaDB testing instructions and version update procedures are not documented.

## Cross-References

- `unit-test-coverage` -- PHPUnit coverage thresholds and reporting apply to the database matrix jobs
- `api-test-coverage` -- Newman API test collections run on both database matrix entries

## Standards & References

- GitHub Actions matrix strategy documentation
- Nextcloud server `stable31` and `stable32` release branches
- Nextcloud supported databases: PostgreSQL 11+, MariaDB 10.6+, MySQL 8.0+, SQLite 3 (dev only)
- PostgreSQL 16 documentation (JSONB operators, GIN indexes, pg_trgm)
- MariaDB 10.11 LTS documentation (JSON functions, JSON_CONTAINS, JSON_EXTRACT)
- Newman CLI for Postman collection execution
- Doctrine DBAL platform abstraction (`PostgreSQLPlatform`, `MySQLPlatform`)

## Specificity Assessment

- **Highly specific and implementable as-is**: The spec provides exact matrix configurations, YAML snippets, Docker container setup instructions, and parameterized install commands.
- **Clear scope**: Modifies `.github/workflows/quality.yml`, MagicMapper database handlers, test scripts, and documentation.
- **Identifies concrete compatibility gaps**: Lists specific methods (`applyJsonArrayFilter`, `applyRelationsContainsFilter`, `buildArrayPropertyConditionSql`) that need MariaDB fallbacks, with exact SQL operator mappings.
- **Well-defined maintenance procedure**: Describes the version bump process when a new Nextcloud stable is released.
- **No ambiguity**: Matrix entries, service containers, health checks, and parameterized install steps are all fully specified.
