---
status: implemented
---

# MariaDB Support & Dual-Database CI Matrix

OpenRegister SHALL be fully tested on both PostgreSQL and MariaDB through a cost-efficient 2-line CI matrix that piggybacks the database difference onto the PHP version split. Blob storage (Normal mode) is removed — only MagicMapper (dedicated SQL tables per schema) is supported.

## Current State

- CI runs Newman integration tests against **PostgreSQL only** (single job)
- PHPUnit runs on PHP 8.2 + 8.3 but both use **SQLite** (no real DB testing)
- `MariaDbFacetHandler` and MySQL JSON functions exist in code but are **never tested in CI**
- `run-dual-storage-tests.sh` tests Normal + MagicMapper modes — blob storage (Normal) is being dropped
- All jobs pin to a single Nextcloud version (`stable32`)

## Test Matrix

### Requirement: 2-line CI matrix covering both databases and Nextcloud versions

The CI SHALL run exactly **2 parallel integration test jobs**, each combining a unique PHP version, Nextcloud version, and database:

| Job | PHP | Nextcloud | Database | Storage |
|-----|-----|-----------|----------|---------|
| 1 | 8.3 | stable32 (latest) | PostgreSQL 16 | MagicMapper |
| 2 | 8.2 | stable31 (latest − 1) | MariaDB 10.11 | MagicMapper |

#### Scenario: PostgreSQL job (PHP 8.3, Nextcloud stable32)

- **GIVEN** the CI pipeline triggers on push or pull request
- **AND** linting has passed
- **WHEN** integration test job 1 runs
- **THEN** it SHALL use PHP 8.3
- **AND** start a PostgreSQL 16 service container
- **AND** checkout Nextcloud `stable32`
- **AND** install Nextcloud with `--database pgsql`
- **AND** run all Newman collections against the running instance
- **AND** report results independently from job 2

#### Scenario: MariaDB job (PHP 8.2, Nextcloud stable31)

- **GIVEN** the CI pipeline triggers on push or pull request
- **AND** linting has passed
- **WHEN** integration test job 2 runs
- **THEN** it SHALL use PHP 8.2
- **AND** start a MariaDB 10.11 service container
- **AND** checkout Nextcloud `stable31`
- **AND** install Nextcloud with `--database mysql`
- **AND** run the same Newman collections as job 1
- **AND** report results independently from job 1

### Requirement: PHPUnit tests use the same matrix

The PHPUnit `php-tests` job SHALL use the same 2-line matrix instead of the current PHP-only matrix with SQLite:

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

## CI Workflow Changes

### Requirement: Matrix strategy in `quality.yml`

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

### Requirement: Dynamic service containers

GitHub Actions `services` do not support matrix variables directly. The workflow SHALL use a container start step instead:

#### Scenario: Start database container from matrix

- **GIVEN** a matrix entry with `db-image` and `db-port`
- **WHEN** the job starts
- **THEN** it SHALL run the database image as a Docker container
- **AND** expose the port on `127.0.0.1`
- **AND** wait for the health check to pass before continuing

### Requirement: Parameterized Nextcloud install

#### Scenario: Install Nextcloud with matrix database

- **GIVEN** the matrix provides `database`, `db-user`, `db-password`, `db-name`, and `db-port`
- **WHEN** `php occ maintenance:install` runs
- **THEN** it SHALL use `--database ${{ matrix.database }}`
- **AND** `--database-host 127.0.0.1`
- **AND** the correct port, user, password, and database name from the matrix

## Storage Mode Simplification

### Requirement: Remove blob storage testing

Blob storage (Normal mode — all objects in `oc_openregister_objects` as JSON) is being dropped. Only MagicMapper (dedicated SQL tables per schema) SHALL be tested.

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

## Version Maintenance

### Requirement: Update Nextcloud versions on each stable release

#### Scenario: New Nextcloud stable release

- **GIVEN** Nextcloud releases a new stable version (e.g., stable33)
- **WHEN** the CI matrix is updated
- **THEN** job 1 (PHP 8.3, PostgreSQL) SHALL move to the new latest stable (stable33)
- **AND** job 2 (PHP 8.2, MariaDB) SHALL move to the previous stable (stable32)
- **AND** this SHALL be documented as a manual step in the testing docs

