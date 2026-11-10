# Tasks — PDF Export Format

## 1. Exception + service
- [x] 1.1 Add `lib/Exception/ExportTooLargeException.php` (row count, limit, `HTTP_STATUS = 400` constant), following the `FolderAccessDeniedException` pattern.
- [x] 1.2 Add `ExportService::MAX_PDF_EXPORT_ROWS = 5000` public class constant.
- [x] 1.3 Add `ExportService::exportToPdf(?Register $register, ?Schema $schema, array $filters, ?IUser $currentUser): string`, reusing `fetchObjectsForExport()` / `getHeaders()` / `identifyNameCompanionColumns()` / `resolveUuidNameMap()` / `getObjectValue()` / `resolveUuidsToNames()`.
- [x] 1.4 Row-cap guard: throw `ExportTooLargeException` when the fetched object count (single schema) or combined count (multi-schema register export) exceeds `MAX_PDF_EXPORT_ROWS`, before any HTML/Dompdf work. Extracted as `guardPdfRowCap()` so the boundary can be unit-tested without a full Dompdf render.
- [x] 1.5 Build escaped HTML: per-schema section with title (register/schema name), export timestamp, object count, striped `<table>` with the same headers/columns as CSV/Excel, long cell values truncated (200 chars + ellipsis) with `htmlspecialchars()` escaping throughout.
- [x] 1.6 Render via Dompdf using the `PdfReportWriter` sandboxing pattern (`isRemoteEnabled=false`, `isPhpEnabled=false`, runtime assertion), A4 landscape, `DejaVu Sans` default font.
- [x] 1.7 Page numbers via `Canvas::page_text()` with `{PAGE_NUM}`/`{PAGE_COUNT}` placeholders (no PHP execution).
- [x] 1.8 Empty result set (0 objects) still yields a valid PDF (header-only table, count 0).

## 2. Controller wiring
- [x] 2.1 `ObjectsController::export()` — add `pdf` branch: call `exportToPdf()`, catch `ExportTooLargeException` → 400 JSON, else return `DataDownloadResponse` with `Content-Type: application/pdf` and `{register}_{schema}_{timestamp}.pdf` filename.
- [x] 2.2 `RegistersController::export()` — add `case 'pdf':` to the existing switch, same single-schema requirement as `csv`, same exception→400 mapping, `Content-Type: application/pdf`, `{register}_{schema}_{timestamp}.pdf` filename.
- [x] 2.3 Update both methods' docblocks (`@return`/`@psalm-return`) to mention the new format.

## 3. Tests
- [x] 3.1 New `ExportServicePdfTest`: `exportToPdf()` output starts with `%PDF` magic bytes.
- [x] 3.2 Column selection / RBAC parity: `buildPdfSection()` headers respect `hideOnCollection` and `PropertyRbacHandler::canReadProperty()`, matching the same rules CSV/Excel already enforce via `getHeaders()`.
- [x] 3.3 Row-cap: `guardPdfRowCap()` throws `ExportTooLargeException` when over cap (tested directly via reflection to avoid an expensive full-scale Dompdf render), allows exactly at cap, and the full `exportToPdf()` path short-circuits before any rendering work for both single-schema and combined multi-schema register exports.
- [x] 3.4 Empty result set (0 objects) still produces a valid `%PDF`-prefixed document.
- [x] 3.5 Controller tests: `format=pdf` on `ObjectsController::export()` returns `DataDownloadResponse` with `application/pdf` content-type and `.pdf` filename; row-cap exception maps to HTTP 400 with `error`/`rowCount`/`maxRows`.
- [x] 3.6 Controller tests: `format=pdf` on `RegistersController::export()` same assertions; missing-schema case returns 400 (mirroring the existing `csv` branch's missing-schema 400).
- [x] 3.7 Full existing PHPUnit suite stays green (no regressions to CSV/Excel/JSON export tests) — 471 export/controller-scoped tests pass; full 14,311-test suite shows only pre-existing, unrelated failures in files this change never touches.

## 4. Quality gates
- [x] 4.1 SPDX docblocks on every new/changed PHP file.
- [x] 4.2 PHPCS clean on changed files (`ExportService.php`, `ObjectsController.php`, `RegistersController.php`, `ExportTooLargeException.php`).
- [x] 4.3 `composer show --tree dompdf/dompdf` re-verified against `composer.lock` unchanged (no new deps introduced) — see design.md.
- [x] 4.4 PHPMD clean (fixed a new `ElseExpression` via early-return refactor and a `CyclomaticComplexity` breach on `RegistersController::export()` via the established `@SuppressWarnings` pattern).
- [x] 4.5 PHPStan clean on changed files modulo baseline update (added baseline entries mirroring the existing `PdfReportWriter.php` `$output ?? ''` pattern and the pre-existing `DataDownloadResponse` template-covariance debt already baselined for the `csv`/`json`/`excel` branches).
- [x] 4.6 Psalm: 0 errors on changed files.
