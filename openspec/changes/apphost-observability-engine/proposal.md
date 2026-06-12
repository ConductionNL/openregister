---
kind: code
---

# Proposal: AppHost Declarative Observability Engine

## Problem

Every Conduction app ships its own hand-written `HealthController` and `MetricsController`. A 2026-06-12 fleet inventory (18 repos) found:

- 15 `HealthController.php` and 13 `MetricsController.php` near-duplicates, all drifted copies of the petstore/nextcloud-app-template skeleton.
- **100% of the 21 health checks** across the fleet reduce to 5 primitives: database `SELECT 1`, temp-file write, app-enabled check, appconfig key assertion, OpenRegister availability.
- **~92% of the ~50 metrics** reduce to 6 source kinds: implicit `info`/`up`, OR object counts (optionally grouped by a JSON field, filtered by date, or summing a numeric field), own-table counts, appconfig counters. Only 4 metrics are genuinely imperative (shillinq customer-bridge circuit-breaker state, nldesign CSS-file parsing).
- The drift has produced real contract violations against ADR-006: openbuild's health endpoint requires auth (must be public), nldesign's metrics endpoint is public (must be admin), shillinq's metrics emit JSON instead of Prometheus text, and larpingapp/softwarecatalog/zaakafhandelapp have **no health or metrics endpoint at all**.
- pipelinq and procest aggregate OR object JSON in PHP because portable JSON-SQL across MySQL/PostgreSQL is hard in a leaf app — a problem OpenRegister's query layer already solves.

## Proposed Change

OpenRegister grows an **AppHost observability engine**: apps declare their health checks and metrics as a JSON `observability` block in `src/manifest.json`; OpenRegister executes the descriptors and serves the responses through generic, reusable controllers. Leaf apps delete their hand-written controllers and keep at most a thin provider class for the truly imperative residue.

1. **Manifest schema**: an `observability` block with `health.checks[]` (5 check types) and `metrics[]` (6 source kinds), per ADR-040 (hydra).
2. **Engine**: `OCA\OpenRegister\AppHost\HealthCheckExecutor` and `OCA\OpenRegister\AppHost\MetricsEngine` execute descriptors. Metrics object queries run through OR's existing portable aggregation layer (fixing the pipelinq/procest PHP-side aggregation). Per-metric `cacheTtl` honoured via `ICacheFactory` (procest parity: 30s/60s).
3. **Generic controllers**: `AppHost\Controller\GenericHealthController` (public, ADR-006) and `AppHost\Controller\GenericMetricsController` (admin, Prometheus text 0.0.4). Leaf apps point their existing `/api/health` and `/api/metrics` routes at these via container service aliases — probe/scrape URLs do not change.
4. **Implicit metrics**: every app gets `{app}_info` (version, php_version, nextcloud_version labels) and `{app}_up` without declaring them.
5. **Escape hatch**: `IHealthCheckProvider` / `IMetricsProvider` interfaces, discovered by service alias `OCA\OpenRegister\AppHost\IMetricsProvider::{appId}` (same pattern as ADR-035 MCP providers). A `{"kind":"provider"}` descriptor merges provider output into the generic response.
6. **Policy knobs**: `statusCodePolicy` (`adr006` = 503 on critical failure, `always200` = decidesk REQ-API-004 reverse-proxy contract), optional `cors` flag (decidesk parity).
7. **Contract tests**: a Newman collection in OR asserting the generic endpoints' response shape, auth posture, and exposition format — guarding all adopting apps against regression at once.

### Scope

**In scope**: engine, generic health/metrics controllers, descriptor execution for all 5 check types and 6 source kinds, provider interfaces, ManifestService consumption of the `observability` block, Newman contract collection, unit tests.

**Out of scope**: leaf-app adoption (one chained `adopt-apphost` change per app), the boilerplate Dashboard/Preferences/Settings extraction (sibling change `apphost-boilerplate-controllers`), the ADR text itself (hydra change `adr-040-declarative-observability`), push-based metrics, historical metric storage.

## Impact

- **New files**: ~10 under `openregister/lib/AppHost/` (engine, controllers, provider interfaces, descriptor value objects), 1 Newman collection, unit tests.
- **Modified**: `ManifestService` (parse + validate `observability`), `appinfo/routes.php` (none — generic controllers are routed from leaf apps' own routes.php via aliases).
- **Downstream**: 18 per-app `adopt-apphost` changes depend on this spec (see `depends_on` chains).
- **Risk**: one engine bug affects all adopters at once — mitigated by the Newman contract collection and by per-app e2e smoke checks in the adoption specs.

## Dependencies

- ADR-040 (hydra: `adr-040-declarative-observability`) defines the manifest block contract this engine implements.
- ADR-006 (observability endpoint contract) — unchanged, now enforced centrally.
- ADR-035 provider-alias discovery pattern (reused for the escape hatch).
