# Changelog

## Unreleased

### Fixed
- Empty-string date fields now correctly round-trip as `null`. Previously, objects persisted with an empty string (`""`) for a `date` or `date-time` property were silently rewritten to the current date on write (because `(new DateTime(''))->format('Y-m-d')` returns today) and rendered as the current datetime on read (because `new DateTime('')` returns "now"). Object writes (`ObjectService::normalizeDateValues`), reads (`MagicStatisticsHandler`), bulk imports, metadata handling, and search now route user-supplied datetime input through a central `DateTimeNormalizer`. On next read/save, empty-string values normalize to `null`. ([#1292](https://github.com/ConductionNL/openregister/issues/1292))

## 0.2.13-unstable.78 – 2026-04-03

### Fixed
- Fix `MagicMapper::getExistingTableColumns()` to use platform-aware `table_schema` filter (`DATABASE()` for MySQL/MariaDB, `current_schema()` for PostgreSQL), preventing `Duplicate column name '_id'` errors on MariaDB writes


## 0.2.9-beta.36 – 2026-01-12

### Other
- By checking the md5 checksum of the existing file and the content of the incoming data. ([#518](https://github.com/ConductionNL/openregister/pull/518))
- Would be nice to delete schemas ([#519](https://github.com/ConductionNL/openregister/pull/519))
- Stable 2025-08-05 ([#523](https://github.com/ConductionNL/openregister/pull/523))

## 0.2.9-beta.1 – 2026-01-09

### Other
- By checking the md5 checksum of the existing file and the content of the incoming data. ([#518](https://github.com/ConductionNL/openregister/pull/518))
- Would be nice to delete schemas ([#519](https://github.com/ConductionNL/openregister/pull/519))
- Stable 2025-08-05 ([#523](https://github.com/ConductionNL/openregister/pull/523))

