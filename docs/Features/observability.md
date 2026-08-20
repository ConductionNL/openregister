# Production Observability

OpenRegister exposes two read-only HTTP endpoints intended for operational monitoring: a JSON health probe and a Prometheus-format metrics scrape target. Both require an authenticated Nextcloud user (typical setup: a dedicated `monitoring` user added to the relevant scrape config) and are routed under the standard `apps/openregister/api/` prefix.

## `/api/health` — Health check

`GET /apps/openregister/api/health`

Returns a JSON envelope with the per-component health status. Useful for liveness probes, status pages, and on-call diagnostics. Returns HTTP 503 when any check fails, HTTP 200 otherwise.

```json
{
  "status": "ok",
  "version": "0.2.13-unstable.78",
  "checks": {
    "database":   "ok",
    "filesystem": "ok"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | Overall status: `ok`, `degraded`, or `error`. |
| `version` | string | Installed app version (semver). |
| `checks.database` | string | `"ok"` if a `SELECT 1` round-trips through the configured DB; otherwise `"failed: {error}"`. |
| `checks.filesystem` | string | `"ok"` if the appdata folder is writable; otherwise `"failed: {error}"`. |

**Status-code semantics**

- `database` failed → status `error`, HTTP 503.
- `filesystem` failed but `database` ok → status `degraded`, HTTP 503.
- All checks ok → status `ok`, HTTP 200.

## `/api/metrics` — Prometheus metrics

`GET /apps/openregister/api/metrics`

Returns Prometheus text-exposition format (Content-Type `text/plain; version=0.0.4; charset=utf-8`). Each metric is preceded by `# HELP` and `# TYPE` comments per the [Prometheus exposition format spec](https://prometheus.io/docs/instrumenting/exposition_formats/).

### Canonical metrics

| Metric | Type | Description |
|--------|------|-------------|
| `openregister_info{version, php_version}` | gauge | Always 1; carries app version + PHP version as labels. |
| `openregister_up` | gauge | 1 if the app is healthy and serving requests. |
| `openregister_registers_total` | gauge | Number of register entities. |
| `openregister_schemas_total` | gauge | Number of schema entities. |
| `openregister_objects_total{register, schema}` | gauge | Number of objects per (register, schema) pair. |
| `openregister_search_requests_total` | counter | Cumulative count of search requests served. |

### Sample output

```
# HELP openregister_info Application information
# TYPE openregister_info gauge
openregister_info{version="0.2.13-unstable.78",php_version="8.3.30"} 1

# HELP openregister_up Whether the application is healthy
# TYPE openregister_up gauge
openregister_up 1

# HELP openregister_registers_total Total number of registers
# TYPE openregister_registers_total gauge
openregister_registers_total 44

# HELP openregister_schemas_total Total number of schemas
# TYPE openregister_schemas_total gauge
openregister_schemas_total 224
```

### Recommended scrape config

```yaml
scrape_configs:
  - job_name: openregister
    metrics_path: /apps/openregister/api/metrics
    static_configs:
      - targets: ['nextcloud.example.com']
    basic_auth:
      username: monitoring
      password: <password>
```

### Long-window metrics

For dashboards needing time-windowed data (file processing throughput, embedding stats, search latency percentiles, storage growth), `MetricsService::getDashboardMetrics()` returns a structured payload covering the last 30 days. This isn't exposed via `/api/metrics` (which is a snapshot at scrape time) but is available to internal dashboard widgets.

The metrics retention policy is 90 days by default; the `cleanOldMetrics(int $retentionDays)` cleanup job prunes older rows.

## Audit logging

Object writes are recorded in `oc_openregister_audit_trails` (with full before/after diffs) and surface via the standard `oc_activity` stream. See [Versioning and Audit](versioning-and-audit.md) for the audit-trail data shape and `Activity Provider` for the activity-stream integration.

## Test coverage

`tests/Service/ProductionObservabilityIntegrationTest` (6 tests) verifies both endpoints end-to-end:

- Health: 200 with `{status, version, checks: {database, filesystem}}`; semver-shaped version.
- Metrics: Prometheus exposition format with `# HELP`/`# TYPE` comments; five canonical counters present; `Content-Type: text/plain; version=0.0.4`; counters parse as non-negative integers.
