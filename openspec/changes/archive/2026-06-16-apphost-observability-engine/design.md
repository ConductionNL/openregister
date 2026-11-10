# Design: AppHost Declarative Observability Engine

## The manifest `observability` block

Lives in the host app's `src/manifest.json`, parsed by `ManifestService` alongside the existing v2 manifest keys. Canonical JSON Schema lands in hydra (`adr-040-declarative-observability`); this file is the implementation reference.

```jsonc
"observability": {
  "health": {
    "statusCodePolicy": "adr006",        // "adr006" (default): 503 when any critical check fails, 200 + status=degraded otherwise
                                          // "always200": always HTTP 200, status field carries ok|degraded (decidesk REQ-API-004)
    "cors": false,                        // true adds the CORS headers decidesk's probe contract requires
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
    { "name": "cases_total", "type": "gauge", "help": "Cases by status and type", "cacheTtl": 30,
      "source": { "kind": "objectCount", "register": "procest", "schema": "zaak",
                  "groupBy": ["status", "caseType"] } },
    { "name": "cases_overdue_total", "type": "gauge", "cacheTtl": 60,
      "source": { "kind": "objectCount", "register": "procest", "schema": "zaak",
                  "filter": { "uiterlijkeEinddatumAfdoening": { "lt": "now" } } } },
    { "name": "cases_created_today", "type": "gauge",
      "source": { "kind": "objectCount", "register": "procest", "schema": "zaak",
                  "filter": { "startDate": { "gte": "today" } } } },
    { "name": "leads_value_total", "type": "gauge",
      "source": { "kind": "objectSum", "register": "pipelinq", "schema": "lead",
                  "field": "value", "groupBy": ["pipeline"] } },
    { "name": "widgets_total", "type": "gauge",
      "source": { "kind": "tableCount", "table": "launchpad_widget_placements" } },
    { "name": "dashboards_total", "type": "gauge",
      "source": { "kind": "tableCount", "table": "launchpad_dashboards",
                  "groupBy": ["type"], "labelDefaults": { "type": "personal" },
                  "filter": { "status": { "eq": "active" } } } },
    { "name": "pdf_generations_total", "type": "counter",
      "source": { "kind": "appConfig", "key": "pdf_generations_total" } },
    { "name": "customer_bridge", "source": { "kind": "provider" } }
  ]
}
```

### Health check types (closed set, validated)

