---
status: implemented
---

# Data Import and Export

## Purpose

Document and extend OpenRegister's existing import/export infrastructure. The core pipeline is already implemented: ImportService with ChunkProcessingHandler for bulk ingest, ExportService/ExportHandler for CSV/JSON/XML output, and Configuration/ImportHandler for register template loading. This spec validates the existing implementation and defines extensions for additional formats, progress tracking, and schema validation. The existing pipeline already handles CSV and Excel import via PhpSpreadsheet, CSV and Excel export with RBAC-aware header generation and relation name resolution, configuration import/export in OpenAPI 3.0.0 format, bulk operations via SaveObjects with BulkRelationHandler and BulkValidationHandler, deduplication efficiency reporting, multi-sheet Excel import, two-pass UUID-to-name resolution, and property-level RBAC enforcement on export columns. This spec extends that foundation with JSON/XML/ODS format support, interactive column mapping, progress tracking UI, downloadable error reports, import templates, streaming for large datasets, scheduled imports, and i18n for headers and templates.

**Source**: Gap identified in cross-platform analysis; Baserow implements CSV export (core) and JSON/Excel export (premium) with view-scoped filtering; NocoDB implements Airtable/CSV/Excel import with async job processing and bulk API operations. Both competitors gate advanced export formats behind paid tiers -- OpenRegister should offer all formats in the open-source core.

## Relationship to Existing Implementation
This spec primarily validates and extends an already-functional import/export system:

- **CSV/Excel import (fully implemented)**: `ImportService` with `importFromCsv()` and `importFromExcel()` using PhpSpreadsheet, with ReactPHP optimization and configurable chunk sizes.
- **CSV/Excel export (fully implemented)**: `ExportService` with `exportToCsv()` and `exportToExcel()`, RBAC-aware header generation via `PropertyRbacHandler`, relation name resolution via two-pass `resolveUuidNameMap()`, admin-only `@self.*` metadata columns, and multi-tenancy support.
- **Bulk operations (fully implemented)**: `SaveObjects` with `ChunkProcessingHandler` (60-70% fewer DB calls, 2-3x faster), `BulkRelationHandler` for inverse relations, `BulkValidationHandler` for schema analysis caching.
- **Configuration portability (fully implemented)**: `Configuration/ImportHandler` and `Configuration/ExportHandler` for OpenAPI 3.0.0 format with slug-based references, workflow deployment, and idempotent re-import.
- **Deduplication (fully implemented)**: Import summaries include `created`, `updated`, `unchanged`, `errors` counts with deduplication efficiency reporting.
- **Multi-sheet Excel (fully implemented)**: `processMultiSchemaSpreadsheetAsync()` matches sheet titles to schema slugs.
- **RBAC on export (fully implemented)**: `PropertyRbacHandler::canReadProperty()` controls column visibility, admin check gates `@self.*` columns.
- **SOLR warmup (fully implemented)**: `ImportService::scheduleSmartSolrWarmup()` via `IJobList` after import.
- **What this spec adds**: JSON/XML/ODS/JSONL format support, interactive column mapping UI, progress tracking with polling endpoint, downloadable error report CSV, import template generation, column selection for exports, streaming for 10k+ rows, scheduled/recurring imports, i18n for headers, and import rollback on critical failure.

## Requirements

### Requirement: The system MUST support import from CSV, Excel, JSON, and XML formats

Users MUST be able to upload files in CSV, XLSX, JSON, or XML format. The `ImportService` SHALL detect the file type from the extension and delegate to the appropriate reader. CSV import SHALL use `PhpOffice\PhpSpreadsheet\Reader\Csv`, Excel import SHALL use `PhpOffice\PhpSpreadsheet\Reader\Xlsx`, JSON import SHALL parse the file as a JSON array of objects, and XML import SHALL parse each child element of the root as an object record.

#### Scenario: Import a CSV file with auto-detected schema
- **GIVEN** register `meldingen-register` has a single schema `meldingen`
- **AND** a CSV file `import.csv` with headers: titel, omschrijving, status, locatie
- **WHEN** the user uploads `import.csv` via `POST /api/objects/{register}/import` without specifying a schema
- **THEN** the `ExportHandler::import()` method SHALL auto-select the first schema from the register
- **AND** `ImportService::importFromCsv()` SHALL process the file using `PhpSpreadsheet\Reader\Csv`
- **AND** the response MUST include a summary with `found`, `created`, `updated`, `unchanged`, and `errors` counts

#### Scenario: Import a multi-sheet Excel file with per-sheet schema mapping
- **GIVEN** register `gemeente-register` has schemas `personen` and `adressen`
- **AND** an Excel file `data.xlsx` has two sheets named `personen` and `adressen`
- **WHEN** the user uploads `data.xlsx` without specifying a schema
- **THEN** `ImportService::importFromExcel()` SHALL call `processMultiSchemaSpreadsheetAsync()` to match each sheet title to its corresponding schema slug
- **AND** the response MUST include separate summaries keyed by sheet title

