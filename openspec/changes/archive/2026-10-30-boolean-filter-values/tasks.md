# Tasks: boolean-filter-values

## 1. Failing tests first

- [x] 1.1 Unit: `tests/Unit/Db/MagicSearchHandlerBooleanFilterValueTest.php` drives `applyObjectFilters()`, `buildObjectFilterConditionsSql()` and the facet filter with `false`, `true`, `'false'`, `'true'`, `in`, `ne` on a boolean, an integer and a string property. Fails before the fix.
- [x] 1.2 Database: `tests/Db/BooleanFilterIntegrationTest.php` saves rows with `true`, `false` and no value through the real `MagicMapper`, searches with each form and asserts the counts. Fails before the fix on PostgreSQL.

## 2. Fix

- [x] 2.1 `FilterParams::comparableValue()` with the mapping of design D-2 to D-4.
- [x] 2.2 Call it from the three builders.

## 3. Spec and proof

- [x] 3.1 Spec delta on `zoeken-filteren`: a boolean value filters like its string form; null matches neither.
- [x] 3.2 Live check on a throwaway instance: dossiq's work queue query returns its rows with bool `false`.
- [x] 3.3 List fleet callers that pass a PHP boolean filter in the PR body.
