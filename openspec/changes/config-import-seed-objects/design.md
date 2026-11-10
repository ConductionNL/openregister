## Context

Apps ship starter/seed data inside their register JSON
(`lib/Settings/{app}_register.json`). At app init the app calls
`AppHostSettingsService::loadConfiguration(force: true)`
(`lib/AppHost/Service/AppHostSettingsService.php:185`) →
`ConfigurationService::importFromApp()`
(`lib/Service/ConfigurationService.php:569-577`) → `ImportHandler::importFromApp()`
→ `ImportHandler::importFromJson()`.

### Root cause (file:line evidence)

`ImportHandler::importFromJson()` imports each component by reading a fixed set of
keys under `data['components']`:

- schemas — `lib/Service/Configuration/ImportHandler.php:1602` (`$data['components']['schemas']`)
- registers — `:1772` (`$data['components']['registers']`)
- workflows — `:1852`, mappings — `:1871`
- **objects — `:1960`** (`$data['components']['objects']`), then `saveObject()` at `:2108` / `:2145`.

The object loop is **only** fed from `data['components']['objects']`. There is **no
read of the top-level `data['objects']` key anywhere on the import path** — a repo
grep finds top-level `['objects']` only in `PreviewHandler.php` (preview display,
`:194`/`:211`) and in `result['objects']` accumulators. The export side confirms the
canonical location is nested: `ExportHandler.php:359` writes
`$openApiSpec['components']['objects'][]`.

shillinq's `lib/Settings/shillinq_register.json` places all **78** seed objects in
the **top-level `objects`** array (verified: top-level keys include `objects`;
`components` has `schemas` + bare schema-name keys but no `components.objects`). All
78 carry an `@self.slug`. So:

1. Schemas import (the `components.schemas` branch runs) — matching the observed
   "schemas DO import".
2. The object loop reads `components.objects`, finds it absent, and imports **0**
   objects — matching the observed "78 seed objects yield 0 rows, incl. `SalesOrderLine`".

This is a **pure location mismatch**: the app authored objects at the top level
(the same shape the loop expects, just one level up), and the importer never looks
there. It is **not** the slug guard (`:1969-1971`, which skips slug-less objects),
not RBAC, and not the no-user-context path (those are already handled). There is
also a third, unrelated seed path — `x-openregister.seedData.objects` keyed by
schema slug, `importSeedData()` at `:3714`/`:3807` — which shillinq does not use;
this change does not touch it.

### Where the fix lands

**Purely in OpenRegister** — `ImportHandler::importFromJson()`. Immediately before
the object loop (~`:1960`), normalise the seed-object source by merging a top-level
`data['objects']` array into `data['components']['objects']` (top-level entries
appended; `components.objects` kept as-is). The existing loop — register/schema
resolution via maps + DB fallback, search-by-(register, schema, slug), version
compare, `saveObject()` with `_rbac:false`/`_multitenancy:false`, per-entity
try/catch, `@ref` token resolution at `:1948` — then handles both sources unchanged.

**No app-side change is required.** `loadConfigurationForced` /
`AppHostSettingsService::loadConfiguration` and the `importFromApp` signature stay
the same; the forced path already calls the loop. Apps need not move their objects
to `components.objects` (though that remains valid and canonical). Stated explicitly
so consumers know this is not a dependency on a coordinated app change.

## Goals / Non-Goals

**Goals:**
- Make a top-level `objects` array an accepted seed-object source on the
  app-init/forced import path, folded into the existing `components.objects` loop.
- Idempotent re-import: match by `@self` identity (slug within register+schema, with
  uuid fallback), update-or-skip, never duplicate.
- Prove both with tests.

**Non-Goals:**
- Changing the `components.objects` branch, the slug-skip guard, RBAC/multitenancy
  bypass, acting-user resolution, or `@ref` resolution.
- Touching the `x-openregister.seedData.objects` path (`importSeedData()`).
- Changing export, `PreviewHandler`, or any public method signature.

## Decisions

### ADR-031 declarative-vs-imperative decision

This change is import-plumbing inside OpenRegister core, not app business logic, so
the declarative-vs-imperative table applies to the *enablement* rather than a new
service:

| Concern | Approach | Rejected alternative | Decision |
|---|---|---|---|
| Where to read seed objects | Merge top-level `objects` into the existing `components.objects` loop | Add a second parallel loop for top-level objects | **Merge** — one code path, one set of counters, reuses idempotency + resilience |
| App-side vs OR-side fix | Fix in OR importer | Tell every app to move objects to `components.objects` | **OR-side** — single fix vs N app PRs; export already writes `components.objects` so authored top-level is a legitimate equivalent |
| Idempotency key | `@self` slug within (register, schema), uuid fallback | Append-always | **Match-then-update** — reuses the loop's existing search; declarative seed must be re-runnable on every forced import |

This keeps app seed data declarative (objects in the register JSON, imported by the
engine) per ADR-031 — no app gains an imperative seeding service.

### Idempotency

The existing loop already searches `@self{register, schema, slug}` (`:2035-2055`)
and version-compares before `saveObject()`; for objects carrying `@self.id`/uuid it
passes the uuid to `saveObject()` (`:2100-2116`). The fold reuses this verbatim, so
re-import of a top-level seed object updates the matched object (or skips when the
imported version is not higher) rather than creating a duplicate. Where a seed object
carries an explicit `@self.id`/uuid, that uuid is the stable identity across
re-imports.

## Risks / Trade-offs

- **Both keys present (`objects` + `components.objects`)** → Merge appends top-level
  after `components.objects`. If the same logical object appears in both, the
  search-by-slug idempotency collapses them to one (second is an update of the
  first). Mitigation: dedupe by `@self` slug/uuid during the merge so the loop sees
  each once; covered by a test.
- **Slug-less top-level objects** → still skipped by the existing guard
  (`:1969-1971`), same as `components.objects`. This is intended (an
  `ImportHandlerSluglessSkipTest` already pins slug-less skip for the schema path);
  objects needing identity must carry `@self.slug` or `@self.id`. Noted, not changed.
- **Performance** → merge is an `array_merge` of already-loaded data; negligible.

## Migration Plan

Additive importer behaviour; no data migration. Deploy = code change. On the next
forced import (`loadConfiguration(force: true)` at app init, or the
`SyncConfigurationsJob` re-import), previously-dropped top-level seed objects
materialise. Rollback = revert the file; top-level seed objects stop importing again
(no data is destroyed; already-imported objects remain). No DB/schema rollback.

## Open Questions

- Confirm shillinq's 78 objects are intended to live at the top level vs being
  re-authored into `components.objects`. Provisional: support both (this change).
- Whether the export should additionally emit a top-level `objects` mirror for
  symmetry — out of scope here; export already uses `components.objects`.

## Test Plan

Unit tests in `tests/Unit/Service/Configuration/` (e.g.
`ImportHandlerTopLevelObjectsTest.php`):
- **Materialisation:** a configuration with schemas + registers + a **top-level
  `objects`** array (each with `@self{register, schema, slug}`) imports the objects;
  assert the saved-object count and that `saveObject()` was invoked per object (via
  the existing `ObjectService` mock pattern used in `ImportHandlerTest`).
- **Idempotency:** importing the same top-level seed twice does not duplicate — the
  second pass matches the existing object by (register, schema, slug)/uuid and
  updates-or-skips; assert no second create for an unchanged version.
- **Equivalence:** the same objects authored at `components.objects` vs top-level
  produce the same import result.
- **Regression:** a config using only `components.objects` is unchanged; a slug-less
  top-level object is skipped (and counted in `skipped.objects`).
