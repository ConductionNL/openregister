## ADDED Requirements

### Requirement: Date histogram facets MUST work on both PostgreSQL and MariaDB
`MagicFacetHandler` MUST produce correctly bucketed `date_histogram` facet results on both PostgreSQL and MariaDB/MySQL for every supported interval (`day`, `week`, `month`, `quarter`, `year`). The date-key SQL expression MUST be selected based on the database platform detected via `$this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform`, using `TO_CHAR()` with PostgreSQL format patterns on PostgreSQL and `DATE_FORMAT()` (plus `CONCAT(YEAR(), '-Q', QUARTER())` for quarter) on MariaDB/MySQL. Returning an empty `buckets` array on MariaDB when data is present is a defect.

#### Scenario: Year interval on MariaDB
- **GIVEN** MariaDB is the active database platform
- **AND** a schema-backed table contains a `datetime` column `publicatiedatum` with rows `2023-05-01`, `2024-06-15`, `2024-11-30`
- **WHEN** `MagicFacetHandler.getDateHistogramFacet()` is called with `interval: 'year'`
- **THEN** the result MUST be `{ type: 'date_histogram', interval: 'year', buckets: [{ key: '2023', results: 1, from: '2023-01-01', to: '2023-12-31' }, { key: '2024', results: 2, from: '2024-01-01', to: '2024-12-31' }] }`
- **AND** the underlying SQL MUST use `DATE_FORMAT(publicatiedatum, '%Y')`, NOT `TO_CHAR(publicatiedatum, 'YYYY')`

#### Scenario: Month interval on MariaDB
- **GIVEN** MariaDB is the active database platform
- **AND** a schema-backed table contains `datetime` values in months `2025-01`, `2025-01`, `2025-03`
- **WHEN** `getDateHistogramFacet()` is called with `interval: 'month'`
- **THEN** the result MUST contain buckets keyed `'2025-01'` (count 2) and `'2025-03'` (count 1)
- **AND** the SQL MUST use `DATE_FORMAT(..., '%Y-%m')`

#### Scenario: Quarter interval on MariaDB
- **GIVEN** MariaDB is the active database platform
- **AND** `datetime` values in Q1 2024 and Q3 2024
- **WHEN** `getDateHistogramFacet()` is called with `interval: 'quarter'`
- **THEN** the SQL MUST use `CONCAT(YEAR(...), '-Q', QUARTER(...))` to produce keys `'2024-Q1'` and `'2024-Q3'`
- **AND** each bucket MUST include correct `from`/`to` dates for the quarter

#### Scenario: Year interval on PostgreSQL unchanged
- **GIVEN** PostgreSQL is the active database platform
- **WHEN** `getDateHistogramFacet()` is called with `interval: 'year'`
- **THEN** the SQL MUST use `TO_CHAR(publicatiedatum, 'YYYY')` as it does today
- **AND** bucket keys MUST remain identical to current behavior

#### Scenario: UNION date histogram across schemas on MariaDB
- **GIVEN** MariaDB is the active database platform
- **AND** two magic tables each contain a `datetime` column `created_on`
- **WHEN** `MagicFacetHandler.getDateHistogramFacetUnion()` is called with `interval: 'month'`
- **THEN** each UNION sub-query MUST use `DATE_FORMAT(created_on, '%Y-%m')`, NOT `TO_CHAR(...)`
- **AND** merged counts MUST be the sum per bucket key across all tables

### Requirement: Weekly histogram buckets MUST use ISO week numbering on all databases
`MagicFacetHandler`, `MariaDbFacetHandler`, and `MetaDataFacetHandler` MUST produce identical bucket keys for the `week` interval regardless of the underlying database platform. The key format MUST be ISO 8601 year + ISO 8601 week (`YYYY-WW` where both components follow ISO 8601 week numbering: Monday as first day of week, week 1 is the week containing the first Thursday of the year). This means `TO_CHAR(field, 'IYYY-IW')` on PostgreSQL and `DATE_FORMAT(field, '%x-%v')` on MariaDB/MySQL. Use of `%Y-%u` (non-ISO week, year from date) is forbidden because it disagrees with PostgreSQL around Jan 1.

#### Scenario: Week spanning year boundary produces ISO-aligned key
- **GIVEN** a date of 2023-01-01 (Sunday; ISO week 52 of 2022)
- **WHEN** a weekly histogram bucket is computed on either database
- **THEN** the bucket key MUST be `'2022-52'`
- **AND** both databases MUST produce the same key for the same input

#### Scenario: MariaDB week format uses ISO components
- **GIVEN** MariaDB is the active database platform
- **WHEN** the date-key SQL expression is built for `interval: 'week'`
- **THEN** it MUST be `DATE_FORMAT(<field>, '%x-%v')`
- **AND** NOT `DATE_FORMAT(<field>, '%Y-%u')`

### Requirement: Weekly histogram bucket bounds MUST reflect ISO week start/end
When a `date_histogram` facet returns buckets with `interval: 'week'`, each bucket's `from` date MUST be the Monday of the ISO week identified by the bucket key, and `to` MUST be the Sunday of that same ISO week. Bucket bounds MUST NOT be derived by parsing the bucket key as a month (`strtotime('YYYY-WW')` does not parse a week) — they MUST be computed via an ISO-aware routine such as PHP's `DateTime::setISODate()`.

#### Scenario: Week 12 of 2025 has correct ISO bounds
- **GIVEN** a bucket with key `'2025-12'` from a weekly histogram
- **WHEN** `getDateBoundsForBucket('2025-12', 'week')` is called
- **THEN** it MUST return `{ from: '2025-03-17', to: '2025-03-23' }` (Monday–Sunday of ISO week 12, 2025)
- **AND** NOT `{ from: '2025-12-01', to: '2025-12-31' }` (which is the current bug — December 2025)

#### Scenario: Week 1 of 2024 is the week containing the first Thursday
- **GIVEN** a bucket with key `'2024-01'` from a weekly histogram
- **WHEN** bounds are computed
- **THEN** it MUST return `{ from: '2024-01-01', to: '2024-01-07' }` (Monday Jan 1 through Sunday Jan 7, 2024)
