---
status: implemented
---

# Production Observability

## Purpose
Provide production-grade observability for OpenRegister deployments through Prometheus metrics, structured logging, health/readiness endpoints, and audit-compliant monitoring. This capability enables operations teams to monitor application health, track SLA compliance, detect anomalies in real-time, and satisfy BIO (Baseline Informatiebeveiliging Overheid) audit logging requirements for Dutch government deployments.

## Requirements

### Requirement: Prometheus Metrics Endpoint
The system SHALL expose a dedicated metrics endpoint that returns all application metrics in Prometheus text exposition format (version 0.0.4). The endpoint MUST be served at `GET /index.php/apps/openregister/api/metrics` and MUST return the `Content-Type: text/plain; version=0.0.4; charset=utf-8` header. The `MetricsController` (`lib/Controller/MetricsController.php`) already implements this endpoint with basic gauge metrics; this requirement extends it with counters, histograms, and richer labels.

#### Scenario: Prometheus scrapes metrics endpoint
- **GIVEN** Prometheus is configured to scrape `/index.php/apps/openregister/api/metrics` every 15 seconds
- **WHEN** Prometheus sends a GET request to the metrics endpoint
- **THEN** the response MUST return HTTP 200 with `Content-Type: text/plain; version=0.0.4; charset=utf-8`
- **AND** the response body MUST contain valid Prometheus exposition format with `# HELP`, `# TYPE`, and metric lines

#### Scenario: Metrics endpoint requires admin authentication by default
- **GIVEN** a non-admin user requests the metrics endpoint
- **WHEN** the request is processed by the Nextcloud controller framework
- **THEN** the response MUST return HTTP 401 or HTTP 403
- **AND** no metric data SHALL be exposed to unauthorized users

#### Scenario: Metrics endpoint supports token-based authentication for scrapers
- **GIVEN** an admin has configured a metrics API token in app settings (`metrics_api_token`)
- **WHEN** a request includes the header `Authorization: Bearer <token>`
- **THEN** the metrics endpoint MUST return metrics without requiring a Nextcloud session
- **AND** requests with invalid tokens MUST receive HTTP 403

#### Scenario: IP-restricted unauthenticated access
- **GIVEN** an admin has configured `metrics_allowed_ips` to `10.0.0.0/8,172.16.0.0/12`
- **WHEN** a request from IP `10.0.1.50` reaches the metrics endpoint without authentication
- **THEN** the endpoint MUST return metrics (using `@PublicPage` annotation)
- **AND** requests from IP `203.0.113.5` without authentication MUST receive HTTP 403

### Requirement: Standard Application Metrics
Every OpenRegister deployment MUST expose a baseline set of metrics that are consistent across all Conduction apps (`opencatalogi`, `pipelinq`, `procest`). These metrics use the `openregister_` prefix and follow the naming conventions defined in the shared Prometheus metrics spec pattern.

#### Scenario: Application info gauge
- **GIVEN** OpenRegister version 1.5.0 is running on PHP 8.2.15 with Nextcloud 29.0.1
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `openregister_info{version="1.5.0",php_version="8.2.15",nextcloud_version="29.0.1"} 1`

#### Scenario: Application health gauge reflects degraded state
- **GIVEN** the search backend (Solr/Elasticsearch) is unreachable but the database is healthy
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_up` MUST be `0` (not `1`)
- **AND** the health check detail MUST be queryable via the `/api/health` endpoint

#### Scenario: HTTP request counter with labels
- **GIVEN** 50 GET requests to `/api/objects` returned HTTP 200 and 3 POST requests returned HTTP 422
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `openregister_requests_total{method="GET",endpoint="/api/objects",status="200"} 50`
  - `openregister_requests_total{method="POST",endpoint="/api/objects",status="422"} 3`

#### Scenario: Request duration histogram with standard buckets
- **GIVEN** API requests have been processed with varying latencies
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include histogram buckets at: 0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0 seconds
- **AND** each bucket MUST carry `method` and `endpoint` labels
  - e.g., `openregister_request_duration_seconds_bucket{method="GET",endpoint="/api/objects",le="0.1"} 42`

#### Scenario: Error counter by type
- **GIVEN** 2 database errors and 5 validation errors have occurred
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_errors_total{type="database"} 2` and `openregister_errors_total{type="validation"} 5`

