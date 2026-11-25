# Query Console

## Overview

The query console is an ad hoc question box for a functional administrator. You
open it on the **Operations** page, write a question about the data, run it, and
download the answer as CSV or JSON. The question is written in **GraphQL**, the
query language OpenRegister already serves — not in SQL.

Every run is bounded and recorded:

- it can only **read** — a query that writes is refused before it runs;
- it stops at **1,000 rows** and **30 seconds**, hard caps, not defaults;
- it sees exactly what **your own API calls** may see — the same RBAC,
  property-level RBAC, organisation scoping and field protection;
- every run and every download is on the **audit trail**, with who ran what.

## Running a query

`POST /api/operations/query` with a JSON body:

```json
{
  "query": "query($status: String) { zaken(status: $status) { edges { node { id status } } } }",
  "variables": { "status": "open" },
  "operationName": null
}
```

The response is the GraphQL result, with an `extensions` block reporting the
run:

```json
{
  "data": { "zaken": { "edges": [ /* … */ ] } },
  "extensions": { "rows": 42, "truncated": false, "durationMs": 118, "complexity": { /* … */ } }
}
```

- `rows` — the records returned across every list in the query.
- `truncated` — `true` when the 1,000-row cap cut a list short.
- `durationMs` — how long the run took.

A query that holds a `mutation` or a `subscription` is refused with **400** and
`{"error": "READ_ONLY"}`, before anything executes. A run that passes the
deadline between lists answers a `QUERY_TIMEOUT` error for the list it could not
reach.

## Downloading the answer

`POST /api/operations/query/export?format=csv|json` runs the same query and
returns the **first list** in the result as a file named
`query-{yyyyMMdd-HHmm}.{csv|json}`.

- A connection is read through `edges[].node`; a plain list is read as-is.
- Each row is flattened to columns by dot path; an array value is written as its
  JSON.
- The CSV is UTF-8 with a byte order mark so a spreadsheet opens it in the right
  encoding, and a cell that would read as a formula (starting `=`, `+`, `-` or
  `@`) is written with a leading apostrophe, so a downloaded value can never run
  as a formula.
- A result with no list answers **422** `{"error": "NO_LIST"}`: there is nothing
  tabular to download.

## The caps

| Cap | Value | Why |
| --- | --- | --- |
| Rows | 1,000 across the whole run | A download is one answer, not a stream. |
| Time | 30 seconds | The deadline is checked between lists; it ends further work after a slow statement, it does not interrupt one already running. The complexity cap is what keeps a single statement small. |
| Complexity / depth | the API's own caps | The console runs through the same service as `POST /api/graphql`. |

## What the audit records

Each run writes a `query.run` row and each download a `query.export` row, on the
hash chain. The row holds:

- the query text (capped at 8 KB) and its sha256;
- the operation name;
- the **names** of the variables — **never their values**, because a filter
  value is often a BSN or a name;
- the rows returned, whether a cap truncated the result, the duration, and the
  outcome (`ok`, `read-only-refused`, `timeout`, `error`);
- for a download, the format.

The row's organisation is your active organisation — the one the query ran in.

## Why GraphQL and not SQL

The capability this console answers is often phrased as "run a SQL query from
the admin screen". OpenRegister answers it in GraphQL, for reasons that come
from how the data is kept:

1. **SQL skips every rule the API applies.** A read through the API goes through
   RBAC, property-level RBAC, organisation scoping, field-level encryption and
   the reveal audit for protected fields. A SQL statement reads the columns
   underneath all of that. Being an administrator does not make those rules
   irrelevant — the reveal audit exists precisely to record who looked.
2. **The tables are an implementation detail.** Objects live in one table per
   register and schema. A SQL query written against them describes storage, not
   the register, and breaks on the next schema migration.
3. **The database is Nextcloud's.** A read-only SQL session on it also reads
   `oc_authtoken`, `oc_credentials` and every other app's tables. Restricting it
   by parsing the statement is not reliable: file-reading functions such as
   PostgreSQL's `pg_read_file` or MySQL's `LOAD_FILE` are expressions inside an
   ordinary `SELECT`.
4. **A read-only transaction does not bound cost.** It stops writes, not a cross
   join over two million rows. GraphQL's complexity and row caps do.
5. **GraphQL already has the limits.** Complexity and depth caps, ad hoc
   `groupBy` with time buckets, filters and facets matching the REST API.

The trade-off, stated plainly: an administrator cannot join to Nextcloud's own
tables or write arbitrary SQL functions. What they lose is exactly what the
rules above protect.

## Multi-tenancy

The console has no tenant switch. It runs under your session, so the resolvers
apply your active organisation and its children, the same as any API caller. To
ask about another organisation, switch your active organisation in the usual
place; the audit row of the run names the organisation it ran in.

## For developers

The GraphiQL explorer at `GET /api/graphql/explorer` is unchanged and remains
the place to explore the schema and build a query. The console is the
administrator's bounded, audited, downloadable counterpart on the operations
page.
