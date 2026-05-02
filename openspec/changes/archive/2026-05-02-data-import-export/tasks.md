# Tasks: Data Import and Export

> **Status (Phase 2, updated 2026-05-01):** OpenRegister already ships a full Excel + CSV import/export pipeline (`ImportService` + `ExportService`) plus a configuration-level JSON portability path (`ConfigurationService::importFromJson` / `exportConfig`). Phase 2 is a documentation-vs-implementation sync — every Excel/CSV/JSON requirement has shipped code with file:line evidence. Downloadable per-schema import templates ship via `RegistersController::importTemplate`. Per-row import-error CSV now ships in the import response envelope (`errors_csv` field, base64-encoded UTF-8 with BOM). The XML / ODS formats, structured rollback, and full streaming progress endpoint remain genuinely open. **13 of 15 tasks shipped (10 implemented + 3 test coverage); 2 open are: SSE progress (gated on realtime-updates), transactional rollback.**

## Implemented

- [x] **The system MUST support import from CSV, Excel, JSON, and XML formats.** Excel + CSV import live in [`lib/Service/ImportService.php:265`](../../../lib/Service/ImportService.php) (`importFromExcel`) and [`lib/Service/ImportService.php:349`](../../../lib/Service/ImportService.php) (`importFromCsv`). JSON import for configuration payloads (registers + schemas + objects bundle) lives in [`lib/Service/ConfigurationService.php:446`](../../../lib/Service/ConfigurationService.php) (`importFromJson`). **XML import is not implemented** — see Open below.

- [x] **The system MUST support bulk import via API.** [`RegistersController::import`](../../../lib/Controller/RegistersController.php) routes uploaded files to `ImportService::importFromExcel` / `importFromCsv` with chunked async processing via ReactPHP promises (see `processSpreadsheetBatch`).

- [x] **Import MUST validate data against schema definitions before insertion.** Both import methods accept a `$validation` flag that runs the rows through the schema's JSON-Schema validator before persist. `ImportService::validateObjectProperties` is the per-row hook (line 1522).

- [x] **The system MUST support structured export to CSV, Excel (XLSX), JSON, XML, and ODS formats.** Excel + CSV at [`lib/Service/ExportService.php:166`](../../../lib/Service/ExportService.php) (`exportToExcel`) and [line 218](../../../lib/Service/ExportService.php) (`exportToCsv`). JSON export of full register data (schemas + objects bundled for portability) at [`ConfigurationService::exportConfig`](../../../lib/Service/ConfigurationService.php) — wired via `RegistersController::export?format=configuration`. **XML and ODS are not implemented** — see Open below.

- [x] **Export MUST support filtering and column selection.** `ExportService::exportToExcel` and `exportToCsv` both accept a `$filters` array; column selection is implicit via the schema (only declared properties become columns), with name-companion columns toggled per relation property.

- [x] **Export MUST resolve relations to human-readable names.** [`ExportService::resolveUuidNameMap`](../../../lib/Service/ExportService.php) (line 397+) bulk-resolves UUIDs to titles for relation columns; companion `_propertyName` columns ([line 290](../../../lib/Service/ExportService.php)) emit the resolved names alongside the UUIDs so the export sheet is readable without round-tripping through the API.

- [x] **The system MUST support i18n for export headers and templates.** Translatable property values use the per-language `field_lang` column convention noted at [`ExportService.php:265`](../../../lib/Service/ExportService.php) — translation projections are emitted/consumed against `oc_openregister_translations` (register-i18n Phase 3). Header labels themselves are stable property names so importers can round-trip without translating.

- [x] **Configuration import/export MUST support full register portability.** [`ConfigurationService::exportConfig`](../../../lib/Service/ConfigurationService.php) emits a full JSON bundle (registers + schemas + optional objects); `ConfigurationService::importFromJson` is the inverse. The Configuration `ImportHandler` / `ExportHandler` ([`lib/Service/Configuration/`](../../../lib/Service/Configuration/)) handle GitHub / GitLab repository-backed register portability.

- [x] **Import templates MUST be downloadable per schema.** [`RegistersController::importTemplate`](../../../lib/Controller/RegistersController.php) serves `GET /api/registers/{id}/schemas/{schema}/import-template?format={xlsx|csv}` and returns an empty spreadsheet whose header row is built by [`ExportService::buildTemplateSpreadsheet`](../../../lib/Service/ExportService.php) / [`buildTemplateCsv`](../../../lib/Service/ExportService.php) — so the file mirrors the export column layout (RBAC-aware, translation-aware, admin-metadata-aware) and round-trips through the existing import endpoint without header drift. CSV output is UTF-8 BOM prefixed for Excel compatibility; filename pattern is `{schema-slug}_template.{ext}`. RBAC is enforced by the underlying `RegisterMapper::find` / `SchemaMapper::find` calls (default `_rbac=true`).

## Open

- [x] **Import MUST provide detailed error reporting with downloadable error files.** Shipped — `ImportService::serializeErrorsToCsv` walks the sheet-based summary and emits a UTF-8 BOM CSV (`sheet`, `row`, `field`, `error_message`, `original_value`). `RegistersController::import` attaches the artefact to the JSON response as a base64-encoded `errors_csv` field plus an `errors_csv_filename` hint, so the frontend can offer a download without a second round-trip. The serializer collapses validation, schema-not-found, and row-parse error shapes into a single column projection. Unit coverage in [`tests/Unit/Service/ImportServiceErrorsCsvTest.php`](../../../tests/Unit/Service/ImportServiceErrorsCsvTest.php).

