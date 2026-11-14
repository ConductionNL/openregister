# Design: register-scoped-schema-slug-resolution

## Context

`SchemaMapper::find()` resolves an identifier through an `orX(id, uuid, slug)` and returns the first matching row. For a numeric id or a uuid that is unambiguous. For a slug it is not: slugs are unique *within a register*, never across the instance, so `LOWER(slug) = 'agent'` can match several rows and the winner is whichever the database hands back first.

Two mitigations already exist, and the gap between them is the bug:

1. `SchemaMapper::findBySlugInIds(string $slug, array $schemaIds): ?Schema` — matches a slug restricted to a supplied id set. Returns `null` when the set is empty or the slug is not in it, which makes it safe to try first and fall through.
2. `ObjectService::setSchema()` — calls (1) against `$this->currentRegister->getSchemas()` before falling back to the global `find()`. Its docblock states the problem plainly and names `conversation` as an example.

So the primitive is built and one caller uses it. Every other slug-resolving path still calls `find()` directly.

Measured on the dev instance, 2026-08-13:

| Observation | Value |
|---|---|
| Schemas with slug `agent` | hermiq's (36 properties) and openbuild's (6 properties) |
| `GET /api/schemas/agent` resolves to | openbuild's |
| Schemas with slug `session` | scholiq's (id 1286) |
| Only property hermiq and openbuild's `agent` share | `name` |

The consequence is already load-bearing: hermiq's agent detail page carries a comment explaining that the `agent-core` widget is sized for the 9 fields it declares rather than the 1 it renders, "because that is an OpenRegister slug-resolution bug, not a layout one".

## Goals / Non-Goals

**Goals:**

- A slug resolved with a register in context returns *that register's* schema, on every path, not just through `ObjectService`.
- The set of paths that resolve slugs is written down, so the next person does not have to rediscover it.
- A path that genuinely cannot be register-scoped says so in a comment, rather than being silently left global.
- A regression test that fails before the fix and passes after.

**Non-Goals:**

- Global slug uniqueness. Slugs are register-scoped by design; enforcing global uniqueness would break every install that has two apps with an `order` schema, and would be solving the wrong problem.
- Renaming any colliding schema.
- Changing `find()`'s signature or its behaviour for ids and uuids.

## Decisions

### Decision 1: Fix the callers, not `find()`

`find()` stays as it is. It has no register context and inventing one — an ambient "current register" on the mapper — would make resolution depend on hidden state and be worse than the bug.

Instead each caller that holds a register routes through `findBySlugInIds()` first and falls back to `find()`. This is exactly the shape `setSchema()` already uses, so the fix is a known-good pattern applied consistently rather than a new mechanism.

### Decision 2: The audit is a deliverable, not a step

The failure mode here is silent — a wrong schema resolves successfully and returns plausible data. A fix applied to the paths someone remembers would leave the same class of bug in the paths they did not, and would look complete.

So the change produces an enumerated list of every slug-resolving path with a disposition for each: scoped / fixed / deliberately global. The task list requires that list to exist as a written artifact.

### Decision 3: `GET /api/schemas/{id}` takes an optional `?register=`

This is the open question from the proposal. Two candidates:

- **(a) Optional `?register=`; unchanged when absent.** Backwards-compatible. A caller that knows which register it means gets the right schema; a caller that does not gets today's first-row-wins behaviour.
- **(b) Resolve across the caller's visible registers and `409` on genuine ambiguity.** More correct — it never silently returns the wrong schema — but it turns a currently-working request into an error for any existing consumer relying on the accidental winner.

**Chosen: (a).** Option (b) converts a silent wrong answer into a loud failure, which is usually the right trade, but it does it for *every* existing consumer of a colliding slug at once, in a foundation repository that 18 apps depend on. The proportionate step is to make the correct answer *reachable* now and to consider (b) separately once the audit reveals how many consumers are actually affected.

The ambiguity that remains when `?register=` is absent must be logged at debug level with the candidate ids, so the next investigation starts from evidence instead of a screenshot.

### Decision 4: Verify the `findCache` key

`SchemaMapper::find()` caches per request. If the cache key is the raw identifier, then within one request a global resolution can populate `agent → openbuild` and a later register-scoped call could read it back. The fix must confirm the scoped path either bypasses that cache or keys it with the register — and the regression test must exercise both orders within a single request, because a test that only ever resolves scoped-first would pass against a broken key.

### Declarative-vs-imperative decision (ADR-031)

Not applicable. This change introduces no lifecycle, aggregation, derived field, notification, relation, or widget behaviour — it corrects an existing lookup inside the data-access layer. No `x-openregister-*` declaration is appropriate. One small lookup class is added, `SchemaRefInRegister`: it is the existing `setSchema()` pattern made reusable so that each fixed caller applies it the same way, not a new mechanism.

### Seed Data (ADR-001)