#### Scenario: Import a JSON array of objects
- **GIVEN** schema `producten` with properties: naam, prijs, categorie
- **AND** a file `producten.json` containing `[{"naam": "Widget A", "prijs": 12.50, "categorie": "onderdelen"}, ...]`
- **WHEN** the user uploads `producten.json` via the import endpoint
- **THEN** the system SHALL parse the JSON array and create one object per array element
- **AND** each object SHALL be validated against the `producten` schema properties

#### Scenario: Import an XML file
- **GIVEN** schema `besluiten` with properties: titel, datum, status
- **AND** a file `besluiten.xml` with root element `<besluiten>` containing `<besluit>` child elements
- **WHEN** the user uploads `besluiten.xml`
- **THEN** the system SHALL parse each `<besluit>` element as a record, mapping child element names to schema property names
- **AND** attributes on child elements MUST be ignored unless a mapping explicitly references them

#### Scenario: Reject unsupported file type
- **GIVEN** a user uploads a file `data.pdf` with extension `.pdf`
- **WHEN** the `ExportHandler::import()` method determines the extension
- **THEN** the system MUST return HTTP 400 with message "Unsupported file type: pdf"
- **AND** no objects SHALL be created

### Requirement: The system MUST support bulk import via API

The bulk import API MUST accept an array of objects in a single request body for programmatic import without file upload. This endpoint SHALL leverage `SaveObjects` and `ChunkProcessingHandler` for high-performance batch processing with configurable chunk sizes.

#### Scenario: Bulk create objects via API
- **GIVEN** schema `contactmomenten` in register `klantcontact`
- **AND** a JSON request body containing an array of 500 objects
- **WHEN** the client sends `POST /api/objects/{register}/{schema}/bulk` with the array
- **THEN** `SaveObjects` SHALL process the objects in chunks (default chunk size: 5 per `ImportService::DEFAULT_CHUNK_SIZE`)
- **AND** the response MUST include `created`, `updated`, `unchanged`, and `errors` arrays

#### Scenario: Bulk import with validation enabled
- **GIVEN** the request includes query parameter `validation=true`
- **WHEN** the bulk import processes 500 objects
- **THEN** `BulkValidationHandler` SHALL validate each object against the schema definition
- **AND** objects that fail validation MUST appear in the `errors` array with their row index and error details
- **AND** valid objects MUST still be created (partial success)

#### Scenario: Bulk import with events disabled for performance
- **GIVEN** the request includes query parameter `events=false`
- **WHEN** 10,000 objects are imported
- **THEN** the system SHALL skip dispatching object lifecycle events (webhooks, audit trail entries)
- **AND** processing time MUST be measurably lower than with events enabled
- **AND** a SOLR warmup job SHALL be scheduled via `IJobList` after import completes

### Requirement: Import MUST validate data against schema definitions before insertion

Each row or object MUST be validated against the target schema's property definitions, including required fields, type constraints, enum values, format validators, and custom validation rules. Validation SHALL use the same `ValidateObject` infrastructure as single-object saves.

#### Scenario: Validation errors with partial success
- **GIVEN** schema `meldingen` with required property `titel` and enum property `status` with values [nieuw, in_behandeling, afgehandeld]
- **AND** a CSV with 100 rows where rows 15 and 42 have empty `titel` and row 88 has `status: "ongeldig"`
- **WHEN** the import runs with `validation=true`
- **THEN** 97 valid rows MUST be imported successfully
- **AND** 3 invalid rows MUST be skipped
- **AND** the `errors` array MUST contain entries like: `{"row": 15, "field": "titel", "error": "Required property 'titel' is missing"}`, `{"row": 88, "field": "status", "error": "Value 'ongeldig' is not one of the allowed values: nieuw, in_behandeling, afgehandeld"}`

#### Scenario: Import with validation disabled (fast mode)
- **GIVEN** the request includes `validation=false` (the default per `ImportService`)
- **WHEN** a CSV with 5000 rows is imported
- **THEN** the system SHALL skip schema validation for performance
- **AND** all rows MUST be inserted regardless of data quality
- **AND** the import summary MUST include a `validation` field set to `false` to indicate no validation was performed

#### Scenario: Validate relation references during import
- **GIVEN** schema `taken` has property `toegewezen_aan` with `format: uuid` referencing schema `medewerkers`
- **AND** a CSV row has `toegewezen_aan: "550e8400-e29b-41d4-a716-446655440000"`
- **WHEN** the import processes this row with validation enabled
- **THEN** the system SHALL verify that a `medewerkers` object with that UUID exists
- **AND** if the referenced object does not exist, the row MUST be reported as an error with message "Referenced object not found: 550e8400-e29b-41d4-a716-446655440000"

### Requirement: Import MUST provide detailed error reporting with downloadable error files

When an import completes with errors, the system MUST provide a detailed error report. The error report MUST be available as a downloadable CSV file containing the original row data plus error descriptions.

