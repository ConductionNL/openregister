## 1. Backend — AggregationRunner

- [ ] 1.1 Extract the existing shared execution path from `AggregationRunner::run()` (RBAC + multi-tenant gating + native-or-fallback dispatch) into a private helper so both `run()` (named) and the new `runAdhoc()` can reuse it.
- [ ] 1.2 Add public method `AggregationRunner::runAdhoc(Register $register, Schema $schema, AggregationQuery $query): array` returning the same `{ value | groups, backend, cached? }` shape as `run()`.
- [ ] 1.3 Extend `AggregationRunner::tryNativeAggregation()` to honour `AggregationQuery::dateBucket` — emit `SELECT date_trunc(?, "$field")::text AS bucket, <metric> AS agg FROM <table> WHERE <rbac> AND "$field" >= ? AND "$field" < ? GROUP BY bucket ORDER BY bucket`. Bind `gap`, `start`, `end` first; sanitise `field` via `sanitizeColumnName()`.
- [ ] 1.4 Add a PHP fallback bucketer for non-Postgres databases — pull RBAC-filtered rows, bucket in PHP via a `date_trunc` polyfill keyed on the gap vocabulary, return the same response shape with `backend: "php-fallback"`.
- [ ] 1.5 Coerce bucket keys to ISO-8601-UTC `Y-m-d\TH:i:s\Z` strings before returning so the Postgres path and the PHP-fallback path emit identical wire formats.
- [ ] 1.6 Confirm `runAdhoc()` rejects `null` active organisation (fail-closed) and `false` on `PermissionHandler::canRead($schema)` with the existing `NotAuthorizedException`.

## 2. Backend — REST Controller

- [ ] 2.1 Add `AggregationController::timeseries(string $register, string $schema): JSONResponse` action.
- [ ] 2.2 Parse + validate query params (`field`, `interval`, `from`, `to`, `metric`, `metricField`, `filter[...]`) per the `aggregation-api` spec; on validation failure return `400` with `{ error: <message> }`.
- [ ] 2.3 Validate `field` and `metricField` against the schema's declared property list (`Schema::getProperties()`) plus the magic-table metadata allow-list (`_created`, `_updated`, `_deleted_at`); reject anything else with `400`.
- [ ] 2.4 Validate that sub-day intervals (`MINUTE`, `HOUR`) are only used with `format: date-time` fields; reject otherwise with `400`.
- [ ] 2.5 Build an `AggregationQuery` via `AggregationQuery::create()` (which already validates the dateBucket gap vocabulary) and call `AggregationRunner::runAdhoc()`.
- [ ] 2.6 Translate `NotAuthorizedException` to HTTP `403`; translate `RuntimeException` (register/schema not found) to `404`.
- [ ] 2.7 Register the new route in `appinfo/routes.php` as `['name' => 'aggregation#timeseries', 'url' => '/api/objects/aggregations/{register}/{schema}/timeseries', 'verb' => 'GET']`. Place it BEFORE the `{name}` wildcard route so Symfony routing dispatches `timeseries` to the dedicated action (memory: route ordering — specific before wildcard).

## 3. Backend — GraphQL

- [ ] 3.1 Add `GroupByInput`, `TimeInterval` (enum), `AggregationMetric` (enum), and `GroupBucket` (object type) declarations to `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php`. Cache them on the handler so they are constructed once per request.
- [ ] 3.2 Extend `TypeMapperHandler::getListArgs()` to include `groupBy: GroupByInput`.
- [ ] 3.3 Extend `TypeMapperHandler::getConnectionType()` to add `groups: [GroupBucket!]` as a nullable field on every `<Schema>Connection` type.
- [ ] 3.4 In `GraphQLResolver::resolveList()`, when `args.groupBy` is present, validate the same field allow-list as the REST path, build an `AggregationQuery`, call `AggregationRunner::runAdhoc()`, and attach the `groups` array to the connection result. Surface validation errors as GraphQL field-errors (not exceptions).
- [ ] 3.5 When `args.groupBy` is absent, return `null` for the `groups` field (do not run an empty aggregation).

## 4. Tests

- [ ] 4.1 Add `tests/Unit/Service/Aggregation/AggregationRunnerDateBucketTest.php` — unit-test the Postgres date_trunc SQL generation, the PHP fallback path, ISO key coercion, sub-day-on-date-only-field rejection, and the field allow-list.
- [ ] 4.2 Add `tests/Unit/Controller/AggregationControllerTimeseriesTest.php` — controller-level tests covering each `400` validation path, the `403`/`404` translations, and a happy-path mocked-runner response.
- [ ] 4.3 Add `tests/Unit/Service/GraphQL/SchemaGeneratorGroupByTest.php` — assert the new types, args, and connection-field declarations.
- [ ] 4.4 Add `tests/Unit/Service/GraphQL/GraphQLResolverGroupByTest.php` — assert that `resolveList()` dispatches to the runner when `groupBy` is supplied, returns `null` when absent, and surfaces validation errors as GraphQL field-errors.
- [ ] 4.5 Add `tests/Integration/AggregationTimeseriesIntegrationTest.php` — end-to-end through Postgres for one categorical and one DAY-bucketed query; assert wire-shape parity between REST and GraphQL (same `groups` array for equivalent inputs).
- [ ] 4.6 Assert the multi-tenant predicate is honoured: insert rows for two tenants, switch active org, run the query, assert only the active tenant's rows are counted.

## 5. Quality gates

- [ ] 5.1 PHPCS strict — `composer check:strict` clean on touched files; fix any pre-existing warnings the gate flags on those files.
- [ ] 5.2 PHPMD strict — clean on touched files.
- [ ] 5.3 Psalm strict — clean; add narrow `@psalm-*` annotations only where the GraphQL closure surface requires.
- [ ] 5.4 PHPStan level 8 — clean on touched files.
- [ ] 5.5 PHPUnit — full unit + integration suites green.

## 6. Documentation

- [ ] 6.1 Update `docs/annotations/x-openregister-aggregations.md` with a "Runtime ad-hoc primitive" section covering REST (`/api/objects/aggregations/{register}/{schema}/timeseries`) + GraphQL (`groupBy` arg on list queries) including the response shape, validation rules, and recommended btree indexes on bucketed fields.
- [ ] 6.2 Add the change to `openspec/specs/aggregation-api/spec.md`'s OpenSpec-changes list (created at archive time) and to `openspec/specs/graphql-api/spec.md`'s list. Move the `graphql-api` spec status back to `in-progress`.
- [ ] 6.3 Update `openspec/platform-capabilities.md` to mention the ad-hoc aggregation primitive alongside the existing named-annotation row.
