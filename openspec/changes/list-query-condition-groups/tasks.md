# Tasks: list-query-condition-groups

## 1. The parameter

- [ ] 1.1 Parse `_where` (query string JSON, POST body object, `$query['_where']`, `$config['where']`) into a validated tree with node paths; refuse per design D-3. Verify: `tests/Unit/Db/MagicMapper/ConditionGroupParserTest.php`.
- [ ] 1.2 Build each leaf through the existing per-property condition code and wrap groups in `andX()` / `orX()` in `MagicSearchHandler`; one `andWhere()` for the tree. Verify: `tests/Unit/Db/MagicMapper/MagicSearchHandlerConditionGroupTest.php` on PostgreSQL and MariaDB, including an OR across a reference column and a scalar column, and a leaf compared with the same flat filter.
- [ ] 1.3 RBAC and organisation scoping stay outside the tree. Verify: the same test with a user scoped to one organisation and an OR that names an `isnull` leaf.
- [ ] 1.4 `countSearchObjects()` and facets apply the tree. Verify: the same test compares list length, total and one facet bucket.
- [ ] 1.5 Solr and Elasticsearch backends refuse `_where` with 400; unknown and encrypted properties refuse with the node path. Verify: `tests/Unit/Service/Object/ConditionGroupRefusalTest.php`.
- [ ] 1.6 The capabilities endpoint lists `listQuery.where`. Verify: its controller test.

## 2. Proof and docs

- [ ] 2.1 Newman: `GET /api/objects/{register}/{schema}?_where=...` returns the first scenario's 7 rows and total; a bad leaf returns 400 with its path.
- [ ] 2.2 Document `_where` in `docs/` beside the filter table and in the OAS export (`OasService`), as a reserved parameter.

Acceptance:
- Every existing flat filter returns exactly what it returned before.
- pipelinq change `segment-groups-its-rules` task 3.2 can count a segment with one query.
