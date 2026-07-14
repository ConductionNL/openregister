# Tasks — Migration Mapping Packs

## 1. Pack model: validator + mapping engine
- [x] 1.1 `lib/Service/MigrationPack/PackDefinitionValidator.php` — structural validation per design.md: id slug, name, `sourceFormat` allow-list (csv|json|excel), strict semver `version`, `fieldMappings` shape incl. per-transform required fields (`map` for bool-map/lookup, `fields` for concat, `value` for const), `defaults`/`skipRows`, `idStrategy` (generate | sourceField+field). `validate(): string[]` + `assertValid(): void` (throws `InvalidArgumentException`).
- [x] 1.2 `lib/Service/MigrationPack/MappingEngine.php` — `mapRow(pack, sourceRow, rowNumber): {data, errors}`: format-agnostic source resolution (flat key or JSON-Pointer `/a/b` with `~0`/`~1` unescaping), transforms trim/date/bool-map/concat/lookup/const, `defaults` seeding, `required`-missing errors, `idStrategy` → `data['id']`, `isRowSkipped()`. Literal-leak guard: lookup/bool-map with no map entry AND no `default` errors the row (`{row, source, target, transform, message}`), never passes the literal through.

## 2. Storage + management
- [x] 2.1 `lib/Db/MigrationPack.php` — Entity/JsonSerializable per the `ScheduledReport` convention: `packSlug`/`name`/`sourceFormat`/`version` denormalised columns + full document as JSON in `definition` (decoded via `getDefinitionArray()`), `builtin`, `owner`, timestamps.
- [x] 2.2 `lib/Db/MigrationPackMapper.php` — `QBMapper<MigrationPack>` over `openregister_migration_packs`: `find(int)`, `findByPackSlug(string)` (the `packId` lookup), `findAll()`.
- [x] 2.3 `lib/Migration/Version1Date20260714000000.php` — idempotent `changeSchema()` creating `openregister_migration_packs` with a unique index on `pack_slug`.
- [x] 2.4 `lib/Service/MigrationPackService.php` — validation-first CRUD (`create` rejects duplicate slugs, `update` re-validates + guards slug collisions with other rows, `delete`), `importDefinition()` upsert-by-slug for shared pack files.
- [x] 2.5 `lib/Controller/MigrationPacksController.php` — `index`/`show`/`export` (any authenticated user; 401 anonymous), `create`/`update`/`destroy`/`import` (admin-gated, 403 otherwise), `export` as `DataDownloadResponse` `<packSlug>.json`, `import` multipart upload → `importDefinition()`. Dual `#[NoAdminRequired]`/`#[NoCSRFRequired]` + docblock annotation convention.
- [x] 2.6 `appinfo/routes.php` — `migrationPacks#index|create|import|show|update|destroy|export` under `/api/migration-packs[...]` with a comment banner referencing this change.

## 3. Execution: pack + dryRun through the existing import flow
- [x] 3.1 `ImportService::importFromCsv()`/`processCsvSheet()` — optional `?array $pack` + `bool $dryRun`; per-row `isRowSkipped()` + `mapRow()` BEFORE `transformCsvRowToObject()`; mapping-error rows excluded from `$allObjects` and appended to `summary['errors']` via `formatMappingError()` (`type: MigrationPackMappingError`, message labeled with source column + transform, compatible with `serializeErrorsToCsv()`).
- [x] 3.2 `ImportService::importFromJson()` — same `pack`/`dryRun` params; loop restructured into resolve-then-persist phases; pack path maps the raw decoded object (JSON-Pointer sources) with `idStrategy` supplying the upsert uuid; non-pack behaviour byte-for-byte preserved; persistence stays on `ObjectService::saveObject()`.
- [x] 3.3 `ImportService::buildDryRunSummary()` — validates every mapped object via `ValidateObject::validateObject()` (read-only), returns `dryRun: true`, `validRows`/`invalidRows`, per-row `{index, valid, errors, preview}`; NO `ObjectService` save call on the dry-run path.
- [x] 3.4 `RegistersController::import()` — `packId` resolved via `MigrationPackService::findByPackSlug()` (unknown ⇒ 400 before file processing), `dryRun` via `parseBooleanParam()`; wired into the csv + json branches; `packId` on excel/configuration ⇒ 400 with guidance (never silently ignored — orphaned-capability rule).

