---
title: Declarative Observability (AppHost)
sidebar_position: 3
description: Declare health checks and Prometheus metrics as JSON in your app manifest; OpenRegister's AppHost engine serves the endpoints.
keywords:
  - Open Register
  - AppHost
  - Observability
  - Prometheus
  - Health check
  - ADR-040
---

# Declarative Observability (AppHost)

OpenRegister's **AppHost observability engine** lets a Conduction app declare its
health checks and Prometheus metrics as a JSON `observability` block in
`src/manifest.json`. OpenRegister executes the descriptors and serves the
responses through generic, reusable controllers — so an adopting app ships
**zero hand-written observability PHP** while keeping its existing
`/apps/{appid}/api/health` and `/apps/{appid}/api/metrics` URLs and the ADR-006
contract.

This is the implementation of **ADR-040 (declarative observability)**. It
specialises ADR-006 (the endpoint contract, now enforced centrally) and reuses
the ADR-035 provider-alias discovery pattern for the escape hatch.

## The manifest `observability` block

```jsonc
"observability": {
  "health": {
    "statusCodePolicy": "adr006",   // "adr006" (default): 503 when any critical check fails, 200 otherwise
                                     // "always200": always HTTP 200, status field carries ok|degraded|error
    "cors": false,                  // true adds Access-Control-Allow-* headers (reverse-proxy probe contract)
    "checks": [
      { "id": "database",     "type": "database" },
      { "id": "filesystem",   "type": "filesystem", "severity": "degraded" },
      { "id": "openregister", "type": "appEnabled", "app": "openregister", "severity": "critical" },
      { "id": "config",       "type": "appConfig",  "key": "token_set", "assert": "nonEmpty", "severity": "degraded" },
      { "id": "or",           "type": "orAvailable" }
    ]
  },
  "metrics": [
    // {app}_info and {app}_up are implicit — never declared.
    { "name": "cases_total", "type": "gauge", "help": "Cases by status", "cacheTtl": 30,
      "source": { "kind": "objectCount", "register": "procest", "schema": "zaak", "groupBy": ["status"] } },
    { "name": "cases_overdue_total", "type": "gauge",
      "source": { "kind": "objectCount", "schema": "zaak", "filter": { "deadline": { "lt": "now" } } } },
    { "name": "leads_value_total", "type": "gauge",
      "source": { "kind": "objectSum", "schema": "lead", "field": "value", "groupBy": ["pipeline"] } },
    { "name": "widgets_total", "type": "gauge",
      "source": { "kind": "tableCount", "table": "launchpad_widget_placements" } },
    { "name": "dashboards_total", "type": "gauge",
      "source": { "kind": "tableCount", "table": "launchpad_dashboards",
                  "groupBy": ["type"], "labelDefaults": { "type": "personal" },
                  "labelMap": { "type": "kind" }, "filter": { "status": { "eq": "active" } } } },
    { "name": "pdf_generations_total", "type": "counter",
      "source": { "kind": "appConfig", "key": "pdf_generations_total" } },
    { "name": "customer_bridge", "source": { "kind": "provider" } }
  ]
}
```

If the block is **absent**, the app still gets compliant endpoints: a `database`
health check (plus `orAvailable` when the manifest declares OpenRegister
registers) and the implicit `{app}_info` / `{app}_up` metrics.

## Health check types (closed set)

| `type` | Params | Semantics |
|---|---|---|
| `database` | — | `SELECT 1` via `IDBConnection` |
| `filesystem` | — | write + unlink a temp file |
| `appEnabled` | `app` | `IAppManager::isInstalled` |
| `appConfig` | `key`, `assert` (`present` \| `nonEmpty`) | `IAppConfig` read |
| `orAvailable` | — | resolve OpenRegister's `ObjectService`, non-null |

Each check carries a `severity`: `critical` (default — failure drives status
`error` and HTTP 503 under `adr006`) or `degraded` (failure drives status
`degraded`, still HTTP 200).

Response shape:

```json
{ "status": "ok|degraded|error", "app": "myapp", "version": "1.2.3",
  "checks": { "database": "ok", "config": "failed: config key empty" } }
```

