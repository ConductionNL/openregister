# Tasks: nested-property-filters

## 1. Filter

- [x] 1.1 `NestedPathFilter`: split the path filters out of a filter map in both spellings, read the path per platform, write the conditions per operator. Verify: PHPUnit `MagicSearchHandlerNestedPathFilterTest`.
- [x] 1.2 Apply them on the QueryBuilder path (`applyObjectFilters`) and the raw UNION path (`buildObjectFilterConditionsSql`), refusing an encrypted root. Verify: the same test, both paths; a live list on PostgreSQL with `dunning.currentStage[isnull]=true|false`.
