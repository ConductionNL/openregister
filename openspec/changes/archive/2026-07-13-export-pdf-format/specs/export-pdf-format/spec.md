## ADDED Requirements

### Requirement: The system MUST support PDF export of register/schema object lists
`ExportService` SHALL expose `exportToPdf(?Register $register, ?Schema $schema, array $filters, ?IUser $currentUser): string`, returning raw PDF bytes. The method SHALL reuse the same column/data-extraction pipeline as `exportToExcel()`/`exportToCsv()` (`fetchObjectsForExport()`, `getHeaders()`, `identifyNameCompanionColumns()`, `resolveUuidNameMap()`, `getObjectValue()`, `resolveUuidsToNames()`), so PDF output has identical RBAC-filtered columns, multi-tenancy filtering, and relation-name resolution to the existing CSV/Excel formats. The rendered document SHALL be A4 landscape, and SHALL contain a header (register/schema title, export timestamp, object count), a striped data table with the selected columns, page numbers, and truncation of long cell values.

#### Scenario: Export a single schema's objects to PDF
- **GIVEN** register `meldingen-register` has schema `meldingen` with 12 objects and properties `titel`, `status`, `locatie`
- **WHEN** the user requests export with `format=pdf` for that register/schema
- **THEN** `ExportService::exportToPdf()` returns bytes beginning with the `%PDF` magic header
- **AND** the document is A4 landscape with a title section naming the register and schema, the export timestamp, and "12" as the object count
- **AND** the table contains one row per object with columns `id`, `titel`, `status`, `locatie` matching the same header set `getHeaders()` produces for CSV/Excel

#### Scenario: Column selection and property-RBAC parity with CSV/Excel
- **GIVEN** a schema property is hidden via `hideOnCollection: true` or denied by `PropertyRbacHandler::canReadProperty()` for the current user
- **WHEN** the same register/schema is exported to `pdf`, `csv`, and `excel` for that user
- **THEN** the hidden/denied property SHALL be absent from all three outputs' header sets identically

#### Scenario: Empty result set still yields a valid PDF
- **GIVEN** a schema with zero matching objects for the given filters
- **WHEN** the user exports that schema to `format=pdf`
- **THEN** the system returns a well-formed PDF (`%PDF`-prefixed) containing the header section, an object count of 0, and a table with only the header row

#### Scenario: Register-level export without a schema renders one section per schema
- **GIVEN** register `zaken-register` has schemas `zaak` and `besluit`
- **WHEN** the user exports the register to PDF without specifying a schema
- **THEN** the PDF contains one table section per schema (mirroring `exportToExcel()`'s one-sheet-per-schema behaviour), each starting on a fresh page with its own title/count header

### Requirement: PDF export enforces a row-count cap to bound memory use
PDF rendering builds a full in-memory box-tree per row, unlike the streaming CSV writer or PhpSpreadsheet's XLSX writer, and is therefore meaningfully more memory-heavy per row. `ExportService::MAX_PDF_EXPORT_ROWS` SHALL be a public class constant set to `5000`. When the fetched object count (summed across all schema sections for a register-level export) exceeds this cap, `exportToPdf()` SHALL throw `OCA\OpenRegister\Exception\ExportTooLargeException` before any HTML construction or Dompdf rendering begins. Controllers exposing PDF export SHALL catch this exception specifically and return HTTP 400 with a structured JSON body identifying the actual row count and the limit — never a 500, and never a silently truncated PDF.

#### Scenario: Export request under the cap succeeds
- **GIVEN** a schema with 4,000 matching objects
- **WHEN** the user requests `format=pdf`
- **THEN** the export proceeds and returns a valid PDF

#### Scenario: Export request over the cap is rejected with 400
- **GIVEN** a schema with 6,000 matching objects
- **WHEN** the user requests `format=pdf` via `GET /api/objects/{register}/{schema}/export`
- **THEN** the controller returns HTTP 400 with a JSON body containing `error`, the actual row count (6000), and the configured limit (5000)
- **AND** no Dompdf rendering work is performed (the guard runs immediately after the object fetch, before HTML construction)

#### Scenario: Row cap applies to combined multi-schema register export
- **GIVEN** a register-level PDF export (no schema specified) whose schemas' object counts sum to more than 5,000
- **WHEN** the user requests `format=pdf`
- **THEN** the export is rejected with the same `ExportTooLargeException` → HTTP 400 mapping, using the combined count across all schema sections

### Requirement: PDF format is wired into the objects and register export endpoints
`ObjectsController::export()` (`GET /api/objects/{register}/{schema}/export`) and `RegistersController::export()` (`GET /api/registers/{id}/export`) SHALL accept `format=pdf` alongside their existing `csv`/`excel`/`json`/`configuration` values, returning a `DataDownloadResponse` with `Content-Type: application/pdf` and a `.pdf` filename following the same `{register}_{schema}_{timestamp}` naming convention already used for `.csv`/`.xlsx` downloads. `RegistersController::export()`'s `pdf` case SHALL require an explicit `schema` query parameter and return HTTP 400 when absent, mirroring the existing `csv` case's single-schema requirement. `RegistersController::importTemplate()` SHALL NOT gain a `pdf` option — it downloads a blank, re-importable header-only template, and PDF cannot round-trip back through the import endpoint the way its existing `xlsx`/`csv` outputs do.

#### Scenario: Objects export accepts format=pdf
- **WHEN** the user calls `GET /api/objects/{register}/{schema}/export?format=pdf`
- **THEN** the response is a `DataDownloadResponse` with `Content-Type: application/pdf`, a filename ending in `.pdf`, and a body starting with `%PDF`

#### Scenario: Register export accepts format=pdf with a schema
- **WHEN** the user calls `GET /api/registers/{id}/export?format=pdf&schema={schemaId}`
- **THEN** the response is a `DataDownloadResponse` with `Content-Type: application/pdf` and a `.pdf` filename

#### Scenario: Register export rejects format=pdf without a schema
- **WHEN** the user calls `GET /api/registers/{id}/export?format=pdf` with no `schema` parameter
- **THEN** the controller returns HTTP 400 with an error message stating that PDF export requires a specific schema, mirroring the existing `csv` case's behaviour

#### Scenario: Import template endpoint does not offer PDF
- **WHEN** the user calls `GET /api/registers/{id}/schemas/{schema}/import-template?format=pdf`
- **THEN** the controller returns its existing "Unsupported template format" 400 response (the allow-list stays `['xlsx', 'csv']`)
