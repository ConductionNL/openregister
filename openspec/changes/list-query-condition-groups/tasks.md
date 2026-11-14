# Tasks: list-query-condition-groups

## 1. The parameter

- [x] 1.1 Parse `_where` (query string JSON, POST body object, `$query['_where']`, `$config['where']`) into a validated tree with node paths; refuse per design D-3. Verify: `tests/Unit/Db/MagicMapper/ConditionGroupParserTest.php`. (`ConditionGroupParser` parses a JSON string or a decoded array into a `ConditionGroup`/`ConditionLeaf` tree and raises `ConditionGroupException` with the node path for every STRUCTURAL refusal — bad JSON, a group with no children or a bad type, an unknown operator, depth > 5, > 200 leaves. The SEMANTIC refusals that need the schema and the backend — an undeclared or encrypted property, a Solr/Elasticsearch backend — are 1.2/1.5, the condition builder's, which reads the leaves this produces.)
- [x] 1.2 Build each leaf through the existing per-property condition code and wrap groups in `andX()` / `orX()` in `MagicSearchHandler`; one `andWhere()` for the tree. Verify: `tests/Unit/Db/MagicMapper/MagicSearchHandlerConditionGroupTest.php` on PostgreSQL and MariaDB, including an OR across a reference column and a scalar column, and a leaf compared with the same flat filter.
      > 2026-11-13: `MagicSearchHandler::applyConditionGroup()` (QueryBuilder path, called last in `applyContentFilters()`, so before RBAC and organisation scoping) and `conditionGroupSql()` (raw UNION path, appended in `buildObjectFilterConditionsSql()`). Each leaf goes through the flat filter's own code (`objectFieldFilterConditions()`, nested paths through `readableNestedPathTarget()`), groups through `andX()`/`orX()` or parenthesised AND/OR, the whole tree one condition. `tests/Unit/Db/MagicMapper/MagicSearchHandlerConditionGroupTest.php` asserts on a rendering builder that each leaf equals the flat filter's condition, on both paths. Not yet run against live PostgreSQL and MariaDB, and the reference-column OR is not covered there.
- [ ] 1.3 RBAC and organisation scoping stay outside the tree. Verify: the same test with a user scoped to one organisation and an OR that names an `isnull` leaf.
- [ ] 1.4 `countSearchObjects()` and facets apply the tree. Verify: the same test compares list length, total and one facet bucket.
- [ ] 1.5 Solr and Elasticsearch backends refuse `_where` with 400; unknown and encrypted properties refuse with the node path. Verify: `tests/Unit/Service/Object/ConditionGroupRefusalTest.php`.
      > 2026-11-13, partly: an undeclared, encrypted or `@self.` leaf is refused with its node path on both filter paths, and `ConditionGroupMiddleware` answers any `ConditionGroupException` with 400 `{error, path}`. The Solr and Elasticsearch refusal is still open, and `@self.` leaves are refused rather than supported.
- [ ] 1.6 The capabilities endpoint lists `listQuery.where`. Verify: its controller test.

## 2. Proof and docs

- [ ] 2.1 Newman: `GET /api/objects/{register}/{schema}?_where=...` returns the first scenario's 7 rows and total; a bad leaf returns 400 with its path.
- [ ] 2.2 Document `_where` in `docs/` beside the filter table and in the OAS export (`OasService`), as a reserved parameter.

Acceptance:
- Every existing flat filter returns exactly what it returned before.
- pipelinq change `segment-groups-its-rules` task 3.2 can count a segment with one query.
