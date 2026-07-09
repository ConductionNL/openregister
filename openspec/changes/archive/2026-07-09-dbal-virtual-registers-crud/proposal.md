---
kind: code
---

# Proposal: dbal-virtual-registers-crud

## Why

The merged `dbal-virtual-registers` feature (openregister#322/#327/#331) exposes external relational databases as **read-only** virtual registers. Real deployments also need to write back: a permit clerk working in a Conduction app must be able to create, correct, or remove rows that live in the external line-of-business database — without a second master, sync job, or bespoke integration. The read-only stance was a deliberate v1 boundary (hydra ADR-064 Rule 4), not a permanent one; this change lifts it in a controlled, opt-in way.

## What Changes

- **Writable object-source seam**: a new `WritableObjectSourceProvider` interface (extends `ObjectSourceProvider`) with `insert`/`update`/`remove`. Only `DbalObjectSourceProvider` implements it; the eight Nextcloud-native providers remain read-only.
- **Conditional dispatch**: the read-only guards in `SaveObject` (~line 2796) and `DeleteObject` (~line 507) delegate to the writable provider when — and only when — the schema's `x-openregister-object-source.readOnly === false` AND the backing `type: database` Source currently has `authConfig.writable === true` (checked live at write time, fail-closed). Everything else keeps today's rejection.
- **Opt-in plumbing**: a `writable` toggle on the database source (admin UI + API); introspection/re-introspection stamps `readOnly` accordingly on every produced schema. Views and no-PK tables are never update/delete-able regardless of the flag.
- **SQL write mapping**: parameterized INSERT/UPDATE/DELETE against introspected scalar columns only (unknown properties → 400); generated single-column PKs returned via PostgreSQL `RETURNING` / `lastInsertId`; composite-PK predicates reconstructed from the joined object id.
- **Error semantics**: external constraint violations map to sanitized 4xx (unique/FK → 409, NOT NULL/check/type → 422) via a new typed exception handled by `ObjectSourceErrorMiddleware`; connection failures stay 502/503.
- **RBAC + audit**: schema-level `create`/`update`/`delete` permission checks BEFORE the external database is touched (no enumeration oracle); audit-trail rows are recorded for external writes.

Follow-up outside this change: amend hydra **ADR-064 Rule 4** (read-only v1 → opt-in writable v2) in a separate hydra PR.

## Capabilities

### Modified Capabilities
- `dbal-virtual-registers` — write operations become conditionally allowed; the unconditional "writes are rejected" requirement becomes the read-only default.

## Impact

- Backend: `lib/Service/ObjectSource/` (new interface + provider writes), `lib/Service/Object/SaveObject.php`, `lib/Service/Object/DeleteObject.php`, `lib/Service/Dbal/DatabaseIntrospectionService.php`, `lib/Middleware/ObjectSourceErrorMiddleware.php`, `lib/Controller/SourcesController.php` (writable flag round-trip).
- Frontend: `src/modals/source/EditSource.vue` (writable toggle) — minimal.
- Security surface: outbound writes to external databases (opt-in, parameterized, column-allowlisted, RBAC-gated, sanitized errors; credential custody per OR ADR-004 unchanged).
- No migrations; no register JSON changes; native object-source providers unaffected.
