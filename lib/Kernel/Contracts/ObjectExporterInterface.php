<?php

/**
 * ObjectExporterInterface: export objects as CSV, Excel, JSON or PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Exports the objects of a register and schema, and builds empty import templates. Implemented by ExportService.
 */
interface ObjectExporterInterface {

	/**
	 * Render an empty CSV import template for a schema
	 *
	 * Emits a UTF-8 BOM-prefixed CSV string containing only the header row
	 * derived from the schema's properties. Mirrors the BOM convention used
	 * by the export pipeline so Excel opens the file with the correct encoding.
	 *
	 * @param ObjectExportRequest $request The register context, the schema whose property keys become the header row (required), and the user (admin
	 *                                     metadata columns).
	 *
	 * @return string CSV content with a UTF-8 BOM prefix and a single header row
	 *
	 * @throws \InvalidArgumentException When the request names no schema.
	 */
	public function buildTemplateCsv(ObjectExportRequest $request): string;

	/**
	 * Build an empty import template spreadsheet for a schema
	 *
	 * Generates a spreadsheet that contains only the header row derived from the
	 * schema's properties (the same headers `exportToExcel` would emit), with no
	 * data rows. The returned spreadsheet can be written to either XLSX or CSV.
	 *
	 * @param ObjectExportRequest $request The register context (translation columns), the schema whose property keys become the header row
	 *                                     (required), and the user (admin metadata columns).
	 *
	 * @return Spreadsheet Spreadsheet with a single sheet containing only header cells
	 *
	 * @throws \InvalidArgumentException When the request names no schema.
	 */
	public function buildTemplateSpreadsheet(ObjectExportRequest $request): Spreadsheet;

	/**
	 * How many objects an export with these filters would carry.
	 *
	 * Used where the produced artefact cannot be counted after the fact. A
	 * rendered pdf is a box tree, not rows, so the audit trail would otherwise
	 * have to name a guess. Every other format counts off its own
	 * bytes, which is one query cheaper and cannot drift from the file.
	 *
	 * @param ObjectExportRequest $request The register, schema and filters to count; the user is not used.
	 *
	 * @return int The object count this export would write.
	 */
	public function countExportRows(ObjectExportRequest $request): int;

	/**
	 * Export data to CSV format
	 *
	 * @param ObjectExportRequest $request The register, schema, filters and user (permission checks) of the export.
	 *
	 * @return string CSV content
	 *
	 * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
	 */
	public function exportToCsv(ObjectExportRequest $request): string;

	/**
	 * Export data to TSV format
	 *
	 * @param ObjectExportRequest $request The register, schema, filters and user (permission checks) of the export.
	 *
	 * @return string TSV content
	 *
	 * @throws \InvalidArgumentException If trying to export multiple schemas to TSV
	 */
	public function exportToTsv(ObjectExportRequest $request): string;

	/**
	 * Export data to Excel format
	 *
	 * @param ObjectExportRequest $request The register, schema, filters and user (permission checks) of the export.
	 *
	 * @return Spreadsheet
	 */
	public function exportToExcel(ObjectExportRequest $request): Spreadsheet;

	/**
	 * Export objects of a single schema to a JSON document
	 *
	 * Serialises every object (subject to the same RBAC + multi-tenancy + filter
	 * rules as the spreadsheet exporters) to its canonical `jsonSerialize()`
	 * representation — body properties at the top level plus the `@self`
	 * metadata block. The result is a JSON array re-importable via
	 * `ImportService::importFromJson()`, which upserts by `@self.id`.
	 *
	 * @param ObjectExportRequest $request The register context, the schema whose objects are exported (required) and the `@self.*` metadata filters;
	 *                                     the user is not used.
	 *
	 * @return string Pretty-printed JSON array of objects
	 *
	 * @throws \InvalidArgumentException When no schema is given (JSON export is single-schema, like CSV)
	 */
	public function exportToJson(ObjectExportRequest $request): string;

	/**
	 * Export data to PDF format
	 *
	 * Renders the same tabular data the CSV/Excel exporters produce into an
	 * A4-landscape PDF: an ODT converted by the office server. Reuses the existing column/data-extraction
	 * pipeline (`fetchObjectsForExport()`, `getHeaders()`,
	 * `identifyNameCompanionColumns()`, `resolveUuidNameMap()`,
	 * `getObjectValue()`, `resolveUuidsToNames()`) — only the renderer is
	 * new. When `$register` is given without `$schema`, one table section
	 * per schema is rendered (the PDF analogue of `exportToExcel()`'s
	 * one-sheet-per-schema behaviour).
	 *
	 * @param ObjectExportRequest $request The register, schema, filters and user (permission checks) of the export.
	 *
	 * @return string Raw PDF bytes
	 *
	 * @throws \OCA\OpenRegister\Exception\ExportTooLargeException When the object count exceeds the PDF row limit.
	 * @throws \OCA\OpenRegister\Exception\OfficeServerRequiredException When no office server converts to PDF.
	 */
	public function exportToPdf(ObjectExportRequest $request): string;

	/**
	 * Whether PDF output is possible: an office server (Collabora or Euro-Office) converts to PDF.
	 *
	 * @return bool
	 */
	public function isPdfAvailable(): bool;
}//end interface
