# Changelog

## Unreleased

### Fixed
- Schema-level RBAC now honours the full operator set (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`) and dynamic variables (`$organisation/$userId/$now`) for conditional `match` clauses, matching the behaviour of the SQL row-level filter. Previously `PermissionHandler::evaluateMatchConditions()` was an equality-only reimplementation, so any `find()`/single-object fetch against a schema using, for example, `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }` would throw "User 'Anonymous' does not have permission" even though the list endpoint correctly returned the same object. `PermissionHandler` and `MagicRbacHandler::hasPermission()` now delegate conditional match evaluation to the shared `ConditionMatcher` service (the same matcher already used by `PropertyRbacHandler`). The SQL emitter in `MagicRbacHandler::applyRbacFilters()` is unchanged. Fixes OpenCatalogi `PublicationsController::attachments` returning 500 on publications whose schema uses operator-based public rules. ([#1336](https://github.com/ConductionNL/openregister/issues/1336))
- **RBAC condition matching now treats `null` property values consistently with SQL three-valued logic.** Previously `OperatorEvaluator` used raw PHP comparison operators, so a rule like `{ "publishedAt": { "$lte": "$now" } }` would incorrectly grant access to objects with `publishedAt: null` (because PHP coerces `null` to `""` and `"" <= "<datetime>"` is true), while the SQL list endpoint correctly excluded those rows. `$gt`/`$gte`/`$lt`/`$lte` now return `false` when either side is null; `$in`/`$nin`/`$ne` return `false` when the object value is null. `$eq: null` is preserved as a "match missing field" escape hatch; `$exists` is unchanged. List and find endpoints now produce identical verdicts for the full cross-product of operators × object-null cases. ([#1336](https://github.com/ConductionNL/openregister/issues/1336))
- **`$now` dynamic variable now emits the same string format in both RBAC paths.** `ConditionMatcher::resolveDynamicValue` previously returned ISO 8601 `c` format (`"2026-04-24T14:43:49+00:00"`) while `MagicRbacHandler::resolveDynamicValue` returned SQL-native `"Y-m-d H:i:s"` (`"2026-04-24 14:43:49"`). For text/JSON columns storing dates, the two paths performed raw lexicographic comparison against different `$now` strings, and the comparison diverged around the separator character (`"T"` vs space). Both paths now use `"Y-m-d H:i:s"`, which is the canonical format produced by OpenRegister's `DateTimeNormalizer` on input. ([#1336](https://github.com/ConductionNL/openregister/issues/1336))
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

