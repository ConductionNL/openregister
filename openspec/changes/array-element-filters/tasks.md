# Tasks: array-element-filters

### Task 1: `_element` on the QueryBuilder path
- **spec_ref**: `openspec/changes/array-element-filters/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property`
- **files**: `lib/Db/MagicMapper/ArrayElementFilter.php`, `lib/Db/MagicMapper/MagicSearchHandler.php`, `lib/Db/MagicMapper.php`
- [x] Implement: blocks, EXISTS SQL for PostgreSQL and MariaDB, guards, UNION refusal
- [x] Test: `tests/Unit/Db/MagicMapper/ArrayElementFilterTest.php`
