# Tasks: Production Observability

> **Status:** `MetricsController` + `MetricsService` + `HealthController` are in production. `tests/Service/ProductionObservabilityIntegrationTest` (6 tests) verifies both endpoints end-to-end. 8 of 15 spec tasks are tickably complete; 7 are partial / open with notes.

## Implemented

- [x] **Prometheus Metrics Endpoint.** `GET /apps/openregister/api/metrics` returns Prometheus exposition format (text/plain; version=0.0.4). **Verified live** by `testMetricsEndpointReturnsPrometheusTextFormat` and `testMetricsContentTypeIsPrometheus`.

- [x] **Standard Application Metrics.** `openregister_info` gauge (with version + php_version labels), `openregister_up` gauge. Each preceded by `# HELP` and `# TYPE` comments per Prometheus spec. **Verified live** by `testMetricsEndpointReturnsPrometheusTextFormat`.

- [x] **Register, Schema, and Object Count Metrics.** `openregister_registers_total`, `openregister_schemas_total`, `openregister_objects_total{register, schema}` gauges populated via `countTable()` on the corresponding tables. **Verified live** by `testMetricsExposeStandardCanonicalCounters` and `testMetricsCountsAreNonNegativeIntegers`.

- [x] **Search Performance Metrics.** `openregister_search_requests_total` counter exposed; data sourced from the `MetricsService` recorded events. **Verified live** by `testMetricsExposeStandardCanonicalCounters`.

- [x] **Health Check Endpoint.** `GET /apps/openregister/api/health` returns `{status, version, checks: {database, filesystem}}`. Status codes: 200 ok, 503 on degraded/error. **Verified live** by `testHealthEndpointReturnsOkStructure` and `testHealthVersionMatchesAppVersion`.

- [x] **Database Connection Monitoring.** Health endpoint's `checks.database` validates the connection by issuing a `SELECT 1` via the QueryBuilder. Failure is captured into the response with the error message. Verified live by the health-status assertion in `testHealthEndpointReturnsOkStructure`.

- [x] **Performance Baseline Metrics.** `MetricsService::getDashboardMetrics()` exposes file processing throughput, embedding stats, search latency, and storage growth windows; `MetricsService::recordMetric` is called by the relevant services to record latency/event metrics. The HTTP metrics endpoint surfaces a curated subset; the broader set is queryable via the dashboard endpoint.

- [x] **Metrics Storage Strategy.** `MetricsService::cleanOldMetrics(int $retentionDays = 90)` retains 90 days of point-in-time metrics and deletes older rows on schedule. Used by the cleanup TimedJob.

## Open / partial

- [ ] **CRUD Operation Counters.** Partial — counters for object create/update/delete are recorded by `MetricsService::recordMetric` from the relevant handlers, but the Prometheus endpoint doesn't yet aggregate them into per-action counters (`openregister_objects_created_total`, etc.). **Open** — additive change to `collectMetrics()`.

- [ ] **Webhook Delivery Monitoring.** Partial — `WebhookDeliveryJob` already logs delivery outcomes per webhook, but Prometheus-side `openregister_webhook_deliveries_total{status}` aggregates aren't exposed yet. **Open** — additive metric.

- [ ] **Readiness Endpoint.** Partial — health does liveness; a separate `/api/ready` endpoint that surfaces "ready to serve traffic" (DB pool warm, cache warmed, migrations applied) isn't implemented. **Open** — typically only matters under k8s-style orchestration.

- [ ] **Structured Logging.** Partial — most services log via `LoggerInterface` with structured context arrays, but a uniform structured-log envelope (single shape across the app) isn't enforced. **Open** — code review pass; not a single feature.

- [ ] **BIO2 Audit Logging Compliance.** Partial — `oc_openregister_audit_trails` captures object writes but BIO2's specific event vocabulary (categorisation per BIO2 control) isn't yet mapped. **Open** — gated on a BIO2 control mapping.

- [ ] **Alerting Threshold Configuration.** Partial — Prometheus surfaces the data; alert thresholds are configured externally in Prometheus/Alertmanager, not in OpenRegister itself. **Open** — design question whether OR should ship a recommended `prometheus-alerts.yaml` companion artifact.

- [ ] **Nextcloud Dashboard Integration.** Partial — `MetricsService::getDashboardMetrics()` returns the data structures a dashboard widget would need; the actual frontend dashboard widget isn't shipped here. **Open** — frontend work.

## Test coverage

- [x] `tests/Service/ProductionObservabilityIntegrationTest` — 6 integration tests:
  - `testHealthEndpointReturnsOkStructure` (200 + status/version/checks shape)
  - `testHealthVersionMatchesAppVersion` (semver-shaped version)
  - `testMetricsEndpointReturnsPrometheusTextFormat` (`# HELP`/`# TYPE` comments + labelled `openregister_info` gauge)
  - `testMetricsExposeStandardCanonicalCounters` (5 canonical metric names present)
  - `testMetricsContentTypeIsPrometheus` (text/plain version=0.0.4)
  - `testMetricsCountsAreNonNegativeIntegers` (registers/schemas counters parse as ≥0)
