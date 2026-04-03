# Changelog

## [0.2.13-unstable.78] - 2026-04-03

### Fixed
- Fix `MagicMapper::getExistingTableColumns()` to use platform-aware `table_schema` filter (`DATABASE()` for MySQL/MariaDB, `'public'` for PostgreSQL), preventing `Duplicate column name '_id'` errors on MariaDB writes



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

