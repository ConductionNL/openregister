# Tasks: AppHost Declarative Observability Engine

## 0. Setup and Verification

- [ ] 0.1 Verify ADR-040 draft exists in hydra (`hydra/openspec/changes/adr-040-declarative-observability`) and the descriptor schema there matches design.md — flag drift back to the hydra change, do not fork the contract here
- [ ] 0.2 Deduplication check: confirm no existing generic health/metrics machinery in OR (`lib/Controller/HealthController.php`, `lib/Service/MetricsService.php` are OR-self-only today; AppHost namespace is free)
- [ ] 0.3 Confirm ManifestService can resolve a calling app's `src/manifest.json` for all deploy layouts (bind-mount apps-extra/<app> and custom_apps) — reuse the `GET /api/manifest/{appId}` resolution path

## 1. Descriptor model

- [ ] 1.1 `lib/AppHost/Observability/HealthCheckDescriptor.php` + `MetricDescriptor.php` value objects with strict validation (closed `type`/`kind` sets, filter operator allowlist, `tableCount.table` regex `/^[a-z0-9_]+$/`)
  - **spec_ref**: `specs/apphost-observability/spec.md` — Requirement: Descriptor Validation
- [ ] 1.2 ManifestService: parse `observability` block into descriptors; absent block → defaults (database check + orAvailable when OR registers declared; implicit metrics only); invalid block → manifest diagnostics error, endpoints fall back to defaults

## 2. Health engine

- [ ] 2.1 `lib/AppHost/Observability/HealthCheckExecutor.php` implementing the 5 primitives (database, filesystem, appEnabled, appConfig, orAvailable)
- [ ] 2.2 Status + HTTP code resolution: `severity` (critical/degraded) × `statusCodePolicy` (adr006/always200); CORS headers when `cors: true`
- [ ] 2.3 `lib/AppHost/Controller/GenericHealthController.php` — `#[PublicPage]` + `#[NoCSRFRequired]`, response shape `{status, app, version, checks}`; exception messages logged never returned
- [ ] 2.4 `lib/AppHost/IHealthCheckProvider.php` + provider merge (alias `OCA\OpenRegister\AppHost\IHealthCheckProvider::{appId}`)

## 3. Metrics engine

- [ ] 3.1 `lib/AppHost/Observability/MetricsEngine.php` + Prometheus text renderer (0.0.4, HELP/TYPE, `{app}_` prefix sanitised, label escaping)
- [ ] 3.2 `ObjectMetricSource` — objectCount/objectSum via OR's portable aggregation layer (groupBy JSON fields → labels, filter operators, `now`/`today` tokens). MUST NOT emit dialect-specific JSON_EXTRACT SQL from this class
- [ ] 3.3 `TableMetricSource` — COUNT/GROUP BY via QueryBuilder, `labelDefaults` for NULL/empty columns, `labelMap` column→label rename (openconnector needs `status_code`→`status`, `result`→`status`; launchpad needs empty-string→default, not just NULL), graceful zero-emission when the table does not exist (openconnector drained-table parity)
- [ ] 3.4 `AppConfigMetricSource` — counter/gauge from IAppConfig int values
- [ ] 3.5 `ProviderMetricSource` — `lib/AppHost/IMetricsProvider.php`, alias discovery `OCA\OpenRegister\AppHost\IMetricsProvider::{appId}`, merge rendered samples
- [ ] 3.6 Implicit `{app}_info` (version, php_version, nextcloud_version) + `{app}_up`
- [ ] 3.7 Per-metric `cacheTtl` via ICacheFactory distributed cache (key: appId + metric name)
- [ ] 3.8 `lib/AppHost/Controller/GenericMetricsController.php` — admin-only, `text/plain; version=0.0.4`

## 4. Tests and contract

- [ ] 4.1 Unit tests: every check type, every source kind, severity×policy matrix, validation rejections, cache TTL behaviour, label escaping
- [ ] 4.2 Newman contract collection `tests/integration/apphost-observability.postman_collection.json`: health public + shape, health 503/200 policies, metrics admin-gated (401/403 anonymous), Prometheus content-type + `_info`/`_up` presence — parameterised by appId so adoption specs can reuse it
- [ ] 4.3 Wire collection into CI (existing Newman job)

## 5. Documentation

- [ ] 5.1 `docs/` page: "Declarative observability" — block reference, all types/kinds, defaults, provider escape hatch, migration table from hand-written controllers
- [ ] 5.2 Update OR website docs nav + changelog entry

## 6. Quality gates

- [ ] 6.1 `composer check:strict` green; all 18 hydra gates green; `@spec` tags on every new public method referencing this change
