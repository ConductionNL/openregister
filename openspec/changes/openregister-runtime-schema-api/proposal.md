---
kind: mixed
depends_on: []
chain:
  - openregister-runtime-schema-api   # THIS spec
  - openbuilt-schema-editor           # consumer (lives in openbuilt repo)
---

## OpenBuilt chain context

This is spec #3 of the 9-spec OpenBuilt chain. OpenBuilt is the in-Nextcloud
citizen-developer app that lets a non-admin author a fully-functional app —
including its data model — without shipping a PHP register PR. Today every
new schema must be added to `lib/Settings/{app}_register.json` on disk and
imported on install, which means a citizen developer cannot create a new
domain entity at runtime. This spec closes that gap by completing the
runtime CRUD on `/api/schemas` and `/api/registers`, hardening cache and
declarative-engine reload on mutation, and folding two real bugs found
during the `bootstrap-openbuilt` smoke test (commit 3138e4c) back into
the platform: importFromApp must auto-create the Register entity, and
slug-aware searches must resolve slugs before reaching `ObjectService`.

The consumer in spec #4 (`openbuilt-schema-editor`, lives in the openbuilt
repo) will be the first caller; OpenCatalogi and softwarecatalog will
adopt the same path once they grow their own runtime schema editors.

## Why

OpenBuilt cannot ship a runtime schema authoring surface while OpenRegister
treats schema/register creation as an install-time, on-disk concern. The
current code path has three concrete gaps that the `bootstrap-openbuilt`
smoke test exposed on 2026-05-11:

1. **No cache invalidation on mutation.** `SchemaCacheHandler` warms a
   per-request cache from `oc_openregister_schemas` and never re-reads
   after a `POST /api/schemas`. Newly created schemas are invisible
   until the next request worker, which breaks the "create schema →
   immediately create object against it" loop that any builder UI must
   support.
2. **Declarative engines never re-bind.** When a schema is updated with
   new `x-openregister-lifecycle`, `-aggregations`, `-calculations`, or
   `-notifications` blocks, the corresponding engines keep their
   pre-mutation bindings until the PHP process recycles. The
   builder-flow of "edit lifecycle → publish object → expect new
   transition" silently fails.
3. **`importFromApp` ships schemas without a Register.** Today the
   handler creates a Configuration row plus Schema rows but stops there.
   The smoke test had to manually `POST /api/registers` with the slug
   pulled out of the OAS document — a step no citizen developer will
   discover. A second smoke-test foot-gun: `searchObjects` requires
   numeric register/schema IDs in `@self`, not slugs, and the current
   slug-aware controllers silently downgrade the search instead of
   resolving the slug.

This change is **not** about exposing new endpoints; the routes already
exist. It is about making the runtime path correct, observable, and
documented so OpenBuilt — and every future runtime-authoring caller —
can rely on it.

## What Changes

- **Audit and complete CRUD on `/api/schemas` and `/api/registers`.** All
  five verbs (POST, GET-collection, GET-single, PUT/PATCH, DELETE) MUST
  behave consistently, return canonical entity shape, and emit OR's
  standard audit events.
- **Cache invalidation on mutation.** `SchemaCacheHandler` and
  `RegisterCacheHandler` MUST invalidate the affected entity (and any
  parent register that lists it) on every successful create/update/delete.
  The next read in the same request worker MUST see the new state.
- **Declarative-engine reload.** When a schema mutation changes any of
  `x-openregister-lifecycle`, `-aggregations`, `-calculations`, or
  `-notifications`, the corresponding engine MUST re-bind its registry
  entry for that schema before the controller returns. Per-schema reload
  is preferred over a whole-engine flush so concurrent requests are not
  blocked. See design Decision 2.
- **DELETE safety.** `DELETE /api/schemas/{id}` MUST refuse when objects
  exist against the schema unless `?force=true` is passed; same on
  registers. Bare DELETE returns HTTP 409 with the object count.
- **`importFromApp` auto-creates Register.** When the imported
  configuration carries `x-openregister.type=application`,
  `ImportHandler::importFromApp` MUST derive a Register entity from
  `x-openregister.app` (slug), `info.title` (title), and
  `info.description` (description), and attach every imported schema's
  ID to that Register's `schemas[]` field. Lookup MUST be idempotent
  per `(slug, organisation)` so re-imports update rather than duplicate.
- **`ObjectService::searchObjectsBySlug` helper.** A new method on
  `ObjectService` that accepts register and schema slugs (string), resolves
  them to numeric IDs via the mappers, and delegates to the existing
  `searchObjects`. The numeric-IDs-only contract on `searchObjects` is
  documented in its docblock so callers that work in raw IDs stay on the
  fast path. See design Decision 4.
- **No breaking changes.** All wire formats and existing route signatures
  are preserved; the new helper is additive and the auto-Register
  behaviour only triggers on configurations that carry the
  `application` type marker.

## Capabilities

### New Capabilities

- `runtime-schema-api`: Defines the runtime contract for
  CRUD on `/api/schemas` and `/api/registers` — cache invalidation,
  declarative-engine reload on mutation, deletion safety, and the
  `searchObjectsBySlug` helper that bridges slug-aware callers to the
  numeric-ID search layer.

### Modified Capabilities

- `data-import-export`: The `importFromApp` flow grows an
  auto-Register-creation step so configurations carrying
  `x-openregister.type=application` produce a complete (Configuration +
  Schemas + Register) triple, not just the first two.

## Impact

- **Code (PHP):**
  - `lib/Controller/SchemasController.php` — DELETE force flag, audit on mutation
  - `lib/Controller/RegistersController.php` — DELETE force flag, audit on mutation
  - `lib/Service/SchemaService.php` — invoke cache + engine reload hooks
  - `lib/Service/RegisterService.php` — invoke cache hook
  - `lib/Service/ObjectService.php` — new `searchObjectsBySlug` method;
    docblock update on `searchObjects`
  - `lib/Handler/SchemaCacheHandler.php` — `invalidate(int $schemaId)`
  - `lib/Handler/RegisterCacheHandler.php` — `invalidate(int $registerId)`
  - `lib/Handler/ImportHandler.php` — `importFromApp` auto-Register step
  - Declarative engines:
    `lib/Engine/Lifecycle/LifecycleEngine.php`,
    `lib/Engine/Aggregations/AggregationEngine.php`,
    `lib/Engine/Calculations/CalculationEngine.php`,
    `lib/Engine/Notifications/NotificationEngine.php` — `reloadForSchema(int)` hook
- **APIs:** No new endpoints; behaviour clarified on existing routes. DELETE
  gains a documented `?force=true` query flag.
- **Consumers:**
  - OpenBuilt (chain spec #4 `openbuilt-schema-editor`) — first caller
  - OpenCatalogi / softwarecatalog — unchanged today; adopt later
  - Any client calling `ObjectService::searchObjects` directly is unchanged
- **Database:** No migrations. No schema changes on the core tables.
- **ADR alignment:** Honours ADR-011 (no duplicate utilities — reuses
  existing `SchemaMapper`, `RegisterMapper`) and ADR-031 (declarative
  engines must reload on metadata change, not require process restart).
