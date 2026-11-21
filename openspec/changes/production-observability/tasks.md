# Tasks: Production Observability

- [ ] Implement: Prometheus Metrics Endpoint
- [x] Implement: Standard Application Metrics
- [x] Implement: Register, Schema, and Object Count Metrics
- [x] Implement: CRUD Operation Counters
- [x] Implement: Search Performance Metrics
- [x] Implement: Webhook Delivery Monitoring
- [x] Implement: Health Check Endpoint
- [x] Implement: Readiness Endpoint
- [ ] Implement: Structured Logging
- [ ] Implement: BIO2 Audit Logging Compliance
- [ ] Implement: Database Connection Monitoring
- [x] Implement: Alerting Threshold Configuration
- [x] Implement: Metrics Storage Strategy
- [x] Implement: Performance Baseline Metrics
- [ ] Implement: Nextcloud Dashboard Integration

## Status, 2026-11-13

**Built: the readiness endpoint.** `GET /api/ready` is served by
`AppHost\Controller\GenericReadinessController`, beside the generic health
controller. It answers 200 `{"ready": true}` once this app's migrations have run
for the deployed code (`IAppManager::isUpgradeRequired()`) and the database
answers (the same `SELECT 1` probe as the health check); otherwise 503
`{"ready": false, "reason": "migrations_pending" | "database_unavailable"}`. A
check that throws counts as not ready. It is public like health (ADR-006) and
returns a reason code only, never an error message or a version.

It is registered on OpenRegister's own route table. Offering it to leaf apps
through `AppHost\Routes::standard()` also needs a leaf controller alias in
`Bootstrap` (see the store-route note there for what a route without its alias
does), so that is a follow-up.

**Already served before this change,** by the AppHost observability engine from
the `observability` block of `src/manifest.json` (ADR-040): the metrics endpoint,
register/schema/object counts, CRUD counters from the audit trail, search request
and webhook delivery counters, and the health endpoint (database, filesystem).
These items are left unticked here because not every scenario of their
requirements was verified against the engine (for example scraper-token and IP
access to the metrics endpoint, request-duration histograms, and the search
backend health check); each needs its own check before it is ticked.

**Second pass, 2026-11-13: webhook retry queue depth.** `webhook_retry_queue_depth`
(scenario "Webhook retry queue depth") is published: a gauge of failed deliveries whose
`next_retry_at` is still ahead (`WebhookLogMapper::countPendingRetries()`). It compares with
the clock, so it is a provider metric (`Service\Observability\WebhookRetryMetricsProvider`).
The engine runs an app's one provider once per `provider` descriptor, so
`Service\Observability\OpenRegisterMetricsProvider` joins it with the overdue-task gauge
under OpenRegister's alias and the manifest keeps one `provider` entry. A retry that is due
but not yet picked up by `WebhookRetryJob` is not counted, because the log has no "retried"
marker. "Webhook Delivery Monitoring" stays unticked: the delivery-duration histogram is not
built.

**Third pass, 2026-11-14: scraper access to the metrics endpoint.** Scenarios "Metrics
endpoint requires admin authentication by default", "Metrics endpoint supports token-based
authentication for scrapers" and "IP-restricted unauthenticated access" are built.
`GenericMetricsController::index()` is now `#[PublicPage]` and asks
`AppHost\Observability\MetricsAccessGuard` instead of relying on Nextcloud's admin check:
an admin session is served; a request with `Authorization: Bearer <token>` is served when the
token equals the app's `metrics_api_token` app config value and refused with 403 otherwise
(throttled like a failed login, and never rescued by the address check); a caller whose
address lies in one of the comma-separated ranges of `metrics_allowed_ips` is served, any
other address 403. With neither configured the endpoint stays admin-only: 403 for a
non-admin session, 401 anonymously. Unreadable config or unparsable ranges count as not
configured, so a failure refuses. Leaf apps get the same posture with their own config.
Both values are set with `occ config:app:set <app> metrics_api_token --value=...`; an admin
settings field for them is not built. "Prometheus Metrics Endpoint" stays unticked: the
counters/histograms/richer labels named in its requirement text are covered by the other
items, which remain open.

**Fourth pass, 2026-11-14: search performance metrics.** All three scenarios of "Search
Performance Metrics" are built, so the item is ticked. `search_requests_total{type}` now
carries the `keyword` / `semantic` / `hybrid` label (it was an unlabelled tableCount over
`search_%` rows), `search_duration_seconds{type}` is a histogram with the 0.05 … 5.0 s
bounds, and `embeddings_generated_total{status}` counts `success` and `failure` (every
non-success status). `Search\Service\SearchMetricsReader` aggregates the
`openregister_metrics` rows in two grouped queries, so a scrape never loads single rows;
`Service\Observability\SearchMetricsProvider` turns them into samples and joins
`OpenRegisterMetricsProvider`. A search recorded without a duration counts as a request but
not in the histogram. The renderer now emits `# TYPE … histogram` and the `_bucket` / `_sum`
/ `_count` series for a `histogram` family (a point names its series in an optional
`suffix`); gauge and counter output is unchanged.

