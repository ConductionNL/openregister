---
kind: code
---

# Proposal: OpenRegister Adopts Its Own AppHost (Dogfood)

## Problem

OpenRegister ships the AppHost engine but still hand-writes its own `HealthController` (161 lines: database + filesystem checks) and `MetricsController` (362 lines: registers/schemas/objects-by-register×schema, audit-trail CRUD counters, webhook delivery counters, search-request counter). If OR doesn't run on its own engine, the engine's first real regression will be found by a leaf app instead of by OR's own CI.

## Proposed Change

Replace OR's hand-written health/metrics with manifest descriptors executed by its own AppHost engine.

- `observability.health.checks`: `database`, `filesystem` (severity degraded) — exact parity with today.
- `observability.metrics` descriptors:
  - `registers_total` / `schemas_total` → `tableCount` on `openregister_registers` / `openregister_schemas`
  - `objects_total{register,schema}` → `tableCount` on `openregister_objects` with `groupBy` register/schema (label join handled by the engine's table source)
  - `objects_{created,updated,deleted,read}_total` → `tableCount` on `openregister_audit_trails` with `filter: {action: {eq: ...}}`
  - `webhook_deliveries_total{status}` → `tableCount` on `openregister_webhook_logs` `groupBy: [success]` with label mapping
  - `search_requests_total` → `tableCount` on `openregister_metrics` with `filter: {metric_type: {like: "search_%"}}`
- Delete `HealthController`; shrink `MetricsController` to the alias or a thin subclass if OCS registration requires it. Route names/URLs unchanged.
- Anything not expressible (none identified in the 2026-06-12 inventory) goes through `IMetricsProvider` — its absence is itself the dogfood assertion.

## Impact

- **Deleted**: ~520 lines of controller code; **modified**: `src/manifest.json`, `appinfo/routes.php` (alias wiring), `Application.php`.
- **Verification**: byte-compare Prometheus output before/after on a seeded dev instance (same metric names, types, labels); Newman contract collection from `apphost-observability-engine` runs against OR itself in CI.

## Dependencies

Chained: `apphost-observability-engine`, `apphost-boilerplate-controllers` (Bootstrap wiring).
