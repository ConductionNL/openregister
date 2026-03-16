# production-observability Specification

## Purpose
Implement production-grade observability using Prometheus metrics, structured logging, and health check endpoints. Every CRUD operation MUST increment counters, response times MUST be tracked as histograms, and the system MUST expose standard health and readiness endpoints for container orchestration and SLA monitoring.

**Source**: Gap identified in cross-platform analysis; enterprise deployment requirement.

## ADDED Requirements

### REQ-PROM-001: Metrics Endpoint
- MUST expose `GET /index.php/apps/openregister/api/metrics` returning `text/plain; version=0.0.4; charset=utf-8`
- MUST require admin authentication (Nextcloud admin or API token)
- MUST return metrics in Prometheus text exposition format

### REQ-PROM-002: Standard Metrics
Every app MUST expose these standard metrics:
- `openregister_info` (gauge, labels: version, php_version, nextcloud_version) — always 1
- `openregister_up` (gauge) — 1 if app is healthy, 0 if degraded
- `openregister_requests_total` (counter, labels: method, endpoint, status) — HTTP request count
- `openregister_request_duration_seconds` (histogram, labels: method, endpoint) — request latency
- `openregister_errors_total` (counter, labels: type) — error count by type

### REQ-PROM-003: App-Specific Metrics

#### CRUD operation counters
- `openregister_objects_created_total` (counter, labels: register, schema) — objects created
- `openregister_objects_updated_total` (counter, labels: register, schema) — objects updated
- `openregister_objects_deleted_total` (counter, labels: register, schema) — objects deleted
- `openregister_objects_total` (gauge, labels: register, schema) — total objects per register/schema
- `openregister_registers_total` (gauge) — total registers
- `openregister_schemas_total` (gauge) — total schemas

#### Scenario: CRUD operation counters
- GIVEN the metrics endpoint is enabled
- WHEN 10 objects are created, 5 updated, and 2 deleted in schema `meldingen`
- THEN the metrics endpoint MUST report:
  - `openregister_objects_created_total{register="zaken",schema="meldingen"} 10`
  - `openregister_objects_updated_total{register="zaken",schema="meldingen"} 5`
  - `openregister_objects_deleted_total{register="zaken",schema="meldingen"} 2`

#### Scenario: Request duration histogram
- GIVEN the metrics endpoint is enabled
- WHEN API requests are processed
- THEN the metrics MUST include:
  - `openregister_request_duration_seconds_bucket{method="GET",endpoint="/api/objects",le="0.1"}`
  - Histogram buckets at 0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0 seconds

#### Scenario: Object count gauge
- GIVEN register `zaken` contains 500 meldingen and 200 vergunningen
- THEN the metrics MUST include:
  - `openregister_objects_total{register="zaken",schema="meldingen"} 500`
  - `openregister_objects_total{register="zaken",schema="vergunningen"} 200`

### Requirement: The system MUST use structured logging
All log entries MUST be structured JSON for integration with log aggregation systems.

#### Scenario: Structured log for API request
- GIVEN an API request to create an object
- WHEN the request is processed
- THEN the log entry MUST be JSON with fields:
  - `timestamp`, `level`, `message`
  - `request_id`: unique per request
  - `user`: the authenticated user
  - `method`, `path`, `status_code`, `duration_ms`
  - `register`, `schema` (when applicable)

#### Scenario: Structured log for error
- GIVEN a database connection failure during object creation
- THEN the error log MUST include:
  - `level`: `error`
  - `error_type`: the exception class
  - `error_message`: the exception message
  - `stack_trace`: full stack trace
  - `context`: the operation that failed (register, schema, action)

### REQ-PROM-004: Health Check
- MUST expose `GET /index.php/apps/openregister/api/health` returning JSON `{"status": "ok"|"degraded"|"error", "checks": {...}}`
- Checks: database connectivity, required dependencies available, search backend reachability

### Requirement: The system MUST expose health check endpoints
Standard health and readiness endpoints MUST be available for container orchestration.

#### Scenario: Health check passes
- GIVEN the application is running and the database is accessible
- WHEN GET /health is requested
- THEN the response MUST return HTTP 200 with:
  - `status`: `healthy`
  - `checks.database`: `ok`
  - `checks.filesystem`: `ok`
  - `version`: the application version