#### Scenario: Download error report after import
- **GIVEN** an import of 200 rows resulted in 12 validation errors
- **WHEN** the import response is returned
- **THEN** the response MUST include an `errors` array with each error containing: `row` (1-based row index), `field` (property name), `error` (human-readable message), and `data` (the original row data)
- **AND** the response MUST include an `errorReportUrl` pointing to a downloadable CSV

#### Scenario: Error CSV format
- **GIVEN** 3 import errors occurred
- **WHEN** the user downloads the error report CSV
- **THEN** the CSV MUST contain the original column headers plus two additional columns: `_error_field` and `_error_message`
- **AND** each error row MUST contain the original data values alongside the error details
- **AND** the CSV MUST use UTF-8 encoding

#### Scenario: Import with zero errors
- **GIVEN** all 500 rows passed validation
- **WHEN** the import completes
- **THEN** the `errors` array MUST be empty
- **AND** the response MUST NOT include an `errorReportUrl`

### Requirement: Import MUST support duplicate detection and upsert (idempotent import)

The system MUST detect existing objects based on configurable matching fields (UUID, external ID, or unique schema properties) and offer upsert behavior: update existing objects and create new ones. This makes imports idempotent -- running the same import twice SHALL NOT create duplicate records.

#### Scenario: Detect duplicates by UUID
- **GIVEN** schema `personen` with an `id` column in the CSV containing UUIDs
- **AND** 50 of 200 CSV rows have UUIDs that match existing objects in the register
- **WHEN** the import processes these rows
- **THEN** the 50 matching objects MUST be updated with the CSV data
- **AND** the remaining 150 rows MUST create new objects
- **AND** the summary MUST report `created: 150, updated: 50`

#### Scenario: Detect duplicates by unique schema property
- **GIVEN** schema `medewerkers` with property `personeelsnummer` marked as unique in the schema definition
- **AND** a CSV row has `personeelsnummer: "P12345"` which matches an existing object
- **WHEN** the import processes this row
- **THEN** the existing object MUST be updated with the new CSV data
- **AND** the `updated` array MUST include the object UUID

#### Scenario: Deduplication efficiency reporting
- **GIVEN** an import of 1000 rows where 300 are duplicates
- **WHEN** the import completes
- **THEN** the summary MUST include `deduplication_efficiency` (e.g., "30.0%") as already supported by `ImportService`
- **AND** the summary MUST include separate `created`, `updated`, and `unchanged` counts

#### Scenario: Skip unchanged duplicates
- **GIVEN** a CSV row matches an existing object by UUID
- **AND** the CSV data is identical to the existing object data
- **WHEN** the import processes this row
- **THEN** the object MUST NOT be updated (no unnecessary write)
- **AND** the row MUST be counted in the `unchanged` array

### Requirement: Import MUST support progress tracking for large datasets

For imports exceeding 100 rows, the system MUST provide progress tracking. The UI MUST display a progress indicator showing the current position and percentage. The import MUST run asynchronously without blocking the HTTP request.

#### Scenario: Progress tracking for large CSV import
- **GIVEN** a CSV file with 5000 rows
- **WHEN** the import starts
- **THEN** the API response MUST include an `importJobId` for polling progress
- **AND** polling `GET /api/objects/{register}/import/{jobId}/status` MUST return: `{"status": "processing", "processed": 1500, "total": 5000, "percentage": 30, "errors": 2}`

#### Scenario: Import completion notification
- **GIVEN** an asynchronous import of 10,000 rows completes
- **WHEN** the last chunk is processed
- **THEN** the system MUST send a Nextcloud notification via `INotifier` to the importing user
- **AND** the notification MUST include the import summary (created, updated, errors)
- **AND** the SOLR warmup job SHALL be scheduled via `IJobList::add()` as implemented in `ImportService::scheduleSmartSolrWarmup()`

#### Scenario: UI progress indicator
- **GIVEN** a user initiated an import from the objects view
- **WHEN** the import is processing
- **THEN** the UI MUST display a progress bar with text: "Importeren... 1500/5000 (30%)"
- **AND** the progress MUST update every 2 seconds via polling
- **AND** the user MUST be able to navigate away without cancelling the import

### Requirement: The system MUST support structured export to CSV, Excel (XLSX), JSON, XML, and ODS formats

Export MUST generate files in the requested format reflecting the current view state (filters, sort order). The `ExportService` SHALL handle CSV and Excel via `PhpSpreadsheet`, JSON via native `json_encode`, XML via `DOMDocument`, and ODS via `PhpSpreadsheet\Writer\Ods`.

#### Scenario: Export filtered list to CSV
- **GIVEN** 500 `meldingen` objects, filtered to show 45 with `status = afgehandeld`
- **WHEN** the user requests `GET /api/objects/{register}/{schema}/export?format=csv&status=afgehandeld`
- **THEN** `ExportService::exportToCsv()` SHALL return CSV content with exactly 45 data rows
- **AND** the CSV MUST use UTF-8 encoding with BOM (U+FEFF) for Excel compatibility
- **AND** the filename MUST follow the pattern `{register}_{schema}_{datetime}.csv` as implemented in `ObjectsController::export()`

