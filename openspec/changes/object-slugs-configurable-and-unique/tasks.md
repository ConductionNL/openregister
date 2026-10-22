# Tasks

## 1. Schema configuration (save + import)

- [ ] 1.1 Add `objectSlugField` to `$stringFields` and `objectSlugSuffix` to `$boolFields` in `Schema::validateConfigurationArray()` (`lib/Db/Schema.php`), and document both in the `setConfiguration()` docblock

Acceptance criteria:
- Both keys survive a schema save through the API and a configuration import.
- An invalid `objectSlugSuffix` drops only that key; an empty `objectSlugField` is stored as `null`.

## 2. One slug decision for single save

- [ ] 2.1 Add `ObjectSlugHandler` (`lib/Service/Object/SaveObject/ObjectSlugHandler.php`) with `candidate()`, `applyOnSave()` and `applyToBatch()`, plus `MagicMapper::findSlugHolders()` / `findSlugState()` (live rows, no access filter, chunked `IN`)
- [ ] 2.2 Wire uniqueness + collision into `SaveObject`: call `applyOnSave()` after `hydrateObjectMetadata()` in `prepareObjectForCreation()` and `prepareObjectForUpdate()`; collision saves without a slug and logs the D7 warning naming register, schema, slug and both uuids
- [ ] 2.3 Freeze: capture `$originalSlug` before `setSelfMetadata()` in `prepareObjectForUpdate()`; a stored non-empty slug is never overwritten; remove `setSlug()` from `setSelfMetadata()` and from `MetadataHydrationHandler::hydrateObjectMetadata()`
- [ ] 2.4 Freed-slug reuse: an object without a slug gets its candidate on every save when the slug is free, with no action on delete; soft-deleted rows do not hold a slug
- [ ] 2.5 `objectSlugSuffix`: on collision write `<base>-<YYYYMMDDHHMMSS>` (UTC via `ITimeFactory`, base cut to fit 255), recheck once, else no slug + warning
- [ ] 2.6 Client slug: `@self.slug` / `slug` first as candidate, same uniqueness/freeze/suffix rules, "ignored, frozen" warning; legacy branch unchanged for schemas without `objectSlugField`

Acceptance criteria:
- The reproduction table in the proposal ends as: A `hello-world`, B `null` + warning, A keeps `hello-world` after the rename, GET `…/hello-world` returns A.
- A save never fails because of a slug.

## 3. Clean-up of the dead slug paths

- [ ] 3.1 Stop `SaveObject::setDefaultValues()` from adding `slug` to the data; remove `SaveObject::generateSlug()` / `createSlug()` and `MetadataHydrationHandler::generateSlug()`; update the "SLUG GENERATION DOES NOT LIVE HERE" comments in `MetadataHandler.php` and `DataManipulationHandler.php` to name `ObjectSlugHandler`

Acceptance criteria:
- No `slug` key in object data and no `@self.relations.slug`; no "Discarding … slug" warning on save.
- One slugify routine left: `MetadataHydrationHandler::createSlug()`.

## 4. Bulk parity

- [ ] 4.1 Run `applyToBatch()` per register+schema group in `SaveObjects::saveObjects()` between preparation and chunk processing; stop copying the slug in `applyHydratedMetadata()`; make `MagicBulkHandler::withDisplayColumns()` honour an explicit `null`; add the `COALESCE(NULLIF(_slug,''), …)` upsert guard for schemas with `objectSlugField` (PostgreSQL + MySQL)

Acceptance criteria:
- 2 × "Bulk Item" in one batch: first `bulk-item`, second `null` + one warning.
- Two slug queries per group, not one per row (ADR-009).

## 5. Ambiguous lookup

- [ ] 5.1 In `MagicMapper::findInRegisterSchemaTable()` read `_uuid` of both matching rows, log the ambiguous-lookup warning, throw `MultipleObjectsReturnedException` without uuids in the message; catch it in `ObjectsController::show()` and answer 409 JSON

