## Context

OpenRegister already exposes CRUD routes on `/api/schemas` and
`/api/registers`. They were originally built for the OpenRegister admin
UI, which is used by Nextcloud admins to inspect data — not for runtime
schema authoring by non-admin citizen developers. Three things break
when the OpenBuilt builder UI (`openbuilt-schema-editor`, chain spec #4)
starts hitting these routes:

1. `SchemaCacheHandler` warms a per-request `array` cache from
   `oc_openregister_schemas` and never refreshes after a mutation. Reads
   later in the same worker miss the new state.
2. The four declarative engines
   (`LifecycleEngine`, `AggregationEngine`, `CalculationEngine`,
   `NotificationEngine`) register their per-schema bindings during
   bootstrap. They have no public `reload` surface. A runtime edit to
   `x-openregister-lifecycle` silently no-ops on objects published
   later in the same process.
3. `ImportHandler::importFromApp` was designed to import configurations
   shipped as installer JSON in `lib/Settings/{app}_register.json`. The
   matching Register row was provisioned by a separate `repair` step at
   install time. When the same handler is called at runtime from a
   builder UI, the installer's `repair` step does not run, so the
   Register row never appears.

The 2026-05-11 `bootstrap-openbuilt` smoke test (commit 3138e4c titled
*"Runtime smoke test — fix 5 real bugs"*) caught these gaps end-to-end.
Two of the five bugs are platform-level concerns that this spec resolves
in OpenRegister; the other three were OpenBuilt-local fixes already
landed on the apply branch.

The reference is at:
`apps-extra/openbuilt/openspec/changes/bootstrap-openbuilt/design.md`
sections **Decision 4** (in-memory manifest workaround) and **OQ-1**
(on_state lifecycle hooks vs PHP listener). OQ-1 in particular is the
upstream question this spec answers for the lifecycle engine: per-schema
reload is the supported in-process path.

## Goals / Non-Goals

**Goals:**

- Make `POST/PUT/PATCH/DELETE /api/schemas` and `/api/registers`
  correct on the first request after mutation in any PHP worker —
  no stale cache reads, no stale engine bindings.
- Ship a documented, idempotent auto-Register step in
  `importFromApp` so runtime callers (OpenBuilt, future builders)
  get a complete (Configuration, Schemas, Register) triple from one
  call.
- Bridge slug-aware callers to the numeric-ID `searchObjects`
  contract with a single helper, and document the numeric-ID
  requirement on `searchObjects` so any direct caller fails noisily
  (not silently).
- Keep all changes backwards-compatible: same wire formats, same
  routes, no DB migrations.

**Non-Goals:**

- Frontend / UI work: the builder UI lives in chain spec #4 in the
  openbuilt repo, not here.
- Permission model overhaul: `openregister.schema.write` already
  guards the existing routes; per-organisation RBAC on runtime schema
  authoring is chain spec #7.
- New audit-event categories: we reuse the existing
  `openregister.schema.created/updated/deleted` events.
- Versioning of runtime schemas: superseded by the existing
  `content-versioning` spec. This change does not alter version
  semantics.

## Decisions

### Decision 1 — Cache invalidation: imperative flush in service layer

The `SchemaService` and `RegisterService` methods that mutate an
entity (`create`, `update`, `delete`) SHALL call
`SchemaCacheHandler::invalidate(int $id)` (or `RegisterCacheHandler`)
imperatively after a successful `Mapper` round-trip and before
returning to the controller. The cache handlers grow a public
`invalidate(int)` method that unsets the in-process entry for the
given ID and bumps a per-handler version counter so any future
`getAll()` calls re-fetch from the database.

**Alternatives considered:**

- *Event-based invalidation* via Symfony's `EventDispatcher` — already
  used for audit events. **Rejected** because the listeners would
  execute *after* the controller returns the response, leaving a small
  window where a follow-up read in the same worker still sees stale
  state. The imperative flush is the simplest path that closes that
  window.
- *Drop the per-worker cache entirely* — pure read-through to the DB.
  **Rejected** because `SchemaService::find` is called dozens of times
  per request in the validation hot path; removing the cache regresses
  read latency by a measurable amount on integration tests.

The cache key shape stays unchanged (`schema:{id}`); only the lifecycle
gains the imperative invalidate hook.

### Decision 2 — Declarative-engine reload: per-schema, not whole-app

Each of the four declarative engines (`LifecycleEngine`,
`AggregationEngine`, `CalculationEngine`, `NotificationEngine`) SHALL
expose a public `reloadForSchema(int $schemaId): void` method. The
service layer calls it for every engine whose corresponding
`x-openregister-*` block CHANGED VALUE between the previous and the
new schema state (computed by deep-equality on the metadata block).
Unchanged metadata MUST NOT trigger a reload — this matters when
several callers update unrelated parts of the same schema in quick
succession.

For CREATE, the service layer calls `reloadForSchema` on every engine
whose corresponding block is present and non-empty in the new schema.

For DELETE, the service layer calls `reloadForSchema` on every engine
regardless of metadata; each engine drops its registry entry for the
absent ID and is a no-op if no entry exists.

**Alternatives considered:**

- *Whole-engine flush* — clear and re-bootstrap every engine on any
  schema change. **Rejected** because the lifecycle engine in
  particular re-binds all schemas in the database on bootstrap (a
  ~150ms operation on a populated dev environment), which would
  block concurrent requests holding a database snapshot.
- *Delegate to existing hot-reload via process restart* — rely on
  PHP-FPM cycling workers. **Rejected** because PHP-FPM keeps workers
  alive for minutes-to-hours; a builder UI cannot wait for a worker
  recycle to see its own schema edit.

This is the upstream answer to `bootstrap-openbuilt` OQ-1: the
lifecycle engine grows a real `reloadForSchema` hook, and the
ADR-031 fallback `BuiltAppRouteSyncListener` becomes unnecessary on
the OpenBuilt side.

### Decision 3 — Auto-Register creation: idempotent on `(slug, organisationId)`

`ImportHandler::importFromApp` SHALL look up an existing Register row
by the composite key `(slug = x-openregister.app, organisationId = currentOrg)`
before inserting. If a row matches, the import path becomes an
update-and-reconcile: the title/description fields are refreshed from
the new OAS document, the `schemas[]` field is unioned with the IDs
of every schema persisted in this import, and the row is saved. If no
row matches, a new Register is inserted with the schemas already
attached.

This keeps two important properties:

- A second import of the same OAS (e.g. on an app upgrade) does not
  spawn duplicate Register rows.
- A second import of the same OAS into a *different organisation*
  correctly creates an independent Register row, preserving the
  multi-tenant boundary OpenRegister relies on everywhere else.

**Alternatives considered:**

- *Use `x-openregister.app` as a global unique key* (no
  organisation tuple). **Rejected** because two organisations on the
  same Nextcloud must be able to install the same app independently;
  this is the standard OR multi-tenant contract.
- *Move register creation into a separate `POST /api/registers/from-import`
  endpoint*. **Rejected** because it leaves the importFromApp output
  incomplete for every caller — pushing the bug downstream instead of
  fixing it at the source.

### Decision 4 — searchObjectsBySlug: new method, not overload

`ObjectService` SHALL gain a new public method
`searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters): array`
rather than teaching the existing `searchObjects` to accept either
strings or ints in `@self.register` / `@self.schema`. The existing
method's signature and behaviour stay numeric-only. A docblock note
on `searchObjects` makes the numeric-ID contract explicit.

**Alternatives considered:**

- *Overload `searchObjects` to detect slug-vs-ID and resolve on the
  fly*. **Rejected** for two reasons:
  - Type ambiguity. `@self.register` accepting `string | int` makes
    every internal caller (including the indexer) responsible for
    knowing both paths.
  - Silent fallback. The pre-spec bug — where slugs were passed and
    silently returned zero results — was caused by exactly this
    style of "be liberal in what you accept". Keeping `searchObjects`
    strict means the next misuse fails loudly.
- *Resolve at the controller layer only*. **Rejected** because
  internal services (OpenCatalogi, softwarecatalog, decidesk) call
  `ObjectService` directly via DI and hit the same foot-gun without
  going through the controller.

The new method delegates to `RegisterMapper::findBySlug` and
`SchemaMapper::findBySlug`, both of which already exist. No new
mapper methods are introduced.

### Decision 5 — Multi-tenancy on the lookup layer

The smoke test pain point was that `searchObjectsBySlug`-style
callers operating in a multi-tenant Nextcloud must scope slug
resolution to the calling organisation. `RegisterMapper::findBySlug`
and `SchemaMapper::findBySlug` already accept an optional
`organisationId` argument. The new helper SHALL pass
`OCP\IUserSession::getUser()->getUID()`'s organisation context
through (resolved via the existing `OrganisationService::current()`)
so the slug resolution can never cross organisations.

If a slug exists in another organisation but not the caller's, the
helper MUST throw `DoesNotExistException`, NOT return the
foreign-org entity. This matches the principle of least surprise for
multi-tenant resolution everywhere else in OR.

**Alternatives considered:**

- *Make the organisation tuple a required arg on the helper*.
  Rejected. The existing `searchObjects` derives the org context
  from the user session; the helper SHOULD behave identically so
  callers don't have to learn a new pattern.

## Risks / Trade-offs

- **Per-schema engine reload introduces a new public surface on
  each engine.** Engines today are internal services with private
  state. Exposing `reloadForSchema` increases the API surface that
  must stay backwards-compatible. We accept this cost because the
  alternative (whole-engine flush) regresses request latency.
- **The DELETE `?force=true` flag is a footgun.** A misused
  `force=true` orphans every object that referenced the schema, and
  the orphaned objects' search index entries become inconsistent.
  Mitigation: the controller logs every force-delete at WARNING level
  with the calling user, the schema slug, and the orphan-object
  count. Audit-trail consumers will see this and operators can
  review.
- **`importFromApp` auto-create changes the implicit contract of
  the handler.** Today callers that don't want a Register row simply
  call `importFromApp` and ignore the absence of one. After this
  spec, callers that explicitly do NOT want a Register on import of
  an application-type config must either set `x-openregister.type`
  to `library`, or use a different import entry point. The risk is
  small because (a) the marker is opt-in and (b) the existing
  caller set inside OR + first-party apps is short and all of them
  WANT the Register.
- **Slug-resolution helper introduces an extra DB roundtrip.**
  Two `findBySlug` queries on every search. Mitigation: callers that
  already hold the numeric IDs (the indexer, batch jobs) keep using
  `searchObjects` directly; only the slug-aware HTTP layer pays
  the extra cost, where it is dwarfed by the search query itself.
- **No DB migrations.** This is a deliberate design constraint
  so the change can ship on a hot upgrade. The flip side is that
  any future field on `oc_openregister_registers` (e.g. a
  `created_via_import` marker) is out of scope; if we discover we
  need it, that becomes a follow-up spec.

## Migration Plan

This change is backwards-compatible — no data migration is required:

1. **Cache handler updates** are additive (`invalidate(int)` is a
   new public method). Existing read paths are unchanged.
2. **Engine `reloadForSchema` hooks** are additive. Engines are
   bootstrapped exactly as before; the new hook is only called by
   the service layer on mutation.
3. **DELETE `?force=true`** is a new optional query parameter.
   Existing DELETE callers receive the new HTTP 409 response when
   objects exist — this is a *behaviour change* on the error path,
   but pre-spec DELETE on a schema with objects also failed (with
   a less informative database constraint error), so existing
   well-behaved callers should not see a regression. The new
   response body is JSON, matching the rest of the controller.
4. **`importFromApp` auto-Register** triggers only when the
   incoming OAS carries `x-openregister.type=application`. Every
   pre-spec configuration in `lib/Settings/` is updated in a
   one-time pass (a documentation step, not a migration) to add
   the marker where the configuration genuinely represents an
   application. Configurations without the marker continue to
   behave as before.
5. **`searchObjectsBySlug`** is purely additive. No callers are
   touched. The docblock note on `searchObjects` is the only
   change to the existing method's contract.

Rollback is trivial: revert the diff. Nothing in the database has
been altered.
