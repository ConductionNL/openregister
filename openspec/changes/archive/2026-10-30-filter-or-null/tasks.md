# Tasks: filter-or-null

## 1. Code

- [x] 1.1 `NullFilter::splitOrNull()` and `NullFilter::admitNull()`, one reader for every path.
- [x] 1.2 QueryBuilder path: object fields (`applyObjectFilters()`) and `@self` (`applyMetadataOperators()`) collect a field's predicates and AND them in one group.
- [x] 1.3 Raw UNION path: object fields and `@self` do the same.
- [x] 1.4 Nested paths: `NestedPathFilter::conditions()` and `OPERATORS`.

## 2. Tests and docs

- [x] 2.1 `tests/Unit/Db/MagicSearchHandlerOrNullModifierTest.php`: every builder, `ne` unchanged, `orNull=false`, `orNull` alone, and a run against SQLite that shows the unwritten row coming back.
- [x] 2.2 `tests/Unit/Support/NullFilterTest.php`: the split and the grouping.
- [x] 2.3 `docs/Features/search.md`: the operator row and a short section.
