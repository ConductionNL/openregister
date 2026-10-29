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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;
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
	 * @param Register|null $register Optional register context
	 * @param Schema $schema Schema whose property keys become the header row
	 * @param IUser|null $currentUser Current user (drives admin metadata column inclusion)
	 *
	 * @return string CSV content with a UTF-8 BOM prefix and a single header row
	 */
	public function buildTemplateCsv(
		?Register $register,
		Schema $schema,
		?IUser $currentUser = null,
	): string;

	/**
	 * Build an empty import template spreadsheet for a schema
	 *
	 * Generates a spreadsheet that contains only the header row derived from the
	 * schema's properties (the same headers `exportToExcel` would emit), with no
	 * data rows. The returned spreadsheet can be written to either XLSX or CSV.
	 *
	 * @param Register|null $register Optional register context (used for translation column expansion)
	 * @param Schema $schema Schema whose property keys become the header row
	 * @param IUser|null $currentUser Current user (drives admin metadata column inclusion)
	 *
	 * @return Spreadsheet Spreadsheet with a single sheet containing only header cells
	 */
	public function buildTemplateSpreadsheet(
		?Register $register,
		Schema $schema,
		?IUser $currentUser = null,
	): Spreadsheet;

	/**
	 * How many objects an export with these filters would carry.
	 *
	 * Used where the produced artefact cannot be counted after the fact. A
	 * rendered pdf is a box tree, not rows, so the audit trail would otherwise
	 * have to name a guess. Every other format counts off its own
	 * bytes, which is one query cheaper and cannot drift from the file.
	 *
	 * @param Register|null $register Optional register to export.
	 * @param Schema|null   $schema   Optional schema to export.
	 * @param array         $filters  Optional filters to apply.
	 *
	 * @return int The object count this export would write.
	 */
	public function countExportRows(?Register $register = null, ?Schema $schema = null, array $filters = []): int;

	/**
	 * Export data to CSV format
	 *
	 * @param Register|null $register Optional register to export
	 * @param Schema|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return string CSV content
	 *
	 * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
	 */
	public function exportToCsv(
		?Register $register = null,
		?Schema $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): string;

	/**
	 * Export data to Excel format
	 *
	 * @param Register|null $register Optional register to export
	 * @param Schema|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return Spreadsheet
	 */
	public function exportToExcel(
		?Register $register = null,
		?Schema $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): Spreadsheet;

	/**
	 * Export objects of a single schema to a JSON document
	 *
	 * Serialises every object (subject to the same RBAC + multi-tenancy + filter
	 * rules as the spreadsheet exporters) to its canonical `jsonSerialize()`
	 * representation — body properties at the top level plus the `@self`
	 * metadata block. The result is a JSON array re-importable via
	 * `ImportService::importFromJson()`, which upserts by `@self.id`.
	 *
	 * @param Register|null $register Register context (required)
	 * @param Schema|null $schema Schema whose objects are exported (required)
	 * @param array $filters Optional `@self.*` metadata filters
	 *
	 * @return string Pretty-printed JSON array of objects
	 *
	 * @throws \InvalidArgumentException When no schema is given (JSON export is single-schema, like CSV)
	 */
	public function exportToJson(
		?Register $register = null,
		?Schema $schema = null,
		array $filters = [],
	): string;

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
	 * @param Register|null $register Optional register to export
	 * @param Schema|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return string Raw PDF bytes
	 *
	 * @throws \OCA\OpenRegister\Exception\ExportTooLargeException When the object count exceeds the PDF row limit.
	 * @throws \OCA\OpenRegister\Exception\OfficeServerRequiredException When no office server converts to PDF.
	 */
	public function exportToPdf(
		?Register $register = null,
		?Schema $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): string;

	/**
	 * Whether PDF output is possible: an office server (Collabora or Euro-Office) converts to PDF.
	 *
	 * @return bool
	 */
	public function isPdfAvailable(): bool;
}//end interface
