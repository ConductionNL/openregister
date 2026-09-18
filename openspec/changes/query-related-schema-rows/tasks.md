# Tasks: query-related-schema-rows

## 1. Parser and SQL

- [x] 1.1 Parse `_related[<schema>][<fk>]` blocks with field operators.
  - `RelatedRowFilterParser` and the `RelatedRowFilter` it returns. The parser
    is the only thing that understands the wire format; the query builders take
    the objects, so a builder never parses and a parser never builds SQL.
  - IT REFUSES RATHER THAN IGNORES. A misspelt block that is quietly dropped
    answers the UNFILTERED set: every case in the register, presented as the
    answer to a narrow question. Seven malformed shapes are asserted to throw.
  - THE SEMANTIC IS "ONE ROW THAT IS ALL OF THESE". Two conditions in a block
    are one existence clause; two NUMBERED blocks are two. Collapsing them
    would ask for one row that is two property definitions, which no row is, so
    the caller would get an empty list and no explanation. Mutation-checked.
  - The operators are the six the object query already accepts, read off
    `MariaDbSearchHandler::convertToSqlOperator()`, plus `in`. A test fails if
    the two lists drift, because a related-row filter must not become a second
    query language.
- [ ] 1.2 `EXISTS` subquery on Postgres and MariaDB with RBAC predicate
      inside.
  - NEXT, and it is the task that needs a live database of each kind. The
    parser above hands it `RelatedRowFilter` objects; nothing here parses.
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
