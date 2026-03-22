# Data Import and Export

## Problem
Document and extend OpenRegister's existing import/export infrastructure. The core pipeline is already implemented: ImportService with ChunkProcessingHandler for bulk ingest, ExportService/ExportHandler for CSV/JSON/XML output, and Configuration/ImportHandler for register template loading. This spec validates the existing implementation and defines extensions for additional formats, progress tracking, and schema validation. The existing pipeline already handles CSV and Excel import via PhpSpreadsheet, CSV and Excel export with RBAC-aware header generation and relation name resolution, configuration import/export in OpenAPI 3.0.0 format, bulk operations via SaveObjects with BulkRelationHandler and BulkValidationHandler, deduplication efficiency reporting, multi-sheet Excel import, two-pass UUID-to-name resolution, and property-level RBAC enforcement on export columns. This spec extends that foundation with JSON/XML/ODS format support, interactive column mapping, progress tracking UI, downloadable error reports, import templates, streaming for large datasets, scheduled imports, and i18n for headers and templates.
**Source**: Gap identified in cross-platform analysis; Baserow implements CSV export (core) and JSON/Excel export (premium) with view-scoped filtering; NocoDB implements Airtable/CSV/Excel import with async job processing and bulk API operations. Both competitors gate advanced export formats behind paid tiers -- OpenRegister should offer all formats in the open-source core.

## Proposed Solution
Implement Data Import and Export following the detailed specification. Key requirements include:
- Requirement: The system MUST support import from CSV, Excel, JSON, and XML formats
- Requirement: The system MUST support bulk import via API
- Requirement: Import MUST validate data against schema definitions before insertion
- Requirement: Import MUST provide detailed error reporting with downloadable error files
- Requirement: Import MUST support duplicate detection and upsert (idempotent import)

## Scope
This change covers all requirements defined in the data-import-export specification.

## Success Criteria
- Import a CSV file with auto-detected schema
- Import a multi-sheet Excel file with per-sheet schema mapping
- Import a JSON array of objects
- Import an XML file
- Reject unsupported file type
