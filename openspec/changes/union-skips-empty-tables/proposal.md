# A plain text search across many tables leaves out the tables without rows

## What was wrong

A global text search (`GET /api/objects?_search=...`, the unified-search
provider) builds one UNION arm for every magic table the reader may search.
On a fresh install with the bundled registers that is 43 tables, of which 31
hold no row at all. Each arm costs about 0.5 ms of PHP to build (its WHERE,
its organisation and RBAC predicates, a NULL for every property column of
every other schema) and about the same again for the database to plan and
run, whatever it finds. The statement was 465 KB of SQL with 13339 `NULL AS`
padding columns. With openregister #11389 the search still answered in
~135 ms and the unified search in ~150 ms, over the 100 ms API rule (ADR-124).

## What changes

- Before it builds the arms, a plain text search asks which of its tables hold
  at least one row: one `SELECT <index> FROM (SELECT <index> FROM <table>
  LIMIT 1)` probe per table, all joined with `UNION ALL` into one statement
  (chunks of 50 tables). On the reference stack that statement takes ~0.6 ms
  for 43 tables.
- Only the tables that hold a row get an arm, in the search statement and in
  the union count. A table without rows could not have matched, so the page
  and the total are unchanged. With no table holding a row the search answers
  an empty page with total 0 without a search statement.
- Only tables this request already knows to exist (from the batched catalogue
  read) are probed. A pair whose table is not known keeps its arm and its
  create-on-demand path. A failed probe keeps every arm.
- Which columns order as text is still decided over every schema of the search.
- Only plain text searches are narrowed: a query whose keys are all in
  `@self`, `_search`, `_limit`, `_offset`, `_page`, `_rbac`, `_multitenancy`,
  `_multitenancy_explicit`, `_snippet`. Any other key (a property filter,
  `_order`, `_where`, a lens) keeps every arm, because an arm builder can
  refuse such a query for one schema (an unreadable or explicit-reveal
  property), and that refusal must not depend on whether the schema's table
  happens to hold rows.

## Out of scope

- The union statement's width: every arm still selects the superset of all
  property columns of the searched schemas.
- The search-term dictionary load (~10 ms).
