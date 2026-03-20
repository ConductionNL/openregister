---
status: draft
---

# Rapportage en BI Export

## Purpose
Provide a comprehensive reporting and business intelligence export layer for OpenRegister that enables government organisations to generate management reports, perform data aggregation queries, connect external BI tools, and satisfy Dutch public accountability requirements (WOO, jaarverslag, verantwoording). The system MUST expose a general-purpose aggregation API (count, sum, avg, min, max, group by) on top of the existing `MagicMapper` and `MagicStatisticsHandler` infrastructure, support scheduled report generation via Nextcloud background jobs, produce exports in CSV, Excel, PDF, and ODS formats through the existing `ExportService`/`ExportHandler` pipeline, and provide OData v4 and ODBC-compatible endpoints for integration with Power BI, Tableau, and other external BI platforms. All reporting operations MUST enforce RBAC via `PermissionHandler`, `MagicRbacHandler`, and `PropertyRbacHandler`, and MUST respect multi-tenancy boundaries to guarantee data isolation between organisations.

**Tender demand**: 89% of analyzed government tenders require reporting and BI export capabilities. Key recurring requirements include management dashboards, KPI tracking, periodic status reports (wekelijkse voortgangsrapportage), WOO transparency reporting, and integration with existing BI tooling (Power BI, Tableau, QlikView).

## Requirements

### Requirement: The system MUST provide a general-purpose aggregation API
REST API endpoints MUST support aggregation queries with `count`, `sum`, `avg`, `min`, and `max` metrics, `groupBy` for categorical breakdowns, `interval` for time-series bucketing, and `having` for post-aggregation filtering. The aggregation engine SHALL leverage SQL-level `GROUP BY` queries via `MagicMapper` for database-backed schemas and delegate to Solr/Elasticsearch facet aggregations when a search backend is configured. This extends the existing `MagicStatisticsHandler::getStatistics()` and `MagicFacetHandler` infrastructure with a user-facing API.

#### Scenario: Count objects grouped by a categorical property
- **GIVEN** register `zaken` with schema `meldingen` containing objects with `status` values: nieuw (30), in_behandeling (45), afgehandeld (125)
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=status&metric=count`
- **THEN** the response MUST return `{"results": [{"status": "nieuw", "count": 30}, {"status": "in_behandeling", "count": 45}, {"status": "afgehandeld", "count": 125}], "total": 200}`
- **AND** the query MUST execute as a SQL `GROUP BY` on the magic table column (not application-level iteration)
- **AND** RBAC filtering via `MagicRbacHandler` MUST be applied before aggregation

#### Scenario: Sum a numeric property grouped by category
- **GIVEN** schema `subsidies` with objects containing `bedrag` (number) and `categorie` (string) properties
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=categorie&metric=sum&field=bedrag`
- **THEN** the response MUST return the sum of `bedrag` per `categorie`
- **AND** null values in `bedrag` MUST be excluded from the sum (SQL `SUM` semantics)
- **AND** the response MUST include `"metric": "sum"` and `"field": "bedrag"` for self-documentation

#### Scenario: Time-series aggregation with monthly interval
- **GIVEN** schema `meldingen` with objects created over the past 12 months
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=@self.created&interval=month&metric=count`
- **THEN** the response MUST return monthly counts for each of the past 12 months
- **AND** months with zero objects MUST still appear in the response with `count: 0` (gap filling)
- **AND** the date labels MUST use ISO 8601 format (`2026-01`, `2026-02`, etc.)

#### Scenario: Multiple metrics in a single request
- **GIVEN** schema `facturen` with numeric property `bedrag`
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=status&metric=count,sum,avg&field=bedrag`
- **THEN** each result row MUST include `count`, `sum`, and `avg` values
- **AND** the response format MUST be `{"results": [{"status": "betaald", "count": 50, "sum": 125000.00, "avg": 2500.00}, ...]}`

