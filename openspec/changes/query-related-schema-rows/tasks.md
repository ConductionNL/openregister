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
- [x] 1.2 `EXISTS` subquery on Postgres and MariaDB with RBAC predicate
      inside.
  - `RelatedRowExistsClause`. The parser hands it `RelatedRowFilter` objects;
    nothing here parses.
  - THE ACCESS PREDICATE IS A REQUIRED ARGUMENT AND RENDERING WITHOUT ONE
    THROWS. The class does not invent one either: a second evaluator of the
    access question disagrees with the first within a week, and the one that
    ends up wider is the one that discloses.
  - EXERCISED ON POSTGRES ONLY, against the live `conduction-postgres`
    container with seeded rows, which is what found the two defects below.
    MariaDB is written for and NOT exercised: this machine has no MariaDB
    container and no `mysql` or `mariadb` client. Recorded in quality-debt.
  - 🔴 TWO DEFECTS FOUND BY RUNNING THE SQL, NEITHER READABLE OFF THE RENDERER.
    `->>` yields TEXT, so `value gte 100` matched a stored `50` under
    lexicographic ordering, wrong in the direction that returns MORE rows. The
    first fix guarded both sides with a `CASE`, which Postgres defeats by
    folding the constant cast at PLAN time before any `WHEN` runs, so a date
    bound raised outright. The bound side is now decided in PHP, where its
    value is known, and never cast in SQL.
- [x] 1.3 Two blocks on one schema produce two clauses.
  - `renderAll()` prefixes each clause's placeholders by POSITION. Keyed on the
    schema instead, the second block would overwrite the first's bindings, the
    query would still run, and it would answer a question nobody asked without
    failing.

## 2. Facets and backend

- [ ] 2.1 Facets over a related field.
- [ ] 2.2 Solr `{!join}` translation with database fallback and response
      attribution.

## 3. Tests

- [~] 3.1 Unit tests on both databases for the clause shape and RBAC.
  - `RelatedRowExistsClauseTest`, 13 tests, covering both engines' rendering
    and the access predicate. PARTIAL BY DESIGN: the suite has no database, so
    it asserts the CONSEQUENCE of the live findings rather than the SQL string.
    A renderer test written before running the SQL would have asserted the
    defect and gone green, which is why the live evidence sits in the PR body.
- [ ] 3.2 `tests/e2e/ci/query-related-schema-rows.spec.ts`: seed a case with
      a caseProperty row, filter the case list on the row's value, see the
      case.