**Fourth pass, continued: webhook delivery latency.** "Webhook Delivery Monitoring" is ticked:
its counters and retry queue depth were already published, and
`webhook_delivery_duration_seconds` is now a histogram with the 0.1 … 30 s bounds. Each
delivery attempt stamps `duration_ms` on its webhook log row (a new nullable column,
migration `Version1Date20261114190000`; the time is taken around the HTTP request, also when
it throws). `WebhookLogMapper::deliveryDurationStats()` aggregates it in one query and
`Service\Observability\WebhookDurationMetricsProvider` joins `OpenRegisterMetricsProvider`.
Rows written before the column existed have no duration and are left out. The webhook log's
JSON gains a trailing `durationMs` key.

**Fourth pass, continued: database connection health.** `db_connection_healthy` (scenario
"Database connection health") is published by
`Service\Observability\DatabaseHealthMetricsProvider`: 1 when a `SELECT 1` round-trip
succeeds within 5 seconds, 0 when it fails or is slower, with a warning logged. "Database
Connection Monitoring" stays unticked: the health endpoint does not yet report a slow
database as degraded, and the query-duration histogram and slow-query counter need query
instrumentation that is not built.

**Fifth pass, 2026-11-15: health endpoint checks and a health-driven `up` gauge.** "Health
Check Endpoint" is ticked. Beside the engine's `database` (critical) and `filesystem` checks,
`/api/health` now runs OpenRegister's `IHealthCheckProvider`,
`Service\Observability\OpenRegisterHealthChecks`, registered under OpenRegister's alias in
`ObservabilityWiring`. It adds three checks, all of `degraded` severity, so a failure turns the
status to `degraded` with HTTP 200 and never to a 503: `search_backend` (the database-backed
search index, `openregister_chunks`, answers a one-row read; the external Solr and
Elasticsearch backends no longer exist), `webhooks` (in the last 15 minutes not every delivery
attempt failed, read in one aggregate query by the new `WebhookLogMapper::outcomesSince()`;
a quiet window is healthy, an unreadable log fails) and `database_latency` (the
`SELECT 1` round-trip of `DatabaseHealthMetricsProvider`, now public as `isHealthy()`, within
5 seconds). A failing check reads `failed: <code>` (`unreachable`, `deliveries failing`,
`slow or unavailable`), never an error message. This also gives scenario "Database
connection health" its health-endpoint half: a slow database reports degraded.

The implicit `up` gauge can now follow health: an app whose manifest sets
`observability.health.upFollowsHealth: true` gets `up 0` whenever the health status is not
`ok` (including health checks that throw); without it `up` stays a constant 1, so leaf apps
are unchanged. OpenRegister's manifest opts in, which is scenario "Application health gauge
reflects degraded state". The engine runs the health checks once per scrape for an app that
opted in. "Standard Application Metrics" stays unticked: request counters, the request
duration histogram and the error counter are still not built.

**Left after the fourth pass, and why.** Request counters and the request-duration
histogram, the error counter, the APCu counter path with its flush job, and the alerting
thresholds all need a per-request recording path (middleware plus a durable or APCu store)
that is a design decision of its own; structured logging needs a request-id carrier through
every log call (the search-backend and webhook health checks were built in the fifth pass);
cache hit/miss and import progress need instrumentation in `CacheHandler` and the import
jobs; the dashboard widget and serverinfo section are UI/integration work. BIO2 audit items
were not re-verified in this pass.

**Sixth pass, 2026-11-15: storage growth threshold.** Scenario "Storage growth threshold" of
"Alerting Threshold Configuration" is built. `Service\Observability\StorageGrowthMetricsProvider`
joins `OpenRegisterMetricsProvider` and publishes `storage_growth_bytes` (the bytes of the
vectors added in the last 24 hours, one aggregate query on `openregister_vectors`) and
`storage_growth_exceeded` (1 when that is over the `storage_growth_alert_bytes` app config
value, with a WARNING logged). The threshold is set with
`occ config:app:set openregister storage_growth_alert_bytes --value=<bytes>`; unset, zero or not
a whole number means no threshold, and a growth that cannot be read publishes no growth and no
alert. "Alerting Threshold Configuration" stays unticked: the error-rate and response-time
thresholds and their admin notifications are not built.