#### Scenario: Aggregation with filters applied
- **GIVEN** schema `meldingen` with 200 objects across three statuses
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=locatie&metric=count&status=nieuw`
- **THEN** the aggregation MUST only include objects where `status = nieuw`
- **AND** the same filter syntax used in `ObjectService::searchObjects()` MUST be accepted

### Requirement: The system MUST support configurable report templates
Administrators MUST be able to define report templates that specify data sources (register, schema, filters), layout sections (title, summary statistics, data table, charts), output format (PDF, Excel, CSV), and branding (logo, header/footer text, organisation name). Report templates SHALL be stored as OpenRegister objects in a dedicated `report-templates` schema, making them versionable and exportable via the standard configuration pipeline.

#### Scenario: Create a report template via API
- **GIVEN** an administrator with write access to the `report-templates` schema
- **WHEN** they create a template object with: `{"name": "Wekelijks Meldingen Rapport", "dataSource": {"register": "zaken", "schema": "meldingen", "filters": {"status!": "afgehandeld"}}, "sections": ["summary", "statusBreakdown", "dataTable"], "format": "pdf", "branding": {"logo": "/apps/theming/logo", "organisatie": "Gemeente Utrecht"}}`
- **THEN** the template MUST be stored and retrievable via the standard objects API
- **AND** the template MUST be usable by the report generation endpoint

#### Scenario: Render a report from a template
- **GIVEN** report template `Wekelijks Meldingen Rapport` exists
- **WHEN** the API receives `POST /api/reports/generate` with `{"templateId": "<uuid>", "dateRange": {"from": "2026-03-01", "to": "2026-03-07"}}`
- **THEN** the system MUST query `meldingen` objects matching the template filters and date range
- **AND** generate a PDF with the configured sections and branding
- **AND** return the PDF as a downloadable file or store it at a configured Nextcloud Files path

#### Scenario: Template with custom summary statistics
- **GIVEN** a template configured with summary section containing: total count, status breakdown (pie chart data), average handling time
- **WHEN** the report is generated
- **THEN** the summary section MUST display the aggregated statistics computed via the aggregation API
- **AND** the status breakdown MUST include both counts and percentages

### Requirement: The system MUST support scheduled report generation
Reports MUST be configurable to run on a cron schedule and be delivered via Nextcloud notifications, stored in Nextcloud Files, or sent via email through n8n workflow integration. Scheduled reports SHALL use Nextcloud `TimedJob` infrastructure. This builds on the `BackgroundJob` pattern already used by `SolrNightlyWarmupJob` and `ConfigurationCheckJob`.

#### Scenario: Schedule a weekly status report
- **GIVEN** a report template `Wekelijks Meldingen Rapport` with schedule: every Monday at 08:00
- **AND** delivery target: Nextcloud Files path `/Reports/Meldingen/`
- **WHEN** the Nextcloud cron runs on Monday at 08:00
- **THEN** a `ScheduledReportJob` (extending `TimedJob`) MUST generate the PDF report with current data
- **AND** store the file at `/Reports/Meldingen/meldingen_2026-03-16.pdf`
- **AND** send a Nextcloud notification to the report owner via `INotifier`

#### Scenario: Schedule a daily CSV export for data warehouse
- **GIVEN** a scheduled export configured for schema `meldingen`, format CSV, schedule daily at 02:00
- **AND** delivery: Nextcloud Files path `/DataWarehouse/meldingen/`
- **WHEN** the scheduled job triggers at 02:00
- **THEN** `ExportService::exportToCsv()` MUST generate the CSV with all current objects
- **AND** the filename MUST include the date: `meldingen_2026-03-19.csv`
- **AND** previous exports MUST be retained according to the configured retention period (default: 90 days)

#### Scenario: Scheduled report with email delivery via n8n
- **GIVEN** a scheduled report with delivery target `email` and recipients `management@gemeente.nl`
- **AND** an n8n workflow is configured for report email delivery
- **WHEN** the scheduled job triggers
- **THEN** the system MUST generate the report and trigger the n8n workflow with the report file as payload
- **AND** the n8n workflow SHALL handle SMTP delivery (OpenRegister does not manage SMTP directly)

#### Scenario: Report retention management
- **GIVEN** a scheduled report configured with retention period of 52 weeks
- **AND** 60 weekly reports have accumulated in Nextcloud Files
- **WHEN** the retention cleanup runs
- **THEN** reports older than 52 weeks MUST be deleted from Nextcloud Files
- **AND** the 52 most recent reports MUST be preserved
- **AND** a log entry MUST record how many reports were cleaned up

### Requirement: The system MUST support export in CSV, Excel, PDF, and ODS formats
Register objects MUST be exportable in CSV (already implemented via `ExportService::exportToCsv()`), Excel XLSX (already implemented via `ExportService::exportToExcel()`), PDF (new), and ODS (new) formats. The existing `ExportHandler` SHALL be extended with `exportToPdf()` and `exportToOds()` methods. PDF generation SHALL use a PHP library (Dompdf or TCPDF) or delegate to Docudesk's PDF capabilities if available.

#### Scenario: Export filtered results to CSV
- **GIVEN** 200 `meldingen` objects, 45 with status `afgehandeld`
- **AND** the user has applied filter `status=afgehandeld`
- **WHEN** the user exports to CSV format via `GET /api/objects/{register}/{schema}/export?format=csv&status=afgehandeld`
- **THEN** `ExportService::exportToCsv()` MUST generate a CSV with exactly 45 data rows
- **AND** the CSV MUST use UTF-8 encoding with BOM for Excel compatibility
- **AND** the filename MUST follow pattern `{register}_{schema}_{datetime}.csv` (as implemented in `ExportHandler::export()`)

#### Scenario: Export to Excel with relation name resolution
- **GIVEN** schema `taken` with property `toegewezen_aan` referencing `medewerkers` via UUID
- **WHEN** the user exports to XLSX format
- **THEN** the XLSX MUST include both the UUID column (`toegewezen_aan`) and the companion name column (`_toegewezen_aan`) as implemented in `ExportService::identifyNameCompanionColumns()`
- **AND** names MUST be resolved via the two-pass bulk approach in `ExportService::resolveUuidNameMap()`
- **AND** admin users MUST see `@self.*` metadata columns (per `ExportService::getHeaders()` admin check)

#### Scenario: Export to PDF as a formatted report
- **GIVEN** 25 `vergunningen` objects filtered by date range Q1 2026
- **WHEN** the user exports to PDF
- **THEN** the system MUST generate a formatted PDF document containing:
  - Report title, generation timestamp, and applied filters
  - Summary statistics: total count (25), status breakdown with counts and percentages
  - Paginated data table with key properties (respecting `PropertyRbacHandler` column visibility)
- **AND** the PDF MUST support A4 landscape orientation for wide tables
- **AND** page numbers MUST appear in the footer

#### Scenario: Export to ODS (Open Document Spreadsheet)
- **GIVEN** schema `meldingen` with 100 objects
- **WHEN** the user exports to ODS format
- **THEN** `PhpSpreadsheet\Writer\Ods` MUST generate the file with the same headers and data as the XLSX export
- **AND** the Content-Type MUST be `application/vnd.oasis.opendocument.spreadsheet`
- **AND** relation name resolution and RBAC filtering MUST be identical to the Excel export path

#### Scenario: Export entire register to multi-sheet Excel
- **GIVEN** register `gemeente-register` with schemas `personen` (500 objects) and `adressen` (800 objects)
- **WHEN** the user exports the register without specifying a schema
- **THEN** `ExportService::exportToExcel()` SHALL create one sheet per schema (per existing `populateSheet()`)
- **AND** each sheet title MUST be the schema slug
- **AND** CSV and ODS formats MUST reject multi-schema export with an appropriate error message

### Requirement: The system MUST provide chart data API endpoints for frontend visualization
Dedicated API endpoints MUST return data in a format optimized for chart rendering (labels + series arrays), extending the existing `MagicStatisticsHandler::getRegisterChartData()` and `MagicStatisticsHandler::getSchemaChartData()` methods with user-configurable chart queries. These endpoints power the built-in-dashboards spec and provide data for custom frontends.

#### Scenario: Bar chart data for status distribution
- **GIVEN** schema `meldingen` with objects across 5 status values
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/chart?type=bar&groupBy=status&metric=count`
- **THEN** the response MUST return `{"labels": ["nieuw", "in_behandeling", "wacht_op_info", "afgehandeld", "gesloten"], "series": [{"name": "count", "data": [30, 45, 12, 125, 8]}]}`
- **AND** the format MUST be directly consumable by Chart.js or Apache ECharts