#### Scenario: Export to Excel with schema-aware formatting
- **GIVEN** schema `meldingen` with properties: titel (string), aangemaakt (date-time), aantal (integer), status (enum)
- **WHEN** the user exports to Excel format
- **THEN** the XLSX file MUST include a header row using property keys as column headers (per `ExportService::getHeaders()`)
- **AND** the first column MUST be `id` containing the object UUID
- **AND** relation properties MUST have companion `_propertyName` columns with resolved human-readable names (per `ExportService::identifyNameCompanionColumns()`)
- **AND** admin users MUST see additional `@self.*` metadata columns (created, updated, owner, organisation, etc.)

#### Scenario: Export to JSON
- **GIVEN** 45 filtered `meldingen` objects
- **WHEN** the user exports to JSON format
- **THEN** the response MUST be a JSON array of 45 objects
- **AND** each object MUST use the same structure as the API response from `ObjectEntity::jsonSerialize()`
- **AND** Unicode characters MUST be preserved (JSON_UNESCAPED_UNICODE)

#### Scenario: Export to XML
- **GIVEN** 45 filtered `meldingen` objects
- **WHEN** the user exports to XML format
- **THEN** the response MUST be a valid XML document with root element `<objects>` and child elements `<object>`
- **AND** each object property MUST be a child element of `<object>` with the property name as element name
- **AND** array values MUST use repeated child elements

#### Scenario: Export entire register to Excel (multi-sheet)
- **GIVEN** register `gemeente-register` with schemas `personen` and `adressen`
- **WHEN** the user exports the register without specifying a schema
- **THEN** `ExportService::exportToExcel()` SHALL create one sheet per schema (per `populateSheet()`)
- **AND** each sheet title MUST be the schema slug
- **AND** CSV format MUST be rejected with "Cannot export multiple schemas to CSV format" (per existing implementation)

### Requirement: Export MUST support filtering and column selection

Export operations MUST respect the same filters, sort orders, and search queries available in the list view. Users MUST be able to select which columns to include in the export.

#### Scenario: Export with metadata filters
- **GIVEN** the export request includes filter `@self.owner=admin`
- **WHEN** `ExportService::fetchObjectsForExport()` processes the filter
- **THEN** the `@self.` prefix MUST be stripped and the filter applied as a metadata filter on the `owner` field
- **AND** only objects owned by `admin` SHALL appear in the export

#### Scenario: Export with multi-tenancy control
- **GIVEN** the export request includes `_multi=false`
- **WHEN** the export fetches objects
- **THEN** `ObjectService::searchObjects()` SHALL be called with `_multitenancy: false`
- **AND** only objects belonging to the current user's organisation SHALL be exported

#### Scenario: Export with column selection
- **GIVEN** schema `meldingen` has 15 properties
- **AND** the export request includes `_columns=titel,status,locatie`
- **WHEN** the export generates headers
- **THEN** only the specified columns (plus the mandatory `id` column) SHALL appear in the export
- **AND** companion `_propertyName` columns for relation properties among the selected columns SHALL be included

### Requirement: Export MUST resolve relations to human-readable names

When exporting objects with relation properties (UUID references to other objects), the export MUST include companion columns with resolved human-readable names. The resolution SHALL use the two-pass bulk approach in `ExportService::resolveUuidNameMap()` for performance.

#### Scenario: Export with single UUID relation
- **GIVEN** schema `taken` has property `toegewezen_aan` with `format: uuid` referencing schema `medewerkers`
- **AND** object has `toegewezen_aan: "550e8400-e29b-41d4-a716-446655440000"` which resolves to medewerker "Jan de Vries"
- **WHEN** the export generates the spreadsheet
- **THEN** column `toegewezen_aan` MUST contain the UUID
- **AND** companion column `_toegewezen_aan` MUST contain "Jan de Vries"

#### Scenario: Export with array of UUID relations
- **GIVEN** schema `projecten` has property `teamleden` with `type: array, items: {format: uuid}`
- **AND** object has `teamleden: ["uuid-1", "uuid-2", "uuid-3"]`
- **WHEN** the export resolves names via `CacheHandler::getMultipleObjectNames()`
- **THEN** the `teamleden` column MUST contain the JSON array of UUIDs
- **AND** the `_teamleden` column MUST contain a JSON array of resolved names: `["Jan de Vries", "Piet Bakker", "Anna Smit"]`

#### Scenario: Bulk UUID resolution with pre-seeding
- **GIVEN** an export of 1000 objects where 200 have self-references (objects referencing other exported objects)
- **WHEN** `ExportService::resolveUuidNameMap()` runs
- **THEN** the pre-seeding step SHALL populate the name map from already-loaded objects (avoiding DB lookups for self-references)
- **AND** only UUIDs not in the pre-seeded map SHALL be resolved via `CacheHandler::getMultipleObjectNames()`

### Requirement: Export MUST support streaming for large datasets

