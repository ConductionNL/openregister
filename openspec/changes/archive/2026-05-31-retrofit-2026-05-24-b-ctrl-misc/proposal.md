---
status: draft
retrofit: true
---

# Retrofit: Reverse-spec controller bundle — misc (5 sub-clusters)

## Why

A coverage scan of OpenRegister's controller layer grouped five leftover
"miscellaneous" controller sub-clusters whose HTTP-surface contracts were not
yet captured by any spec. The underlying services are implemented; what was
missing is the *controller* contract (route shape, status codes, parameter
handling, error envelopes). This ghost change reverse-specs the observed
controller behavior and annotates the methods, biasing strongly toward
**extending** existing capabilities and toward **annotation-only** where the
behavior is already owned by a live spec or in-flight change.

This is a documentation/annotation change only. No runtime behavior is modified.

## What changes

Five sub-clusters, all `--extend`:

### 1. `ui-spa-mount` → extend **no-code-app-builder** (one consolidated REQ)

`UiController` is 25 history-mode deep-link routes (`/registers`, `/schemas`,
`/objects`, `/tables`, `/chat`, `/avg`, `/reports`, `/endpoints`, ...) that all
delegate to a single private `makeSpaResponse()` helper returning the same Vue
SPA `index` template with a permissive `connect-src '*'` CSP. Twenty-three of
the route methods are trivial one-line delegations (the SPA-mount stubs the
`openregister-app-manifest` work references); per architect review they are
**captured as ONE consolidated REQ describing the SPA-mount contract, not 25
trivial per-route REQs**. The 23 trivial stubs are documented by the single REQ
and not individually annotated. Only the load-bearing methods are annotated:
the `makeSpaResponse()` contract helper, and the two non-trivial routes
(`integrationsView` — the per-leaf screenshot-harness route — and the AVG/
reports surface noted in the contract).

> NOTE: `no-code-app-builder` is a local redirect stub; the canonical cross-app
> no-code builder lives in the root openspec. The REQ added here documents the
> **OpenRegister-local SPA shell** (`UiController`), which is the PHP mount
> surface the manifest-driven UI loads into — distinct from the cross-app
> builder. When the `openregister-app-manifest` spec lands in `openspec/specs/`,
> this REQ SHOULD migrate there.

### 2. `object-integration-dispatch` → CROSS-CUTTING (annotate-only)

`IntegrationsController` (read-only registry discovery API) and
`ObjectIntegrationsController` (object-scoped sub-resource dispatch through the
pluggable registry) are **already annotated** with
`@spec openspec/changes/pluggable-integration-registry/tasks.md#task-18` /
`#task-19`, and that capability has its own dedicated reverse-spec change
(`retrofit/rspec-newcap-pluggable-integration-registry-and-providers`). Per the
"bias extend / do not duplicate" guardrail these two files are **NOT re-specced
here and NOT re-annotated** — they are already covered by a more specific,
live capability. The bundle's nominal `product-service-catalog` target is a
redirect stub and is the wrong home; the existing `pluggable-integration-registry`
annotations are authoritative. **No new REQs, no edits** for this sub-cluster.

### 3. `object-collaboration-attachments` → extend **object-interactions**

`NotesController`, `TasksController`, and `TagsController` are the object-scoped
collaboration attachments. `object-interactions` already fully covers Notes,
Tasks (per-object), Tags, and the sub-resource pattern — those members are
**annotated to the existing REQs, not re-specced**. Two genuinely uncovered
surfaces get new REQs:

- `TasksController::allUserTasks()` — the cross-calendar `/api/tasks` aggregate
  endpoint (all of the session user's VTODOs across every calendar, with
  status/assignee filters). The existing spec only covers *per-object* task
  endpoints; the user-wide aggregate is uncovered. → one new REQ.
- `DeckController` (`index`/`create`/`objects`) — Nextcloud Deck card linkage on
  objects, plus the board-reverse-lookup. Deck is not mentioned anywhere in
  `object-interactions`. → one new REQ.

### 4. `scope-rbac-api` → extend **rbac-scopes**

`ScopesController` implements `GET /api/scopes` — the effective-scope discovery
endpoint that returns the current user's permitted `(register, schema, actions)`
tuples by probing `PermissionHandler` per canonical action, with admin
short-circuit and optional `register`/`schema` filters. `rbac-scopes` documents
the OAS scope generation and the "Frontend Scope Checking" need but has **no REQ
for the runtime effective-scope discovery API itself**. → one new REQ.
`resolveRegisters`/`resolveSchemas`/`collectActionsForUser` are private helpers
of `index()` — annotated under the same REQ, no separate REQ.

### 5. `workflow-transition-tables-migration` → extend **workflow-engine-abstraction**

`TransitionController` (`transition`/`availableActions`), `MigrationController`
(`migrate`/`status`), and `TablesController` (`sync`/`syncAll`) are data-shape
and lifecycle operations, not REST CRUD. The existing spec covers engine
adapters and `WorkflowEngineController` but **none of these three controllers**.
Three new REQs:

- Lifecycle transition HTTP surface (`TransitionController` over
  `TransitionEngine` / `x-openregister-lifecycle`).
- Storage migration HTTP surface (`MigrationController`: blob ↔ magic-table
  migration runner + status).
- Magic-table sync HTTP surface (`TablesController`: explicit per-pair and
  all-pairs schema-to-table sync).

## New requirements summary (target ≤ ~10)

| Capability | New REQs | Notes |
|---|---|---|
| no-code-app-builder | 1 | consolidated SPA-mount contract (covers 23 stubs) |
| object-interactions | 2 | cross-calendar tasks aggregate; Deck card linkage |
| rbac-scopes | 1 | effective-scope discovery API (`GET /api/scopes`) |
| workflow-engine-abstraction | 3 | transition / storage-migration / table-sync HTTP surfaces |
| product-service-catalog | 0 | annotation-only — already covered by pluggable-integration-registry |
| **Total** | **7** | within budget |

## Impact

- Specs extended: `no-code-app-builder`, `object-interactions`, `rbac-scopes`,
  `workflow-engine-abstraction`.
- Specs referenced (annotation targets, no delta): `pluggable-integration-registry`
  (already annotated upstream).
- Controllers annotated: `UiController`, `NotesController`, `TasksController`,
  `TagsController`, `DeckController`, `ScopesController`, `TransitionController`,
  `MigrationController`, `TablesController`.
- Dropped: the 23 trivial `UiController` SPA-mount stubs (covered by one REQ,
  not individually annotated); `IntegrationsController` /
  `ObjectIntegrationsController` (already covered elsewhere).
- No code behavior change; docblock `@spec` annotations only.