### Requirement: Register, Schema, and Object Count Metrics
The system MUST expose gauge metrics for the total number of registers, schemas, and objects. Object counts MUST be labeled by register and schema to enable per-domain monitoring. The existing `MetricsController.getObjectCountsByRegisterAndSchema()` provides the foundation for this; the requirement formalizes the metric names and label structure.

#### Scenario: Register and schema totals
- **GIVEN** the deployment contains 3 registers and 12 schemas
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_registers_total 3` and `openregister_schemas_total 12`

#### Scenario: Object counts by register and schema
- **GIVEN** register "zaken" contains 500 "meldingen" and 200 "vergunningen" objects
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `openregister_objects_total{register="zaken",schema="meldingen"} 500`
  - `openregister_objects_total{register="zaken",schema="vergunningen"} 200`

#### Scenario: Object counts update after CRUD operations
- **GIVEN** `openregister_objects_total{register="zaken",schema="meldingen"}` is 500
- **WHEN** 10 objects are created, 2 are deleted, and the metrics endpoint is scraped
- **THEN** the gauge MUST report 508

### Requirement: CRUD Operation Counters
The system MUST maintain monotonic counters for create, update, and delete operations on objects. These counters SHALL be labeled with `register` and `schema` to enable per-domain throughput analysis. Counters MUST persist across PHP request boundaries using the `openregister_metrics` database table (already used by `MetricsService`).

#### Scenario: Object creation counter increments
- **GIVEN** 10 objects have been created in schema "meldingen" of register "zaken"
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_objects_created_total{register="zaken",schema="meldingen"} 10`

#### Scenario: Object update and delete counters
- **GIVEN** 5 objects were updated and 2 deleted in schema "meldingen"
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_objects_updated_total{register="zaken",schema="meldingen"} 5`
- **AND** `openregister_objects_deleted_total{register="zaken",schema="meldingen"} 2`

#### Scenario: Counter survives PHP process restarts
- **GIVEN** the counter was at 100 before Apache was restarted
- **WHEN** Apache restarts and the metrics endpoint is scraped
- **THEN** the counter MUST still report at least 100 (counters stored in database, not APCu)

### Requirement: Search Performance Metrics
The system MUST expose metrics for search operations across all three search modes: keyword, semantic, and hybrid. The existing `MetricsService.getSearchLatencyStats()` already tracks per-type latency in the `openregister_metrics` table; this requirement extends it to Prometheus exposition format with histogram buckets.

#### Scenario: Search request counter by type
- **GIVEN** 100 keyword searches, 30 semantic searches, and 20 hybrid searches have been performed
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `openregister_search_requests_total{type="keyword"} 100`
  - `openregister_search_requests_total{type="semantic"} 30`
  - `openregister_search_requests_total{type="hybrid"} 20`

#### Scenario: Search latency histogram
- **GIVEN** semantic searches have latencies ranging from 50ms to 2000ms
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_search_duration_seconds` histogram MUST be present with `type` label
- **AND** bucket boundaries at 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0 seconds

#### Scenario: Embedding generation metrics
- **GIVEN** the `MetricsService` has recorded 500 successful and 12 failed embedding generations
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_embeddings_generated_total{status="success"} 500`
- **AND** `openregister_embeddings_generated_total{status="failure"} 12`

### Requirement: Webhook Delivery Monitoring
The system MUST expose metrics for webhook delivery status, success rates, and retry counts. The `WebhookLog` entity (`lib/Db/WebhookLog.php`) and `WebhookDeliveryJob` (`lib/BackgroundJob/WebhookDeliveryJob.php`) already track delivery attempts; these MUST be surfaced as Prometheus metrics.

#### Scenario: Webhook delivery counters
- **GIVEN** webhook "zaak-created" has delivered 95 successful and 5 failed notifications
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_webhook_deliveries_total{webhook="zaak-created",status="success"} 95`
- **AND** `openregister_webhook_deliveries_total{webhook="zaak-created",status="failure"} 5`