Acceptance criteria:
- A duplicated slug answers 409 JSON (not an HTML 500); uuid and numeric-id lookups unchanged.

## 6. Backfill command

- [ ] 6.1 `ObjectSlugBackfillService` (`lib/Service/Object/ObjectSlugBackfillService.php`) + `MagicMapper::updateSlugColumn()`: duplicate report, default mode fills only empty slugs oldest first, no version bump / audit entry / event, cache eviction
- [ ] 6.2 `BackfillObjectSlugsCommand` (`openregister:backfill-slugs <register> <schema> [--reslug] [--dry-run]`), registered in `appinfo/info.xml`: `--reslug` recomputes all slugs from the current source field (empty source keeps its slug, one transaction), `--dry-run` prints the plan and writes nothing, exit 1 on unresolved input or a schema without `objectSlugField`

Acceptance criteria:
- A second run in the same mode reports zero changes.
- Existing duplicates are listed with their uuids and left as they are in default mode.

## 7. Schema editor

- [ ] 7.1 nextcloud-vue PR: `CnSchemaConfigurationTab.vue` gets the "Object slug field" `NcSelect` over `propertyOptions` and the `objectSlugSuffix` switch (disabled without a slug field) plus the freeze note; defaults in `CnSchemaFormDialog.vue`; strings in nextcloud-vue's catalogue
- [ ] 7.2 OpenRegister: bump `@conduction/nextcloud-vue`; add both keys to `src/entities/schema/schema.ts` (type, default, zod) and `src/store/modules/schema.js` defaults
- [ ] 7.3 l10n: run `npm run check:l10n`, `npm run test:l10n` and `npm run test:l10n:parity`; any new `t('openregister', …)` string goes in through the `docs/l10n-workflow.md` §6.15 procedure, never by hand-editing `l10n/*.js`

Acceptance criteria:
- Picking a slug field and saving round-trips; clearing it stores `null`.
- l10n gates show no new failure compared with the base run (`test:l10n` is red at HEAD for unrelated keys, see the repo `CLAUDE.md`).

## 8. Tests

- [ ] 8.1 Unit tests, one per spec requirement: `ObjectSlugHandlerTest`, `Schema` config keys, `MagicMapper` slug queries and ambiguity log, `MagicBulkHandler` upsert clause and explicit `null`, `ObjectsController::show()` 409, `ObjectSlugBackfillService`; rewrite or drop the tests of removed methods (`SaveObjectAdditionalTest`, `SaveObjectDeepTest`, `BulkAndHandlersIntegrationTest`)
- [ ] 8.2 BC1–BC6, one test each, plus an integration test replaying the reproduction table on a real magic table (single + bulk)

Acceptance criteria:
- Every assertion checks the resulting slug value and the uuid that holds it, not only that the call succeeded.
- Each BC test fails if its rule is broken; a negative check proves existing duplicates and `-<timestamp>` slugs stay.

## 9. Spec tags, docs and verification

- [ ] 9.1 `@spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-…` tags on every new or changed method (handler, `SaveObject`, `SaveObjects`, `MagicBulkHandler`, `MagicMapper`, `ObjectsController::show()`, `Schema::validateConfigurationArray()`, backfill service + command)
- [ ] 9.2 Update `docs/technical/schemas.md` and `docs/Features/objects.md` (slug field, suffix, freeze, uniqueness, backfill) and add GW1–GW4 to `CHANGELOG.md` under Unreleased
- [ ] 9.3 Rerun the reproduction on the NC 35 rig and record the side-by-side result; smoke-test OpenCatalogi and Softwarecatalog for regressions

Acceptance criteria:
- `composer check:strict` passes; hydra gates are compared against the base run, and a gate that is SKIPPED counts as not run.
- `openspec validate object-slugs-configurable-and-unique` is green.
