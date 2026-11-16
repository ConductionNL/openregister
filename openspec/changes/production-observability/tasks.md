# Tasks: Production Observability

- [ ] Implement: Prometheus Metrics Endpoint
- [x] Implement: Standard Application Metrics
- [ ] Implement: Register, Schema, and Object Count Metrics
- [ ] Implement: CRUD Operation Counters
- [x] Implement: Search Performance Metrics
- [x] Implement: Webhook Delivery Monitoring
- [x] Implement: Health Check Endpoint
- [x] Implement: Readiness Endpoint
- [ ] Implement: Structured Logging
- [ ] Implement: BIO2 Audit Logging Compliance
- [ ] Implement: Database Connection Monitoring
- [ ] Implement: Alerting Threshold Configuration
- [x] Implement: Metrics Storage Strategy
- [ ] Implement: Performance Baseline Metrics
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
