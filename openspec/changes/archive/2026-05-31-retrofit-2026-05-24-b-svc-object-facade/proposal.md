# Retrofit — service bundle: object facade (extend object-lifecycle)

Describes observed behavior of the `ObjectService` facade and the schema/register cache handlers, classified by the scanner under the `service` cluster (2 sub-clusters). The facade orchestrates the already-specced object-lifecycle handlers; this change specifies the genuinely-facade-owned concerns (context resolution, result name hydration, save orchestration ordering, write protection) plus the two-tier schema/register cache. Code already exists — this change retroactively specifies it.

## Affected code units

### Sub-cluster: object-service-facade-extends (ObjectService + handlers)
- lib/Service/ObjectService.php — context resolution, name hydration, save orchestration, write protection (annotated)
- lib/Service/Object/SaveObject.php — DROPPED, owned by object-lifecycle#REQ-001/REQ-005 (save pipeline)
- lib/Service/Object/MergeHandler.php — DROPPED, thin facade delegation; merge mechanics owned by referential-integrity
- lib/Service/Object/LockHandler.php — DROPPED, already annotated (object-interactions retrofit task-59)
- lib/Service/Object/RenderObject.php — DROPPED, render owned by object-lifecycle render path
- lib/Service/Object/BatchOperationStatus.php — DROPPED, already annotated (reference-existence-validation)
- lib/Service/Object/SaveObjects/TransformationHandler.php — DROPPED, already annotated (annotate-openregister task-25)

### Sub-cluster: schema-and-register-cache-handlers
- lib/Service/Schemas/SchemaCacheHandler.php — two-tier (memory + persistent) schema cache (annotated)
- lib/Service/Registers/RegisterCacheHandler.php — register find-cache invalidation (annotated)
- lib/Service/Schemas/FacetCacheHandler.php — DROPPED, already annotated (annotate-openregister task-30)
- lib/Service/Schemas/PropertyValidatorHandler.php — DROPPED, property validation owned by object-lifecycle#REQ-002
- lib/Service/RequestScopedCache.php — DROPPED, already annotated (annotate-openregister task-58)

## Approach

`ObjectService` is a ~74-method facade the REST controllers call. The scanner flagged all of it under `service`, but most public entrypoints (`listObjects`, `createObject`, `updateObject`, `patchObject`, `mergeObjects`, `validateObjectsBySchema`) are thin delegations to handlers already specced under object-lifecycle, or disabled stubs (`exportObjects`, `importObjects`, `downloadObjectFiles` throw "temporarily disabled"). Those are DROPPED.

What is genuinely facade-owned and uncovered: (a) request-scoped register/schema/object **context resolution** with cached-entity lookup and deliberate RBAC/multitenancy bypass when deriving context from an accessible object; (b) **result name hydration** — collecting related-object UUIDs from query results so the frontend can show names; (c) the **save orchestration ordering** the facade enforces before delegating to the SaveObject pipeline (always-defaults → date-normalization → validation); and (d) **transferred / append-only write protection** the facade applies before any mutation. These become REQ-006..REQ-009.

The schema/register cache sub-cluster adds REQ-010: a two-tier (in-memory + persistent table) schema cache with warm-on-miss and explicit invalidation on schema/register CRUD.

Notes:
- `getActiveOrganisationForContext()`, `buildSearchQuery()`, `searchObjects*()` are search/tenant concerns owned by zoeken-filteren / tenant-isolation specs — not re-specced here.
- The disabled export/import/download stubs are documented as DROPPED rather than specced, since they throw unconditionally.

Source: /tmp/or-scan/bundle-svc-object-facade.json. See retrofit playbook.
