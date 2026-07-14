# Design — Migration Mapping Packs

## Storage decision: entity + mapper (`lib/Db`), not OR objects, not app-config

Three candidate homes for pack persistence were read at HEAD before deciding:

1. **OR objects in a seeded register** (the `ImportTrustConfigurationRegister` / credential-broker style). Rejected: packs must be resolvable *during* an import into any register — making the import machinery depend on object-store reads of a specific register creates a bootstrap/availability coupling, and packs carry no business meaning, relations, or audit value that the object store would add.
2. **`IAppConfig` values**. Rejected: pack documents are multi-KB JSON with list/filter/uniqueness needs (`packSlug` unique index); app-config is a key-value store with no query surface.
3. **Infrastructure DB row: entity + `QBMapper` + migration** — the exact convention `ScheduledReport` shipped with one day earlier (`lib/Db/ScheduledReport.php`, `openregister_scheduled_reports`, ADR-001 framing: "infrastructure DB state, NOT an OpenRegister object/register"). **Chosen.** `MigrationPack` follows it verbatim: denormalised list/filter columns (`pack_slug` unique, `name`, `source_format`, `version`, `builtin`, `owner`) plus the full document as JSON in a `definition` TEXT column, decoded on demand via `getDefinitionArray()` (mirrors `ScheduledReport::getFiltersArray()`).

Auth posture differs from ScheduledReport deliberately: ScheduledReports are owner-scoped personal config; packs are **shared instance assets**. Reads (`index`/`show`/`export`) are open to any authenticated user because the import endpoint's `packId` must be usable by anyone holding manage-permission on a register (which `registers#import` already enforces server-side). Mutations (`create`/`update`/`destroy`/`import`) are admin-gated with the same `IGroupManager::isAdmin()` check `ConfigurationsController::import()` uses.

## Pack document model

```json
{
  "id": "zgw-zaken-json",          // slug, unique lookup key (packId param)
  "name": "ZGW Zaken (JSON)",
  "description": "…",
  "sourceFormat": "csv|json|excel",
  "version": "1.0.0",               // strict semver
  "idStrategy": { "type": "generate" } | { "type": "sourceField", "field": "<col-or-pointer>" },
  "fieldMappings": [
    { "source": "<column|/json/pointer>", "target": "<schema property>",
      "required": true,
      "transform": { "type": "trim|date|bool-map|concat|lookup|const", ... } }
  ],
  "defaults": { "<target>": <value> },
  "skipRows": [2, 3]
}
```

Transforms (each with its own required shape, enforced by `PackDefinitionValidator`):
- `trim` — whitespace trim.
- `date` — `sourceFormat` (PHP date format; strict `DateTime::createFromFormat`, unparseable ⇒ row error) → `targetFormat` (default `Y-m-d`).
- `bool-map` — `map: {"J": true, "N": false}` (+ optional `default`); result cast to bool.
- `lookup` — `map: {…}` (+ optional `default`); the zaaktype-URL→code case.
- `concat` — primary source + `fields: […]` joined by `separator` (default space); a missing *extra* field is an empty string (no map lookup involved, so not a leak case).
- `const` — fixed `value`, applied even when the source cell is empty.

**Literal-leak guard**: `lookup`/`bool-map` with a present source value, no matching map key, and no `default` produce a row-scoped error `{row, source, target, transform, message}` — the raw value is never passed through. The seeded reference pack exploits this deliberately (placeholder map, no default) so unmapped zaaktype URLs fail loudly until the operator supplies their own catalogue mapping.

`MappingEngine::resolveSource()` accepts a flat key (CSV/Excel column, top-level JSON key) or a leading-`/` JSON-Pointer path with `~0`/`~1` unescaping — one engine for all three formats.

## Execution wiring (single write path preserved)

- `registers#import` gains `packId` (+ `dryRun`) request params. `packId` resolves via `MigrationPackMapper::findByPackSlug()`; unknown slug ⇒ 400 before any file processing.
- **CSV** (`processCsvSheet`): after `extractRowData()`, each row runs through `MappingEngine::mapRow()`; rows with mapping errors are excluded from `$allObjects` and their errors appended to the sheet summary in the existing `{row, field, error, type}` shape (`type: MigrationPackMappingError`, message prefixed `[migration-pack]` with source column + transform). Mapped rows then flow through the untouched `transformCsvRowToObject()` → `ObjectService::saveObjects()` pipeline.
- **JSON** (`importFromJson`): the per-object loop is restructured into resolve-then-persist phases; with a pack, `mapRow()` runs against the raw decoded object (JSON-Pointer sources) and `idStrategy` supplies the upsert uuid. Persistence stays on the single-object `ObjectService::saveObject()` path (bulk path silently skips dedicated-table schemas — pre-existing constraint, unchanged).
- **Excel**: `packId` ⇒ 400 with guidance (multi-sheet fan-out vs single-schema pack mismatch; see proposal alternatives). Never silently ignored.
- **Configuration-bundle imports**: `packId` ⇒ 400 (packs are row-import artifacts).

## Dry-run: genuine side-effect-freedom

`dryRun=1` short-circuits **before** any `ObjectService` save call. Each mapped row is validated via `ValidateObject::validateObject()` — read-only by construction (schema resolution + uniqueness SELECTs; no INSERT/UPDATE path exists in that handler) — and the summary reports `dryRun: true`, `validRows`/`invalidRows`, and a per-row `{index, valid, errors, preview}` list. `created`/`updated`/`unchanged` stay empty. Unit tests assert `saveObjects()`/`saveObject()` are **never** invoked on the dry-run path (`$this->objectService->expects($this->never())`). The audit-trail import-job UUID is still allocated/cleared in the existing try/finally, but since no save runs, no audit rows are stamped — nothing to roll back, nothing written.

This is intentionally NOT the `softDeleteByImportJobId()` rollback (write-then-unwrite): quoting a 40k-row migration must not create 40k soft-deleted objects.

## Reference pack: why ZGW, why a template, why not Decos/Centric

`zgw-zaken-json` maps the ZGW ("Zaakgericht Werken") Zaken API export shape — `identificatie`, `omschrijving`, `startdatum`, `einddatum`, `status`, `zaaktype` (url→code via `lookup`), `vertrouwelijkheidaanduiding` — because ZGW is a published VNG standard whose shape is verifiable. It is seeded by `SeedZgwZakenMigrationPack` (repair step, `IRepairStep`, idempotent by slug, never throws — the `SeedDirectoryVirtualSchemas` convention) with `builtin: true, owner: null`, and is explicitly a **template**: its description instructs the operator to (a) pick/author a target schema matching the mapping targets and (b) replace the placeholder zaaktype lookup map. Targets use a generic case-like vocabulary (`caseNumber`, `title`, `startDate`, `endDate`, `status`, `zaakTypeCode`, `confidentialityLevel`) rather than binding to any specific app's schema.

**Deliberately not shipped**: packs for proprietary vendor export formats (Decos JOIN, Centric Suite4SD, Roxit Squit). Their export shapes could not be verified against a real system, and shipping a fabricated mapping would be worse than shipping none (an operator would trust it). They are the highest-value **pack-authoring candidates** for anyone with access to a real export: author against a sample file, validate with `dryRun=1`, share the pack JSON via export/import.

## Follow-ups (documented, not in scope)
- nc-vue import wizard adoption: pack picker + dry-run report rendering in the import dialog (UI change, belongs in nextcloud-vue; the REST surface this change ships is sufficient).
- Excel single-sheet pack support (see proposal alternatives).
- Pack-level `targetSchema` hint (informational binding so the UI can preselect a schema).
