## Context

`SchemaMapper::find()` and `RegisterMapper::find()` take a `_multitenancy: bool` argument (default `true`) that gates the `MultiTenancyTrait::applyOrganisationFilter()` WHERE clause. When `_multitenancy: true` and the current user has no active organisation, the filter resolves to `organisation IS NULL`, so any schema/register with a concrete `organisation` value is invisible — including to admins who happen not to have an active organisation set on their session.

The runtime symptom is the 2026-05-27 Newman triage: 5 aggregation assertions in the `platform-annotations` collection fail with `Schema "<ref>" not found` because `AggregationRunner::loadSchema()` (lib/Service/Aggregation/AggregationRunner.php:1924) keeps the default tenancy filter on while running as admin without an active org. The aggregation never gets to compute anything — the runner 404s on the schema lookup.

The deeper issue is that OpenRegister has two conflicting habits for schema lookups already in production:

| Path                                                  | `_multitenancy`     | Intent                                           |
| ----------------------------------------------------- | ------------------- | ------------------------------------------------ |
| `SchemasController::index` L205                       | `false` (explicit)  | `@PublicPage` catalog list                       |
| `SchemasController::show` L296                        | `false` (explicit)  | `@PublicPage` catalog read                       |
| `SchemasController::download` L938                    | `true` (default)    | Read JSON dump                                   |
| `SchemasController::related` L972, `findAll` L974     | `true` (default)    | Read references                                  |
| `SchemasController::stats` L1031                      | `true` (default)    | Read stats                                       |
| `SchemasController::publish` L1211                    | `true` (default)    | Read-then-mutate (publish toggle)                |
| `SchemasController::depublish` L1297                  | `true` (default)    | Read-then-mutate (publish toggle)                |
| `SchemasController::update` L535                      | `true` (default)    | Mutation authorization                           |
| `SchemasController::destroy` L689                     | `true` (default)    | Mutation authorization                           |
| `SchemasController::upload` L811                      | `true` (default)    | Mutation authorization                           |
| `AggregationRunner::loadSchema` L1930                 | `true` (default)    | Read for aggregation compute                     |
| `AggregationRunner::loadRegister` L1949               | `true` (default)    | Read for aggregation compute                     |

Two of these (`index`, `show`) decided years ago that schema *definitions* are a globally-visible catalog — `_multitenancy: false`. Tenant isolation in OpenRegister has always lived at the OBJECT row level through `MultiTenancyTrait` + the per-row `_organisation` column on magic tables (see `auth-system` REQ "Multi-tenancy isolation MUST restrict data access to the user's active organisation"). The remaining read paths inherited the default mostly because nobody wrote a policy.

Existing related architecture:
- `auth-system` already has REQ "Admin users see all organisations" (multi-tenancy bypass for admins) and REQ "Multi-tenancy isolation MUST restrict data access to the user's active organisation" (object-row scope).
- The `auth-system` REQ "Register and schema read endpoints MUST remain reachable when OpenRegister is restricted to a user group" was added 2026-05-27 in the read-accessibility change; this is the precedent for treating schema metadata as a globally-visible catalog.
- `BackfillSystemOwnerCommand` already uses `_multitenancy: false` for schema/register lookups (REQ-001) — the OCC-command precedent.

Stakeholders: backend developers writing read-path lookups against `SchemaMapper`/`RegisterMapper`; the Newman test platform-annotations collection; admin users running aggregations.

## Goals / Non-Goals

**Goals:**
- Codify a clear, spec-level policy for when schema/register metadata lookups bypass multi-tenancy versus when they enforce it.
- Repair the 5 `platform-annotations` aggregation assertions by making `AggregationRunner::loadSchema/loadRegister` consistent with the catalog-read paths that already exist.
- Eliminate the policy ambiguity in `SchemasController`'s seven read-default lookups so the same class of bug doesn't keep recurring on every new read path.
- Make the policy testable: each path's tenancy-bypass status MUST be explicit in code AND derivable from the spec.