#### Scenario: Webhook retry queue depth
- **GIVEN** 3 webhook deliveries are pending retry via `WebhookRetryJob`
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_webhook_retry_queue_depth 3`

#### Scenario: Webhook delivery latency
- **GIVEN** webhook deliveries have varying response times
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_webhook_delivery_duration_seconds` histogram MUST be present
- **AND** bucket boundaries at 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0 seconds

### Requirement: Health Check Endpoint
The system MUST expose a JSON health check endpoint at `GET /index.php/apps/openregister/api/health` that reports the status of all critical subsystems. The existing `HealthController` (`lib/Controller/HealthController.php`) checks database and filesystem; this requirement extends it with search backend, webhook connectivity, and migration status checks.

#### Scenario: All checks pass
- **GIVEN** the database is accessible, filesystem is writable, and the search backend is reachable
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 200 with `{"status": "ok", "version": "1.5.0", "checks": {"database": "ok", "filesystem": "ok", "search_backend": "ok", "webhooks": "ok"}}`

#### Scenario: Database failure produces error status
- **GIVEN** the database connection has been lost
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 503 with `{"status": "error", "checks": {"database": "failed: Connection refused"}}`

#### Scenario: Search backend unreachable produces degraded status
- **GIVEN** the Solr/Elasticsearch backend is unreachable but the database is healthy
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 200 with `{"status": "degraded", "checks": {"database": "ok", "search_backend": "unreachable"}}`
- **AND** `openregister_up` gauge MUST be set to 0

#### Scenario: Health check is usable by container orchestrators
- **GIVEN** a Kubernetes or Docker deployment with liveness probes configured
- **WHEN** the orchestrator sends `GET /api/health` at regular intervals
- **THEN** HTTP 200 indicates the container is healthy; HTTP 503 triggers a restart

### Requirement: Readiness Endpoint
The system MUST expose a readiness endpoint at `GET /index.php/apps/openregister/api/ready` that indicates whether the application is fully initialized and ready to serve traffic. This is distinct from the health endpoint: readiness checks whether migrations have completed and all required services are initialized.

#### Scenario: Application not yet ready during startup
- **GIVEN** the application is starting and database migrations are still running
- **WHEN** `GET /api/ready` is requested
- **THEN** HTTP 503 with `{"ready": false, "reason": "migrations_pending"}`

#### Scenario: Application becomes ready after initialization
- **GIVEN** all migrations have completed and services are initialized
- **WHEN** `GET /api/ready` is requested
- **THEN** HTTP 200 with `{"ready": true}`

#### Scenario: Readiness used as Kubernetes readiness probe
- **GIVEN** Kubernetes is configured with `readinessProbe` pointing to `/api/ready`
- **WHEN** the pod starts and migrations are still running
- **THEN** the pod SHALL NOT receive traffic until `/api/ready` returns HTTP 200

### Requirement: Structured Logging
All log entries for API operations and errors MUST be structured with consistent fields to enable integration with log aggregation systems (ELK Stack, Loki, Graylog). The existing `LoggerInterface` usage throughout the codebase (via `Psr\Log\LoggerInterface`) provides the foundation; this requirement specifies the required context fields.

#### Scenario: Structured log for API request
- **GIVEN** an authenticated user sends a POST request to create an object
- **WHEN** the request is processed
- **THEN** the log entry MUST include context fields: `request_id` (unique per request), `user`, `method`, `path`, `status_code`, `duration_ms`, `register`, `schema`

#### Scenario: Structured log for error with stack trace
- **GIVEN** a database connection failure occurs during object creation
- **WHEN** the error is logged
- **THEN** the log entry MUST include: `level: error`, `error_type` (exception class), `error_message`, `stack_trace`, `context` (register, schema, action)