## Documentation Updates

### Requirement: Update `docs/testing.md`

The testing documentation SHALL be updated to reflect:

1. The 2-line matrix strategy and its rationale (cost efficiency)
2. Which database is tested on which PHP/Nextcloud combination
3. That blob storage (Normal mode) testing is removed — MagicMapper only
4. How to run tests locally against MariaDB (using docker-compose `--profile mariadb`)
5. The version update procedure when a new Nextcloud stable is released

#### Scenario: Local MariaDB testing instructions

- **GIVEN** a developer wants to test against MariaDB locally
- **WHEN** they read the testing documentation
- **THEN** they SHALL find instructions to:
  - Start the MariaDB profile: `docker compose --profile mariadb up -d`
  - Configure Nextcloud to use MariaDB during install
  - Run Newman tests against the MariaDB-backed instance

### Requirement: Update Quality Summary

#### Scenario: Matrix-aware PR comments

- **GIVEN** the CI summary job
- **WHEN** it generates the quality report
- **THEN** it SHALL show results per matrix entry (e.g., "Newman (PG/8.3/NC32)" and "Newman (MariaDB/8.2/NC31)")
- **AND** the PR comment SHALL include both job results

## Estimated Scope

| Change | Files Affected |
|--------|---------------|
| Rewrite `integration-tests` job to matrix | `.github/workflows/quality.yml` |
| Rewrite `php-tests` job to matrix | `.github/workflows/quality.yml` |
| Update summary job for matrix labels | `.github/workflows/quality.yml` |
| Remove/deprecate `run-dual-storage-tests.sh` | `tests/integration/run-dual-storage-tests.sh` |
| Update testing documentation | `docs/testing.md` |
| Update development testing docs | `docs/development/testing.md` |

### Current Implementation Status
- **Implemented — CI matrix workflow**: `.github/workflows/database-tests.yml` implements the 2-line matrix with PHPUnit and Newman jobs running against both PostgreSQL 16 (PHP 8.3, NC stable32) and MariaDB 10.11 (PHP 8.2, NC stable31). Uses Docker containers started dynamically from matrix variables with health-check polling.
- **Implemented — quality.yml updated**: PHPUnit and Newman disabled in the shared quality workflow call (`quality.yml`) since they now run in `database-tests.yml` with real database backends instead of SQLite.
- **Implemented — MagicMapper-only testing**: Newman runs once per matrix job (MagicMapper only); no dual-storage testing in CI.
- **Implemented — MariaDB code support**: `MariaDbFacetHandler` (`lib/Db/ObjectHandlers/MariaDbFacetHandler.php`) and `MariaDbSearchHandler` (`lib/Db/ObjectHandlers/MariaDbSearchHandler.php`) exist with MySQL JSON functions. `MagicMapper` (`lib/Db/MagicMapper.php`) and `MagicSearchHandler` (`lib/Db/MagicMapper/MagicSearchHandler.php`) contain MySQL/MariaDB-specific code paths.
- **Exists but deprecated — dual storage testing**: `run-dual-storage-tests.sh` still exists but is no longer used in CI.
- **Not yet implemented — documentation updates**: `docs/testing.md` does not yet exist; local MariaDB testing instructions and version update procedures are not documented.

### Standards & References
- GitHub Actions matrix strategy documentation
- Nextcloud server `stable31` and `stable32` release branches
- PostgreSQL 16 documentation
- MariaDB 10.11 LTS documentation
- Newman CLI for Postman collection execution

### Specificity Assessment
- **Highly specific and implementable as-is**: The spec provides exact matrix configurations, YAML snippets, Docker container setup instructions, and parameterized install commands.
- **Clear scope**: Only modifies `.github/workflows/quality.yml`, test scripts, and documentation.
- **Well-defined maintenance procedure**: Describes the version bump process when a new Nextcloud stable is released.
- **No ambiguity**: Matrix entries, service containers, health checks, and parameterized install steps are all fully specified.