**Non-Goals:**
- Changing the object-row multi-tenancy contract. `MultiTenancyTrait` against `MagicMapper` queries stays exactly as it is — that's where tenant isolation lives.
- Introducing a new actor-resolution helper (e.g. `isAdminOrSystemActor()`). Approach A would require one; this change deliberately picks the simpler policy that doesn't need one (see Decision 1).
- Changing the `_multitenancy: true` default on `SchemaMapper::find`/`RegisterMapper::find`. Mutation paths keep their current safe default. The change is per-caller, not per-mapper.
- Adding a `$crossTenant` argument to `AggregationRunner::loadSchema()`. See Decision 1 rejection of Approach D.
- Frontend or API contract changes. The spec is a backend invariant only.

## Decisions

### Decision 1: Adopt Approach B — read paths bypass multi-tenancy, write paths keep it on

**Choice:** Codify that schema- and register-**metadata-READ** lookups MUST pass `_multitenancy: false`; metadata-**WRITE** (mutation-gating) lookups MUST keep `_multitenancy: true` (default). Sweep `AggregationRunner` AND the inconsistent `SchemasController` read paths in this change.

**Why B over A/C/D:**

| Approach                                | Pros                                                                                                                                                       | Cons                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **A. Admin-aware bypass**               | Tenant users keep the schema-definition filter (defence in depth); admins/system get the bypass.                                                            | Requires a new `isAdminOrSystemActor()` resolver — new code in a sensitive layer, new test surface. Tenant users still can't resolve published schemas owned by a sibling tenant, which contradicts the *existing* `@PublicPage` catalog precedent (`index`/`show` already give them that). Two policies coexisting on the same mapper. Doesn't repair the publish/depublish/stats/download path for tenant users at all.                  |
| **B. Read-paths-always-bypass (CHOSEN)**| Matches the existing `index`/`show` precedent and the `BackfillSystemOwnerCommand` precedent. Tenant isolation lives at the object-row level — consistent with the `auth-system` mental model. One uniform rule across every metadata-read caller. No new helper. Zero risk of definition leakage because schema definitions are already globally visible per `index`/`show`. | Requires sweeping the seven inconsistent `SchemasController` read paths in addition to `AggregationRunner` (mitigated: explicit migration list in the spec).                                                                                                                                                                                                                                                                              |
| **C. Explicit organisation context for admin** | Lets `_multitenancy: true` keep working naturally everywhere.                                                                                            | Forces auth/session layer changes for what is essentially a per-call argument. Doesn't address the cross-tenant schema-definition visibility question (still hides sibling-tenant schemas from non-admins, contradicting `index`/`show`). Migrating session resolution is a much larger change than tuning a per-call argument.                                                                                                            |
| **D. Per-call `$crossTenant` argument** | Local, no policy commitment.                                                                                                                                | Pushes the decision onto every future caller with no spec to guide them — the exact same bug we're trying to prevent will recur. Adds a parallel argument that mirrors what `_multitenancy` already does. No precedent in the codebase.                                                                                                                                                                                                       |

**Decisive reason:** The `@PublicPage` catalog precedent (`SchemasController::index/show` at `_multitenancy: false`) plus the `auth-system` REQ "Register and schema read endpoints MUST remain reachable when OpenRegister is restricted to a user group" already declares schema metadata to be globally readable. Approaches A, C, D all introduce mechanics to preserve a tenant-scoped *definition* read — but the codebase no longer needs that, because it doesn't enforce it on the two endpoints that matter most. Approach B is the only option that aligns the runtime with the existing public contract.

**Provisional — pending human confirmation.** See DEFERRED_QUESTIONS below.

### Decision 2: Put the policy in `auth-system`, not a new capability

The policy is a *clarification* of how the existing `auth-system` multi-tenancy isolation requirement applies to schema/register metadata reads versus object data. It does not introduce a new operational surface (no new endpoint, no new entity, no new mechanism); it tightens the semantics of an existing one. Creating a `aggregation-multitenancy-policy` capability would scatter the multi-tenancy story across two specs and force `aggregation-runner-multitenancy-policy` to remain visible forever as a separate capability — when the durable concept is just "metadata reads vs object reads vs writes" inside `auth-system`.

Rejected alternatives:
- New capability `aggregation-multitenancy-policy`: too narrow — the policy spans `SchemasController` too.
- New capability `schema-metadata-visibility`: would duplicate concerns already settled by the `@PublicPage` catalog precedent inside `auth-system`.

**Provisional — pending human confirmation.** See DEFERRED_QUESTIONS below.

