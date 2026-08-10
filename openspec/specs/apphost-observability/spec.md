---
status: done
---

# apphost-observability Specification

## Purpose
Serves health and Prometheus-format metrics endpoints for any Nextcloud app by executing declarative descriptors from the app's manifest, so apps get monitoring without writing endpoint code. Supports five health-check types and five metric source kinds with caching, a provider escape hatch for imperative metrics, and descriptor validation that falls back to safe defaults. Enforces the engine-owned auth posture — public health, admin-only metrics — and powers OpenRegister's own observability endpoints from its manifest.
## Requirements
### Requirement: Declarative Health Execution

The system SHALL execute the `observability.health.checks[]` descriptors of the calling app's manifest using exactly five check types — `database`, `filesystem`, `appEnabled`, `appConfig`, `orAvailable` — and SHALL render `{status, app, version, checks}` with status derived from per-check `severity` and HTTP code from `statusCodePolicy`.

#### Scenario: Critical check failure under adr006 policy

- **GIVEN** an app manifest declaring `{"type":"appEnabled","app":"openregister","severity":"critical"}` and `statusCodePolicy: "adr006"`
- **AND** the openregister app is disabled
- **WHEN** `GET /apps/{appid}/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status: "error"` and `checks.openregister` starting with `failed`
- @e2e exclude API-only endpoint — covered by the Newman contract collection (tests/integration/apphost-observability.postman_collection.json), not Playwright UI

#### Scenario: Degraded check under always200 policy

- **GIVEN** a manifest with `statusCodePolicy: "always200"` and a failing `severity: "degraded"` check
- **WHEN** the health endpoint is called
- **THEN** the response MUST be HTTP 200 with `status: "degraded"`
- @e2e exclude API-only endpoint — Newman contract collection

#### Scenario: Absent observability block yields compliant defaults

- **GIVEN** an app whose manifest has no `observability` block
- **WHEN** the health endpoint is called
- **THEN** the response MUST execute the default `database` check (plus `orAvailable` when the manifest declares OR registers) and return the standard shape
- @e2e exclude API-only endpoint — Newman contract collection

---

### Requirement: Declarative Metrics Execution

The system SHALL render Prometheus text-format 0.0.4 from `observability.metrics[]` descriptors using exactly the source kinds `objectCount`, `objectSum`, `tableCount`, `appConfig`, `provider`, SHALL always emit implicit `{app}_info` and `{app}_up`, and SHALL execute `objectCount`/`objectSum` through OpenRegister's portable aggregation layer (no dialect-specific JSON SQL).

#### Scenario: Grouped object count with date filter

- **GIVEN** a descriptor `{"kind":"objectCount","schema":"zaak","groupBy":["status"],"filter":{"deadline":{"lt":"now"}}}`
- **WHEN** the metrics endpoint is called by an admin
- **THEN** the output MUST contain one labelled gauge sample per distinct `status` value counting only objects whose `deadline` is in the past, with `# HELP` and `# TYPE` lines
- @e2e exclude API-only endpoint — Newman contract collection

#### Scenario: Provider escape hatch merges imperative metrics

- **GIVEN** an app registering `OCA\OpenRegister\AppHost\IMetricsProvider::{appId}` and a `{"kind":"provider"}` descriptor
- **WHEN** the metrics endpoint is called
- **THEN** the provider's samples MUST appear in the output alongside declarative and implicit metrics
- @e2e exclude API-only endpoint — Newman contract collection

#### Scenario: Metric caching honours cacheTtl

- **GIVEN** a descriptor with `cacheTtl: 60`
- **WHEN** the metrics endpoint is called twice within 60 seconds
- **THEN** the underlying source query MUST execute at most once
- @e2e exclude backend caching behaviour — unit-tested, no UI surface

---

### Requirement: A leaf app's provider MUST be resolved from that app's OWN container

