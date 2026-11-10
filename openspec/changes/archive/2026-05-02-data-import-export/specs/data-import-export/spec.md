---
status: draft
---
# Data Import and Export

## Purpose
Document and extend OpenRegister's existing import/export infrastructure. The core pipeline is already implemented: ImportService with ChunkProcessingHandler for bulk ingest, ExportService/ExportHandler for CSV/JSON/XML output, and Configuration/ImportHandler for register template loading.

## ADDED Requirements


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

