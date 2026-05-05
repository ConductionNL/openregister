# Design — retrofit-oas-generation-2026-05-01

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work. The code exists; this design documents the observed architecture.

## Architecture

`OasController` exposes two public GET endpoints registered in `appinfo/routes.php`:
- `GET /api/oas` → `generateAll()`
- `GET /api/oas/{id}` → `generate(string $id)`

Both delegate to `OasService::createOas(?string $registerId)`, which is the sole entry point for OAS generation. The service holds the full generation pipeline: template loading, schema enrichment, RBAC scope extraction, path generation, and integrity validation.

## Key decisions

1. **Public endpoints, RBAC bypassed** — OAS generation is intentionally unauthenticated (`@PublicPage`). The service uses `_rbac: false, _multitenancy: false` so all registers/schemas appear regardless of the requesting user. This is the correct contract for API documentation.

2. **Single service method, scope via nullable parameter** — Both endpoints call the same `createOas()`. The `null` / non-null distinction controls whether the `info` section is generic or register-specific and whether all registers or one register's paths are generated. This keeps the generation logic DRY.

3. **Extended endpoints whitelist is empty by design** — `INCLUDED_EXTENDED_ENDPOINTS` is a constant that currently lists no endpoints. Audit-trails, files, lock/unlock paths are implemented in the switch statement but not exposed. This is a deliberate conservative stance: expose only stable CRUD, not all possible endpoints.

## Risks / trade-offs

- **500 for all failures** — both controller methods return 500 for any exception, including missing register IDs. There is no `404 Not Found` path for unknown registers. Callers cannot distinguish "server error" from "register not found" without parsing the error message.
- **RBAC bypass as a feature** — since all schemas are loaded regardless of caller permissions, the OAS document reveals all schema definitions to unauthenticated callers. This is intentional but should be reviewed if any schema contains sensitive field definitions.