Nextcloud app containers are ISOLATED. An alias a leaf app registers —
`IMetricsProvider::{appId}`, `IHealthCheckProvider::{appId}`, its MCP tool
provider — exists only in that app's container and is invisible in
OpenRegister's. Resolving it against OpenRegister's container returns nothing,
which reads as "the app contributes no metrics" rather than as a failed lookup
(#390).

The lookup MUST therefore go through the calling app's registered container, and
MUST be reachable through ONE injectable collaborator
(`AppHost\AppContainerLocator`) rather than inlined at each call site. Three
private copies of it existed — in `ProviderMetricSource`, `HealthCheckExecutor`
and `Application` — and none was substitutable, which is what made the tests
around them silently depend on which apps happened to be installed.

An app with no registered container MUST fail soft: one app that was never
bootstrapped cannot fatal a scrape that walks every installed app.

#### Scenario: The provider is read from the app's container, not OpenRegister's

- **GIVEN** an app whose own container answers `IMetricsProvider::{appId}`, and
  an OpenRegister container that would answer the same alias differently
- **WHEN** the metrics endpoint collects that app's provider metrics
- **THEN** the APP's container MUST be the one consulted
- @e2e exclude container topology is not observable from a browser — asserted in
  `tests/Unit/AppHost/MetricSourceTest.php`, with a positive control that turns
  red when the lookup is pointed back at OpenRegister's container

#### Scenario: An app with no registered container falls back rather than fataling

- **GIVEN** an app that was never bootstrapped, so it has no registered container
- **WHEN** its provider metrics are collected
- **THEN** no samples MUST be returned and the scrape MUST continue
- @e2e exclude covered by `tests/Unit/AppHost/MetricSourceTest.php`

---

### Requirement: Engine-Owned Auth Posture and Format

The generic health endpoint SHALL be public (`#[PublicPage]` + `#[NoCSRFRequired]`) and the generic metrics endpoint SHALL be admin-only, per ADR-006; the metrics content type SHALL be `text/plain; version=0.0.4`. Leaf apps MUST NOT be able to alter these through the manifest.

#### Scenario: Anonymous metrics access is rejected

- **GIVEN** any adopting app
- **WHEN** `GET /apps/{appid}/api/metrics` is called without a session
- **THEN** the response MUST be 401 (or NC login redirect), never metric data
- @e2e exclude API auth posture — Newman contract collection

---

### Requirement: Descriptor Validation

The system SHALL reject unknown check types, source kinds, or filter operators at manifest-validation time, SHALL constrain `tableCount.table` to `/^[a-z0-9_]+$/` executed via QueryBuilder, and SHALL fall back to defaults (never a runtime 500) when a block is invalid.

#### Scenario: Invalid descriptor falls back safely

- **GIVEN** a manifest with `{"kind":"rawSql","query":"..."}` in metrics
- **WHEN** the manifest is validated
- **THEN** a manifest diagnostic error MUST be reported
- **AND** the metrics endpoint MUST still serve implicit `{app}_info`/`{app}_up`
- @e2e exclude validation backend — unit-tested, no UI surface

### Requirement: Self-Hosted Declarative Observability

OpenRegister SHALL serve its health and metrics endpoints through the AppHost engine from descriptors in its own `src/manifest.json`, with metric names, types, and label sets identical to the pre-adoption output.

#### Scenario: Metrics parity after adoption

- **GIVEN** a seeded instance with registers, schemas, objects, audit trails, and webhook logs
- **WHEN** `GET /apps/openregister/api/metrics` is called by an admin
- **THEN** the output MUST contain `openregister_info`, `openregister_up`, `openregister_registers_total`, `openregister_schemas_total`, `openregister_objects_total{register,schema}`, `openregister_objects_created_total`, `openregister_objects_updated_total`, `openregister_objects_deleted_total`, `openregister_objects_read_total`, `openregister_webhook_deliveries_total{status}`, `openregister_search_requests_total` with values matching direct table counts
- @e2e exclude API-only — Newman contract collection runs against OR in CI

#### Scenario: Health parity after adoption

- **GIVEN** a healthy instance
- **WHEN** `GET /apps/openregister/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `checks.database = "ok"` and `checks.filesystem = "ok"` in the standard shape
- @e2e exclude API-only — Newman contract collection