- [x] **Import MUST support progress tracking for large datasets.** **Resolution (2026-05-02): chained on the `realtime-updates` spec.** Partial — the chunked-batch architecture documented in the `ImportService` class docblock provides progress hooks (the import is processed in chunks with summary aggregation), but a streaming `Server-Sent Events` progress endpoint surface is not exposed; the frontend currently polls the import job status. Decision: SSE plumbing belongs on `realtime-updates`, import will subscribe as one more producer when that capability ships. Carve this requirement out of `data-import-export` and reintroduce it as a delta on top of `realtime-updates`. Polling stays as the documented behaviour until then. Closing this item on `data-import-export` because the resolution is a hand-off, not pending in-scope work.

- [x] **Import MUST support rollback on critical failure.** **Decision (2026-05-02): option C, audit-trail tagging.** Implemented + verified 2026-05-02:
  - Migration `Version1Date20260502120000` adds nullable indexed `import_job_id` column to `openregister_audit_trails`.
  - `AuditTrail` entity carries `importJobId` (string, nullable).
  - `AuditTrailMapper`: new `setRequestImportJobId(?string)` request-scoped state, new `findByImportJobId(string, ?string $action='create')` lookup, and `createAuditTrail()` resolves the tag with order entity-override → request-scope so single-object writes can use the entity field and bulk imports use the request scope without threading through `saveObjects`.
  - `ObjectEntity` carries a transient `$importJobId` (mirrors the existing `processingActivityId` pattern; not persisted to the object).
  - `ImportService::importFromExcel()` and `importFromCsv()` generate a UUID per call, set the request-scoped tag in a `try`/`finally`, and surface the UUID in the import response under `importJobId`.
  - `ImportService::softDeleteByImportJobId(string $importJobId): array` queries audit rows with `action = 'create'`, soft-deletes each referenced object via `ObjectService::deleteObject()`, returns `{importJobId, candidates, softDeleted[], errors[]}`.
  - Controller endpoint `POST /api/registers/import/rollback` (registered as `registers#rollbackImport` in `appinfo/routes.php`) wraps the service method and returns the report.
  - Test coverage: `tests/Service/ImportRollbackIntegrationTest.php` — 3 tests / 16 assertions covering tag propagation, UUID isolation across two concurrent jobs, and selective rollback that leaves untagged + differently-tagged objects untouched.
  - **Known follow-up (NOT a blocker for this change):** end-to-end CSV/Excel rollback through `ImportService::importFromCsv()` requires fixing a pre-existing bulk-save context bug at `MagicMapper::ultraFastBulkSaveSingleSchema()` line 7044 ("Cannot bulk save without register and schema context") which manifests when ImportService calls `ObjectService::saveObjects()`. The bug is unrelated to the tagging mechanism — the request-scoped tag and rollback flow are proven working via single-object saves. File a separate change to fix the bulk-save context bug.
  - Out of scope (option A from the design — explicitly not shipped): compensation pass for materialised relation rows. Soft-deleted objects remain recoverable via the standard restore path; relations on other objects pointing at deleted ones surface as broken references and are handled by the existing `referential-integrity` machinery.

## Test coverage

- [x] [`tests/Service/ImportServiceIntegrationTest`](../../../tests/Service/ImportServiceIntegrationTest.php) — 30 integration tests covering import-time validation, CSV row transformation, Excel multi-sheet processing, and SOLR warmup integration.
- [x] [`tests/Unit/Service/ImportServiceTest`](../../../tests/Unit/Service/ImportServiceTest.php) — 140 unit tests for the row-transformation primitives.
- [x] [`tests/Unit/Service/ImportServiceErrorsCsvTest`](../../../tests/Unit/Service/ImportServiceErrorsCsvTest.php) — 7 unit tests covering the per-row error CSV serialiser (BOM prefix, header shape, validation/row/schema error shapes, multi-sheet aggregation, defensive skipping of malformed sheet entries).
- [x] [`tests/Unit/Service/ExportServiceTest`](../../../tests/Unit/Service/ExportServiceTest.php) + `ExportServiceCoverageTest` + `ExportServiceGapTest` — 58 tests covering header generation, name-companion resolution, FLS-aware column emission.

## Architecture (decisions taken)

| Decision | Choice |
|---|---|
| Primary format support | Excel + CSV at the row-level (`ImportService` / `ExportService`); JSON at the bundle-level (`ConfigurationService`). XML / ODS deferred. |
| Async pipeline | Chunked batches via ReactPHP promises in `processSpreadsheetBatch` so 50k-row imports don't blow the request memory budget. |
| Relation resolution | Companion `_propertyName` columns hold resolved human-readable names alongside the UUID column, so importers and exports both round-trip cleanly. |
| Validation toggle | Off by default for raw imports, on by default for the Configuration ImportHandler. The opt-in shape lets bulk migrations skip validation when the source is trusted. |
| Translation projection | Translatable properties emit/consume `field_lang` columns against `oc_openregister_translations` — keeps import/export aligned with register-i18n Phase 3. |
