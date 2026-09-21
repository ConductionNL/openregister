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

- [x] 2.1 Facets over a related field.
  - 🔴 THE DEFECT WAS IN MY OWN #3923 WIRING, AND ONLY READING THE FACET CALLER
    SHOWED IT. `MagicFacetHandler` calls `buildFilteredQuery()` WITHOUT a
    register id, and I had passed the bare `$registerId` parameter instead of
    the `registerIdFromQuery()` fallback the access-control filter beside it
    uses. So a facet request carrying `_related` was refused outright even when
    the query itself named the register.
  - The worse half is what happens once it is not refused: a facet count that
    ignores a filter the list honours describes every case in the register
    beside a narrowed list. Nothing looks broken. The numbers are answers to a
    different question and there is nothing on screen that could say so. Both
    facet paths, terms and date histogram, now carry the register.
  - Pinned by a DERIVED test that reads the handler's own source and requires
    every `buildFilteredQuery(` call to name a register, so a facet path added
    later is covered the day it is written. The defect was created by exactly
    the opposite: a call site that predated the filter and was never revisited.
    It carries a control, because a renamed method would otherwise make it pass
    by finding nothing.
- [~] 2.2 Solr `{!join}` translation with database fallback and response
      attribution.
  - 🔴 OBSOLETE AS WRITTEN, AND THE SOURCE IS WHY, NOT THIS PROPOSAL. There is
    no Solr left to translate for. `remove-solr-and-publishing` deleted the
    whole search-Index abstraction, and the tree agrees: `find lib src -iname
    '*solr*'` returns ZERO files, there is no `SearchBackendInterface` and no
    `IndexService`, and `grep -rn '{!join' lib src` finds nothing. That change
    also says of this very capability: "`zoeken-filteren`: full-text/filter
    search requirements drop the Solr/Elasticsearch backend branch; the
    PostgreSQL Magic-Tables path becomes the sole search backend."
  - So there is no second engine to translate to, and no fallback to attribute
    a response to. The DB path is not the fallback any more, it is the path.
    Building a `{!join}` translator now would add a caller-less translator for a
    subsystem that was deliberately deleted.
  - WHAT SURVIVES OF THE INTENT is the storage split, and that is built:
    `RelatedRowExistsClause` renders against BOTH storages. See 2.3.
  - One leftover reported, not swept, because it belongs to that change and not
    this one: `elasticsearch/elasticsearch` is still required in
    `composer.json` though nothing in `lib/` or `src/` imports it. The
    `/api/objects/*/vectorize*` and `/api/settings/search/semantic` routes also
    survive, but those are NOT orphans: their controller methods exist and they
    run on pgvector, not on the removed backends.

