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
| `openregister_objects_total{register, schema}` | gauge | Live (not soft-deleted) objects per register and schema id, counted in the per-schema object tables plus the legacy object table; a pair with no objects, or whose register or schema is gone, is not published. |
| `openregister_objects_{created,updated,deleted,read}_total{register, schema}` | counter | Object operations recorded in the audit trail, per register and schema id. Stored in the database, so they survive restarts. |
| `openregister_search_requests_total{type}` | counter | Search requests served, by search type (`keyword`, `semantic`, `hybrid`). |
| `openregister_search_duration_seconds{type}` | histogram | Search duration, buckets 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0 s; searches recorded without a duration count as requests only. |
| `openregister_embeddings_generated_total{status}` | counter | Embedding generations by outcome (`success`, `failure`; every non-success status is a failure). |
| `openregister_webhook_delivery_duration_seconds` | histogram | Webhook delivery request duration, buckets 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0 s; attempts logged before durations were recorded are left out. |
| `openregister_cache_hits_total{cache}` / `openregister_cache_misses_total{cache}` | counter | Object (`object`) and object-name (`name`) cache lookups answered from the cache or sent to the database; the hit ratio is `hits / (hits + misses)`. Counted in APCu and flushed with the request metrics, so they lag by at most a minute and stay 0 without APCu. |
| `openregister_import_objects_processed_total{job_id, phase}` | counter | Rows a running bulk import job has decided (`phase="preview"`) or written, including failed writes (`phase="commit"`); only jobs that are previewing or committing are published. |
| `openregister_import_duration_seconds{job_id}` | gauge | Seconds since a running bulk import job was created. |
| `openregister_db_connection_healthy` | gauge | 1 when a `SELECT 1` round-trip succeeds within 5 seconds, else 0 (the slow or failed round-trip is logged as a warning). |

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

For time-windowed data (embedding stats, search latency per search type, storage growth), `MetricsService` offers `getEmbeddingStats()`, `getSearchLatencyStats()` and `getStorageGrowth()`. They are not exposed via `/api/metrics`, which is a snapshot at scrape time.

The metrics retention policy is 90 days by default; the `cleanOldMetrics(int $retentionDays)` cleanup job prunes older rows.

### Alert thresholds

Three thresholds are set as app config values; unset, zero or not a whole number means off.

| Setting | Gauge | When it is 1 |
|---------|-------|--------------|
| `error_rate_alert_percent` | `openregister_error_rate_exceeded` | More than this share of the requests in the last window answered with a 5xx status. |
| `response_time_p95_alert_ms` | `openregister_response_time_exceeded` | The 95th percentile request duration of the last window (interpolated inside its histogram bucket) is over this many milliseconds. |
| `storage_growth_alert_bytes` | `openregister_storage_growth_exceeded` | The vectors added in the last 24 hours take more than this many bytes. |

```bash
occ config:app:set openregister error_rate_alert_percent --value=5
occ config:app:set openregister response_time_p95_alert_ms --value=3000
```

The `RequestAlertJob` background job judges the request thresholds every five minutes over the requests answered since its previous run. When a condition starts, every member of the `admin` group gets one Nextcloud notification; no second one follows until a window has been below the threshold again. A window without requests keeps the previous outcome.

## Request log entries

Every answered OpenRegister request writes one INFO entry to the Nextcloud log with the context
fields `request_id`, `user`, `method`, `path`, `status_code`, `duration_ms`, `register` and
`schema`. `request_id` equals the `reqId` Nextcloud writes on every entry of the same request, so
a log platform can group them. A database failure writes an ERROR entry with `error_type`,
`error_message` (quoted values masked as `?`), `stack_trace` (without arguments) and
`context.register`, `context.schema`, `context.action`. Request bodies and query values are
never logged. INFO entries are only kept when the Nextcloud `loglevel` is 1 or lower.

## Dashboard widget

Administrators can add the **OpenRegister operations** widget to their Nextcloud dashboard. It
shows the total objects, registers and schemas, and the server errors and average response time
of the last five-minute window judged by `RequestAlertJob`. Other users do not see it.

## Audit logging

Object writes are recorded in `oc_openregister_audit_trails` (with full before/after diffs) and surface via the standard `oc_activity` stream. See [Versioning and Audit](versioning-and-audit.md) for the audit-trail data shape and `Activity Provider` for the activity-stream integration.

## Test coverage

`tests/Service/ProductionObservabilityIntegrationTest` (6 tests) verifies both endpoints end-to-end:

- Health: 200 with `{status, version, checks: {database, filesystem}}`; semver-shaped version.
- Metrics: Prometheus exposition format with `# HELP`/`# TYPE` comments; five canonical counters present; `Content-Type: text/plain; version=0.0.4`; counters parse as non-negative integers.
