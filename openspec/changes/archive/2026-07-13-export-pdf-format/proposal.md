# PDF Export Format

## Problem
`ExportService` (the shared export leaf every Conduction app builds on — ADR-Leaf-First) supports CSV, Excel (XLSX), and JSON output, but no PDF. Cross-fleet research shows PDF export exists nowhere in the fleet, while municipalities routinely need a print/share-ready PDF for a register or schema's object list (management overviews, WOO/verantwoording hand-outs, meeting hand-outs). Today the only way to get a PDF list is exporting to Excel/CSV and converting it manually outside the app.

This is a narrower slice of the existing `rapportage-bi-export` proposal (status: proposed, unimplemented), which envisions a much larger branded/templated reporting engine with aggregation, scheduled jobs, and OData. This change does **not** implement that — it only adds a plain tabular PDF renderer to the existing CSV/Excel/JSON export pipeline, mirroring what those formats already do. `rapportage-bi-export`'s "system MUST support export in CSV, Excel, PDF, and ODS formats" requirement is partially satisfied by this change (the PDF leg) but the report-template/branding/aggregation/OData requirements in that proposal remain open.

## Proposed Solution
Add `ExportService::exportToPdf()`, reusing the exact same column/data-extraction pipeline that `exportToExcel()`/`exportToCsv()` already use (`getHeaders()`, `fetchObjectsForExport()`, `identifyNameCompanionColumns()`, `resolveUuidNameMap()`, `getObjectValue()`, `resolveUuidsToNames()`). Only the renderer is new: object rows are written into an HTML table (escaped, with long cell values truncated) instead of spreadsheet cells, then rendered to PDF bytes via **Dompdf** — already a first-class OpenRegister dependency (`dompdf/dompdf: ^3.1`, used today by `PdfReportWriter` for dashboard PDF reports) — using the exact sandboxing pattern `PdfReportWriter` already established (`isRemoteEnabled=false`, `isPhpEnabled=false`, no new transitive dependency risk since nothing new is added to `composer.json`).

Output is A4 landscape with a header (register/schema title, export timestamp, object count), a striped data table, and page numbers via Dompdf's safe `Canvas::page_text()` placeholder substitution (`{PAGE_NUM}`/`{PAGE_COUNT}`) — not the PHP-script-in-HTML mechanism, which stays disabled for security.

Because PDF rendering is memory- and CPU-heavy compared to streaming CSV/XLSX writers, exports above `ExportService::MAX_PDF_EXPORT_ROWS` (5000, a class constant) are rejected with a dedicated `ExportTooLargeException` that controllers map to HTTP 400 with a structured error body — never a 500 or a silently-truncated file.

`pdf` is wired into the two controller endpoints that already accept `format=csv|excel`:
- `ObjectsController::export()` (`GET /api/objects/{register}/{schema}/export`) — object list export.
- `RegistersController::export()` (`GET /api/registers/{id}/export`) — register export (single schema, like the existing `csv` branch, since a single PDF document with a coherent table needs one schema's columns).

`RegistersController::importTemplate()` is deliberately **excluded**: it downloads a *blank, re-importable* header-only template for filling in and re-uploading. PDF is not a round-trippable import format, so adding it there would be a dead end, not a feature.

## Alternatives Considered
- **mpdf**: not already vendored; would add a new dependency and a new attack surface for zero benefit over the already-integrated dompdf.
- **tecnickcom/tcpdf**: same — not vendored, lower-level API (manual cell/row layout) with no templating benefit for a simple striped table.
- **Reusing PdfReportWriter directly**: it's built around `HtmlReportWriter`'s dashboard-widget payload shape (branding, chart sections), not a flat tabular row/column export — wrong abstraction for this feature. Instead this change follows its *security pattern* (sandboxed Dompdf::Options, hermetic HTML) without taking on its dependency on dashboard resolution.

See `design.md` for the full shadow-risk assessment.
