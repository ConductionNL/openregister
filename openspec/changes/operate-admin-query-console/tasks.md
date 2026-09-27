# Tasks: operate-admin-query-console

## 1. Runner

- [ ] 1.1 Add `lib/Service/GraphQL/AdminQueryRunner.php` and `GraphQLService::executeBounded()`: refuse a document with any mutation or subscription before execution, pass `deadline` and `rowCap` in the context, and return `rows`, `truncated` and `durationMs` in `extensions` (design D-2). Verify: `tests/Unit/Service/GraphQL/AdminQueryRunnerTest.php` covers a refused mutation that never reaches the schema, a query truncated at 1,000 rows, and a deadline that has passed.
- [ ] 1.2 Make `GraphQLResolver::resolveList()` clamp `first` to the remaining `rowCap` and throw `QUERY_TIMEOUT` once `deadline` has passed, only when those keys are in the context. Verify: `tests/Unit/Service/GraphQL/GraphQLResolverBoundedTest.php`; the existing GraphQL tests pass unchanged.

## 2. Endpoints and audit

- [ ] 2.1 Add `OperationsQueryController::run()` and `export()` with routes `POST /api/operations/query` and `POST /api/operations/query/export`, administrator-only, the export flattening the first list to CSV (formula-safe, UTF-8 with BOM) or JSON (design D-3). Verify: `tests/Unit/Controller/OperationsQueryControllerTest.php` covers 403 for a non-administrator, 400 `READ_ONLY`, 422 for a result with no list, and a cell `=SUM(A1)` written as `'=SUM(A1)`; hydra route-auth and admin-router gates pass.
- [ ] 2.2 Write `query.run` and `query.export` audit rows with the fields in design D-4 and no variable values. Verify: `tests/Unit/Service/GraphQL/AdminQueryAuditTest.php` asserts a variable `bsn` appears by name only and the row lands through `insertAuditTrails()`.

## 3. Page

- [ ] 3.1 Add `src/views/operations/QueryConsoleSection.vue` to `OperationsConsoleIndex.vue`: query and variables editors on `vue-codemirror6`, run, result table with row count, truncation and duration, raw JSON toggle, and the two downloads (design D-5). Verify: `src/views/operations/QueryConsoleSection.spec.js` renders a fixture result, shows the truncation notice at 1,000 rows, and calls the export route with `format=csv`.

## 4. Docs and end-to-end test

- [ ] 4.1 Document the console, the caps, the audit rows and why it is GraphQL and not SQL (design D-1) in a new `docs/features/query-console.md`, linked from `docs/sidebars.js`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 4.2 Add `tests/e2e/ci/query-console.spec.ts`: an administrator runs a `groupBy` query on the operations page, downloads CSV, and finds the `query.run` and `query.export` rows; a mutation is refused; a non-administrator gets 403 on `POST /api/operations/query`. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- No console request can write an object.
- No console result contains a record the same administrator could not read through `POST /api/graphql`.