## 4. Reference pack
- [x] 4.1 `lib/Repair/SeedZgwZakenMigrationPack.php` — `IRepairStep` seeding `zgw-zaken-json` (`builtin: true`, `owner: null`): ZGW Zaken export shape onto generic case-like targets, template-marked description, placeholder zaaktype lookup with NO default (literal-leak guard fails unmapped URLs loudly by design). Idempotent by slug; never throws.
- [x] 4.2 Register the step in `appinfo/info.xml` `<repair-steps>` (post-migration + install).
- [x] 4.3 Decos/Centric/Roxit formats NOT fabricated — documented as pack-authoring candidates in design.md.

## 5. Tests
- [x] 5.1 `tests/Unit/Service/MigrationPack/PackDefinitionValidatorTest.php` — accept/reject matrix: missing/non-slug id, missing name, sourceFormat + version allow-lists, missing/empty fieldMappings, mapping without source/target, unknown transform type, per-transform required fields, idStrategy shapes, skipRows/defaults types, assertValid throw/no-throw.
- [x] 5.2 `tests/Unit/Service/MigrationPack/MappingEngineTest.php` — transform matrix (trim, date incl. unparseable-error, bool-map incl. default, concat incl. missing-extra-field, const-applies-on-empty-source, lookup resolve/default/LITERAL-LEAK error incl. error shape), required-missing, optional-skip, defaults survival, idStrategy generate/sourceField, JSON-Pointer nested + missing, isRowSkipped.
- [x] 5.3 `tests/Unit/Service/MigrationPackServiceTest.php` — validation delegation, duplicate-slug rejection on create, slug-collision guard on update, delete, importDefinition create-vs-update upsert round-trip.
- [x] 5.4 `tests/Unit/Controller/MigrationPacksControllerTest.php` — 401 anonymous everywhere, reads succeed for non-admin, 403 non-admin on create/update/destroy/import, 201/200/204 admin paths, 404 missing, 422 invalid definition.
- [x] 5.5 `tests/Unit/Service/ImportServiceMigrationPackTest.php` — end-to-end through the real row pipeline (mocked at the ObjectService save boundary): CSV pack mapping reaches `saveObjects()` with transformed values; required-missing + unresolved-lookup rows excluded and reported as `MigrationPackMappingError`; dry-run asserts `saveObjects()`/`saveObject()` NEVER called (side-effect-freedom) for both CSV and JSON; JSON pack maps JSON-Pointer sources and `idStrategy` drives create-vs-upsert.
- [x] 5.6 Existing `ImportService`/`RegistersController` test constructors extended for the new DI params (`MappingEngine`, `ValidateObject`, `MigrationPackService`).
- [x] 5.7 Full `tests/Unit` suite run; failures verified pre-existing by file path against an origin/development baseline run in the same environment.

## 6. Quality gates
- [x] 6.1 SPDX docblocks on every new/changed PHP file; no Co-Authored-By in commits.
- [x] 6.2 PHPCS clean on changed lib files (phpcs.xml targets lib/).
- [x] 6.3 PHPMD clean on changed files against phpmd.baseline.xml (pre-existing `transformValueByType` StaticAccess fixed via the file's established suppression idiom).
- [x] 6.4 PHPStan (level 5) clean on changed files.
- [x] 6.5 Psalm clean (0 errors) on changed files.
- [x] 6.6 No new composer dependencies.

## 7. Follow-ups (documented, not in scope)
- [ ] 7.1 nc-vue import wizard: pack picker + dry-run report rendering (UI adoption of the REST surface shipped here).
- [ ] 7.2 Excel single-sheet pack support.
- [ ] 7.3 Vendor-format packs (Decos/Centric/Roxit) authored against verified sample exports.