#### Scenario: Request correlation across log entries
- **GIVEN** a single API request triggers multiple internal operations (validation, save, audit, webhook)
- **WHEN** each operation logs a message
- **THEN** all log entries MUST share the same `request_id` for correlation

#### Scenario: Sensitive data exclusion from logs
- **GIVEN** an object contains BSN (Burger Service Nummer) or other PII fields
- **WHEN** the object is logged for debugging
- **THEN** PII fields MUST be redacted or excluded from the log entry
- **AND** only the object UUID, register, and schema SHALL be logged

### Requirement: BIO2 Audit Logging Compliance
The system MUST satisfy BIO (Baseline Informatiebeveiliging Overheid) audit logging requirements for Dutch government deployments. The existing `AuditTrail` entity (`lib/Db/AuditTrail.php`) and `AuditHandler` (`lib/Service/Object/AuditHandler.php`) track object-level changes; this requirement ensures completeness for BIO2 compliance.

#### Scenario: Every data mutation is audit-logged
- **GIVEN** a user creates, updates, or deletes an object
- **WHEN** the operation completes
- **THEN** an `AuditTrail` record MUST be created with: `user`, `userName`, `action`, `object`, `register`, `schema`, `changed` (diff), `ipAddress`, `session`, `created` timestamp

#### Scenario: Audit trail is immutable
- **GIVEN** an audit trail entry exists for a previous operation
- **WHEN** any user (including admin) attempts to modify the entry via API
- **THEN** the modification MUST be rejected with HTTP 403
- **AND** audit trail entries SHALL only be deletable through the explicit `LogService.deleteLog()` method with admin authorization

#### Scenario: Audit trail export for compliance review
- **GIVEN** a compliance officer needs to review all operations on register "zaken" for the past quarter
- **WHEN** the officer requests an export via `LogService.exportLogs()` with date and register filters
- **THEN** the system MUST return a complete export in CSV, JSON, or XML format containing all required BIO2 fields

#### Scenario: Audit log retention policy
- **GIVEN** `MetricsService.cleanOldMetrics()` implements a 90-day default retention
- **WHEN** the retention cleanup runs
- **THEN** operational metrics older than the retention period MUST be deleted
- **AND** audit trail entries MUST NOT be deleted by the metrics cleanup (separate retention per BIO requirements)

### Requirement: Database Connection Monitoring
The system MUST expose metrics about database connection health, query performance, and connection pool utilization. Since OpenRegister relies on Nextcloud's `IDBConnection` abstraction, these metrics SHALL be derived from query timing within the application layer.