For datasets exceeding 10,000 objects, the export MUST use streaming output to avoid memory exhaustion. The system SHALL NOT build the complete file in memory before sending the response.

#### Scenario: Stream large CSV export
- **GIVEN** 50,000 `meldingen` objects match the export filter
- **WHEN** the user requests CSV export
- **THEN** the system SHALL use `php://output` with `ob_start()`/`ob_get_clean()` (as currently implemented) for datasets under the memory threshold
- **AND** for datasets exceeding 10,000 rows, the system SHALL use chunked streaming with `Transfer-Encoding: chunked`
- **AND** memory usage MUST NOT exceed 256MB regardless of dataset size

#### Scenario: Stream large Excel export
- **GIVEN** 50,000 objects to export
- **WHEN** the user requests Excel export
- **THEN** the system SHALL write directly to `php://output` using `PhpSpreadsheet\Writer\Xlsx::save('php://output')`
- **AND** the response MUST include appropriate Content-Disposition and Content-Type headers

#### Scenario: JSON Lines export for very large datasets
- **GIVEN** more than 100,000 objects match the export filter
- **WHEN** the user requests JSON export with `format=jsonl`
- **THEN** the system SHALL output one JSON object per line (JSON Lines / JSONL format per RFC 7464)
- **AND** each line MUST be a complete, parseable JSON object
- **AND** the Content-Type MUST be `application/x-ndjson`

### Requirement: Import MUST support field mapping and value transformation

Users MUST be able to map source file columns to target schema properties and define value transformations. This SHALL support renaming columns, setting default values for unmapped properties, and applying simple value conversions.

#### Scenario: Column-to-property mapping
- **GIVEN** a CSV with columns: Titel, Omschrijving, Locatie (Dutch names)
- **AND** schema `meldingen` has properties: title, description, location (English names)
- **WHEN** the user provides a mapping: `{"Titel": "title", "Omschrijving": "description", "Locatie": "location"}`
- **THEN** the system SHALL apply the mapping before creating objects
- **AND** unmapped CSV columns SHALL be ignored

#### Scenario: Default values for unmapped properties
- **GIVEN** the CSV has no `status` column
- **AND** the import configuration includes `{"defaults": {"status": "nieuw"}}`
- **WHEN** the import creates objects
- **THEN** all imported objects MUST have `status: "nieuw"`
- **AND** if a CSV column `status` does exist, its value SHALL override the default

#### Scenario: Array value parsing from CSV
- **GIVEN** a CSV cell contains `["tag1", "tag2", "tag3"]` (JSON array syntax)
- **WHEN** the `ImportService` processes this cell
- **THEN** the value MUST be parsed as a PHP array (as implemented in the existing array parsing logic)
- **AND** comma-separated values without JSON syntax (e.g., `tag1, tag2, tag3`) MUST also be parsed as arrays when the schema property type is `array`

#### Scenario: Metadata column import for admin users
- **GIVEN** an admin user imports a CSV with `@self.owner` and `@self.organisation` columns
- **WHEN** `ImportService::isUserAdmin()` returns true
- **THEN** the `@self.*` columns SHALL be used to set object metadata (owner, organisation, created, etc.)
- **AND** for non-admin users, `@self.*` columns MUST be silently ignored

### Requirement: Import MUST support rollback on critical failure

When a critical (non-validation) error occurs during import -- such as database connection loss, disk full, or schema deletion -- the system MUST roll back all objects created in the current import batch to maintain data consistency.

#### Scenario: Database error during chunked import
- **GIVEN** an import of 1000 objects processed in chunks of 5 (per `ImportService::DEFAULT_CHUNK_SIZE`)
- **AND** a database connection error occurs at row 500
- **WHEN** the error is caught
- **THEN** objects created in the current chunk (rows 496-500) MUST be rolled back
- **AND** objects from previously completed chunks (rows 1-495) MUST remain (they were already committed)
- **AND** the error response MUST indicate how many objects were successfully imported before failure

#### Scenario: Schema not found during import
- **GIVEN** a multi-sheet Excel import where sheet `orders` references a non-existent schema
- **WHEN** `processMultiSchemaSpreadsheetAsync()` fails to find a matching schema
- **THEN** that sheet MUST be skipped with an error in the summary
- **AND** other sheets MUST continue processing normally
- **AND** the response MUST include per-sheet results

#### Scenario: Memory limit during large import
- **GIVEN** a CSV with 100,000 rows and complex nested JSON values
- **WHEN** PHP memory usage approaches the limit during chunk processing
- **THEN** the system SHALL reduce the chunk size (down to `ImportService::MINIMAL_CHUNK_SIZE` of 2)
- **AND** the import MUST continue with reduced chunk size rather than crashing

### Requirement: Import templates MUST be downloadable per schema

Users MUST be able to download a template file pre-configured for a specific schema, containing headers matching schema properties, example data, and documentation of required fields and valid values.