No new schema is introduced, so no seed objects are required. The regression test does construct two throwaway schemas sharing one slug in two registers; those are test fixtures created and torn down by the test, not seed data, and must not be added to `_registers.json`.

## Risks / Trade-offs

- **A consumer adapted to the wrong answer.** Correcting resolution changes what some callers receive. The known case (hermiq's agent-detail sizing) is a workaround that becomes unnecessary. The audit must enumerate the collisions actually present so the blast radius is known rather than discovered; start from `SELECT LOWER(slug), COUNT(*) FROM oc_openregister_schemas GROUP BY 1 HAVING COUNT(*) > 1`.
- **Decision 3 leaves a known-imperfect path.** `GET /api/schemas/{id}` without `?register=` still guesses. That is a deliberate, documented deferral, not an oversight — and it is now logged rather than silent.
- **This is the foundation repository.** Every app resolves schemas. The regression test failing before the fix is the minimum bar for believing the change does anything; a test written after the fix, that passes immediately, would prove only that it does not crash.

## Audit (task 2)

Measured against `main` on 2026-11-14. `SchemaMapper::find()` has 206 call sites in 130 files. Every one was traced to where its argument comes from, and the direct slug queries (`findBySlug()`) were added. Each slug-capable path is classified as (a) already register-scoped, (b) holds a register but did not use it, or (c) genuinely has no register. A (c) path carries an inline comment at the lookup saying why it stays global (task 2.3).

### Verified in earlier passes

| Path | Disposition | Notes |
| --- | --- | --- |
| `ObjectService::setSchema()` → `resolveSchemaRef()` | (a) scoped | The reference implementation. A raw ref is remembered so a later `setRegister()` re-resolves it inside the register. |
| `ObjectService::schemaWithinRegister()` (slug searches) | (a) scoped, no fallback | Refuses with `SchemaNotInRegisterException` instead of falling back to a global `find()`. |
| `RegisterScopedSchemaResolver::resolvePair()` | (a) scoped | Shared by `SchemasController`, `TablesController`, `ContextsController`, `TmloController`, aggregation, quality statistics, dedup rules, duplicate detection, migration, federation share targets and the contract bindings. Uses `findInIds()`, so ids, uuids and slugs are all bounded by the register. |
| `GET /api/schemas/{id}?register=` (`SchemasController::resolveSchema()`) | (a) scoped when the parameter is present; global when absent (Decision 3) | Absent: unchanged, and an ambiguous slug logs the candidate ids at debug level. |
| Flow `ObjectReadNode` / `ObjectWriteNode` | (a) scoped | `findBySlugInIds()` against the register's schemas first. |
| `ImportHandler` (configuration import) | (a) scoped | Matches an existing schema by slug only among the register's schemas. |
| `occ openregister:backfill-object-slugs` | (a) scoped | |
| `ObjectsController::moveEnds()` (move to another register and schema) | was (b), now scoped | Both registers are resolved first; each schema slug goes through `findBySlugInIds()` against its own register's schemas, falling back to `find()` for ids, uuids and slugs the register does not carry (`schemaInRegister()`). `ObjectsControllerSchemaSlugScopeTest` fails on the old code, naming schema 22 instead of 36. |
| `ObjectsController::mergeAccessRefusal()` (rights check of a merge) | was (b), now scoped | Same helper, with the route's register. |

### Fixed in this pass (task 3.1)

All of these go through `SchemaRefInRegister` (`lib/Service/Schemas`). It is the `setSchema()` pattern as one lookup: `findBySlugInIds()` against the register's schemas first, then `find()` for ids, uuids and slugs the register does not carry.

| Path | Disposition | Register used |
| --- | --- | --- |
| `ViewService::viewSchema()` (presentation validation) | was (b), now scoped | The view query's first register. |
| `ViewPresentationService::openKanbanGrouping()` | was (b), now scoped | The board's register, the one `setSchema()` resolves in. |
| `occ openregister:rematerialise-calculations` | was (b), now scoped | The register argument read on the line before. |
| `SchemaScopedProvider::resolveSchemaId()` (search and reference providers) | was (b), now scoped | The provider's register, read once and shared with the id lookup. |
| `ObjectMetricSource::buildBaseQuery()` | was (b), now scoped | The descriptor's register when it names one; a missing register still fails after the schema. |
| `RegistersController` export of one schema table, import template, CSV import, JSON import | was (b), now scoped | The route's register. |
| `ScopesController::resolveSchemas()`, `PermissionReportScope::schemas()` | was (b), now scoped | The register filter, when it narrows to exactly one register. |
| `LogService::findObjectInScope()` | was (b), now scoped | The register read on the line before. |
| `TasksProvider::createTaskFor()` | was (b), now scoped | The object's register. |
| `MagicMapper::searchObjects()` / `countObjects()` single-table path | was (b), now scoped | The register resolved on the line before; the slug is passed uncast on purpose. |
| `MagicMapper::findBulkSchema()` (bulk save) | was (b), now scoped | The bulk save's register. |
| `ImportService` multi-sheet spreadsheet import | was (b), now scoped | The register imported into. |
| `TablesSchemaSyncService::findSchemaBySlug()` | was (b), now scoped | The `tables` register. |
| `ImportHandler` object configuration, listed objects, seed objects, seed passes, seed references | was (b), now scoped | The configuration's register, the object's `@self.register`, the pass's target register, the seed object's register. |
| `ObjectsController::crossTablePairs()` (cross-table search) | was (b), now scoped | Each register pairs with its own schema for a shared slug. |

### Still class (b): open

| Path | Disposition | Why it is not done here |
| --- | --- | --- |
| `SchemaManageGate::mayManageNamed()` (mixed-schema bulk rights) and `SaveLookups::schemaIdBySlug()` (the save) | (b), open | Must change together: scoping the rights check alone would check a different schema than the save writes. A follow-up. |
| `RegisterResolverService::findSchema()` (app-config pair) | (b), open | The scoped lookup bypasses the tenant filter, so the explicit-organisation check has to be carried over first. |
| `SourceRecordResolver::lookupSchemaId()` (survivorship) | (b), open | Also memoised in a static keyed by the bare slug; the key needs the register first. |
| `TranslationController::resolveSchema()` | (b), open | The object's register is one read away, but the controller has no register reader. |
| `UtilityHandler::normalizeEntity()` (migration sides) | (b), open | Each side carries its register; the helper resolves one reference alone. |
| `FacetHandler::schemasMatching()` | (b), open | The query's `@self.register` is there; no register reader is injected. |
| `SearchQueryHandler::schemaOrNull()` | (b), open | `buildSearchQuery()` holds the register but passes only the schema down. |
| `AggregationRunner::loadSchema()` | (b) for `resolveCrossTarget()`, (c) for joins and the no-register `findSchema()` | `$run->register` is held on the cross-schema path. |
| `RelatedRowQueryApplier::findSchema()` | (b), open | Reached after an ambiguous slug; the filter target's register is not passed down. |

### Class (a) and (c)

| Path | Disposition | Reason |
| --- | --- | --- |
| `GET /api/schemas/{id}` without `?register=`, `/related`, `/indexes`, `/list-presentation` | (c) | Schema-level routes; no register in the request (Decision 3). |
| MCP `SchemasToolProvider` get/update/delete, agent `SchemaTool` get/update/delete | (c) | The tool inputs carry no register. |
| `ImportHandler::owningAppOfSlug()`, `resolveSchemaGlobally()` | (c) | The app-scoped and legacy import fallbacks, reached only without register schema ids. |
| `ReferenceFilterOperandGuard::propertiesOf()` | (c) | Schema-body validation; a schema is not owned by a register. |
| `ScopesController::matrixPreview()`, `RulesController::resolveSchema()` | (c) | Their routes name only the schema. |
| `ValidateObject::resolveSchema()` (JSON-schema `$ref` loader) | (c) | The loader is handed only the URI. |
| `ObjectService::findSchemaGlobally()` | (c) | Reached only when no register is set. |
| `ManifestService` current-user schema (`findBySlug`) | (c) | A manifest names no register. |
| `RelatedRowQueryApplier::resolveSchema()` (`findBySlug`) | (c), refuses | Two schemas answering one slug is refused as ambiguous rather than guessed. |
| `RegisterSerializer::findSchemaCached()`, `RegisterService::resolveSchemasForStats()`, `TablesSchemaSyncService::findUnscopedSchema()` | (c) | The reference IS the register's own schema-list entry; there is no id set to narrow a legacy slug entry against. |
| `BackfillObjectSlugsCommand`, `ImportHandler::noteSlugOutsideRegisterScope()`, `RegisterScopedSchemaResolver::uniquelyIdentifiedSchema()` | (a) | Scoped first, a log-only probe, and an id/uuid-only branch. |

### Never a slug

About 150 of the call sites never see a slug. They pass a stored id or uuid: `$object->getSchema()`, a `schema_id` column, an `(int)` cast, a typed `int` parameter, or an id taken from a register's own schema list. They are not slug-resolving paths. They sit in the mappers (`MagicMapper` table and union reads, `AuditTrailMapper`, `RegisterMapper`), the listeners, background jobs and repair steps, the archival, retention and deletion services, RBAC ownership and lock checks, notifications, files and the int-routed controller actions (`SchemasController` update, destroy, download, stats; `SchemaImportController`, `SchemaMigrationController`, `RetentionController`).

### The request cache (Decision 4)

`findBySlugInIds()` and `findInIds()` neither read nor write `SchemaMapper::$findCache`, so a scoped lookup cannot be answered from a global one, or seed it. `tests/Unit/Db/SchemaMapperScopedSlugTest.php` runs both orders in one mapper instance.