#### Scenario: Database query duration tracking
- **GIVEN** the system executes database queries for object retrieval
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_db_query_duration_seconds` histogram MUST be present with `operation` label (select, insert, update, delete)

#### Scenario: Database connection health
- **GIVEN** the `HealthController.checkDatabase()` runs a simple query to verify connectivity
- **WHEN** the query takes longer than 5 seconds or fails
- **THEN** `openregister_db_connection_healthy` gauge MUST be set to 0
- **AND** the health endpoint MUST report database status as "degraded" or "failed"

#### Scenario: Slow query detection
- **GIVEN** a database query exceeds the configured slow query threshold (default: 1 second)
- **WHEN** the query completes
- **THEN** `openregister_db_slow_queries_total` counter MUST increment
- **AND** the query details MUST be logged at WARNING level with `duration_ms`, `query_type`, and `table`

### Requirement: Alerting Threshold Configuration
The system MUST support configurable alerting thresholds that can be used by external monitoring systems (Prometheus Alertmanager, Grafana). The thresholds SHALL be exposed as Prometheus recording rules or as metadata alongside the metrics endpoint.

#### Scenario: Error rate threshold
- **GIVEN** the admin has configured an error rate threshold of 5% over 5 minutes
- **WHEN** the error rate exceeds 5% (e.g., 6 out of 100 requests return 5xx)
- **THEN** a Nextcloud notification MUST be sent to admin users
- **AND** the condition MUST be queryable as `openregister_error_rate_exceeded 1`

#### Scenario: Response time threshold
- **GIVEN** the admin has configured a p95 response time threshold of 3 seconds
- **WHEN** the 95th percentile response time exceeds 3 seconds over the last 5 minutes
- **THEN** a Nextcloud notification MUST be sent to admin users

#### Scenario: Storage growth threshold
- **GIVEN** the admin has configured a daily storage growth alert at 1GB
- **WHEN** the `MetricsService.getStorageGrowth()` detects that daily vector additions exceed the threshold
- **THEN** the system MUST log a WARNING and expose `openregister_storage_growth_exceeded 1`

### Requirement: Metrics Storage Strategy
Since PHP is a request-scoped runtime without persistent in-memory state, the system MUST use a durable storage mechanism for counters and histograms. The `openregister_metrics` database table (used by `MetricsService.recordMetric()`) SHALL serve as the primary storage, with optional APCu caching for high-frequency counter increments.

#### Scenario: Counter persistence across requests
- **GIVEN** a counter has been incremented 1000 times across multiple PHP requests
- **WHEN** the metrics endpoint formats the counter value
- **THEN** it MUST query the `openregister_metrics` table to produce an accurate count
- **AND** the query MUST complete within 500ms even with millions of rows (using indexed `metric_type` + `created_at`)

#### Scenario: APCu cache for high-frequency metrics
- **GIVEN** the deployment handles 100+ requests per second
- **WHEN** each request increments `openregister_requests_total`
- **THEN** the increment SHOULD use APCu atomic increment (`apcu_inc`) for performance
- **AND** a periodic flush job MUST persist APCu counters to the database every 60 seconds

#### Scenario: Metrics retention cleanup
- **GIVEN** the `openregister_metrics` table has grown beyond the configured retention period (default: 90 days)
- **WHEN** `MetricsService.cleanOldMetrics()` runs via the `LogCleanUpTask` cron
- **THEN** rows older than the retention period MUST be deleted
- **AND** the deletion count MUST be logged at INFO level

### Requirement: Performance Baseline Metrics
The system MUST expose metrics from the `PerformanceHandler` (`lib/Service/Object/PerformanceHandler.php`) and `PerformanceOptimizationHandler` to track internal optimization effectiveness. These metrics enable capacity planning and regression detection.

#### Scenario: Fast-path detection rate
- **GIVEN** the `PerformanceHandler.optimizeRequestForPerformance()` classifies requests as simple or complex
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_fast_path_requests_total` and `openregister_slow_path_requests_total` counters MUST be present

#### Scenario: Cache hit ratio
- **GIVEN** the `CacheHandler` serves cached objects for repeated lookups
- **WHEN** the metrics endpoint is scraped
- **THEN** `openregister_cache_hits_total` and `openregister_cache_misses_total` counters MUST be present
- **AND** the hit ratio SHOULD be calculable as `hits / (hits + misses)`

#### Scenario: Import job progress tracking
- **GIVEN** a bulk import job is processing 10,000 objects via `ObjectTextExtractionJob` or data import
- **WHEN** the metrics endpoint is scraped during the import
- **THEN** `openregister_import_objects_processed_total{job_id="abc123"}` MUST reflect the current progress
- **AND** `openregister_import_duration_seconds{job_id="abc123"}` MUST track elapsed time

### Requirement: Nextcloud Dashboard Integration
The system SHALL register an `OCP\Dashboard\IWidget` that displays key OpenRegister metrics on the Nextcloud dashboard home screen. The existing `DashboardService` (`lib/Service/DashboardService.php`) provides register/schema aggregation; this requirement extends it with real-time operational widgets.

#### Scenario: Dashboard widget shows key metrics
- **GIVEN** an admin user views the Nextcloud dashboard
- **WHEN** the OpenRegister widget is enabled
- **THEN** the widget MUST display: total objects, total registers, total schemas, recent error count, and average response time

#### Scenario: Dashboard widget links to detailed metrics
- **GIVEN** the admin sees a high error count on the dashboard widget
- **WHEN** the admin clicks the error count
- **THEN** the system MUST navigate to the OpenRegister admin panel with the monitoring tab active