**Seventh pass, 2026-11-16: request counters, request duration histogram and error counter.**
"Standard Application Metrics" and "Metrics Storage Strategy" are ticked.
`Middleware\RequestMetricsMiddleware` (the last app-scoped middleware, so its afterException
sees an exception before another middleware turns it into a response) records every answered
OpenRegister request by method, route and final status, with the time since
`REQUEST_TIME_FLOAT`, and counts exceptions as `database` (Nextcloud or Doctrine database
exceptions and `DatabaseConstraintException`, also as a previous exception), `validation` (a
class name ending in `ValidationException`) or `other`, then rethrows them. Recording never fails
a request.

A request costs APCu atomic increments only (`Service\Observability\ApcuCounters`,
`RequestMetricsRecorder`), never a database write. Every 60 seconds one request of the PHP
process pool wins an APCu marker (`apcu_add` with a 60 s TTL) and flushes: each counter is taken
out with an atomic subtract, so increments that race with the flush stay for the next one, and
added to a cumulative row per label set in `openregister_metrics`
(`RequestMetricsStore`; migration `Version1Date20261116230000` adds the nullable bigint columns
`sample_count` and `sample_sum_ms`). A row the database refuses is put back into APCu. The flush
runs inside a web request instead of a cron job on purpose: APCu memory belongs to the PHP-FPM
pool, so a job run from the CLI cron would see none of it. A flush stamps `created_at`, so the
existing 90-day retention cleanup (`LogCleanUpTask` → `cleanOldMetrics()`, logged at INFO)
only removes a label set that saw no traffic for 90 days, and the table grows with the number of
label sets, not of requests. Without APCu nothing is recorded and the three families read zero.

`Service\Observability\RequestMetricsProvider` joins `OpenRegisterMetricsProvider` and publishes
`requests_total{method,endpoint,status}`, the `request_duration_seconds{method,endpoint}`
histogram with the 0.01 … 10.0 s bounds and `errors_total{type}` (database, validation and other
always present), in three grouped queries. `endpoint` is the route's URL template from
`appinfo/routes.php` (`Service\Observability\RouteTemplates`, keyed like Nextcloud's route
names), so it has one value per route instead of one per object id; the `/api/v1/` aliases keep
their own template. Counts lag by at most one flush interval.

**Left after the seventh pass.** Alerting: the error-rate and p95 response-time thresholds and
their admin notifications can now be computed from these rows, but the rows are cumulative, so a
5-minute window needs a periodic snapshot of them (a background job comparing totals) that is
not built. Structured logging (request-id carrier), BIO2 audit re-verification, the
query-duration histogram and slow-query counter, cache hit/miss and import progress
instrumentation, the dashboard widget and serverinfo section, and an admin settings field for
`metrics_api_token` / `metrics_allowed_ips` remain open.

