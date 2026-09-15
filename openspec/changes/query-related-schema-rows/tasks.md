# Tasks: query-related-schema-rows

## 1. Parser and SQL

- [ ] 1.1 Parse `_related[<schema>][<fk>]` blocks with field operators.
- [ ] 1.2 `EXISTS` subquery on Postgres and MariaDB with RBAC predicate
      inside.
- [ ] 1.3 Two blocks on one schema produce two clauses.

## 2. Facets and backend

- [ ] 2.1 Facets over a related field.
- [ ] 2.2 Solr `{!join}` translation with database fallback and response
      attribution.

## 3. Tests

- [ ] 3.1 Unit tests on both databases for the clause shape and RBAC.
- [ ] 3.2 `tests/e2e/ci/query-related-schema-rows.spec.ts`: seed a case with
      a caseProperty row, filter the case list on the row's value, see the
      case.