Check results are only ever `ok` or `failed[: <generic message>]`; exception
messages are logged, never returned.

## Metric source kinds (closed set + escape hatch)

| `kind` | Params | Executes as |
|---|---|---|
| `objectCount` | `register`, `schema` (slugs), `groupBy[]`, `filter` | OpenRegister portable aggregation (no raw JSON SQL) |
| `objectSum` | as above + `field` (numeric JSON field) | OpenRegister aggregation SUM |
| `tableCount` | `table`, `groupBy[]`, `filter`, `labelDefaults`, `labelMap` | QueryBuilder COUNT / GROUP BY (aggregate-only) |
| `appConfig` | `key` | `IAppConfig::getValueInt` |
| `provider` | — | merge the app's registered `IMetricsProvider` output |
| *(implicit)* | — | `{app}_info`, `{app}_up` |

`filter` operators: `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `like`, with the
server-side date tokens `now` and `today`.

`cacheTtl` (seconds, default `0`): the engine memoises the rendered sample set
per metric via the distributed cache (key = appId + metric name).

### `tableCount` security

`tableCount.table` MUST match `^[a-z0-9_]+$` — the allowlist regex is enforced
both at manifest-validation time and (defence in depth) inside the source. The
table name is passed through the QueryBuilder table API (never string
concatenation), all `groupBy`/`filter` columns are validated identifiers, and
the engine only ever emits aggregate counts — never row data.

## Engine-owned contract

- The **health endpoint is public** (`#[PublicPage]` + `#[NoCSRFRequired]`); the
  **metrics endpoint is admin-only**. Leaf apps cannot drift this through the
  manifest.
- The **exposition format** is Prometheus text 0.0.4 (`# HELP` / `# TYPE`,
  `{app}_` prefix sanitised, label escaping), `Content-Type:
  text/plain; version=0.0.4`.
- Unknown check types, source kinds, or filter operators are reported as
  manifest diagnostics and fall back to defaults — never a runtime 500.

## Escape hatch

Anything that cannot be expressed by a descriptor goes through a provider:

- `OCA\OpenRegister\AppHost\IHealthCheckProvider` — `check(): array` of
  `{id => {ok, severity?, message?}}`.
- `OCA\OpenRegister\AppHost\IMetricsProvider` — `metrics(): MetricSample[]`.

Register the implementation on your app's container under the alias
`OCA\OpenRegister\AppHost\IMetricsProvider::{yourAppId}` (the ADR-035 pattern);
a `{"kind":"provider"}` metric descriptor then merges its output into the
generic response.

## Adopting in a leaf app

1. Add the `observability` block to `src/manifest.json`.
2. Point your existing routes at the generic controllers via container service
   aliases in your `Application.php` (URLs do not change):

   ```php
   $context->registerServiceAlias(
       'OCA\\YourApp\\Controller\\HealthController',
       \OCA\OpenRegister\AppHost\Controller\GenericHealthController::class
   );
   $context->registerServiceAlias(
       'OCA\\YourApp\\Controller\\MetricsController',
       \OCA\OpenRegister\AppHost\Controller\GenericMetricsController::class
   );
   ```

3. Delete the hand-written `HealthController` / `MetricsController`.

The controller's `$appName` (your app id) is what the engine uses to load your
manifest, so the same OpenRegister engine serves every app correctly.

## Migration table

| Old hand-written pattern | Declarative replacement |
|---|---|
| `SELECT 1` in `HealthController` | `{"type":"database"}` |
| temp-file write check | `{"type":"filesystem"}` |
| `isEnabledForUser` check | `{"type":"appEnabled","app":"..."}` |
| OR DI lookup health check | `{"type":"orAvailable"}` |
| PHP-side object JSON aggregation | `{"kind":"objectCount"/"objectSum", ...}` |
| `LIKE '%pattern%'` schema match | `schema` slug in the descriptor |
| own-table COUNT queries | `{"kind":"tableCount","table":"..."}` |
| appconfig counter | `{"kind":"appConfig","key":"..."}` |
| JSON metrics output | engine renders Prometheus 0.0.4 |
| public metrics / auth'd health drift | engine-owned auth posture |