#### Scenario: Time-series line chart data
- **GIVEN** schema `meldingen` with objects created over the past 6 months
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/chart?type=line&groupBy=@self.created&interval=month&metric=count`
- **THEN** the response MUST return monthly labels and a series with monthly counts
- **AND** gap-filled months (zero objects) MUST be included for continuous chart rendering

#### Scenario: Pie chart with percentage calculation
- **GIVEN** schema `meldingen` with 200 objects across 4 categories
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/chart?type=pie&groupBy=categorie&metric=count`
- **THEN** the response MUST include both absolute counts and percentages
- **AND** format: `{"labels": ["Openbare ruimte", "Verkeer", "Afval", "Overig"], "series": [80, 60, 40, 20], "percentages": [40.0, 30.0, 20.0, 10.0]}`

### Requirement: The system MUST support cross-register reporting
Reports and aggregation queries MUST be able to span multiple registers and schemas in a single query, enabling organisation-wide KPI dashboards. Cross-register queries SHALL execute individual aggregations per register-schema pair and merge results, leveraging the `MagicStatisticsHandler::getAllRegisterSchemaPairs()` discovery mechanism.

#### Scenario: Organisation-wide object count across all registers
- **GIVEN** 3 registers (`zaken`, `klanten`, `documenten`) with multiple schemas each
- **WHEN** the API receives `GET /api/reports/aggregate?metric=count` (no register/schema specified)
- **THEN** the response MUST return the total object count across all registers
- **AND** a breakdown by register: `{"total": 15000, "byRegister": [{"register": "zaken", "count": 8000}, {"register": "klanten", "count": 5000}, {"register": "documenten", "count": 2000}]}`
- **AND** the query MUST use `MagicStatisticsHandler::getStatistics()` for efficient cross-table counting

#### Scenario: Cross-register comparison report
- **GIVEN** registers `zaken` and `klanten`
- **WHEN** the API receives `GET /api/reports/aggregate?registers=zaken,klanten&groupBy=@self.created&interval=month&metric=count`
- **THEN** the response MUST return time-series data with one series per register
- **AND** format: `{"labels": ["2026-01", "2026-02", "2026-03"], "series": [{"name": "zaken", "data": [100, 120, 95]}, {"name": "klanten", "data": [50, 60, 55]}]}`

#### Scenario: Cross-register reporting respects RBAC boundaries
- **GIVEN** user `medewerker-1` has access to register `zaken` but NOT to register `vertrouwelijk`
- **WHEN** they request a cross-register aggregate
- **THEN** the response MUST only include data from `zaken`
- **AND** `vertrouwelijk` MUST be silently excluded (no error, no data leakage)

### Requirement: The system MUST support date range filtering and period-over-period comparison
All reporting endpoints MUST accept `from` and `to` date parameters for date range filtering. Period comparison reports MUST allow comparing two date ranges side-by-side (e.g., this month vs. last month, Q1 2026 vs. Q1 2025).

