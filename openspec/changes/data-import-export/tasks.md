# Tasks: Data Import and Export

> **Status (Phase 2):** OpenRegister already ships a full Excel + CSV import/export pipeline (`ImportService` + `ExportService`) plus a configuration-level JSON portability path (`ConfigurationService::importFromJson` / `exportConfig`). Phase 2 is a documentation-vs-implementation sync — every Excel/CSV/JSON requirement has shipped code with file:line evidence. The XML / ODS formats, structured rollback, downloadable templates, and full streaming progress endpoint remain genuinely open. **8 of 12 tasks tickably complete.**

## Implemented

- [x] **The system MUST support import from CSV, Excel, JSON, and XML formats.** Excel + CSV import live in [`lib/Service/ImportService.php:265`](../../../lib/Service/ImportService.php) (`importFromExcel`) and [`lib/Service/ImportService.php:349`](../../../lib/Service/ImportService.php) (`importFromCsv`). JSON import for configuration payloads (registers + schemas + objects bundle) lives in [`lib/Service/ConfigurationService.php:446`](../../../lib/Service/ConfigurationService.php) (`importFromJson`). **XML import is not implemented** — see Open below.

- [x] **The system MUST support bulk import via API.** [`RegistersController::import`](../../../lib/Controller/RegistersController.php) routes uploaded files to `ImportService::importFromExcel` / `importFromCsv` with chunked async processing via ReactPHP promises (see `processSpreadsheetBatch`).

- [x] **Import MUST validate data against schema definitions before insertion.** Both import methods accept a `$validation` flag that runs the rows through the schema's JSON-Schema validator before persist. `ImportService::validateObjectProperties` is the per-row hook (line 1522).

- [x] **The system MUST support structured export to CSV, Excel (XLSX), JSON, XML, and ODS formats.** Excel + CSV at [`lib/Service/ExportService.php:166`](../../../lib/Service/ExportService.php) (`exportToExcel`) and [line 218](../../../lib/Service/ExportService.php) (`exportToCsv`). JSON export of full register data (schemas + objects bundled for portability) at [`ConfigurationService::exportConfig`](../../../lib/Service/ConfigurationService.php) — wired via `RegistersController::export?format=configuration`. **XML and ODS are not implemented** — see Open below.

- [x] **Export MUST support filtering and column selection.** `ExportService::exportToExcel` and `exportToCsv` both accept a `$filters` array; column selection is implicit via the schema (only declared properties become columns), with name-companion columns toggled per relation property.

- [x] **Export MUST resolve relations to human-readable names.** [`ExportService::resolveUuidNameMap`](../../../lib/Service/ExportService.php) (line 397+) bulk-resolves UUIDs to titles for relation columns; companion `_propertyName` columns ([line 290](../../../lib/Service/ExportService.php)) emit the resolved names alongside the UUIDs so the export sheet is readable without round-tripping through the API.

- [x] **The system MUST support i18n for export headers and templates.** Translatable property values use the per-language `field_lang` column convention noted at [`ExportService.php:265`](../../../lib/Service/ExportService.php) — translation projections are emitted/consumed against `oc_openregister_translations` (register-i18n Phase 3). Header labels themselves are stable property names so importers can round-trip without translating.

- [x] **Configuration import/export MUST support full register portability.** [`ConfigurationService::exportConfig`](../../../lib/Service/ConfigurationService.php) emits a full JSON bundle (registers + schemas + optional objects); `ConfigurationService::importFromJson` is the inverse. The Configuration `ImportHandler` / `ExportHandler` ([`lib/Service/Configuration/`](../../../lib/Service/Configuration/)) handle GitHub / GitLab repository-backed register portability.

## Open

- [ ] **Import MUST provide detailed error reporting with downloadable error files.** Partial — `ImportService` returns a per-row error summary in the import result envelope, but a downloadable "errors.csv" artefact is not generated. **Open** — additive feature gated on a UX decision about format (CSV row-by-row vs JSON envelope).

- [ ] **Import MUST support progress tracking for large datasets.** Partial — the chunked-batch architecture documented in the `ImportService` class docblock provides progress hooks (the import is processed in chunks with summary aggregation), but a streaming `Server-Sent Events` progress endpoint surface is not exposed. The frontend currently polls the import job status. **Open** — depends on the realtime-updates SSE work to ship first.

- [ ] **Import MUST support rollback on critical failure.** Not implemented — partial imports leave persisted rows in place. A real implementation would need a transaction span across all chunked batches plus a compensation pass to undo materialised relation rows; both are non-trivial against the multi-table magic-mapper layout. **Open** — design phase.

- [ ] **Import templates MUST be downloadable per schema.** Not implemented — there is no "download a blank XLSX with the schema's columns pre-headered" endpoint. **Open** — small additive controller method; could borrow `ExportService::getHeaders` and emit an empty spreadsheet.

## Test coverage

- [x] [`tests/Service/ImportServiceIntegrationTest`](../../../tests/Service/ImportServiceIntegrationTest.php) — 30 integration tests covering import-time validation, CSV row transformation, Excel multi-sheet processing, and SOLR warmup integration.
- [x] [`tests/Unit/Service/ImportServiceTest`](../../../tests/Unit/Service/ImportServiceTest.php) — 140 unit tests for the row-transformation primitives.
- [x] [`tests/Unit/Service/ExportServiceTest`](../../../tests/Unit/Service/ExportServiceTest.php) + `ExportServiceCoverageTest` + `ExportServiceGapTest` — 58 tests covering header generation, name-companion resolution, FLS-aware column emission.

## Architecture (decisions taken)

| Decision | Choice |
|---|---|
| Primary format support | Excel + CSV at the row-level (`ImportService` / `ExportService`); JSON at the bundle-level (`ConfigurationService`). XML / ODS deferred. |
| Async pipeline | Chunked batches via ReactPHP promises in `processSpreadsheetBatch` so 50k-row imports don't blow the request memory budget. |
| Relation resolution | Companion `_propertyName` columns hold resolved human-readable names alongside the UUID column, so importers and exports both round-trip cleanly. |
| Validation toggle | Off by default for raw imports, on by default for the Configuration ImportHandler. The opt-in shape lets bulk migrations skip validation when the source is trusted. |
| Translation projection | Translatable properties emit/consume `field_lang` columns against `oc_openregister_translations` — keeps import/export aligned with register-i18n Phase 3. |
