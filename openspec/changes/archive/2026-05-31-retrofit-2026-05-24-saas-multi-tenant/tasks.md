# Tasks

Retroactive annotation only — every task documents existing behaviour
in `lib/Service/TenantKeyService.php`. No runtime changes.

- [x] task-1: saas-multi-tenant#REQ-001 — Per-tenant active HMAC key MUST be a single most-recent row (retroactive annotation of `TenantKeyService::fetchActiveRow`)
- [x] task-2: saas-multi-tenant#REQ-002 — New active keys MUST be stored encrypted with status='active' (retroactive annotation of `TenantKeyService::insertKey`)
- [x] task-3: saas-multi-tenant#REQ-003 — TenantKeyService MUST be DI-registered as a server-side internal API (no new annotation — `Application.php` wiring observed but not in cluster scope)

## REQ → method map

| REQ | task | Methods tagged |
|---|---|---|
| REQ-001 | task-1 | `fetchActiveRow` (private; called by `getCurrentTenantKey` + `rotateTenantKey`) |
| REQ-002 | task-2 | `insertKey` (private; called by `bootstrapKey` + `rotateTenantKey`) |
| REQ-003 | task-3 | DI wiring in `lib/AppInfo/Application.php` (not annotated — documented in proposal) |