#### Scenario: Date range filter on aggregation
- **GIVEN** schema `meldingen` with objects spanning 2025 and 2026
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/aggregate?groupBy=status&metric=count&from=2026-01-01&to=2026-03-31`
- **THEN** only objects created between January 1 and March 31, 2026 MUST be included in the aggregation
- **AND** the `from` and `to` parameters MUST filter on the `@self.created` metadata field by default

#### Scenario: Period-over-period comparison
- **GIVEN** schema `meldingen` with data for 2025 and 2026
- **WHEN** the API receives `GET /api/reports/compare?register=zaken&schema=meldingen&metric=count&period1.from=2026-01-01&period1.to=2026-03-31&period2.from=2025-01-01&period2.to=2025-03-31`
- **THEN** the response MUST return side-by-side counts for both periods
- **AND** include a calculated change: `{"period1": {"label": "Q1 2026", "count": 450}, "period2": {"label": "Q1 2025", "count": 380}, "change": {"absolute": 70, "percentage": 18.42}}`

#### Scenario: Month-over-month trend
- **GIVEN** schema `meldingen` with 12 months of data
- **WHEN** the API receives a trend request for the last 6 months with monthly interval
- **THEN** each month MUST include the count, the previous month's count, and the percentage change
- **AND** the first month in the range MUST have `previousCount: null` and `change: null`

### Requirement: The system MUST support custom calculated metrics
Report templates MUST support computed fields that derive values from existing properties using expressions (arithmetic, conditional, date arithmetic). Calculated metrics enable KPIs like "gemiddelde doorlooptijd" (average handling time) or "percentage op tijd afgehandeld" without requiring schema changes.

#### Scenario: Average handling time calculation
- **GIVEN** schema `meldingen` with properties `aangemaakt` (date-time) and `afgehandeld_op` (date-time)
- **WHEN** a report template defines a calculated metric: `{"name": "doorlooptijd_dagen", "expression": "DATEDIFF(@self.updated, @self.created, 'days')", "filter": {"status": "afgehandeld"}}`
- **THEN** the report MUST compute the average number of days between creation and last update for completed meldingen
- **AND** the result MUST be included in the summary section as a single metric value

#### Scenario: Percentage calculation
- **GIVEN** schema `meldingen` with 200 total objects, 125 with status `afgehandeld`
- **WHEN** a calculated metric is defined as: `{"name": "afhandel_percentage", "expression": "COUNT(status='afgehandeld') / COUNT(*) * 100"}`
- **THEN** the metric MUST evaluate to `62.5`
- **AND** the result MUST be formatted with one decimal place

#### Scenario: Conditional KPI with threshold
- **GIVEN** a calculated metric "SLA compliance" defined as: percentage of meldingen resolved within 5 business days
- **AND** the template defines thresholds: green >= 90%, yellow >= 75%, red < 75%
- **WHEN** the report generates and finds 85% compliance
- **THEN** the metric MUST display `85.0%` with a `yellow` indicator
- **AND** the threshold metadata MUST be included in the response for frontend rendering

### Requirement: Reports and exports MUST enforce RBAC permissions
All reporting operations MUST enforce the same RBAC rules as the standard object retrieval pipeline. Users MUST only see aggregated data and exported records for objects and properties they are authorized to access. This extends the existing `PermissionHandler`, `MagicRbacHandler`, and `PropertyRbacHandler` enforcement already applied in `ExportService::fetchObjectsForExport()`.

#### Scenario: RBAC-filtered aggregation
- **GIVEN** user `medewerker-1` has read access to schema `meldingen` but NOT to schema `vertrouwelijk`
- **WHEN** `medewerker-1` requests a cross-schema aggregate on register `zaken`
- **THEN** the aggregation MUST include only `meldingen` objects
- **AND** `vertrouwelijk` objects MUST be excluded from all counts, sums, and averages
- **AND** no error MUST be returned (transparent RBAC filtering)

#### Scenario: Property-level RBAC on report columns
- **GIVEN** schema `personen` has property `bsn` with authorization restricting read access to group `privacy-officers`
- **AND** user `medewerker-1` is NOT in group `privacy-officers`
- **WHEN** `medewerker-1` generates a report from template
- **THEN** the `bsn` column MUST be excluded from the data table section
- **AND** the companion `_bsn` column MUST also be excluded
- **AND** any aggregation on the `bsn` field MUST be rejected with HTTP 403

#### Scenario: Admin-only metadata in reports
- **GIVEN** user `admin` is in the `admin` group
- **WHEN** admin generates a detailed report
- **THEN** `@self.*` metadata columns (created, updated, owner, organisation, locked, deleted) MUST be available
- **AND** non-admin users MUST NOT see these columns in reports (per `ExportService::getHeaders()` admin check)

### Requirement: The system MUST support report caching for performance
Aggregation query results and generated report files MUST be cached to avoid redundant computation. Cache invalidation MUST occur when objects in the aggregated register-schema pair are created, updated, or deleted. The caching layer SHALL use the same APCu/Redis infrastructure already used by `FacetCacheHandler` and `SchemaCacheHandler`.

#### Scenario: Cache aggregation query results
- **GIVEN** an aggregation query on schema `meldingen` with 50,000 objects takes 2 seconds
- **WHEN** the same query is repeated within the cache TTL (default: 5 minutes)
- **THEN** the cached result MUST be returned in under 50ms
- **AND** the response MUST include a `X-Cache: HIT` header

#### Scenario: Invalidate cache on data change
- **GIVEN** a cached aggregation result for schema `meldingen`
- **WHEN** a new `meldingen` object is created via the objects API
- **THEN** the cache key for `meldingen` aggregations MUST be invalidated
- **AND** the next aggregation query MUST execute fresh and return updated counts

#### Scenario: Cache scheduled report output
- **GIVEN** a scheduled report that generates a 5MB PDF every Monday
- **WHEN** 3 users download the same report on Monday afternoon
- **THEN** the PDF MUST be generated only once (during the scheduled job)
- **AND** subsequent downloads MUST serve the stored file from Nextcloud Files

### Requirement: The system MUST support WOO transparency reporting
The system MUST generate reports that satisfy Dutch WOO (Wet Open Overheid) transparency requirements. WOO reports MUST include: document categories (besluitenlijsten, vergaderstukken, onderzoeksrapporten), publication status per category, compliance metrics (percentage published within statutory deadlines), and export in a format suitable for submission to the WOO platform (PLOOI/PlatformOpenOverheidsinformatie).

#### Scenario: WOO compliance dashboard data
- **GIVEN** register `woo-publicaties` with schema `documenten` containing properties: categorie, publicatiedatum, wettelijke_deadline, publicatiestatus
- **WHEN** the API receives `GET /api/reports/woo-compliance?register=woo-publicaties&year=2025`
- **THEN** the response MUST include per-category counts: total documents, published on time, published late, not yet published
- **AND** overall compliance percentage: `(published_on_time / total) * 100`
- **AND** the response format MUST be suitable for rendering a WOO compliance dashboard

#### Scenario: WOO annual report generation
- **GIVEN** WOO publication data for the year 2025
- **WHEN** an administrator generates the annual WOO transparency report
- **THEN** the system MUST produce a PDF report containing:
  - Total documents per information category (11 WOO categories)
  - Publication timeliness statistics
  - Trend comparison with previous year
  - List of documents with publication status
- **AND** the report MUST be suitable for inclusion in the organisation's jaarverslag

#### Scenario: WOO data export for PLOOI submission
- **GIVEN** 200 documents marked for WOO publication
- **WHEN** the administrator exports for PLOOI submission
- **THEN** the system MUST generate an export package containing document metadata in the PLOOI-compatible format
- **AND** each document record MUST include: identifier, title, category, publication date, and document reference

### Requirement: The system MUST support audit report generation
The system MUST generate audit reports from the existing `AuditTrailMapper` data, showing who accessed, created, modified, or deleted which objects and when. Audit reports extend the existing `DashboardService::getAuditTrailStatistics()` and `DashboardService::getAuditTrailActionChartData()` with downloadable report output.

#### Scenario: Generate audit report for a date range
- **GIVEN** register `zaken` with audit trail data for March 2026
- **WHEN** an administrator requests `GET /api/reports/audit?register=zaken&from=2026-03-01&to=2026-03-31&format=xlsx`
- **THEN** the Excel file MUST contain one row per audit trail entry with columns: timestamp, action (create/read/update/delete), object UUID, object name, user ID, IP address, changes summary
- **AND** the report MUST be sorted by timestamp descending
- **AND** only administrators MUST be able to generate audit reports

#### Scenario: Audit report with action distribution chart
- **GIVEN** audit data showing 500 creates, 1200 reads, 800 updates, 50 deletes in the period
- **WHEN** the audit report includes a summary section
- **THEN** the summary MUST include action distribution as already computed by `DashboardService::getAuditTrailActionDistribution()`
- **AND** the most active objects list as computed by `DashboardService::getMostActiveObjects()`

#### Scenario: User activity audit report
- **GIVEN** an administrator needs a report of all actions by user `medewerker-1`
- **WHEN** they request `GET /api/reports/audit?userId=medewerker-1&from=2026-03-01&to=2026-03-31`
- **THEN** the report MUST contain only audit trail entries for `medewerker-1`
- **AND** the summary MUST include total actions by type and most-accessed objects

### Requirement: The system MUST enforce multi-tenant reporting isolation
In multi-tenant deployments, reports MUST only include objects belonging to the requesting user's organisation. Cross-tenant data MUST never appear in reports, aggregations, or exports. This extends the existing multi-tenancy enforcement in `ExportService::fetchObjectsForExport()` (which passes `_multitenancy: true` to `ObjectService::searchObjects()`).

#### Scenario: Tenant-isolated aggregation
- **GIVEN** a multi-tenant deployment with organisations `gemeente-utrecht` and `gemeente-amsterdam`
- **AND** both organisations have `meldingen` objects in the same register
- **WHEN** a user from `gemeente-utrecht` requests an aggregation on `meldingen`
- **THEN** the aggregation MUST include only `gemeente-utrecht` objects
- **AND** `gemeente-amsterdam` objects MUST be completely invisible
- **AND** the `_multitenancy` flag from `ExportService::fetchObjectsForExport()` MUST be applied

#### Scenario: Scheduled report respects tenant context
- **GIVEN** a scheduled report owned by a user from `gemeente-utrecht`
- **WHEN** the `ScheduledReportJob` runs in the Nextcloud cron context
- **THEN** the job MUST execute with the report owner's tenant context
- **AND** the generated report MUST contain only `gemeente-utrecht` data

#### Scenario: Admin can request cross-tenant report
- **GIVEN** a system administrator (instance admin, not tenant admin)
- **WHEN** they request an aggregation with parameter `_multi=false` (disable multi-tenancy filter)
- **THEN** the response MUST include data from all tenants
- **AND** this capability MUST be restricted to instance administrators only

### Requirement: The system MUST provide an OData v4 endpoint for external BI tool integration
An OData v4 compatible endpoint MUST be available for integration with Power BI, Tableau, QlikView, and other BI tools that support OData data sources. The endpoint SHALL translate OData query parameters (`$filter`, `$select`, `$orderby`, `$top`, `$skip`, `$count`) to OpenRegister's internal query format using `MagicSearchHandler` as the backend.

#### Scenario: Connect Power BI to OData endpoint
- **GIVEN** the OData endpoint is configured for register `zaken` with schema `meldingen`
- **WHEN** Power BI connects to `GET /api/odata/{register}/{schema}` with OData query parameters
- **THEN** the endpoint MUST return an OData v4 JSON response with `@odata.context`, `@odata.count`, and `value` array
- **AND** the endpoint MUST support `$filter`, `$select`, `$orderby`, `$top`, `$skip`, and `$count` parameters
- **AND** the OData service document at `GET /api/odata/` MUST list all available register-schema pairs as entity sets

#### Scenario: OData authentication and RBAC
- **GIVEN** an OData endpoint request with Basic Auth credentials
- **WHEN** the credentials map to user `medewerker-1`
- **THEN** the endpoint MUST enforce the same RBAC rules as the REST API
- **AND** schemas the user cannot access MUST NOT appear in the service document
- **AND** property-level RBAC MUST filter the `$select` results

#### Scenario: OData pagination for large datasets
- **GIVEN** schema `meldingen` contains 50,000 objects
- **WHEN** Power BI requests the first page without `$top`
- **THEN** the endpoint MUST return a default page size of 100 objects
- **AND** include `@odata.nextLink` for the next page
- **AND** Power BI MUST be able to follow `@odata.nextLink` to retrieve all pages

#### Scenario: OData filter translation
- **GIVEN** Power BI sends `$filter=status eq 'nieuw' and created gt 2026-01-01`
- **WHEN** the OData controller parses the filter
- **THEN** it MUST translate to the equivalent OpenRegister query: `{"status": "nieuw", "@self.created>": "2026-01-01"}`
- **AND** execute the query via `ObjectService::searchObjects()` with RBAC enforcement

### Requirement: The system MUST support API access for external BI tools beyond OData
For BI tools that do not support OData (or prefer REST/JDBC), the existing REST API MUST support query parameters that enable efficient data extraction: cursor-based pagination for full data sync, `_fields` parameter for column selection, `_format` parameter for response format (JSON, CSV, JSONL), and `If-Modified-Since` headers for incremental sync.

#### Scenario: Full data sync with cursor pagination
- **GIVEN** an external ETL tool needs to sync all 50,000 `meldingen` objects
- **WHEN** it sends `GET /api/objects/{register}/{schema}?_limit=1000&_cursor=<last_uuid>` repeatedly
- **THEN** each page MUST return 1000 objects sorted by a stable cursor (UUID or internal ID)
- **AND** the response MUST include `_nextCursor` for the next page
- **AND** the full sync MUST complete without missing or duplicating objects

#### Scenario: Incremental sync with If-Modified-Since
- **GIVEN** an ETL tool last synced at 2026-03-18T00:00:00Z
- **WHEN** it sends `GET /api/objects/{register}/{schema}?_limit=1000` with header `If-Modified-Since: 2026-03-18T00:00:00Z`
- **THEN** the response MUST include only objects created or updated after the specified timestamp
- **AND** deleted objects MUST be indicated with `_includeDeleted=true` parameter support

#### Scenario: JSONL format for streaming to data pipelines
- **GIVEN** a data pipeline tool requests `GET /api/objects/{register}/{schema}?_format=jsonl&_limit=999999`
- **WHEN** the response is generated
- **THEN** each line MUST be a complete JSON object (JSON Lines format per RFC 7464)
- **AND** the Content-Type MUST be `application/x-ndjson`
- **AND** the response MUST stream without buffering the full dataset in memory

## Current Implementation Status
- **Implemented -- CSV export**: `ExportHandler` (`lib/Service/Object/ExportHandler.php`) supports CSV export via `ExportService::exportToCsv()` with RBAC-aware header generation and multi-tenancy support.
- **Implemented -- Excel (XLSX) export**: `ExportHandler` supports Excel export via `ExportService::exportToExcel()` using PhpSpreadsheet `Xlsx` writer, with two-pass UUID-to-name resolution via `resolveUuidNameMap()`, companion name columns via `identifyNameCompanionColumns()`, and admin-only `@self.*` metadata columns.
- **Implemented -- CSV/Excel import**: `ExportHandler::import()` handles CSV and Excel file import, delegating to `ImportService::importFromCsv()` and `ImportService::importFromExcel()`.
- **Implemented -- RBAC on exports**: Export pipeline passes through `ObjectService::searchObjects()` with `_rbac: true` and property-level filtering via `PropertyRbacHandler::canReadProperty()` in header generation.
- **Implemented -- Basic statistics**: `MagicStatisticsHandler` provides `getStatistics()` (total/deleted/locked counts), `getRegisterChartData()` and `getSchemaChartData()` (labels + series for chart rendering), and `getStatisticsGroupedBySchema()` for batch statistics.
- **Implemented -- Dashboard aggregation**: `DashboardService` provides `getRegistersWithSchemas()` with per-register/schema statistics, `getAuditTrailStatistics()`, `getAuditTrailActionDistribution()`, `getMostActiveObjects()`, and chart data endpoints for audit trail actions, objects by register, objects by schema, and objects by size.
- **Implemented -- Operational metrics**: `MetricsService` records and aggregates operational metrics (files processed, embeddings, search latency, storage growth) with `getDashboardMetrics()` for a metrics overview.
- **Implemented -- Faceting infrastructure**: `FacetHandler`, `MagicFacetHandler`, `HyperFacetHandler`, `MariaDbFacetHandler`, `OptimizedFacetHandler`, and `SolrFacetProcessor` provide comprehensive faceting with caching -- this is the foundation for aggregation queries.
- **Implemented -- Configuration export**: `Configuration/ExportHandler` handles register/schema configuration export in OpenAPI 3.0.0 format (separate from data export).
- **Not implemented -- PDF export**: No PDF generation service or library. No report formatting with titles, summary statistics, or paginated tables.
- **Not implemented -- ODS export**: No `PhpSpreadsheet\Writer\Ods` integration.
- **Not implemented -- General-purpose aggregation API**: No `/aggregate` endpoint with `groupBy`, `metric`, `sum`, `avg`, `min`, `max`, or time-series bucketing. The faceting infrastructure provides categorical counts but not numeric aggregations.
- **Not implemented -- OData v4 endpoint**: No OData protocol support. No `$filter`, `$select`, `$orderby` OData query translation.
- **Not implemented -- Scheduled report generation**: No `ScheduledReportJob` or cron-based report generation. No report delivery via Nextcloud Files or notifications.
- **Not implemented -- Report templates**: No configurable report template system.
- **Not implemented -- Period-over-period comparison**: No comparison API endpoint.
- **Not implemented -- Custom calculated metrics**: No expression engine for computed fields.
- **Not implemented -- WOO transparency reporting**: No WOO-specific report endpoints or PLOOI export format.
- **Not implemented -- Report caching**: Aggregation results are not cached (facet caching exists but is separate).
- **Not implemented -- Cursor-based pagination**: Current pagination uses offset/limit, not cursor-based.

## Standards & References
- **OData v4 specification** (https://www.odata.org/documentation/) -- for BI tool integration protocol
- **ISO 32000 (PDF specification)** -- for report generation output format
- **ECMA-376 / ISO/IEC 29500 (Office Open XML)** -- for XLSX format
- **ISO/IEC 26300 (Open Document Format)** -- for ODS format
- **RFC 4180** -- for CSV format
- **RFC 7464** -- for JSON Lines / NDJSON streaming format
- **PhpSpreadsheet** (https://phpspreadsheet.readthedocs.io/) -- already used for XLSX export
- **Dompdf or TCPDF** -- candidate PHP libraries for PDF generation
- **BIO (Baseline Informatiebeveiliging Overheid)** -- data export security and audit logging requirements
- **WOO (Wet Open Overheid)** -- Dutch transparency law requiring publication of government documents in 11 categories
- **PLOOI** -- Platform Open Overheidsinformatie, the national publication platform for WOO documents
- **Common Ground** -- principles for API-based data access in Dutch government
- **Prometheus exposition format** -- for metrics endpoint compatibility (see production-observability spec)
- **WCAG 2.1 AA** -- accessibility for generated PDF reports

## Cross-References
- **built-in-dashboards** -- Dashboard widgets consume the chart data API and aggregation endpoints defined in this spec. The built-in-dashboards spec handles visual rendering; this spec provides the data layer.
- **production-observability** -- Operational metrics from `MetricsService` (search latency, embedding stats, file processing) are complementary to the business-level reporting in this spec. Prometheus metrics endpoint is defined there.
- **data-import-export** -- Shares `ExportService`, `ExportHandler`, `ImportService` infrastructure. The data-import-export spec covers the import/export pipeline mechanics; this spec covers the reporting, aggregation, and BI integration layer built on top.
- **mock-registers** -- Mock register data (seed data) can be used to validate report templates and aggregation queries during development.

## Specificity Assessment
- **Well-specified**: Aggregation API patterns (count/sum/avg/groupBy/interval), export format support (extending existing CSV/Excel with PDF/ODS), RBAC enforcement (leveraging existing PropertyRbacHandler/MagicRbacHandler), multi-tenancy isolation, and OData endpoint requirements.
- **Implementation-anchored**: Requirements reference specific existing classes (`MagicStatisticsHandler`, `ExportService`, `DashboardService`, `FacetHandler`, `MetricsService`) and their methods, providing clear extension points.
- **Remaining decisions**:
  - PDF library choice: Dompdf (HTML-to-PDF, easier templating) vs. TCPDF (lower-level, more control) vs. Docudesk delegation (if available)
  - Aggregation query execution: SQL-level GROUP BY via MagicMapper extension vs. application-level aggregation of search results (former preferred for performance)
  - OData library: Use an existing PHP OData library (e.g., POData) or custom OData controller translating to internal query format
  - Report template storage: dedicated schema vs. app config vs. Nextcloud Files
  - Scheduled report scheduler: TimedJob (hourly check) vs. cron expression evaluation
  - WOO category mapping: hardcoded 11 WOO categories vs. configurable category list

## Nextcloud Integration Analysis

**Status**: Partially implemented. CSV and Excel export work via `ExportHandler` and `ExportService` (PhpSpreadsheet) with comprehensive RBAC enforcement. Dashboard statistics and chart data are available via `DashboardService` and `MagicStatisticsHandler`. Faceting infrastructure provides categorical counts. PDF export, aggregation API, OData endpoints, scheduled reports, WOO reporting, and report templates are not built.

**Nextcloud Core Interfaces**:
- `TimedJob` (`OCP\BackgroundJob\TimedJob`): Use for scheduled report generation. A `ScheduledReportJob` runs hourly, checks for due reports based on cron expressions, generates the output, and delivers it. Already proven with `SolrNightlyWarmupJob` and `ConfigurationCheckJob`.
- `QueuedJob` (`OCP\BackgroundJob\QueuedJob`): Use for async report generation when triggered by user request. When a user requests a large PDF report or complex aggregation, enqueue a `ReportGenerationJob` that generates the file and stores it in Nextcloud Files, avoiding HTTP timeout issues.
- `IDashboardWidget` / `IAPIWidgetV2` (`OCP\Dashboard`): Register report summary widgets on the Nextcloud home dashboard. Widgets display key metrics (total cases, open cases, monthly trends) fetched from the aggregation API.
- `IMailer` (`OCP\Mail\IMailer`): Available for direct email delivery of scheduled reports, but the preferred approach is n8n workflow integration for SMTP delivery (avoids SMTP configuration in Nextcloud).
- `INotifier` (`OCP\Notification\INotifier`): Notify users when scheduled or async reports are ready for download.
- `ICacheFactory` (`OCP\ICacheFactory`): Use for aggregation result caching. The same APCu/Redis factory used by `FacetCacheHandler` provides distributed cache for report data.
- `IUserSession` / `PermissionHandler` / `MagicRbacHandler` / `PropertyRbacHandler`: Enforce RBAC on all export and reporting operations. Already integrated in the export pipeline.

**Implementation Approach**:
- For the aggregation API, extend `MagicMapper` with a new `aggregate()` method that builds SQL `GROUP BY` queries with `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` on magic table columns. For time-series, use SQL date functions (`DATE_FORMAT` on MySQL, `TO_CHAR` on PostgreSQL) for interval bucketing. When Solr/Elasticsearch is configured, delegate to their native aggregation/facet APIs via `SearchBackendInterface`.
- For PDF export, integrate Dompdf into `ExportService`. Create HTML report templates that use NL Design System CSS variables for government-branded output. Alternatively, if Docudesk provides PDF generation capabilities, delegate to it.
- For OData v4, create an `ODataController` that translates OData query parameters to OpenRegister's internal query format. The service document auto-generates entity sets from register/schema definitions. Use `MagicSearchHandler` as the query backend.
- For scheduled reports, create a `ScheduledReportEntity` storing report definitions (template reference, schedule cron expression, delivery target). A `ScheduledReportJob` (extending `TimedJob`) runs hourly, checks for due reports, generates them, and delivers via Nextcloud Files or notifications.
- For WOO reporting, create WOO-specific aggregation logic that maps schema properties to the 11 WOO document categories and calculates compliance against statutory publication deadlines.

**Dependencies on Existing OpenRegister Features**:
- `ExportHandler` / `ExportService` -- existing CSV/Excel export pipeline, to be extended with PDF and ODS.
- `ObjectService::searchObjects()` -- data retrieval with filtering, RBAC, and multi-tenancy for report data pipelines.
- `MagicStatisticsHandler` -- existing statistics (counts, chart data), foundation for the aggregation API.
- `MagicFacetHandler` / `FacetHandler` -- existing faceting infrastructure with caching, to be leveraged for categorical aggregations.
- `DashboardService` / `DashboardController` -- existing dashboard data endpoints, to be extended with report-specific endpoints.
- `AuditTrailMapper` -- audit trail data for audit report generation.
- `PermissionHandler` / `MagicRbacHandler` / `PropertyRbacHandler` -- RBAC enforcement across all reporting operations.
- `MetricsService` -- operational metrics, complementary to business reporting.
- `FacetCacheHandler` / `SchemaCacheHandler` -- caching patterns to replicate for report caching.
