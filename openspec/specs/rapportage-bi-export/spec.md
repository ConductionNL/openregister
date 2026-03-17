# rapportage-bi-export Specification

## Purpose
Implement reporting and business intelligence export capabilities for register data. The system MUST support CSV, Excel, PDF, and OData export of object data, provide dashboard-ready API endpoints for aggregated metrics, and enable integration with external BI tools for management reporting and KPI tracking.

**Tender demand**: 89% of analyzed government tenders require reporting and BI export capabilities.

## ADDED Requirements

### Requirement: The system MUST support data export in multiple formats
Register objects MUST be exportable in CSV, Excel (XLSX), JSON, and PDF formats.

#### Scenario: Export object list to CSV
- GIVEN a register `zaken` with schema `meldingen` containing 200 objects
- WHEN the user clicks Export and selects CSV format
- THEN the system MUST generate a CSV file containing all 200 objects
- AND each row MUST include all schema-defined properties as columns
- AND the CSV MUST use UTF-8 encoding with BOM for Excel compatibility
- AND the file MUST be downloaded to the user's browser

#### Scenario: Export filtered results to Excel
- GIVEN 200 meldingen objects, 45 of which have status `afgehandeld`
- AND the user has applied filter status = `afgehandeld`
- WHEN the user exports to XLSX format
- THEN the Excel file MUST contain only the 45 filtered objects
- AND the file MUST include a header row with human-readable column names
- AND date fields MUST be formatted as Excel date values

#### Scenario: Export to PDF report
- GIVEN 25 vergunningen objects filtered by date range
- WHEN the user exports to PDF
- THEN the system MUST generate a formatted PDF document with:
  - Report title and generation date
  - Summary statistics (total count, status breakdown)
  - Table of objects with key properties
- AND the PDF MUST support pagination for large result sets

### Requirement: The system MUST provide aggregation API endpoints
REST API endpoints MUST support aggregation queries for dashboard and reporting use cases.

#### Scenario: Count objects by status
- GIVEN schema `meldingen` with objects in statuses: nieuw (30), in_behandeling (45), afgehandeld (125)
- WHEN the API receives GET /api/objects/{register}/{schema}/aggregate?groupBy=status&metric=count
- THEN the response MUST return: `[{"status": "nieuw", "count": 30}, {"status": "in_behandeling", "count": 45}, {"status": "afgehandeld", "count": 125}]`

#### Scenario: Sum values grouped by category
- GIVEN schema `subsidies` with objects containing `bedrag` and `categorie` properties
- WHEN the API receives GET /api/objects/{register}/{schema}/aggregate?groupBy=categorie&metric=sum&field=bedrag
- THEN the response MUST return the sum of `bedrag` per category

#### Scenario: Time-series aggregation
- GIVEN schema `meldingen` with objects created over the past 12 months
- WHEN the API receives GET /api/objects/{register}/{schema}/aggregate?groupBy=created&interval=month&metric=count
- THEN the response MUST return monthly counts for the past 12 months

### Requirement: The system MUST support OData endpoint for BI tool integration
An OData v4 compatible endpoint MUST be available for integration with external BI tools.

#### Scenario: Connect BI tool to OData endpoint
- GIVEN the OData endpoint is configured for register `zaken`
- WHEN an external BI tool connects to /api/odata/{register}/{schema}
- THEN the endpoint MUST return OData-compliant JSON
- AND the endpoint MUST support $filter, $select, $orderby, $top, $skip, and $count

#### Scenario: OData authentication
- GIVEN an OData endpoint request with Basic Auth credentials
- WHEN the credentials are valid
- THEN the endpoint MUST enforce the same RBAC rules as the REST API
- AND unauthorized schemas MUST NOT be exposed in the OData service document

### Requirement: The system MUST support scheduled report generation
Reports MUST be configurable to run on a schedule and be delivered via email or stored in Nextcloud Files.