#### Scenario: Download CSV import template
- **GIVEN** schema `meldingen` with properties: titel (required, string), omschrijving (string), status (enum: nieuw, in_behandeling, afgehandeld), locatie (string)
- **WHEN** the user requests `GET /api/objects/{register}/{schema}/template?format=csv`
- **THEN** the CSV MUST contain a header row: `titel,omschrijving,status,locatie`
- **AND** a second row with example data: `"Voorbeeld melding","Beschrijving van de melding","nieuw","Amsterdam"`
- **AND** the filename MUST follow pattern `{schema_slug}_template.csv`

#### Scenario: Download Excel import template with documentation
- **GIVEN** the same `meldingen` schema
- **WHEN** the user requests an Excel template
- **THEN** the XLSX file MUST contain two sheets: `data` (with headers and example row) and `instructies` (with field documentation)
- **AND** the `instructies` sheet MUST list each property with: name, type, required (yes/no), description, allowed values (for enums)

#### Scenario: Template respects property visibility
- **GIVEN** schema `meldingen` has property `interne_notitie` with `hideOnCollection: true`
- **WHEN** the template is generated
- **THEN** the `interne_notitie` column MUST still be included in the template (it is importable even if hidden on collection views)
- **AND** properties with `visible: false` MUST be excluded from the template

### Requirement: Import and export MUST respect RBAC permissions

Users MUST only be able to import into and export from registers and schemas they have appropriate permissions for. Property-level RBAC SHALL control which columns appear in exports and which columns are accepted during import. The existing `PropertyRbacHandler` and `MagicRbacHandler` SHALL be the single source of truth.

#### Scenario: Export blocked for unauthorized user
- **GIVEN** user `medewerker-1` has no access to register `vertrouwelijk`
- **WHEN** they request an export via `GET /api/objects/vertrouwelijk/documenten/export`
- **THEN** the system MUST return HTTP 403
- **AND** no data SHALL be returned

#### Scenario: Import blocked for read-only user
- **GIVEN** user `lezer-1` has only `read` access to schema `meldingen`
- **WHEN** they attempt to upload a CSV via `POST /api/objects/{register}/import`
- **THEN** the system MUST return HTTP 403 with message "Insufficient permissions for import"

#### Scenario: Property-level RBAC on export columns
- **GIVEN** schema `personen` has property `bsn` with authorization rule restricting read access to group `privacy-officers`
- **AND** user `medewerker-1` is NOT in group `privacy-officers`
- **WHEN** the export generates headers via `ExportService::getHeaders()`
- **THEN** the `bsn` column MUST be excluded (per `PropertyRbacHandler::canReadProperty()`)
- **AND** the companion `_bsn` column MUST also be excluded

#### Scenario: Admin metadata columns in export
- **GIVEN** user `admin` is in the `admin` group
- **WHEN** the export generates headers
- **THEN** `@self.*` metadata columns (created, updated, deleted, locked, owner, organisation, etc.) MUST be included (per `ExportService::getHeaders()` admin check)
- **AND** non-admin users MUST NOT see these columns

### Requirement: The system MUST support i18n for export headers and templates

Export header labels and import template documentation MUST support internationalization. At minimum, Dutch (nl) and English (en) MUST be supported.

#### Scenario: Export with Dutch header labels
- **GIVEN** the user's Nextcloud locale is set to `nl`
- **AND** schema `meldingen` has property `titel` with `title: "Titel"` in its definition
- **WHEN** the export generates the spreadsheet
- **THEN** the header row MAY optionally use the property's `title` field as a display label
- **AND** the property key (`titel`) MUST remain available as a secondary header or in a documentation sheet for re-import compatibility

#### Scenario: Template documentation in user language
- **GIVEN** the user's locale is `nl`
- **WHEN** the user downloads an Excel import template
- **THEN** the `instructies` sheet MUST use Dutch labels: "Veldnaam", "Type", "Verplicht", "Beschrijving", "Toegestane waarden"
- **AND** the system messages (e.g., "Dit veld is verplicht") MUST be in Dutch

#### Scenario: Export with English header labels
- **GIVEN** the user's Nextcloud locale is set to `en`
- **WHEN** the export generates the spreadsheet
- **THEN** the template documentation MUST use English labels: "Field name", "Type", "Required", "Description", "Allowed values"

### Requirement: Configuration import/export MUST support full register portability

The `Configuration/ExportHandler` and `Configuration/ImportHandler` SHALL support exporting and importing complete register configurations (schemas, objects, mappings, workflows) as OpenAPI 3.0.0 + `x-openregister` extension files. This enables register portability between OpenRegister instances.

#### Scenario: Export configuration with objects
- **GIVEN** configuration `gemeente-config` with register `gemeente-register` containing 2 schemas and 100 objects
- **WHEN** the admin exports with `includeObjects=true`
- **THEN** `ExportHandler::exportConfig()` SHALL produce an OpenAPI 3.0.0 spec with `components.registers`, `components.schemas`, and `components.objects`
- **AND** all internal IDs MUST be converted to slugs for portability (per `exportSchema()` slug resolution)
- **AND** `$ref` references in schema properties MUST be converted from numeric IDs to schema slugs

