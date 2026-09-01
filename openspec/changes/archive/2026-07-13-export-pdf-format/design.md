# Design Notes — PDF Export Format

## Library choice: dompdf/dompdf (no new composer dependency)

`composer.json` already requires `dompdf/dompdf: ^3.1` (resolved `v3.1.5` in `composer.lock`), and it is already in active use in `lib/Service/Reporting/PdfReportWriter.php` for dashboard-report PDFs. This is decisive: adding a **second** PDF library (mpdf or tcpdf) purely for this feature would duplicate an already-solved, already-audited dependency for no functional gain, and would be the exact anti-pattern the shadow-dependency warning in this task exists to prevent (an extra vendored HTML/CSS/font stack with its own transitive surface, sitting next to one that already does the job).

Decision: **reuse dompdf/dompdf**, add zero new lines to `composer.json`/`composer.lock`.

## Shadow-dependency risk assessment

Ran `composer show dompdf/dompdf --tree` inside the worktree (after `rsync`-ing canonical `vendor/` + `composer install`, no lock changes):

```
dompdf/dompdf v3.1.5
|-- dompdf/php-font-lib ^1.0.0
|   `-- ext-mbstring, ext-iconv, php
|-- dompdf/php-svg-lib ^1.0.0
|   |-- ext-mbstring, ext-iconv, php
|   `-- sabberworm/php-css-parser ^8.4 || ^9.0
|       `-- thecodingmachine/safe ^1.3 || ^2.5 || ^3.4
|-- ext-dom, ext-mbstring
|-- masterminds/html5 ^2.0
`-- php ^7.1 || ^8.0
```

Checked every transitive package against the categories the task flags as dangerous (things that can shadow Nextcloud core or OCP polyfills instance-wide, per the `sabre/xml` CalDAV incident):

- **`sabre/*`** — none present. dompdf's dependency tree has zero overlap with `sabre/dav`/`sabre/xml`/`sabre/vobject`, which is what actually broke CalDAV previously.
- **`symfony/*`** — none present. dompdf does not depend on any Symfony component; OpenRegister's own `symfony/http-foundation`, `symfony/console`, etc. (pinned to `^6.4`) are untouched.
- **`psr/log`** — not a dompdf dependency at all (dompdf does no PSR-3 logging).
- **`dompdf/php-font-lib`, `dompdf/php-svg-lib`, `masterminds/html5`, `sabberworm/php-css-parser`, `thecodingmachine/safe`** — all dompdf-namespace-owned or dompdf-exclusive utility packages (font parsing, SVG parsing, HTML5 DOM parsing, CSS parsing, PHP-safe wrappers). None of these classes collide with any NC core or OCP class name, and none are used anywhere else in `vendor/` — confirmed no duplicate/conflicting package already vendored under a different version (searched `composer.lock` for `masterminds/html5`, `sabberworm/php-css-parser`, `thecodingmachine/safe` — each appears exactly once, already resolved for this exact dompdf version, since dompdf was already a dependency before this change).

**Conclusion: no shadow risk.** Since this change adds no new `require` entry, `composer.lock`'s dependency graph is byte-for-byte unchanged by this PR except for the new application code that consumes the already-locked `dompdf/dompdf` package. This is the safest possible outcome relative to the CalDAV precedent: zero new autoload surface is introduced into the Nextcloud instance.

## Security posture (carried over from `PdfReportWriter`)

`PdfReportWriter::write()` already established the hardening pattern for Dompdf inside this codebase, in direct response to Dompdf's SSRF/file-disclosure CVE history (CVE-2022-41343, CVE-2023-23924):

- `Options::set('isRemoteEnabled', false)` — no network/file fetches from rendered HTML.
- `Options::set('isPhpEnabled', false)` — no PHP execution via `<script type="text/php">` blocks.
- A runtime assertion that both flags are still `false` right before `new Dompdf($options)`, so a future refactor can't silently flip them.

`ExportService::exportToPdf()` reuses this exact `Options` configuration. Because the HTML fed to Dompdf here is built entirely from OpenRegister's own escaped cell values (via `htmlspecialchars()`), not from a user-supplied HTML/dashboard payload like `PdfReportWriter` consumes, there is no need for `PdfReportWriter`'s additional `<link rel="stylesheet">` / `@font-face` stripping step — but the same `isRemoteEnabled=false`/`isPhpEnabled=false` sandboxing applies unconditionally.

**Page numbers** use `Dompdf\Canvas::page_text()` with the literal placeholders `{PAGE_NUM}` / `{PAGE_COUNT}`, which Dompdf substitutes internally at render time (`src/Adapter/CPDF.php`) — this requires no PHP execution and stays compatible with `isPhpEnabled=false`. The classic `<script type="text/php">` page-number trick some Dompdf tutorials show is deliberately **not** used, since it would require re-enabling PHP execution.

## Data pipeline reuse

No new data-extraction logic. `exportToPdf()` calls the same private helpers `populateSheet()` calls today:
`fetchObjectsForExport()` → `getHeaders()` → `identifyNameCompanionColumns()` → `resolveUuidNameMap()` → per-row `getObjectValue()` / `resolveUuidsToNames()`. This guarantees PDF output has the exact same RBAC-filtered columns, multi-tenancy filtering, and UUID-to-name resolution as the existing CSV/Excel exports — no new authorization surface, no divergent behaviour between formats.

Register-level export with `schema=null` (all schemas) mirrors `exportToExcel()`'s behaviour: one table section per schema, each starting on a fresh page, inside a single PDF document (the PDF analogue of Excel's multi-sheet workbook).

## Row cap

`ExportService::MAX_PDF_EXPORT_ROWS = 5000` (public class constant, so controllers and tests can reference it instead of a magic number). Rendering thousands of table rows through an HTML/CSS layout engine (Dompdf builds a full box-tree in memory, unlike PhpSpreadsheet's more memory-efficient streaming CSV writer or even its own XLSX writer) is meaningfully heavier than the existing formats; 5000 rows is a conservative ceiling chosen to keep worst-case render time and memory bounded on typical PHP-FPM memory limits. Above the cap, `exportToPdf()` throws `OCA\OpenRegister\Exception\ExportTooLargeException` (carrying the actual row count and the limit) **before** any Dompdf work starts — the guard runs on the already-fetched object count, so no partial/wasted render happens. Both controllers catch this exception specifically and return `400` with `{"error": "export_too_large", "rowCount": ..., "maxRows": ...}` — never a 500, never a silently truncated PDF.

## Controller wiring scope

Added `pdf` to:
1. `ObjectsController::export()` — `format=pdf` branch, `Content-Type: application/pdf`, filename `{register}_{schema}_{timestamp}.pdf`.
2. `RegistersController::export()` — `case 'pdf':` in the existing `switch`, same single-schema requirement as the existing `csv` case (a coherent single table needs one column set), `Content-Type: application/pdf`, filename `{register}_{schema}_{timestamp}.pdf`.

**Not** added to `RegistersController::importTemplate()` — see proposal.md's "Alternatives Considered" — a blank fillable template is not a data export, and PDF cannot round-trip back through the import endpoint the way XLSX/CSV templates do.