#### Scenario: Schedule a weekly status report
- GIVEN a report definition: schema `meldingen`, filter `status != afgehandeld`, format `PDF`
- AND schedule: every Monday at 08:00
- AND delivery: email to `management@example.nl`
- WHEN Monday 08:00 arrives
- THEN the system MUST generate the PDF report with current data
- AND email the report as an attachment

#### Scenario: Store scheduled report in Nextcloud
- GIVEN a report with delivery target as a Nextcloud Files path
- WHEN the scheduled report runs
- THEN the PDF MUST be stored at the specified Nextcloud Files path
- AND old reports MUST be retained according to configured retention (default: 52 weeks)

### Requirement: Export MUST respect RBAC permissions
Data exports MUST only include objects and fields the requesting user is authorized to see.

#### Scenario: Restricted export
- GIVEN user `medewerker-1` has read access to schema `meldingen` but not `vertrouwelijk`
- WHEN `medewerker-1` exports from register `zaken`
- THEN the export MUST include `meldingen` objects only
- AND `vertrouwelijk` objects MUST NOT appear in the export

### Current Implementation Status
- **Partially implemented — CSV export**: `ExportHandler` (`lib/Service/Object/ExportHandler.php`) supports exporting objects to CSV format (line ~126). CSV files use proper encoding.
- **Partially implemented — Excel (XLSX) export**: `ExportHandler` also supports Excel export via `ExportService::exportToExcel()` using the PhpSpreadsheet `Xlsx` writer (line ~150). The `ExportService` (`lib/Service/ExportService.php`) handles the spreadsheet generation.
- **Partially implemented — CSV/Excel import**: `ExportHandler` also handles importing objects from CSV and Excel files (line ~195+), supporting both `.xlsx`/`.xls` and `.csv` formats.
- **Not implemented — PDF export**: No PDF generation service or library exists in the codebase. No report formatting with titles, summary statistics, or paginated tables.
- **Not implemented — aggregation API endpoints**: No `/aggregate` endpoints exist with `groupBy`, `metric`, `sum`, or time-series aggregation. The `MetricsService` provides some aggregate counts but not a general-purpose aggregation API.
- **Not implemented — OData endpoint**: No OData v4 compatible endpoint exists. No `$filter`, `$select`, `$orderby` OData query parameter support.
- **Not implemented — scheduled report generation**: No cron job or background task for scheduled report generation and delivery exists.
- **Partially implemented — RBAC on exports**: Exports go through the standard object retrieval pipeline which respects RBAC via `PermissionHandler` and `MagicRbacHandler`, so exported data should only include authorized objects/fields.
- **Related — configuration export**: `Configuration/ExportHandler` (`lib/Service/Configuration/ExportHandler.php`) handles register/schema configuration export (JSON format), which is different from data export.

### Standards & References
- OData v4 specification (https://www.odata.org/documentation/) for BI tool integration
- ISO 32000 (PDF specification) for report generation
- ECMA-376 (Office Open XML) for XLSX format
- RFC 4180 for CSV format
- PhpSpreadsheet library (https://phpspreadsheet.readthedocs.io/) — already used for XLSX export
- BIO (Baseline Informatiebeveiliging Overheid) for data export security requirements
- Common Ground principles for API-based data access

### Specificity Assessment
- **Moderately specific**: The spec covers export formats, aggregation API, OData integration, scheduled reports, and RBAC enforcement with clear scenarios.
- **Missing details**:
  - PDF generation library choice (TCPDF, Dompdf, wkhtmltopdf?)
  - OData library or custom implementation?
  - Aggregation query execution (SQL-level or application-level?)
  - Scheduled report storage and retention management
  - Export size limits and streaming for large datasets
- **Open questions**:
  - Should OData support be a priority given the REST API already supports rich filtering?
  - How should scheduled reports be configured — admin UI, API, or both?
  - Should PDF reports use a template system for custom branding?
  - How large can exports get before they need async processing?
