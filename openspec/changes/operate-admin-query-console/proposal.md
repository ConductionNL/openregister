---
kind: code
---

# Proposal: operate-admin-query-console

## Summary

A functional administrator opens a query console on the operations page, writes an ad hoc question about the data, runs it, and downloads the answer as CSV or JSON. The question is written in GraphQL, the query language Open Register already serves, not in SQL. It can only read, it stops at 1,000 rows and 30 seconds, and it sees exactly what the administrator's own API calls may see. Every run and every download is on the audit trail with who ran what.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | op-sql-console | Run an ad hoc SQL query against the data from the admin screen and download the result. | partial |

**op-sql-console** (openregister's matrix)

- Demand: changelog, https://github.com/pocketbase/pocketbase/releases/tag/v0.39.0 (the row's origin).
- Competitor yes cells:
  - pocketbase (PocketBase), no evidence URL, source path cited: "source read at v0.40.4, not driven: POST /api/sql superuser only (pocketbase:apis/sql.go:24-25), max 1000 rows and 3 minute timeout (:17-18); dashboard page pocketbase:ui/src/settings/sql/pageSQLConsole.js routed at pocketbase:ui/src/router.js:174, with CSV download at pageSQLConsole.js:167-176".

The row asks for SQL. This change answers the need behind it, an administrator's bounded ad hoc question with a downloadable answer, in GraphQL, and design D-1 says why SQL is refused.

## Why

The query language exists, the console an administrator needs does not.

- `POST /api/graphql` (`appinfo/routes.php:1993`) runs a query through `GraphQLService::execute()` (`lib/Service/GraphQL/GraphQLService.php:100`), with complexity caps (`lib/Service/GraphQL/QueryComplexityAnalyzer.php:41-42`, depth 10 and cost 10,000) and RBAC and multitenancy on every list (`lib/Service/GraphQL/GraphQLResolver.php:186-219`). It supports ad hoc `groupBy` (`openspec/specs/graphql-api/spec.md:610`).
- `GET /api/graphql/explorer` (`appinfo/routes.php:1994`) serves GraphiQL from `unpkg.com` with a relaxed CSP (`lib/Controller/GraphQLController.php:155-200`). It is open to every signed-in user, it accepts mutations, it records nothing about a query that only reads (the audit requirement covers mutations, `openspec/specs/graphql-api/spec.md:315-317`), and it has no way to save the result as a file.
- The operations page (`src/views/operations/OperationsConsoleIndex.vue`, sections at `:52-388`) shows jobs, runs and maintenance. It has no query.
- Reports can run a GraphQL data source (`src/store/modules/reports.js:56`), but a report is a saved widget, not an ad hoc question with a download.

## What changes

- A "Query" section on the operations console with a query editor, a variables editor, a run button, a result table and "Download CSV" and "Download JSON".
- `POST /api/operations/query` runs a GraphQL query for an administrator: queries only (a mutation or subscription is refused before it runs), at most 1,000 rows per list, a 30 second deadline, the existing complexity caps.
- `POST /api/operations/query/export?format=csv|json` runs the same query and returns the first list in the result as a file.
- Each run writes a `query.run` audit row and each download a `query.export` row: the administrator, the query text, its hash, the variable names without their values, the row count, the duration and the outcome.
- The GraphiQL explorer stays for developers, unchanged.

## Consumers

- No fleet app calls the console. It is an administrator's tool on Open Register's own operations page, and every leaf app's records are reachable through it because they live in Open Register.

## ADRs

- openregister ADR-002 (organisation tenancy): the console runs under the administrator's session and active organisation through the same resolvers as the API, and never widens what that administrator can read.
- openregister ADR-003 (immutable audit trail): runs and downloads are audit rows on the chain.
- openregister ADR-001 (information architecture): the console lives on the existing operations page; no new menu item.
- hydra ADR-005 (security): administrator-only, read-only, no values of variables in the audit row.
- hydra ADR-058 (bounded object queries): 1,000 rows and 30 seconds are hard caps, not defaults.
- hydra ADR-004 (frontend): the editor uses `vue-codemirror6`, already a dependency (`package.json:89`), and loads nothing from a CDN.

## Impact

- New capability `admin-query-console`.
- Affected code: a new `lib/Controller/OperationsQueryController.php`, a new `lib/Service/GraphQL/AdminQueryRunner.php`, `lib/Service/GraphQL/GraphQLService.php` (an entry that takes a deadline and a row cap), `lib/Service/GraphQL/GraphQLResolver.php` (honour them), `appinfo/routes.php`, `src/views/operations/OperationsConsoleIndex.vue`, a new `src/views/operations/QueryConsoleSection.vue`.
- Backwards compatible. `POST /api/graphql` and the explorer behave as today.
- Size: M.

## Out of scope

- SQL. Refused on purpose, see design D-1.
- Saved and shared queries. A query worth keeping becomes a report widget (`src/views/reports/`), which already stores a GraphQL data source.
- Scheduled queries mailed as files. `scheduled-report-jobs` and its email delivery do that for exports.