#### Scenario: Health check fails
- GIVEN the database is unreachable
- WHEN GET /health is requested
- THEN the response MUST return HTTP 503 with:
  - `status`: `unhealthy`
  - `checks.database`: `failed` with error details

#### Scenario: Readiness check
- GIVEN the application is starting up and migrations are still running
- WHEN GET /ready is requested
- THEN the response MUST return HTTP 503 until the application is fully initialized
- AND return HTTP 200 once ready to serve traffic

### Requirement: The system MUST support alerting thresholds
Configurable thresholds MUST trigger alerts when operational metrics exceed limits.

#### Scenario: Error rate alert
- GIVEN an alert threshold: error rate > 5% over 5 minutes
- WHEN 6 out of 100 requests in the last 5 minutes returned HTTP 5xx
- THEN the system MUST trigger an alert via the configured notification channel

#### Scenario: Response time alert
- GIVEN an alert threshold: p95 response time > 3 seconds
- WHEN the 95th percentile response time exceeds 3 seconds
- THEN the system MUST trigger an alert

### Requirement: Metrics MUST be accessible without authentication
The /metrics endpoint MUST be accessible without Nextcloud authentication for Prometheus scraping, but SHOULD be IP-restricted.

#### Scenario: Prometheus scrape
- GIVEN Prometheus is configured to scrape /metrics every 15 seconds
- WHEN Prometheus requests /metrics from an allowed IP
- THEN metrics MUST be returned in Prometheus exposition format without authentication
- AND requests from non-allowed IPs MUST be rejected with HTTP 403

### Current Implementation Status
- **Partially implemented — metrics service**: `MetricsService` (`lib/Service/MetricsService.php`) exists and provides operational metrics using database queries. It tracks object counts and other aggregate statistics.
- **Partially implemented — heartbeat/health check**: `HeartbeatController` (`lib/Controller/HeartbeatController.php`) exposes a health check endpoint that verifies the application is running.
- **Not implemented — Prometheus metrics endpoint**: No `/metrics` endpoint exists that exposes data in Prometheus exposition format. The `MetricsService` provides internal metrics but does not format them for Prometheus scraping.
- **Not implemented — request duration histograms**: No middleware or interceptor tracks per-request duration as histogram data.
- **Not implemented — structured JSON logging**: The application uses Nextcloud's `ILogger`/`LoggerInterface` for logging, which outputs to Nextcloud's log format, not structured JSON. `LogService` (`lib/Service/LogService.php`) handles some logging but not in structured JSON format.
- **Not implemented — readiness endpoint**: No `/ready` endpoint distinguishes between startup and fully-initialized states.
- **Not implemented — alerting thresholds**: No configurable alert threshold system exists.
- **Not implemented — IP-restricted metrics access**: No IP-based access control for a metrics endpoint.
- **Tangentially related — performance tracking**: `PerformanceHandler` (`lib/Service/Object/PerformanceHandler.php`) and `PerformanceOptimizationHandler` (`lib/Service/Object/PerformanceOptimizationHandler.php`) track internal performance but do not expose Prometheus metrics.

### Standards & References
- Prometheus exposition format (https://prometheus.io/docs/instrumenting/exposition_formats/)
- OpenMetrics specification (https://openmetrics.io/)
- Kubernetes health check conventions (`/health`, `/ready`, `/live`)
- JSON structured logging best practices (ECS - Elastic Common Schema)
- Nextcloud logging framework (`ILogger`, `LoggerInterface`)
- SLA monitoring standards for government IT (BIO - Baseline Informatiebeveiliging Overheid)

### Specificity Assessment
- **Moderately specific**: The spec defines metric names, histogram buckets, health check response formats, and alerting thresholds.
- **Missing details**:
  - How to integrate Prometheus metrics within Nextcloud's PHP architecture (no long-running process for in-memory counters)
  - Storage mechanism for counters (APCu? Database? File-based?)
  - How structured logging integrates with or replaces Nextcloud's native logging
  - Deployment instructions for Prometheus/Grafana alongside the Nextcloud instance
  - How alerting thresholds connect to notification channels (email, Slack, etc.)
- **Open questions**:
  - Can PHP/Nextcloud effectively expose Prometheus metrics without a sidecar exporter?
  - Should metrics be stored in APCu (fast but per-process) or a shared store?
  - How does the readiness check determine that migrations have completed?