**Eighth pass, 2026-11-17: object counts from the object tables, CRUD counters per register and
schema.** "Register, Schema, and Object Count Metrics" and "CRUD Operation Counters" are ticked.
`objects_total{register,schema}` was a `tableCount` of the legacy `openregister_objects` blob
table, so every object stored in a per-schema table since the move was missing from it. It now
comes from `Service\Observability\ObjectCountMetricsProvider`, which joins
`OpenRegisterMetricsProvider`: it lists the `openregister_table_<register>_<schema>` tables, counts
their rows without a `_deleted` marker in `UNION ALL` batches of 100, adds the legacy rows whose
`deleted` is null, and leaves out a table whose register or schema no longer exists and a pair
with no objects. A soft delete therefore lowers the gauge (scenario "Object counts update after
CRUD operations"). A count that fails publishes no samples, with a warning, never a false zero.
`objects_created_total`, `objects_updated_total`, `objects_deleted_total` and
`objects_read_total` are now grouped by the audit trail's `register` and `schema`; they are read
from the audit trail table, so they survive a restart. Label values are the numeric ids, as
documented for `objects_total` since the AppHost adoption; the spec's examples use names.

**Eighth pass, continued: cache hit ratio.** Scenario "Cache hit ratio" of "Performance Baseline
Metrics" is built. `Service\Object\CacheHandler` counts every object lookup (`getObject()`) and
object-name lookup (single and bulk) as a hit or a miss through
`RequestMetricsRecorder::recordCacheLookup()`, given to it on `CacheHandlerRuntime`; a bulk lookup
counts its hits and misses once each. The counts take the same APCu path and 60-second
request-driven flush as the request counters (rows of metric type `cache_lookup` in
`openregister_metrics`), and counting never fails a lookup. `Service\Observability\CacheMetricsProvider`
joins `OpenRegisterMetricsProvider` and publishes `cache_hits_total{cache}` and
`cache_misses_total{cache}`, with `object` and `name` always present. Import job progress
is built in the same pass, below.

**Eighth pass, continued: import job progress.** "Performance Baseline Metrics" is ticked. A bulk
import is an `openregister_import_previews` job that first decides every row (`previewing`,
progress in `processed`, persisted every 50 rows) and then writes the decisions (`committing`,
progress in `applied` + `failed`, persisted every batch). `Service\Observability\ImportJobMetricsProvider`
joins `OpenRegisterMetricsProvider` and publishes, for each job in one of those two states,
`import_objects_processed_total{job_id,phase}` (`job_id` is the job's uuid, `phase` is `preview` or
`commit`) and `import_duration_seconds{job_id}` (seconds since the job was created). A finished
or failed job drops out, so the label set stays as small as the number of running imports.

**Eighth pass, continued: error rate and response time thresholds.** "Alerting Threshold
Configuration" is ticked (its storage growth scenario was built in the sixth pass). The request
rows are cumulative, so a window is the difference between two snapshots:
`BackgroundJob\RequestAlertJob` (every 300 seconds, registered in `appinfo/info.xml`) runs
`Service\Observability\RequestAlertEvaluator`, which reads the request and duration-bucket totals,
compares them with the snapshot it stored last time (app config `request_alert_snapshot`) and
judges the window between them. The error rate is the 5xx share of the window's requests, against
`error_rate_alert_percent`; the p95 response time is interpolated linearly inside the bucket that
holds the 95th percentile (above 10 s it counts as 10 s), against `response_time_p95_alert_ms`.
Unset, zero or not a whole number means no threshold. A window without requests keeps the previous
outcome; totals that went down (retention cleanup) start a new baseline. When a condition starts,
every `admin` group member gets one notification (subjects `observability_error_rate` and
`observability_response_time`, rendered by `Notification\ObservabilitySubjectRenderer`, with the
backend `en` and `nl` strings); no second one follows until a window has been below the
threshold. `Service\Observability\RequestAlertMetricsProvider` joins `OpenRegisterMetricsProvider`
and publishes the stored outcomes as `error_rate_exceeded` and `response_time_exceeded`; a scrape
never judges a window itself. An admin settings field for the thresholds is not built; they are set
with `occ config:app:set openregister <key> --value=<n>`.

**Eighth pass, continued: structured request and error log entries.** Scenarios "Structured log
for API request", "Structured log for error with stack trace" and "Request correlation across log
entries" are built in `Middleware\RequestMetricsMiddleware`. Every answered OpenRegister request
writes one INFO entry, `[OpenRegister] {method} {path} answered {status_code} in {duration_ms} ms`,
with the context fields `request_id`, `user`, `method`, `path` (without the query string),
`status_code`, `duration_ms`, `register` and `schema` (the route parameters, at most 64
characters). `request_id` is Nextcloud's request id, which Nextcloud also writes as `reqId` on
every other entry of the same request, so validation, save, audit and webhook entries correlate
with it. A database failure (the `database` error type) writes an ERROR entry with
`error_type` (the exception class), `error_message` (with every quoted literal replaced by `?`,
because a database message can quote the refused row's values), `stack_trace` (frames without
arguments) and `context` {`register`, `schema`, `action`}. Nothing is taken from the request body
or query values. Writing an entry never fails a request. "Structured Logging" stays unticked:
scenario "Sensitive data exclusion from logs" asks that no log call anywhere logs object data,
and the existing debug and warning calls across the code base have not been audited for that.

**Eighth pass, continued: dashboard widget.** Scenario "Dashboard widget shows key metrics" is
built. `Dashboard\OperationsDashboardWidget` is an `IAPIWidgetV2`, so the Nextcloud dashboard
renders its items itself and no script ships. It is an `IConditionalWidget` enabled for
administrators only (the figures cover every register), and `getItemsV2()` answers no items to
anyone else. It lists total objects (the sum of `ObjectCountMetricsProvider`), total registers,
total schemas, the server errors (5xx) and the average response time of the last window that
`RequestAlertJob` judged (`RequestAlertEvaluator::lastWindow()`, which the evaluator now stores
with each snapshot). A figure that cannot be read is left out. The error item links to the
OpenRegister admin settings, the others to the OpenRegister dashboard. Backend strings in `en` and
`nl`. "Nextcloud Dashboard Integration" stays unticked: the admin settings have no monitoring tab
to open (scenario "Dashboard widget links to detailed metrics" asks for that tab to be active),
and OpenRegister's health is not yet added to the serverinfo OCS response.