#### Scenario: Import configuration into new instance
- **GIVEN** an OpenAPI 3.0.0 JSON file exported from another instance
- **WHEN** `ImportHandler` processes the file
- **THEN** schemas SHALL be created first, then workflows deployed (per `workflow-in-import` spec), then objects imported
- **AND** slug-based references SHALL be resolved to new numeric IDs on the target instance
- **AND** the import MUST be idempotent -- re-importing the same file SHALL update existing entities rather than creating duplicates

#### Scenario: Export configuration with workflows
- **GIVEN** schema `organisatie` has a deployed n8n workflow attached to the `created` event
- **WHEN** the configuration is exported
- **THEN** `ExportHandler::exportWorkflowsForSchema()` SHALL include the workflow definition fetched from the engine
- **AND** the `attachTo` block MUST reference the schema by slug, not by ID

#### Scenario: Export mappings
- **GIVEN** configuration has 3 associated mappings
- **WHEN** the configuration is exported
- **THEN** each mapping SHALL appear in `components.mappings` keyed by its slug
- **AND** instance-specific properties (id, uuid, organisation, created, updated) MUST be removed

### Requirement: The system MUST support scheduled and automated imports

Administrators MUST be able to configure recurring imports from files stored in Nextcloud Files or external URLs. Scheduled imports SHALL use Nextcloud's `QueuedJob` infrastructure.

#### Scenario: Schedule daily CSV import from Nextcloud Files
- **GIVEN** an admin configures a scheduled import: source file `/Documents/daily-export.csv`, target register `meldingen-register`, schema `meldingen`, schedule: daily at 02:00
- **WHEN** the scheduled time arrives
- **THEN** a `QueuedJob` SHALL read the file from Nextcloud Files via WebDAV
- **AND** process it through `ImportService::importFromCsv()`
- **AND** the import result SHALL be logged and a notification sent to the admin

#### Scenario: Schedule import from external URL
- **GIVEN** an admin configures a scheduled import from `https://data.overheid.nl/export/besluiten.json`
- **WHEN** the scheduled job runs
- **THEN** the system SHALL fetch the file via HTTP (using `GuzzleHttp\Client` as already used in `ImportHandler`)
- **AND** process it as a JSON import into the configured register and schema

#### Scenario: Scheduled import with unchanged data detection
- **GIVEN** a daily import runs and the source file has not changed since the last import
- **WHEN** the import processes all rows
- **THEN** the summary MUST show all objects as `unchanged`
- **AND** no database writes SHALL occur for unchanged objects (deduplication optimization)

## Current Implementation Status
- **Implemented:**
  - `ImportService` (`lib/Service/ImportService.php`) with `importFromCsv()` and `importFromExcel()` methods for batch import with ReactPHP optimization
  - `ExportService` (`lib/Service/ExportService.php`) with `exportToCsv()` and `exportToExcel()` methods with RBAC-aware header generation, relation name resolution, and multi-tenancy support
  - `Configuration/ImportHandler` (`lib/Service/Configuration/ImportHandler.php`) for importing OpenAPI 3.0.0 configuration data (registers, schemas, objects, workflows, mappings)
  - `Configuration/ExportHandler` (`lib/Service/Configuration/ExportHandler.php`) for exporting configurations to OpenAPI format with slug-based references
  - `Object/ExportHandler` (`lib/Service/Object/ExportHandler.php`) for coordinating export and import operations between controller and services
  - `SaveObjects` (`lib/Service/Object/SaveObjects.php`) with `ChunkProcessingHandler` for bulk operations (60-70% fewer DB calls, 2-3x faster)
  - `BulkRelationHandler` (`lib/Service/Object/SaveObjects/BulkRelationHandler.php`) for handling inverse relations during bulk import
  - `BulkValidationHandler` (`lib/Service/Object/SaveObjects/BulkValidationHandler.php`) for schema analysis caching and bulk validation
  - `ObjectsController::export()` endpoint returning `DataDownloadResponse` with CSV or XLSX
  - `ObjectsController::import()` endpoint accepting file upload with optional schema, validation, events, rbac, and multitenancy parameters
  - `BulkController` for API-based bulk object operations
  - SOLR warmup scheduling after import via `IJobList` and `SolrWarmupJob`
  - Deduplication efficiency reporting in import summaries
  - Multi-sheet Excel import with per-sheet schema matching
  - Two-pass UUID-to-name resolution in exports with pre-seeding optimization
  - Property-level RBAC enforcement on export columns via `PropertyRbacHandler`
  - Admin-only `@self.*` metadata columns in exports
