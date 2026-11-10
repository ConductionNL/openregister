# Data Import and Export

## Why

Tender requirements (PvE) and migration projects need a single, well-documented round-trip path between OpenRegister registers and the standard formats data owners actually receive: spreadsheets, CSVs, and a portable JSON bundle. The pipeline is already production-grade — chunked async ingest, schema-aware validation, relation name companion columns, downloadable per-schema templates — but it has never been formally captured as a capability spec, so consumers cannot reason about supported formats, error handling, or rollback semantics. This change documents the shipped surface and pins down the remaining XML / ODS / streaming-progress / structured-rollback / downloadable-error-file gaps so they can be planned and prioritised.

## What Changes

- Document `ImportService::importFromExcel` / `importFromCsv` and `ConfigurationService::importFromJson` as the canonical row-level and bundle-level import entry points, with `processSpreadsheetBatch` chunked async via ReactPHP promises.
- Document `ExportService::exportToExcel` / `exportToCsv` and `ConfigurationService::exportConfig` as the canonical export surface, including `_propertyName` companion columns for relation readability and per-language `field_lang` columns for translatable properties.
- Document the shipped per-schema downloadable import templates served by `RegistersController::importTemplate` (`buildTemplateSpreadsheet` / `buildTemplateCsv`), UTF-8 BOM prefixed, RBAC-aware, round-trippable through the existing import endpoint.
- Document the validation toggle (`$validation` flag wired through `validateObjectProperties`) as off-by-default for raw imports, on-by-default for the Configuration `ImportHandler`.
- Add XML and ODS row-level support to `ImportService` / `ExportService` (currently Excel + CSV only at row level).
- Add a downloadable `errors.csv` artefact for partial import failures (today the per-row error summary lives only in the import-result JSON envelope).
- Add a Server-Sent Events progress endpoint for large imports, replacing the current frontend polling against import job status.
- Add structured rollback for critical import failures so partial imports do not leave persisted rows behind (today there is no transaction span across chunked batches).
