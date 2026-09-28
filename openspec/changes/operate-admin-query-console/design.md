# Design: operate-admin-query-console

Read at openregister development c53dd0685c.

## D-1: GraphQL, not SQL

The row names SQL. Open Register answers in GraphQL, for five reasons that come from how the data is kept:

1. **SQL skips every rule the API applies.** A read through the API goes through RBAC and property-level RBAC (`graphql-api` requirements at `openspec/specs/graphql-api/spec.md:247` and `:284`), organisation scoping per openregister ADR-002 (`lib/Service/GraphQL/GraphQLResolver.php:218-219` passes `_rbac: true, _multitenancy: true`), field-level encryption, and the reveal audit for protected fields (`lib/Middleware/RevealAuditMiddleware.php`). A SQL statement reads the columns underneath all of that. Being an administrator does not make those rules irrelevant: the reveal audit exists precisely to record who looked.
2. **The tables are an implementation detail.** Objects live in one table per register and schema, `openregister_table_{registerId}_{schemaId}` (`lib/Db/MagicMapper.php:182`, `:9794`). A saved SQL query breaks on the next schema migration, and a query written against it describes storage, not the register.
3. **The database is Nextcloud's.** A read-only SQL session on it also reads `oc_authtoken`, `oc_credentials` and every other app's tables. Restricting it by parsing the statement is not reliable: file-reading functions such as PostgreSQL's `pg_read_file` or MySQL's `LOAD_FILE` are expressions inside an ordinary `SELECT`.
4. **A read-only transaction does not bound cost.** It stops writes, not a cross join over two million rows.
5. **GraphQL already has the limits.** Complexity and depth caps (`lib/Service/GraphQL/QueryComplexityAnalyzer.php:41-42`), ad hoc `groupBy` with time buckets (`openspec/specs/graphql-api/spec.md:610`), filters and facets matching the REST API.

The trade-off is named in the docs: an administrator cannot join to Nextcloud's own tables or write arbitrary SQL functions. What they lose is exactly what the rules above protect.

## D-2: an administrator-only runner over the existing service

`lib/Service/GraphQL/AdminQueryRunner.php` takes the query text, variables and operation name, and:

1. parses the document and refuses it with 400 `READ_ONLY` if any operation in it is a `mutation` or a `subscription`, before anything executes;
2. calls a new `GraphQLService::executeBounded()` that is `execute()` (`lib/Service/GraphQL/GraphQLService.php:100-150`) plus a context carrying `deadline` (now plus 30 seconds) and `rowCap` (1,000);
3. returns the result with `extensions.rows` (rows returned across all lists), `extensions.truncated` (whether a cap cut a list) and `extensions.durationMs`.

`GraphQLResolver::resolveList()` (`lib/Service/GraphQL/GraphQLResolver.php:186`) reads the two context keys when present. It clamps `first` to what is left of `rowCap`, and before each list resolution it checks `deadline` and throws a `QUERY_TIMEOUT` error when it has passed. Honest limit: the deadline stops further work between resolutions; it does not interrupt a single database statement already running. The complexity cap is what keeps a single statement small.

`OperationsQueryController::run()` is routed as `POST /api/operations/query`. It carries no `#[NoAdminRequired]`, so Nextcloud refuses a non-administrator with 403, the same posture as the other `/api/operations/*` routes (`appinfo/routes.php:1314-1332`).

## D-3: the download

`OperationsQueryController::export()` is routed as `POST /api/operations/query/export`, with `format` `csv` or `json`. It runs the query through the same runner and takes the first list in the result, reading `edges[].node` for a connection or the array itself for a plain list. Rows are flattened to columns by dot path; an array value is written as its JSON. The CSV is RFC 4180, UTF-8 with a byte order mark so a spreadsheet opens it correctly, and a cell starting with `=`, `+`, `-` or `@` is prefixed with a single quote against formula injection. The response is a `DataDownloadResponse` named `query-{yyyyMMdd-HHmm}.{csv|json}`. A result with no list answers 422 naming that there is nothing tabular to download.

## D-4: every run is an audit fact

Each run writes one `AuditTrail` row through `AuditTrailMapper::insertAuditTrails()` with action `query.run`, and each download one with `query.export`. `changed` holds:

- the query text, capped at 8 KB, and its sha256;
- the operation name;
- the variable names, never their values, because a filter value is often a BSN or a name;
- rows returned, whether a cap truncated the result, the duration, and the outcome (`ok`, `read-only-refused`, `timeout`, `error`);
- for an export, the format.

The row's `organisationId` is the administrator's active organisation.

## D-5: the section on the operations page

`src/views/operations/QueryConsoleSection.vue` is a new section in `OperationsConsoleIndex.vue`, after "Maintenance" (`:338-388`). It has:

- a query editor and a variables editor, both `vue-codemirror6` (`package.json:89`), the variables editor in JSON mode with `@codemirror/lang-json` (`package.json:68`);
- a "Run" button, a result table of the first list with its row count, the truncation notice and the duration, and the raw JSON behind a toggle;
- "Download CSV" and "Download JSON";
- a line under the editor: "Runs as you, in your active organisation. Read only. At most 1,000 rows and 30 seconds. Each run is recorded."

The section renders only for administrators. Its two routes follow the posture the console's own controller states: no `#[NoAdminRequired]`, so the middleware refuses a non-administrator before the controller exists (`lib/Controller/OperationsConsoleController.php:10-15`).

## D-6: multitenancy

The console does not have a tenant switch. It runs under the administrator's session, so the resolvers apply the administrator's active organisation and its children, as the `graphql-api` requirement "Multi-tenancy MUST be enforced on all GraphQL operations" (`openspec/specs/graphql-api/spec.md:460-481`) describes for every caller. To ask about another organisation, an administrator switches their active organisation in the usual place, and the audit row of the run names the organisation it ran in.

## Declarative-vs-imperative decision

The question an administrator asks is declarative: a GraphQL document, including `groupBy` aggregations the `graphql-api` capability already declares. The console adds no aggregation of its own and no schema keyword. The runner around it (read-only refusal, caps, audit, download) is imperative, because it is request handling, not a rule on data.

## Risks

- **Security.** Administrator-only, queries only, no CDN, no variable values in the audit. The export guards against CSV formula injection (D-3).
- **Performance.** Hard caps of 1,000 rows and 30 seconds per run, on top of the depth and cost caps, and the existing GraphQL rate limit (`lib/Service/GraphQL/GraphQLService.php:102-103`). An export is one run, not a stream.
- **Honesty of the row.** The row says SQL. This change delivers the need in GraphQL, and the proposal says so rather than rating it as SQL.