| `type` | Params | Semantics | Fleet usage today |
|---|---|---|---|
| `database` | — | `SELECT 1` via IDBConnection | 8 apps |
| `filesystem` | — | write+unlink temp file | 5 apps |
| `appEnabled` | `app` | `IAppManager::isEnabledForUser` | 4 apps (openregister, launchpad) |
| `appConfig` | `key`, `assert` (`present`\|`nonEmpty`) | IAppConfig read | 2 apps |
| `orAvailable` | — | resolve OR ObjectService, non-null | 3 apps (subsumes decidesk's DI lookup) |

`severity`: `critical` (default — failure drives status `error` + 503 under `adr006`) or `degraded` (failure drives status `degraded`, still 200).

Response shape (unchanged from fleet convention):
`{ "status": "ok|degraded|error", "app": "...", "version": "...", "checks": { "<id>": "ok|failed: ..." } }`

### Metric source kinds (closed set + escape hatch)

| `kind` | Params | Executes as | Replaces |
|---|---|---|---|
| `objectCount` | `register`, `schema` (slugs), `groupBy[]` (JSON fields → labels), `filter` | OR portable aggregation layer — **never** raw JSON_EXTRACT SQL in the engine | opencatalogi/pipelinq/procest/docudesk schema-pattern + PHP-aggregation hacks |
| `objectSum` | as above + `field` (numeric JSON field) | OR aggregation SUM | pipelinq `leads_value_total` |
| `tableCount` | `table`, optional `groupBy[]` (columns), `filter` (column ops), `labelDefaults`, `labelMap` (column→label rename, e.g. `{"status_code":"status"}` — openconnector parity) | QueryBuilder COUNT/GROUP BY | openconnector ×9, launchpad ×3, doriath ×1 |
| `appConfig` | `key` | `IAppConfig::getValueInt` | docudesk/nldesign counters |
| `provider` | — | merge output of the app's registered `IMetricsProvider` | shillinq customer-bridge, nldesign CSS parsing |
| *(implicit)* | — | `{app}_info`, `{app}_up` | every app |

`filter` operators: `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `like`, with the date tokens `now` and `today` resolved server-side (procest overdue/created-today parity).

`cacheTtl` (seconds, default 0): engine memoises the rendered sample set per metric via `ICacheFactory` distributed cache; key includes appId + metric name.

## Engine architecture

```
leaf app routes.php ──▶ OCA\{App}\Controller\HealthController (alias)
                        └─▶ OCA\OpenRegister\AppHost\Controller\GenericHealthController
                              └─▶ HealthCheckExecutor ──▶ check primitives
leaf app routes.php ──▶ ... GenericMetricsController
                              └─▶ MetricsEngine ─┬─▶ ObjectMetricSource   (OR aggregation)
                                                 ├─▶ TableMetricSource    (QueryBuilder)
                                                 ├─▶ AppConfigMetricSource
                                                 └─▶ ProviderMetricSource (alias lookup)
```

- **App identity**: the generic controllers receive the calling app's id from the controller's `appName` (set by the alias registration in the leaf app's `Application.php` — see `apphost-boilerplate-controllers` for the `Bootstrap::register()` helper). The manifest is loaded through the existing `ManifestService` path used by `GET /api/manifest/{appId}`.
- **Auth posture is engine-owned**: GenericHealthController is `#[PublicPage]` + `#[NoCSRFRequired]` (ADR-006); GenericMetricsController is admin-only (no `NoAdminRequired`). Leaf apps cannot drift this anymore. This *fixes* openbuild (auth-gated health) and nldesign (public metrics) on adoption.
- **Exposition format is engine-owned**: Prometheus text 0.0.4, `# HELP`/`# TYPE` lines, `{app}_` prefix derived from the app id (sanitised to `[a-z_]`). Fixes shillinq's JSON metrics on adoption.
- **Defaults when the block is absent**: health = `database` + (`orAvailable` if the app's manifest declares OR registers) ; metrics = implicit info/up only. This is how larpingapp/softwarecatalog/zaakafhandelapp/planix/openbuild get compliant endpoints with zero descriptors.
- **Validation**: unknown `type`/`kind`/operator → manifest validation error surfaced in the existing manifest diagnostics path, never a runtime 500. `tableCount.table` must match `/^[a-z0-9_]+$/` and is passed through the QueryBuilder table API (no string concatenation).

## Security notes

- `tableCount` lets a manifest name any oc_ table. The manifest ships inside the app package (same trust level as the PHP it replaces), so this is not an escalation — but the engine still only ever emits aggregate counts, never row data, and the table allowlist regex blocks injection.
- Provider classes execute arbitrary PHP — identical trust to today's hand-written controllers.
- Health stays public but must not leak: check results are only ever `ok` / `failed: <generic message>`; exception messages are logged, not returned (matches current fleet best practice, now enforced once).

## Why OR and not a composer package

All Conduction apps already hard-require OR (ADR-022); a composer lib bundled per-app in `vendor/` risks cross-app class collisions in NC's shared process (first-autoloaded-wins) and re-fragments the single implementation. Alias registration in leaf apps is lazy — a missing OR does not fatal NC bootstrap; routes 503 and the app's own health endpoint correctly reports OR unavailable.

## Decisions

1. **Per-app URLs stay** (`/apps/{appid}/api/health|metrics`): existing Prometheus scrape configs and K8s probes keep working. OR-central URLs (`/apps/openregister/api/apps/{appId}/health`) were rejected — they'd force fleet-wide probe reconfiguration for zero benefit.
2. **`statusCodePolicy` is per-app**, not global: decidesk's always-200 reverse-proxy contract (REQ-API-004) is legitimate and spec'd; everyone else gets ADR-006 503s.
3. **Schema references are slugs, not title patterns**: the `LIKE '%ublicati%'` hacks die on adoption; the manifest already knows its registers/schemas.
4. **The engine never special-cases an app.** Anything that can't be expressed by a descriptor goes through `provider` — and if a third app ever needs the same provider logic, that's the signal to promote it to a new descriptor kind via an ADR-040 amendment.
