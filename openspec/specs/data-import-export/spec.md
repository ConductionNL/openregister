# data-import-export Specification

## Purpose
Implement batch data import with field mapping, validation, and error reporting, plus structured export to CSV, Excel, and JSON formats. The import pipeline MUST support large datasets with progress tracking, duplicate detection, and partial failure handling. Export MUST respect active filters and RBAC permissions.

**Source**: Gap identified in cross-platform analysis; three platforms implement batch import/export.

## ADDED Requirements

### Requirement: The system MUST support batch import from CSV and Excel files
Users MUST be able to upload CSV or Excel files and map columns to schema properties for bulk object creation.

#### Scenario: Import CSV with column mapping
- GIVEN schema `meldingen` with properties: title, description, status, location
- AND a CSV file with columns: Titel, Omschrijving, Locatie (no status column)
- WHEN the user uploads the CSV and maps:
  - Titel -> title
  - Omschrijving -> description
  - Locatie -> location
- THEN the system MUST show a preview of the first 5 rows with mapped values
- AND the user MUST confirm before import starts

#### Scenario: Import with default values
- GIVEN the CSV has no `status` column
- WHEN the user configures default value `nieuw` for unmapped property `status`
- THEN all imported objects MUST have `status: "nieuw"`

#### Scenario: Import progress tracking
- GIVEN a CSV file with 5000 rows
- WHEN the import starts
- THEN the UI MUST show a progress indicator: `Importing... 1500/5000 (30%)`
- AND the import MUST run asynchronously (not blocking the UI)

### Requirement: Import MUST validate data before insertion
Each row MUST be validated against the schema's property definitions before creating objects.

#### Scenario: Validation errors in import
- GIVEN a CSV with 100 rows where rows 15, 42, and 88 have missing required fields
- WHEN the import runs
- THEN valid rows (97) MUST be imported successfully
- AND invalid rows (3) MUST be skipped
- AND the import report MUST list: `Row 15: title is required. Row 42: title is required. Row 88: status is not a valid enum value.`

#### Scenario: Download error report
- GIVEN an import with 10 validation errors
- WHEN the import completes
- THEN the user MUST be able to download an error report CSV
- AND the error CSV MUST contain the original row data plus an error column

### Requirement: Import MUST support duplicate detection
The system MUST detect potential duplicates based on configurable matching rules.

#### Scenario: Detect duplicates by unique field
- GIVEN schema `personen` with property `bsn` marked as unique
- AND a CSV row has BSN `123456789` which already exists in the register
- WHEN the import processes this row
- THEN the system MUST flag it as a duplicate
- AND offer options: skip, update existing, or create anyway

#### Scenario: Bulk update via import
- GIVEN 200 existing objects matched by external ID
- WHEN the user selects "Update existing" for duplicates
- THEN matched objects MUST be updated with the CSV data
- AND new objects MUST be created for non-matching rows

### Requirement: The system MUST support structured export with filters
Export MUST generate files reflecting the current view (filters, sort) in CSV, Excel, or JSON format.

#### Scenario: Export filtered list to CSV
- GIVEN 500 meldingen objects, filtered to show 45 with status `afgehandeld`
- WHEN the user clicks Export CSV
- THEN the CSV MUST contain exactly 45 rows
- AND columns MUST match the schema properties
- AND the CSV MUST use UTF-8 encoding with BOM

#### Scenario: Export to Excel with formatting
- GIVEN the same 45 filtered objects
- WHEN the user exports to Excel
- THEN the XLSX file MUST include:
  - Header row with property labels (not internal names)
  - Date columns formatted as dates
  - Number columns formatted as numbers

#### Scenario: Export to JSON
- GIVEN the same 45 filtered objects
- WHEN the user exports to JSON
- THEN the JSON file MUST contain an array of 45 objects
- AND each object MUST use the same structure as the API response

### Requirement: Import templates MUST be downloadable
Users MUST be able to download a template file pre-configured for a schema.

#### Scenario: Download import template
- GIVEN schema `meldingen` with properties: title, description, status, location
- WHEN the user clicks "Download template"
- THEN a CSV file MUST be generated with:
  - Header row: title, description, status, location
  - One example row with placeholder data
  - A README sheet (for Excel) explaining required fields and valid values

### Requirement: Import/export MUST respect RBAC
Users MUST only import into and export from schemas they have appropriate permissions for.

#### Scenario: Import blocked for read-only user
- GIVEN user `medewerker-1` has only `read` access to schema `meldingen`
- WHEN they attempt to import a CSV
- THEN the system MUST return HTTP 403: insufficient permissions for import

### Current Implementation Status
- **Implemented:**
  - `ImportService` (`lib/Service/ImportService.php`) with `importFromCsv()` and `importFromExcel()` methods for batch import
  - `ExportService` (`lib/Service/ExportService.php`) with `exportToCsv()` and `exportToExcel()` methods for structured export
  - `Configuration/ImportHandler` (`lib/Service/Configuration/ImportHandler.php`) for importing configuration data
  - `Configuration/ExportHandler` (`lib/Service/Configuration/ExportHandler.php`) for exporting configuration data
  - `Object/ExportHandler` (`lib/Service/Object/ExportHandler.php`) for object-level export
  - Magic Mapper import tested via Newman collection (`tests/integration/magic-mapper-import.postman_collection.json`)
  - `SaveObjects` (`lib/Service/Object/SaveObjects.php`) with `ChunkProcessingHandler` for bulk operations
  - `BulkRelationHandler` (`lib/Service/Object/SaveObjects/BulkRelationHandler.php`) for handling relations during bulk import
- **NOT implemented:**
  - Interactive column mapping UI (upload CSV, map columns to schema properties, preview)
  - Default values for unmapped properties during import
  - Progress tracking UI for large imports
  - Validation error reports as downloadable CSV
  - Duplicate detection with configurable matching rules (skip/update/create options)
  - Import template generation (downloadable CSV/Excel with headers and example data)
  - RBAC enforcement on import/export operations
  - JSON export format
  - Excel formatting (date/number column formatting, property labels vs internal names)
  - UTF-8 BOM for CSV export
- **Partial:**
  - CSV and Excel import/export exists at the service level but lacks the full user-facing workflow (mapping, preview, validation reporting)
  - Bulk operations exist but without duplicate detection or conflict resolution

### Standards & References
- **RFC 4180** — CSV format specification
- **ECMA-376 / ISO/IEC 29500** — Office Open XML (XLSX) format
- **PhpSpreadsheet** — PHP library for Excel file generation (likely already used)
- **UTF-8 BOM (U+FEFF)** — Required for Excel CSV compatibility
- **Nextcloud Files WebDAV** — For import template storage
- **JSON Lines (JSONL)** — Alternative to JSON for large dataset export

### Specificity Assessment
- The spec is comprehensive with good coverage of import workflow, validation, duplicate detection, and export formats.
- The core import/export services exist and provide a foundation; the gap is primarily in UI workflow and advanced features.
- Missing: API endpoints for import workflow (upload, map, preview, confirm); async import job implementation details; how import interacts with computed fields and referential integrity.
- Ambiguous: how "current view filters" are passed to the export endpoint; whether export should include related objects or just the primary schema.
- Open questions:
  - Should import be synchronous (blocking request) or async (background job with status polling)?
  - How should import handle objects with references ($ref) — resolve by external ID or UUID?
  - Should export support selecting specific columns/properties or always export all?
  - What is the maximum file size for import?