### Decision 3: Scope the apply to BOTH `AggregationRunner` AND the inconsistent `SchemasController` read paths in this change

The Newman finding is narrow (5 aggregation assertions), but the underlying class of bug is broader. If we patch only `AggregationRunner`, the next read path added to `SchemasController` (e.g. a future export endpoint, a discoverability endpoint) will inherit the same wrong default and re-introduce the same 404 class of bug. The spec scenarios make the policy easy to audit, but the implementation must demonstrate the sweep is doable in a single bounded change.

The seven `SchemasController` read paths to sweep are: `download` (L938), `related` (L972 + the `findAll()` at L974), `stats` (L1031), `publish` (L1211), `depublish` (L1297). The three mutation-gating paths (`update` L535, `destroy` L689, `upload` L811) keep the default `_multitenancy: true`. (Publish/depublish are read-then-mutate; the mutation step is governed by management permission, the read step should still bypass — same logic as `index`/`show`.)

Rejected alternatives:
- Narrow scope to AggregationRunner only: punts the SchemasController inconsistency to "a future PR" — which by `feedback_always-file-issues.md` would need to be an issue filed now. Cheaper to fix it once.

**Provisional — pending human confirmation.** See DEFERRED_QUESTIONS below.

### Decision 4: Keep the `_multitenancy: true` default on the mappers

The default stays as-is so that any new caller defaulting their argument writes the safe (tenant-scoped) behaviour — which is correct for mutation paths and incorrect for read paths, but the read-path callers are now spec-enforced to opt out explicitly. Flipping the default would silently relax tenant scoping for every existing mutation caller that didn't specify the argument; the sweep is bounded, the default is not.

### Decision 5: No new actor-resolution helper

Approach A would have required `isAdminOrSystemActor()` or similar. The chosen Approach B doesn't differentiate by actor — every metadata-read caller bypasses tenancy regardless of who is calling. This keeps the policy auditable from the call site without needing to trace runtime user state.

## Risks / Trade-offs

- **Risk**: A future read path inside `SchemasController` (or another controller / service) is added without `_multitenancy: false` and re-introduces the bug class.
  - **Mitigation**: The added spec scenarios become test cases per the `unit-test-coverage` capability. Combined with the existing `check_spec_coverage.py` gate, any new method on a read-only schema lookup must either be covered by a spec scenario or `@spec exclude`-ed with a reason. The policy text in `auth-system` is the reference reviewers cite.
- **Risk**: Schema-definition information leakage to tenant users who shouldn't see a sibling tenant's schemas.
  - **Mitigation**: This is **not a new exposure** — `SchemasController::index`/`show` are already `@PublicPage` and already pass `_multitenancy: false`, so any tenant user can already list/read every schema definition. The change brings the rest of the read paths in line with that existing public contract. Object-row data continues to be tenant-isolated by `MultiTenancyTrait` against `MagicMapper`.
- **Risk**: The `// SECURITY: keep multitenancy filter on so a tenant user cannot resolve schemas owned by another tenant simply by knowing the slug` comment in `loadSchema()` describes a threat model the codebase no longer enforces; removing it could surprise a reader.
  - **Mitigation**: Replace the comment with a corrected rationale that references the new `auth-system` requirement and the `@PublicPage` catalog precedent.
- **Trade-off**: We give up the option to enforce per-tenant schema-definition visibility entirely. That's a coherent choice — OpenRegister is designed for a globally-visible catalog with tenant-scoped object data; if a future deployment needs schema-definition-level tenancy, it's a much larger architectural change (different from this one).

## Migration Plan

Code change is pure refactor of read-path lookups — no DDL, no data migration. Rollout per the apply tasks:

1. Update `auth-system` spec with the new requirement (this change).
2. Update `AggregationRunner::loadSchema/loadRegister` to pass `_multitenancy: false` and replace the misleading comment.
3. Sweep `SchemasController` read paths.
4. Run the `platform-annotations` Newman collection — the 5 failing assertions MUST go green.
5. Run full PHPUnit + PHPStan to confirm no regression.

Rollback: revert the `_multitenancy: false` arg-passing. Spec change is independently revertable. No data state to undo.

## Open Questions

See DEFERRED_QUESTIONS in the apply-change handoff.
