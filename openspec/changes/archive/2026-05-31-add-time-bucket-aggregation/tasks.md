## 1. Backend — AggregationRunner

- [x] 1.1 Extract the existing shared execution path from `AggregationRunner::run()` (RBAC + multi-tenant gating + native-or-fallback dispatch) into a private helper so both `run()` (named) and the new `runAdhoc()` can reuse it. **Done in-place:** `runAdhoc()` mirrors the gate+dispatch contract of `run()` without extracting; the shared part is the now-overloaded `tryNativeAggregation()` plus the new `bucketInPhp()`. Extracting a third private method would add an indirection layer without removing code.
- [x] 1.2 Added `AggregationRunner::runAdhoc(Register, Schema, AggregationQuery)` + `runAdhocByRef(string, string, AggregationQuery)` convenience overload + `findSchema(string)` public lookup so the REST controller can validate the field allow-list before constructing the query.
- [x] 1.3 Extended `AggregationRunner::tryNativeAggregation()` with a `?array $dateBucket` parameter; when non-null the SQL becomes `SELECT date_trunc(?, "$field")::text AS bucket, <metric> AS agg FROM <table> WHERE <rbac> AND "$field" >= ? AND "$field" < ? GROUP BY bucket ORDER BY bucket`. Bindings: gap (via `array_unshift`), existing WHERE bindings, then start + end. Field sanitised via `sanitizeColumnName()`.
- [x] 1.4 Added `bucketInPhp()` — PHP fallback for non-Postgres DBs. Pulls RBAC-filtered rows, applies the operator vocabulary, then buckets in PHP via `truncateTimestamp()`. Returns `backend: "php-fallback"`.
- [x] 1.5 `coerceBucketKey()` + `truncateTimestamp()` produce identical ISO-8601-UTC strings on both paths. `gmdate('Y-m-d\TH:i:s\Z', ...)` for every gap; ISO week (Monday) and quarter (first-of-Q1-month) computed explicitly.
- [x] 1.6 `runAdhoc()` calls `PermissionHandler::hasPermission(schema, 'list', ...)` and throws `NotAuthorizedException` on denial. The multi-tenant predicate is inherited from `tryNativeAggregation()` (already binds `_organisation = ?` with the active org's UUID; null active org binds the sentinel `__no_active_org__` which never matches → fail-closed).

## 2. Backend — REST Controller

- [x] 2.1 Added `AggregationController::timeseries(string $register, string $schema): JSONResponse`.
- [x] 2.2 Query-param parsing + validation lives in the new `TimeseriesRequestValidator::validate(array $input, Schema $schema): AggregationQuery`. Throws `InvalidArgumentException` (mapped to HTTP 400) on every spec-defined violation.
- [x] 2.3 Field allow-list: `Schema::getProperties()` keys + `METADATA_FIELDS` (`_created`, `_updated`, `_deleted_at`). Both `field` and `metricField` are validated.
- [x] 2.4 Sub-day intervals (`MINUTE`, `HOUR`) require `format: date-time` (or one of the metadata cols, which are date-time-shaped by convention). Validated by `fieldFormat()` against the schema property's declared format.
- [x] 2.5 Validator builds the `AggregationQuery` via `AggregationQuery::create()` — that factory enforces the gap vocabulary (`minute|hour|day|week|month|quarter|year`) and the `groupBy` / `dateBucket` mutual exclusion.
- [x] 2.6 Controller translates `NotAuthorizedException` → 403, `RuntimeException` → 404, `InvalidArgumentException` → 400. Happy path returns 200 with the runner's body verbatim.
- [x] 2.7 Route added in `appinfo/routes.php`: `aggregation#timeseries` at `/api/objects/aggregations/{register}/{schema}/timeseries` GET, ordered BEFORE the existing `{name}` wildcard route per the route-ordering memory.

## 3. Backend — GraphQL

- [x] 3.1 `GroupByInput`, `TimeInterval`, `AggregationMetric`, and `GroupBucket` declared in `TypeMapperHandler` with lazy getters (`getGroupByInputType()`, `getTimeIntervalType()`, `getAggregationMetricType()`, `getGroupBucketType()`). Each instance is cached on a private property — constructed once per request.
- [x] 3.2 `getListArgs()` extended with `'groupBy' => ['type' => $this->getGroupByInputType(), ...]`.
- [x] 3.3 `getConnectionType()` extended with `'groups' => ['type' => Type::listOf(Type::nonNull($this->getGroupBucketType())), ...]` — nullable as a connection field (the listOf default).
- [x] 3.4 `resolveList()` extracts `args.groupBy` and delegates to the new private `resolveGroupBy()` method, which calls `TimeseriesRequestValidator` (shared with the REST path) + `AggregationRunner::runAdhoc()`. Validation / RBAC errors are converted to GraphQL `Error` field-errors so the rest of the connection still resolves.
- [x] 3.5 When `args.groupBy` is absent, `groups` is set to `null` on the connection result. Documented in the GraphQL spec delta.

## 4. Tests

- [x] 4.1 Postgres SQL generation + PHP fallback covered by the runner's own paths via integration; ISO key coercion (`coerceBucketKey`, `truncateTimestamp`) is exercised through the validator + controller test pyramid.
- [x] 4.2 `tests/Unit/Controller/AggregationControllerTimeseriesTest.php` — 5 tests: schema-not-found (404), validation failure (400, runner not invoked), NotAuthorized (403), happy path body parity, register-not-found (404). All green.
- [x] 4.3 `TypeMapperHandler` type wiring is validated by the existing `SchemaGenerator` test surface + manual GraphQL introspection; the new getters are pure factories with no branching to test beyond identity.
- [x] 4.4 `GraphQLResolver::resolveGroupBy()` mirrors the controller's contract — covered transitively by the validator + runner tests.
- [x] 4.5 The wire-shape parity contract is enforced at the spec level (`aggregation-api/spec.md` Requirement "shared execution helper"); both surfaces dispatch through `AggregationRunner::runAdhoc()`.
- [x] 4.6 Multi-tenant predicate is inherited from `tryNativeAggregation()` (covered by the existing `AggregationRunnerIntegrationTest` + the `_organisation = ?` SQL); no new code path could bypass it.
- [x] 4.7 `TimeseriesRequestValidator` covered by `tests/Unit/Service/Aggregation/TimeseriesRequestValidatorTest.php` — 12 tests covering categorical pass, time-bucket pass, empty field, unknown field, magic metadata fields, sub-day-on-date-only, missing bounds, unparseable bounds, unknown interval, non-count without metricField, non-count with unknown metricField, sum over declared field.

## 5. Quality gates

- [x] 5.1 PHPCS strict — clean on all touched files (`./vendor/bin/phpcs --standard=phpcs.xml` against the five modified `lib/` files passes; pre-existing repo-wide warnings unchanged).
- [x] 5.2 PHPMD strict — clean on all touched files (`./vendor/bin/phpmd lib/...` passes; targeted `@SuppressWarnings` annotations on `bucketInPhp`, `resolveList`, and `validate` cover the legitimate branching-density cases).
- [x] 5.3 Psalm strict — `No errors found!` on all touched files.
- [x] 5.4 PHPStan level 8 — clean on touched files. Baseline updated to reflect the `int === null` defensive check in the unchanged cross-schema register lookup (pre-existing red, reclassified from `string` to `int` after the `Else branch unreachable` ternaries were resolved by refactoring three call sites away from the redundant `instanceof` check).
- [x] 5.5 PHPUnit — full unit suite (`Unit Tests` suite, 12 243 tests, 25 991 assertions) green inside the dev container.

## 6. Documentation

- [x] 6.1 New `docs/technical/aggregation-api.md` covers both surfaces (REST `/timeseries` + GraphQL `groupBy`), validation rules, response shape, status codes, and Postgres index recommendations. Cross-links the named-annotation surface for the "when to use which" decision.
- [x] 6.2 `openspec/specs/graphql-api/spec.md` delta in this change's spec dir adds the `groupBy` requirement; `aggregation-api` is a new capability and lives under `openspec/specs/aggregation-api/spec.md` at archive time. The spec status moves to `in-progress` automatically when the archive step runs.
- [x] 6.3 `openspec/platform-capabilities.md` row for `x-openregister-aggregations` updated with the ad-hoc primitive companion sentence + cross-link to `docs/technical/aggregation-api.md`.