#### Scenario: Nextcloud OCS monitoring endpoint integration
- **GIVEN** Nextcloud exposes `/ocs/v2.php/apps/serverinfo/api/v1/info` for server monitoring
- **WHEN** an external monitoring tool queries this endpoint
- **THEN** OpenRegister's health status SHOULD be included in the response as an additional section

## Current Implementation Status
- **Implemented -- Prometheus metrics endpoint**: `MetricsController` (`lib/Controller/MetricsController.php`) exposes `/api/metrics` with `openregister_info`, `openregister_up`, `openregister_registers_total`, `openregister_schemas_total`, `openregister_objects_total` (by register/schema), and `openregister_search_requests_total` gauges. Content-Type header is correctly set to Prometheus exposition format.
- **Implemented -- health check endpoint**: `HealthController` (`lib/Controller/HealthController.php`) exposes `/api/health` with database and filesystem checks, returning `ok`/`degraded`/`error` status with HTTP 200/503.
- **Implemented -- heartbeat endpoint**: `HeartbeatController` (`lib/Controller/HeartbeatController.php`) exposes `/api/heartbeat` for connection keep-alive during long operations.
- **Implemented -- metrics recording service**: `MetricsService` (`lib/Service/MetricsService.php`) records operational metrics to `openregister_metrics` table with support for file processing, embedding generation, search latency, and storage growth tracking. Includes 90-day retention cleanup.
- **Implemented -- audit trail**: `AuditTrail` entity, `AuditTrailMapper`, `AuditHandler`, `LogService`, and `AuditTrailController` provide complete object-level audit logging with export support (CSV, JSON, XML, TXT).
- **Implemented -- webhook logging**: `WebhookLog` entity and `WebhookLogMapper` track webhook delivery attempts, success/failure, retry counts, and response data.
- **Implemented -- performance tracking**: `PerformanceHandler` and `PerformanceOptimizationHandler` track fast-path detection, extend optimization, and cache preloading.
- **Not implemented -- request duration histograms**: No middleware tracks per-request duration as histogram data with Prometheus bucket boundaries.
- **Not implemented -- CRUD operation counters**: No counters for create/update/delete operations in Prometheus format (MetricsService records metrics but MetricsController does not format them as counters with register/schema labels).
- **Not implemented -- readiness endpoint**: No `/api/ready` endpoint that checks migration status.
- **Not implemented -- structured JSON logging**: Application uses Nextcloud's `LoggerInterface` but does not enforce structured context fields (request_id, register, schema) consistently.
- **Not implemented -- IP-restricted metrics access**: No IP-based access control or token authentication for the metrics endpoint.
- **Not implemented -- alerting thresholds**: No configurable threshold system with Nextcloud notifications.
- **Not implemented -- APCu counter caching**: All metrics go directly to database; no APCu fast path for high-frequency counter increments.
- **Not implemented -- Nextcloud dashboard widget**: No `IWidget` registration for the Nextcloud dashboard.

## Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Kubernetes health check conventions: `/health` (liveness), `/ready` (readiness)
- JSON structured logging: ECS (Elastic Common Schema)
- Nextcloud logging framework: `Psr\Log\LoggerInterface` via `OCP`
- Nextcloud dashboard widgets: `OCP\Dashboard\IWidget`, `OCP\Dashboard\IAPIWidget`
- Nextcloud server monitoring: `/ocs/v2.php/apps/serverinfo/api/v1/info`
- BIO (Baseline Informatiebeveiliging Overheid): Dutch government information security baseline
- Cross-reference: `api-test-coverage` spec (test coverage for metrics endpoints)
- Cross-reference: `event-driven-architecture` spec (events that trigger metric recording)
- Cross-reference: `audit-trail-immutable` spec (immutability requirements for audit entries)
- Cross-reference: `deletion-audit-trail` spec (audit logging for delete operations)
- Shared pattern: `opencatalogi`, `pipelinq`, `procest` prometheus-metrics specs follow the same `REQ-PROM-001` through `REQ-PROM-004` structure
