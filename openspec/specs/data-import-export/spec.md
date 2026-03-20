---
status: partial
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