- **NOT implemented:**
  - JSON and XML import formats (only CSV and Excel currently supported)
  - JSON, XML, and ODS export formats (only CSV and Excel currently supported)
  - JSON Lines (JSONL) export for very large datasets
  - Interactive column mapping UI (upload CSV, map columns to schema properties, preview)
  - Default values for unmapped properties during import
  - Progress tracking UI and polling endpoint for large imports
  - Downloadable error report CSV after import
  - Import template generation (downloadable CSV/Excel with headers, example data, and documentation)
  - Column selection for exports (`_columns` parameter)
  - Streaming export for datasets exceeding 10,000 rows
  - Scheduled/recurring imports from Nextcloud Files or external URLs
  - i18n of export headers and template documentation
  - Import rollback on critical failure (chunk-level transactions)
  - UTF-8 BOM for CSV export
- **Partial:**
  - CSV and Excel import/export is fully functional at the service level but lacks the full user-facing workflow (mapping, preview, validation reporting, progress)
  - Bulk operations exist with deduplication but without explicit upsert mode selection (skip/update/create options)
  - Relation reference validation during import not yet enforced
  - Validation during import is opt-in (`validation=false` by default) and uses SaveObjects infrastructure rather than full ValidateObject pipeline

## Standards & References
- **RFC 4180** -- CSV format specification
- **RFC 7464** -- JSON Text Sequences (JSONL/NDJSON)
- **ECMA-376 / ISO/IEC 29500** -- Office Open XML (XLSX) format
- **ISO/IEC 26300** -- Open Document Format (ODS)
- **PhpOffice/PhpSpreadsheet** -- PHP library for Excel/CSV/ODS file generation (already used)
- **UTF-8 BOM (U+FEFF)** -- Required for Excel CSV compatibility
- **Nextcloud QueuedJob (OCP\BackgroundJob\QueuedJob)** -- For async import processing
- **Nextcloud INotifier (OCP\Notification\INotifier)** -- For import completion notifications
- **OpenAPI 3.0.0** -- Configuration export/import format with `x-openregister` extensions
- **Nextcloud Files WebDAV** -- For import template storage and scheduled file imports

## Cross-References
- **mock-registers** -- Mock register JSON files use the same `ConfigurationService -> ImportHandler` pipeline for seed data import. The `components.objects[]` array follows the `@self` envelope format processed by this import pipeline.
- **data-sync-harvesting** -- The three-stage sync pipeline (gather, fetch, import) uses the import infrastructure for its final stage. Field mapping and transformation requirements overlap significantly with import mapping.
- **workflow-in-import** -- Workflow definitions in import files are processed between schemas and objects. The `ImportHandler` handles workflow deployment during configuration import.
- **workflow-engine-abstraction** -- Exported configurations include workflow definitions via `ExportHandler::exportWorkflowsForSchema()`.

## Specificity Assessment
- The spec is comprehensive with 15 requirements covering all requested areas: format support, bulk API, schema validation, error reporting, duplicate detection, progress tracking, export formats, export filtering, relation resolution, streaming, field mapping, rollback, templates, RBAC, i18n, configuration portability, and scheduled imports.
- The core import/export services are mature and production-ready with significant optimization (chunked processing, bulk relation handling, deduplication, two-pass name resolution).
- Primary gaps are in user-facing workflow (interactive mapping UI, progress UI, error report downloads) and format expansion (JSON, XML, ODS, JSONL).
- Open questions resolved: imports run synchronously by default with async support planned; references are resolved by UUID; export currently outputs all columns with column selection as a planned feature; no hard file size limit but chunk size adapts to complexity.

## Nextcloud Integration Analysis

**Status**: Partially Implemented

**Existing Implementation**: `ImportService` and `ExportService` provide CSV and Excel import/export at the service layer with comprehensive bulk optimization via `SaveObjects`, `ChunkProcessingHandler`, `BulkRelationHandler`, and `BulkValidationHandler`. Configuration import/export is handled by `Configuration/ImportHandler` and `Configuration/ExportHandler` using OpenAPI 3.0.0 format. Object-level export is available via `Object/ExportHandler`. The `ObjectsController` exposes `export()` and `import()` endpoints. RBAC is enforced via `PropertyRbacHandler` for column visibility and admin checks for metadata columns.

**Nextcloud Core Integration**: The import pipeline leverages `QueuedJob` (OCP\BackgroundJob\QueuedJob) for SOLR warmup scheduling after imports. Completion notifications should use `INotifier` (OCP\Notification\INotifier). File handling should integrate with Nextcloud Files (WebDAV) for import template storage, scheduled file imports, and export file delivery. The `IJobList` service is already injected into `ImportService` for background job management.

**Recommendation**: The core import/export services are solid and production-ready for backend operations. Priority enhancements should be: (1) Add JSON and XML import/export formats to match competitor feature parity, (2) Implement progress tracking with a polling endpoint for imports over 100 rows, (3) Add downloadable error report generation, (4) Implement import template generation per schema, (5) Add UTF-8 BOM to CSV exports for Excel compatibility. For streaming large exports, consider using `StreamResponse` or chunked transfer encoding rather than buffering in `ob_start()`. The existing `PropertyRbacHandler` integration is excellent and should be extended to import operations (rejecting writes to RBAC-protected properties for non-authorized users).
