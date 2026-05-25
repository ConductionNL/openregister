# Rapportage en BI Export (retrofit delta)

## ADDED Requirements

### Requirement: Server-side report rendering MUST resolve widget data then dispatch to a pluggable format writer

The system MUST render a dashboard object into downloadable bytes via a
resolve-then-dispatch pipeline: `ReportRenderService::render()` MUST validate the
requested format against its supported set (`csv`, `xlsx`, `ods`, `html`, `pdf`),
resolve each widget's data, build a slugified `{title-slug}_{Y-m-d_His}.{ext}`
filename, and dispatch to the writer that matches the format —
`HtmlReportWriter` for `html`, `PdfReportWriter` for `pdf`, and
`SpreadsheetReportWriter` for `csv`/`xlsx`/`ods` — returning a
`{mime, filename, bytes}` envelope. Widget data resolution MUST be per-widget
fault-tolerant: a widget whose data source fails to resolve MUST yield `null`
data (logged at warning level) and MUST NOT abort the whole render.

#### Scenario: Unsupported format is rejected before any work
- **GIVEN** a dashboard object with a `widgets` array
- **WHEN** `ReportRenderService::render($dashboard, 'docx')` is called
- **THEN** the service MUST throw `InvalidArgumentException` naming the supported formats
- **AND** no writer MUST be invoked

#### Scenario: Aggregation widget resolves with RBAC bypassed at render time
- **GIVEN** a widget whose `dataSource.mode` is `aggregation` with `register`, `schema`, and `aggregation` set
- **WHEN** the widget is resolved during `render()`
- **THEN** `AggregationRunner::run()` MUST be called with `bypassRbac: true` (the dashboard's own RBAC gate already filtered the viewer at load time)
- **AND** the resolved data MUST be paired with its widget descriptor for the writer

#### Scenario: A widget that fails to resolve does not abort the report
- **GIVEN** a dashboard with three widgets where the second widget's data source throws
- **WHEN** `render()` resolves the widgets
- **THEN** the second widget's resolved data MUST be `null` and a warning MUST be logged
- **AND** the first and third widgets MUST still resolve
- **AND** the rendered output MUST include all three widgets (the failed one showing a no-data placeholder)

#### Scenario: Filename is slugified from the dashboard title
- **GIVEN** a dashboard titled `Wekelijks Meldingen Rapport`
- **WHEN** it is rendered to `xlsx`
- **THEN** the returned `filename` MUST match `wekelijks-meldingen-rapport_<timestamp>.xlsx`
- **AND** the returned `mime` MUST be the XLSX spreadsheet MIME type

#### Scenario: Spreadsheet writer produces one sheet per widget plus a cover sheet
- **GIVEN** a dashboard with two widgets rendered to `xlsx`
- **WHEN** `SpreadsheetReportWriter::write()` runs
- **THEN** the workbook MUST contain an `Overview` cover sheet summarising the dashboard and listing each widget with its source and headline
- **AND** one additional sheet per widget, each sheet title sanitised to ≤ 31 chars with `: \ / ? * [ ]` replaced
- **AND** CSV output MUST be written with a UTF-8 BOM and concatenate every sheet

### Requirement: PDF report rendering MUST keep the Dompdf renderer hermetic

`PdfReportWriter::write()` MUST render through the `HtmlReportWriter` output with
Dompdf locked down: it MUST strip `<link rel="stylesheet">` tags and
`@font-face` declarations from the HTML before handing it to Dompdf, MUST set
`isRemoteEnabled=false` and `isPhpEnabled=false`, and MUST assert those two flags
did not drift (throwing `RuntimeException` if either is enabled) before
rendering. This is the primary mitigation for Dompdf's SSRF / file-disclosure
CVE class and MUST NOT be relaxed.

#### Scenario: Smuggled stylesheet reference is stripped before rendering
- **GIVEN** a dashboard whose rendered HTML contains a `<link rel="stylesheet" href="file:///etc/passwd">`
- **WHEN** `PdfReportWriter::write()` runs
- **THEN** the `<link>` tag MUST be removed before the HTML reaches Dompdf
- **AND** `@font-face { ... }` declarations MUST likewise be removed

#### Scenario: Sandbox-flag drift aborts the render
- **GIVEN** a future refactor that enables Dompdf remote fetching or PHP execution
- **WHEN** `PdfReportWriter::write()` checks the configured options
- **THEN** the writer MUST throw `RuntimeException` indicating the sandbox configuration drifted
- **AND** no PDF bytes MUST be produced

#### Scenario: PDF renders A4 portrait from the HTML pipeline
- **GIVEN** a resolved dashboard
- **WHEN** the PDF is rendered
- **THEN** Dompdf MUST load the (sanitised) HTML as UTF-8 and render at A4 portrait
- **AND** the returned bytes MUST be a non-empty PDF document
