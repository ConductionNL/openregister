# admin-query-console

## ADDED Requirements

### Requirement: An administrator runs a bounded read-only query

Open Register SHALL offer administrators `POST /api/operations/query`, which runs a GraphQL query through the same resolvers, RBAC and organisation scoping as `POST /api/graphql`, under the administrator's own session. It SHALL refuse a document containing a mutation or a subscription with 400 `READ_ONLY` before anything executes. It SHALL return at most 1,000 rows across the lists in a result and SHALL stop resolving after 30 seconds with a `QUERY_TIMEOUT` error. The response SHALL report the rows returned, whether a cap truncated the result, and the duration. A user who is not an administrator SHALL get 403.

#### Scenario: an administrator counts open cases per month

- **GIVEN** register `zaken` with 40,000 cases
- **WHEN** a functional administrator opens the operations page, writes a `zaken` query grouped by month of `startdatum` in the "Query" section and presses "Run"
- **THEN** the section shows one row per month with its count, the number of rows, and the duration
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}

#### Scenario: a mutation is refused before it runs

- **GIVEN** a functional administrator on the operations page
- **WHEN** they run `mutation { deleteZaak(id: "00000000-0000-0000-0000-000000000000") { id } }` in the "Query" section
- **THEN** the response is 400 with code `READ_ONLY`
- **AND** no object is changed and no resolver ran
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}

#### Scenario: a large result stops at the cap

- **GIVEN** a query that would list 40,000 cases
- **WHEN** a functional administrator runs it
- **THEN** 1,000 rows are returned, `truncated` is true, and the section says the result was cut at 1,000 rows
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}

#### Scenario: a caseworker cannot use the console

- **GIVEN** a signed-in caseworker who is not an administrator
- **WHEN** they call `POST /api/operations/query`
- **THEN** the response is 403
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}

### Requirement: The result downloads as CSV or JSON

`POST /api/operations/query/export` with `format` `csv` or `json` SHALL run the query under the same rules and SHALL return the first list in the result as a file, one row per item with nested values flattened to dot-path columns. The CSV SHALL be UTF-8 with a byte order mark and SHALL prefix a cell that starts with `=`, `+`, `-` or `@` with a single quote. A result with no list SHALL answer 422.

#### Scenario: an administrator downloads the monthly counts

- **GIVEN** the grouped query from the earlier scenario
- **WHEN** the administrator presses "Download CSV"
- **THEN** the browser saves `query-{date}.csv` with a header row and one line per month
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}

#### Scenario: a formula in the data stays text

- **GIVEN** a case whose `omschrijving` is `=HYPERLINK("http://example.org")`
- **WHEN** an administrator downloads a query result that includes it as CSV
- **THEN** the cell reads `'=HYPERLINK("http://example.org")`
- @e2e exclude {specified only; task 2.1 covers it in tests/Unit/Controller/OperationsQueryControllerTest.php}

### Requirement: Every run and download is on the audit trail

Each console run SHALL write a `query.run` audit row and each download a `query.export` row on the hash-chained audit trail, carrying the administrator, their active organisation, the query text capped at 8 KB and its sha256, the operation name, the variable names without their values, the rows returned, whether the result was truncated, the duration, the outcome and, for a download, the format.

#### Scenario: an auditor finds who queried personal data

- **GIVEN** a functional administrator ran a query with variable `bsn` set to a citizen's number and downloaded the result
- **WHEN** an auditor reads `GET /api/audit-trails?action=query.export`
- **THEN** the row names the administrator, the organisation, the query text and the variable name `bsn`
- **AND** the citizen's number does not appear in the row
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/query-console.spec.ts}
