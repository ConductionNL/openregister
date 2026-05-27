## Why

The 2026-05-27 Newman triage on the `platform-annotations` collection surfaced 5 consecutive aggregation assertions failing with HTTP 404 `Schema "<ref>" not found`. Root cause: `AggregationRunner::loadSchema()` (lib/Service/Aggregation/AggregationRunner.php:1924) calls `SchemaMapper::find($ref)` with the default `_multitenancy: true`, and the admin user driving Newman has **no active organisation**. `MultiTenancyTrait::applyOrganisationFilter` therefore restricts the query to schemas where `organisation IS NULL`, excluding every schema the test seed creates with a concrete `organisation` value. The aggregation requests resolve nothing, the controller returns 404, and the assertions go red.

This is a single symptom of a broader inconsistency: `SchemasController::index` (L205) and `SchemasController::show` (L296) deliberately pass `_multitenancy: false` because those endpoints are `@PublicPage` and must stay reachable for cross-tenant catalog browsing, while **every other schema read path** in the controller (L535 update-lookup, L689 destroy-lookup, L811 upload-lookup, L938 download, L972 related, L1031 stats, L1211 publish, L1297 depublish) **and** `AggregationRunner::loadSchema/loadRegister` rely on the default `_multitenancy: true`. The intent here is mixed: some of those callers want the filter, some don't, and nothing documents the policy. Aggregation is the first place this mixed intent has produced a runtime failure on the happy path.

The change establishes a **named, spec-level policy** for when schema- and register-metadata READ lookups bypass multi-tenancy, and aligns the AggregationRunner (plus any other read-path lookups that contradict the chosen policy) with it. Schema definitions are a globally-visible catalog; per-row data is where tenant isolation lives (`MagicMapper` + `_organisation` per object, see `auth-system` REQ "Multi-tenancy isolation MUST restrict data access to the user's active organisation"). Codifying that explicitly closes the ambiguity, repairs Newman, and prevents the same class of bug in future read paths.

## What Changes

- Add a **schema/register read-vs-write multi-tenancy policy** to the `auth-system` capability: metadata READ lookups bypass multi-tenancy; metadata WRITE lookups keep it on. Tenant isolation is enforced at the OBJECT level via `MultiTenancyTrait` against `MagicMapper` queries, not at the schema-definition level.
- Define the policy precisely in terms of the existing `SchemaMapper::find` / `RegisterMapper::find` `_multitenancy` argument: every caller whose purpose is to **resolve a schema/register entity for reading metadata or computing over its rows** MUST pass `_multitenancy: false`. Every caller whose purpose is to **authorize an administrative mutation against the entity** MUST keep `_multitenancy: true` (default).
- Align `AggregationRunner::loadSchema()` and `AggregationRunner::loadRegister()` (lib/Service/Aggregation/AggregationRunner.php:1930, :1949) with the policy: both are read-path lookups that feed aggregation computation and MUST pass `_multitenancy: false`. The existing in-code `// SECURITY: keep multitenancy filter on` comment is wrong about the threat model — schema *definitions* are public; tenant isolation lives at the row level — and MUST be replaced with a corrected rationale.
- Sweep the read-path inconsistencies in `SchemasController` (`download` L938, `related` L972, `stats` L1031, `publish` L1211, `depublish` L1297) to match the policy (read = bypass). The mutation-gating lookups (`update` L535, `destroy` L689, `upload` L811) keep the filter on because their explicit purpose is to validate the caller's right to mutate.
- Add the `Schema "%s" not found.` 404 response semantics to the spec: unknown ref still returns 404 (caught by `AggregationController`) regardless of tenancy state.
- **No backward-incompatible behaviour change** for tenant users that already have an active organisation: their schemas were already resolvable. The only observable change is that admin/system-actor callers with no active organisation can now resolve schemas owned by any tenant — which is what they should already have been able to do (admins are documented to bypass multi-tenancy, see `auth-system` REQ "Admin users see all organisations").

## Capabilities

### New Capabilities
<!-- None — the policy belongs in auth-system, which already owns the multi-tenancy isolation requirements. -->

### Modified Capabilities
- `auth-system`: Add a new requirement that disambiguates the *schema/register metadata read* path from the *object data* path within multi-tenancy isolation. The existing REQ "Multi-tenancy isolation MUST restrict data access to the user's active organisation" already covers OBJECT rows via `MultiTenancyTrait`; this change adds a sibling REQ that schema/register definition reads bypass multi-tenancy, and metadata writes keep it on. This is a spec-level clarification of an existing capability, not a new capability.

## Impact

- **Affected code**:
  - `lib/Service/Aggregation/AggregationRunner.php` — `loadSchema()` L1924-L1934, `loadRegister()` L1945-L1953: pass `_multitenancy: false`, replace misleading SECURITY comment.
  - `lib/Controller/SchemasController.php` — five read-path lookups (download L938, related L972 + L974 `findAll()`, stats L1031, publish L1211, depublish L1297) MUST pass `_multitenancy: false`. The three mutation-gating lookups (update L535, destroy L689, upload L811) MUST keep the default. Audit-only sweep; no API contract change.
- **Affected APIs**: No request/response schema changes. The only behaviour change is that the affected endpoints become reachable for admin/system-actor callers with no active organisation, matching the documented `auth-system` admin-bypass contract.
- **Affected dependencies**: None — internal to OpenRegister.
- **Newman / verification**: The 5 failing `platform-annotations` aggregation assertions on the 2026-05-27 triage MUST go green. No other Newman collection is expected to shift status.
- **Seed Data**: NONE — this is pure backend policy. No schema definitions or register definitions change. No migration.
- **Database**: NONE — `_multitenancy` is a runtime argument; no DDL.
- **Frontend**: NONE — no UI contract change.