- [x] 2.3 The clause renders for the storage the search path actually uses.
  - 🔴 I HAD THE WRONG TABLE, AND ONLY COUNTING THE LIVE ONES SHOWED IT. The
    first version of the clause rendered `object ->> 'field'` against
    `oc_openregister_objects`, and I verified it against real rows I seeded
    there. But `MagicMapper` resolves
    `oc_openregister_table_<register>_<schema>` for every read and has no
    fallback to the objects table. On this instance there are 1,340 such tables
    and `oc_openregister_objects` holds ZERO rows. The clause was correct SQL
    against a table nothing reads.
  - My earlier measurement missed this because I searched for the prefixes
    `oc_or_%` and `%_magic%` and found nothing, and read that as "no magic
    tables on this rig". The prefix is `openregister_table_`. Searching for the
    name I expected instead of the name the code defines turned a populated
    schema into an empty one.
  - A magic table's properties are REAL TYPED COLUMNS: `days_remaining
    numeric`, `due_at timestamp`, `found integer`. So the numeric-versus-text
    machinery the JSON shape needs is not merely unneeded there, it is
    HARMFUL: applying the regex guard to an integer column is a type error, and
    casting one breaks an ordering the column type already gets right.
    Measured live with the discriminating value 6: the column comparison
    answers 4 parents, the same query over `found::text` answers 0.
  - Metadata columns are underscore-prefixed on a magic table (`_uuid`,
    `_deleted`, `_owner`), which is exactly why a schema may carry its own
    property named `deleted`. And a magic table IS one schema, so the clause
    omits the schema condition there rather than comparing `_schema`.
  - Exercised against the live `oc_openregister_table_29_1108` with its real
    rows. Still not wired into `MagicSearchHandler`: see 2.4.

- [x] 2.4 Wire the clause into `MagicSearchHandler`.
  - BUILT. `RelatedRowQueryApplier` is the caller, invoked from
    `MagicSearchHandler::buildFilteredQuery()` after the lens and search
    filters. It does nothing at all unless the query carries `_related`, so
    every existing call site is unaffected.
  - THE PREREQUISITE WAS SMALLER THAN I SAID, BECAUSE I HAD NAMED THE WRONG
    METHOD. `applyRbacFilters()` does hardcode `t`, but it is the QueryBuilder
    emitter and not the one a subquery needs. `buildRbacConditionsSql()`
    already existed beside it for UNION members, already emitted UNQUALIFIED
    column names, and already threaded the column name into two of its three
    emitters. So the change is a `columnPrefix` parameter through that SQL
    path, defaulting to `''`, and every existing caller is untouched: 1,751 Db
    unit tests pass unchanged.
  - 🔴 WHY THE ALIAS CANNOT BE LEFT OFF, WHICH IS THE WHOLE REASON FOR
    `buildRbacPredicateForAlias()`. Inside
    `EXISTS (SELECT 1 FROM <related> r0 WHERE ...)` an unqualified `_owner`
    still parses and binds to the innermost FROM, so it looks right. It is
    right by accident: the moment the related table lacks the column, SQL
    resolves the name against the OUTER query and the access check passes by
    testing the wrong row. Nothing errors and nothing logs. It fails open.
  - AND THE TWO DEGENERATE ANSWERS ARE SAID OUT LOUD. An empty predicate AND-ed
    into a WHERE is not "no opinion", it is "admit everything", so deny-all
    returns `FALSE` and an admin bypass returns `TRUE`. Never an empty string.
  - THE ACCESS PREDICATE IS THE RELATED SCHEMA'S, NOT THE OUTER ONE'S. The two
    schemas carry different authorization blocks, and reusing the outer query's
    predicate would decide who may read case properties by asking who may read
    cases.
  - REFUSES RATHER THAN DROPS, ALL THE WAY DOWN. The parser throws on a
    malformed block; the applier adds the two refusals only a live lookup can
    make, a schema nobody can name and a slug two schemas answer to. Both end
    the query rather than joining `$ignoredFilters`, because a dropped
    `_related` block answers the unfiltered set and the response looks
    identical to a correctly filtered one.

## 3. Tests

- [x] 3.1 Unit tests on both databases for the clause shape and RBAC.
  - `RelatedRowExistsClauseTest`, 13 tests, covering both engines' rendering
    and the access predicate. PARTIAL BY DESIGN: the suite has no database, so
    it asserts the CONSEQUENCE of the live findings rather than the SQL string.
    A renderer test written before running the SQL would have asserted the
    defect and gone green, which is why the live evidence sits in the PR body.
- [x] 3.2 `tests/e2e/ci/query-related-schema-rows.spec.ts`: seed a case with
      a caseProperty row, filter the case list on the row's value, see the
      case.
  - WRITTEN AND TAGGED, NOT RUN. There is no Playwright runner on this build
    host, which is the standing arrangement for this phase. Said plainly rather
    than implied.
  - IT ASSERTS THE NEGATIVE, WITH A CONTROL. A dropped filter answers the
    unfiltered set, which looks like a working filter as long as you only check
    that the matching case is present. So every assertion pairs "case A is
    there" with "case B is NOT", the unfiltered request is asserted to return
    BOTH, and the opposite boundary (`lt 100`) is asserted to return case B, so
    "case B is absent" cannot pass because case B is absent from everything.
  - THE TWO VALUES ARE CHOSEN, NOT ARBITRARY: 150 and 50. Under text ordering
    '50' >= '100' is TRUE, so a `gte 100` filter returning case B is the exact
    live symptom of the defect this change carried, and returning only case A is
    the proof. The `value` property is declared as a NUMBER for the same reason:
    a string column would hide it again.
  - It also covers the refusal of a misspelt schema and the facet-count
    agreement from 2.1.
