<?php

/**
 * OpenRegister Export Service
 *
 * This file contains the class for handling data export operations in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/export-pdf-format/spec.md
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\Export\ExportTable;
use OCA\OpenRegister\Service\Export\RowsPdfSection;
use OCA\OpenRegister\Service\Export\RowsTable;
use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Service\Export\Odt\OdtWriter;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Service for exporting data to various formats
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class ExportService implements ObjectExporterInterface {

	/**
	 * Maximum number of objects a single PDF export may render.
	 *
	 * A PDF is laid out by an office server from an ODT holding every row,
	 * unlike the streaming CSV writer, so its cost grows with the row count.
	 * Requests whose object count exceeds this cap MUST fail fast with
	 * {@see ExportTooLargeException} before any document is built.
	 *
	 * @var int
	 */
	public const MAX_PDF_EXPORT_ROWS = 5000;

	/**
	 * Request parameters of the export route that are not object filters.
	 *
	 * @var string[]
	 */
	private const NON_FILTER_EXPORT_PARAMS = ['register', 'schema', 'format', 'type', 'multi'];

	/**
	 * The first spreadsheet row below the header row.
	 *
	 * @var int
	 */
	private const FIRST_DATA_ROW = 2;

	/**
	 * Register mapper instance
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Group manager for checking admin group membership
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Object service for optimized object operations
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Cache handler for UUID-to-name resolution
	 *
	 * @var CacheHandlerInterface
	 */
	private readonly CacheHandlerInterface $cacheHandler;

	/**
	 * Property RBAC handler for property-level authorization checks
	 *
	 * @var PropertyRbacHandler
	 */
	private readonly PropertyRbacHandler $propertyRbacHandler;


	/**
	 * Optional register context used during sheet population.
	 *
	 * @var Register|null
	 */
	private ?Register $contextRegister = null;

	/**
	 * The explicit-reveal columns of the export being written.
	 *
	 * @var array<string, true>
	 */
	private array $maskedColumns = [];

	/**
	 * Constructor for the ExportService.
	 *
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param IUserManager $_userManager The user manager (unused).
	 * @param IGroupManager $groupManager The group manager.
	 * @param ObjectOperationsInterface $objectService The object service.
	 * @param CacheHandlerInterface $cacheHandler The cache handler for name resolution.
	 * @param PropertyRbacHandler $propertyRbacHandler The property RBAC handler.
	 * @param NcOfficeConverterInterface|null $officeConverter Office server conversion; PDF output needs it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function __construct(
		RegisterMapperInterface $registerMapper,
		IUserManager $_userManager,
		IGroupManager $groupManager,
		ObjectOperationsInterface $objectService,
		CacheHandlerInterface $cacheHandler,
		PropertyRbacHandler $propertyRbacHandler,
		private readonly ?NcOfficeConverterInterface $officeConverter = null,
	) {
		$this->registerMapper = $registerMapper;
		$this->groupManager = $groupManager;
		$this->objectService = $objectService;
		$this->cacheHandler = $cacheHandler;
		$this->propertyRbacHandler = $propertyRbacHandler;
	}//end __construct()

	/**
	 * Check if the given user is in the admin group
	 *
	 * @param IUser|null $user The user to check (null means anonymous/no user)
	 *
	 * @return bool True if user is admin, false otherwise
	 */
	private function isUserAdmin(?IUser $user): bool {
		if ($user === null) {
			return false;
			// Anonymous users are never admin.
		}

		// Check if user is in admin group.
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return false;
			// Admin group doesn't exist.
		}

		return $adminGroup->inGroup($user);
	}//end isUserAdmin()

	/**
	 * Export data to Excel format
	 *
	 * @param RegisterInterface|null $register Optional register to export
	 * @param SchemaInterface|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return Spreadsheet
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function exportToExcel(
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): Spreadsheet {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->spreadsheetFor(request: new ExportRequest(register: $register, schema: $schema, filters: $filters, currentUser: $currentUser));
	}//end exportToExcel()

	/**
	 * Export one request to a spreadsheet: one sheet, or one sheet per schema of the register when no schema is given.
	 *
	 * @param ExportRequest $request What to export and for whom.
	 *
	 * @return Spreadsheet
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function spreadsheetFor(ExportRequest $request): Spreadsheet {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0);

		if ($request->register !== null && $request->schema === null) {
			foreach ($this->getSchemasForRegister(register: $request->register) as $schema) {
				$this->populateSheet(spreadsheet: $spreadsheet, request: $this->schemaRequest(request: $request, schema: $schema));
			}

			return $spreadsheet;
		}

		$this->populateSheet(spreadsheet: $spreadsheet, request: $request);

		return $spreadsheet;
	}//end spreadsheetFor()

	/**
	 * Export data to CSV format
	 *
	 * @param RegisterInterface|null $register Optional register to export
	 * @param SchemaInterface|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return string CSV content
	 *
	 * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function exportToCsv(
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): string {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->csvFor(request: new ExportRequest(register: $register, schema: $schema, filters: $filters, currentUser: $currentUser));
	}//end exportToCsv()

	/**
	 * Export one request to CSV; CSV holds one schema only.
	 *
	 * @param ExportRequest $request What to export and for whom.
	 *
	 * @return string CSV content
	 *
	 * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function csvFor(ExportRequest $request): string {
		if ($request->register !== null && $request->schema === null) {
			throw new InvalidArgumentException('Cannot export multiple schemas to CSV format.');
		}

		$writer = new Csv($this->spreadsheetFor(request: $request));

		ob_start();
		$writer->save('php://output');
		return ob_get_clean();
	}//end csvFor()

	/**
	 * Export objects of a single schema to a JSON document
	 *
	 * Serialises every object (subject to the same RBAC + multi-tenancy + filter
	 * rules as the spreadsheet exporters) to its canonical `jsonSerialize()`
	 * representation — body properties at the top level plus the `@self`
	 * metadata block. The result is a JSON array re-importable via
	 * `ImportService::importFromJson()`, which upserts by `@self.id`.
	 *
	 * @param RegisterInterface|null $register Register context (required)
	 * @param SchemaInterface|null $schema Schema whose objects are exported (required)
	 * @param array $filters Optional `@self.*` metadata filters
	 *
	 * @return string Pretty-printed JSON array of objects
	 *
	 * @throws InvalidArgumentException When no schema is given (JSON export is single-schema, like CSV)
	 *
	 * @spec exclude Retrofit — JSON object export added alongside the existing Excel/CSV exporters; no dedicated openspec change.
	 */
	public function exportToJson(?RegisterInterface $register = null, ?SchemaInterface $schema = null, array $filters = []): string {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->jsonFor(request: new ExportRequest(register: $register, schema: $schema, filters: $filters));
	}//end exportToJson()

	/**
	 * Export one request's objects to a JSON array; JSON holds one schema only.
	 *
	 * @param ExportRequest $request What to export.
	 *
	 * @return string Pretty-printed JSON array of objects
	 *
	 * @throws InvalidArgumentException When no schema is given (JSON export is single-schema, like CSV)
	 *
	 * @spec exclude Retrofit — JSON object export added alongside the existing Excel/CSV exporters; no dedicated openspec change.
	 */
	public function jsonFor(ExportRequest $request): string {
		if ($request->schema === null) {
			throw new InvalidArgumentException('JSON export requires a specific schema.');
		}

		$rows = array_map(
			static fn (ObjectEntity $object): array => $object->jsonSerialize(),
			$this->fetchObjectsForExport(request: $request)
		);

		return json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end jsonFor()

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
	 * @param RegisterInterface|null $register Optional register to export
	 * @param SchemaInterface|null $schema Optional schema to export
	 * @param array $filters Optional filters to apply
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return string Raw PDF bytes
	 *
	 * @throws ExportTooLargeException When the object count exceeds {@see self::MAX_PDF_EXPORT_ROWS}.
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function exportToPdf(
		?RegisterInterface $register = null,
		?SchemaInterface $schema = null,
		array $filters = [],
		?IUser $currentUser = null,
	): string {
		$this->assertPdfAvailable();
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->pdfFor(request: new ExportRequest(register: $register, schema: $schema, filters: $filters, currentUser: $currentUser));
	}//end exportToPdf()

	/**
	 * Export one request to PDF: one table section, or one per schema of the register when no schema is given.
	 *
	 * @param ExportRequest $request What to export and for whom.
	 *
	 * @return string Raw PDF bytes
	 *
	 * @throws ExportTooLargeException When the object count exceeds {@see self::MAX_PDF_EXPORT_ROWS}.
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function pdfFor(ExportRequest $request): string {
		$this->assertPdfAvailable();

		// Capture register context so getHeaders/getObjectValue can emit
		// per-language columns for translatable properties, same as the
		// Excel/CSV pipeline.
		$this->contextRegister = $request->register;

		if ($request->register !== null && $request->schema === null) {
			return $this->renderPdfDocument(sections: $this->buildPdfSectionsForRegister(request: $request));
		}

		$objects = $this->fetchObjectsForExport(request: $request);
		$this->guardPdfRowCap(rowCount: count($objects));

		return $this->renderPdfDocument(sections: [$this->buildPdfSection(request: $request, objects: $objects)]);
	}//end pdfFor()

	/**
	 * Render rows the caller already fetched as a PDF table
	 *
	 * For a caller whose read is not a Nextcloud user's search, such as a
	 * portal resident's scoped collection (portaliq#765): exportToPdf() fetches
	 * its own objects with the Nextcloud user's RBAC and only `@self.` filters,
	 * so it cannot render that read. This renders what it is given, through the
	 * same office conversion and under the same row cap, and reads nothing itself.
	 *
	 * @param string                           $title   The heading above the table.
	 * @param array<int|string, string>        $columns Column keys to labels, or a list of keys that are their own labels.
	 * @param array<int, array<string, mixed>> $rows    The rows, each keyed by column key.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws ExportTooLargeException When there are more rows than {@see self::MAX_PDF_EXPORT_ROWS}.
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function renderRowsToPdf(string $title, array $columns, array $rows): string {
		return $this->renderTableToPdf(table: new RowsTable(title: $title, columns: $columns, rows: $rows));
	}//end renderRowsToPdf()

	/**
	 * Render a table of rows the caller already read as a PDF
	 *
	 * @param RowsTable $table The title, columns and rows.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws ExportTooLargeException When there are more rows than {@see self::MAX_PDF_EXPORT_ROWS}.
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function renderTableToPdf(RowsTable $table): string {
		$this->assertPdfAvailable();

		$this->guardPdfRowCap(rowCount: count($table->rows));

		return $this->renderPdfDocument(sections: [(new RowsPdfSection())->build(table: $table)]);
	}//end renderTableToPdf()

	/**
	 * Build one PDF section per schema for a register-level export (no
	 * single schema selected), mirroring `exportToExcel()`'s
	 * one-sheet-per-schema behaviour. The row-count cap is enforced on the
	 * combined total across all schemas before any section HTML is built.
	 *
	 * @param ExportRequest $request The register-level export; its schema is null.
	 *
	 * @return string[] One HTML section fragment per schema.
	 *
	 * @throws ExportTooLargeException When the combined object count exceeds {@see self::MAX_PDF_EXPORT_ROWS}.
	 */
	private function buildPdfSectionsForRegister(ExportRequest $request): array {
		$sectionInput = [];
		$totalRows = 0;

		foreach ($this->getSchemasForRegister(register: $request->register) as $schemaItem) {
			$schemaRequest = $this->schemaRequest(request: $request, schema: $schemaItem);
			$objects = $this->fetchObjectsForExport(request: $schemaRequest);
			$totalRows += count($objects);
			$sectionInput[] = ['request' => $schemaRequest, 'objects' => $objects];
		}

		$this->guardPdfRowCap(rowCount: $totalRows);

		$sections = [];
		foreach ($sectionInput as $input) {
			$sections[] = $this->buildPdfSection(request: $input['request'], objects: $input['objects']);
		}

		return $sections;
	}//end buildPdfSectionsForRegister()

	/**
	 * Guard the PDF row-count cap.
	 *
	 * Extracted as its own method (rather than inlined at each call site)
	 * so the boundary condition can be unit-tested directly without
	 * building a document of thousands of rows.
	 *
	 * @param int $rowCount The object count to check against {@see self::MAX_PDF_EXPORT_ROWS}.
	 *
	 * @return void
	 *
	 * @throws ExportTooLargeException When `$rowCount` exceeds the configured cap.
	 */
	private function guardPdfRowCap(int $rowCount): void {
		if ($rowCount > self::MAX_PDF_EXPORT_ROWS) {
			throw new ExportTooLargeException(rowCount: $rowCount, maxRows: self::MAX_PDF_EXPORT_ROWS);
		}
	}//end guardPdfRowCap()

	/**
	 * Build the escaped HTML for a single schema's export section.
	 *
	 * @param ExportRequest  $request The export; its register and schema title the section, its user drives header visibility.
	 * @param ObjectEntity[] $objects The already-fetched, already-capped object set for this section.
	 *
	 * @return string Escaped HTML for the section (title, meta line, table).
	 */
	private function buildPdfSection(ExportRequest $request, array $objects): string {
		$table = $this->exportTable(
			headers: $this->getHeaders(schema: $request->schema, currentUser: $request->currentUser),
			objects: $objects
		);

		$timestamp = (new DateTime())->format('Y-m-d H:i:s');

		$html = '<div class="pdf-section">';
		$html .= '<h1>' . htmlspecialchars($this->pdfSectionTitle(request: $request), ENT_QUOTES, 'UTF-8') . '</h1>';
		$html .= '<p class="meta">'
			. 'Exported: ' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8')
			. ' &middot; Objects: ' . count($objects)
			. '</p>';
		$html .= '<table><thead><tr>';

		foreach ($table->headers as $header) {
			$html .= '<th>' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
		}

		$html .= '</tr></thead><tbody>';

		foreach ($table->objects as $object) {
			$html .= '<tr>';
			foreach ($this->rowValues(table: $table, object: $object) as $value) {
				$html .= '<td>' . htmlspecialchars($this->truncatePdfCellValue(value: $value), ENT_QUOTES, 'UTF-8') . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table></div>';
	}//end buildPdfSection()

	/**
	 * The heading of a PDF section: the register and schema titles, or "Export".
	 *
	 * @param ExportRequest $request The export the section shows.
	 *
	 * @return string The unescaped title.
	 */
	private function pdfSectionTitle(ExportRequest $request): string {
		$titleParts = [];
		if ($request->register !== null) {
			$titleParts[] = $request->register->getTitle() ?? $request->register->getSlug() ?? 'Register';
		}

		if ($request->schema !== null) {
			$titleParts[] = $request->schema->getTitle() ?? $request->schema->getSlug() ?? 'Schema';
		}

		$title = implode(' — ', $titleParts);
		if ($title === '') {
			return 'Export';
		}

		return $title;
	}//end pdfSectionTitle()

	/**
	 * Truncate a cell value for PDF display so a single long value cannot
	 * blow out the table layout or the render time.
	 *
	 * @param string|null $value The raw cell value.
	 *
	 * @return string The value, truncated to 200 characters with an ellipsis if longer.
	 */
	private function truncatePdfCellValue(?string $value): string {
		if ($value === null) {
			return '';
		}

		$maxLength = 200;
		if (mb_strlen($value) > $maxLength) {
			return mb_substr($value, 0, $maxLength) . '…';
		}

		return $value;
	}//end truncatePdfCellValue()

	/**
	 * The same export narrowed to one schema of its register.
	 *
	 * @param ExportRequest $request The register-level export.
	 * @param Schema        $schema  One schema of that register.
	 *
	 * @return ExportRequest
	 */
	private function schemaRequest(ExportRequest $request, Schema $schema): ExportRequest {
		return new ExportRequest(register: $request->register, schema: $schema, filters: $request->filters, currentUser: $request->currentUser);
	}//end schemaRequest()

	/**
	 * Refuse PDF output when no office server converts to PDF.
	 *
	 * @return void
	 *
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 */
	private function assertPdfAvailable(): void {
		if ($this->isPdfAvailable() === false) {
			throw new OfficeServerRequiredException();
		}
	}//end assertPdfAvailable()

	/**
	 * Whether PDF output is possible: an office server converts ODT to PDF.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function isPdfAvailable(): bool {
		return $this->officeConverter?->isAvailable() === true;
	}//end isPdfAvailable()

	/**
	 * Render one or more section HTML fragments into a single A4-landscape PDF.
	 *
	 * The sections are written as one ODT, each section on a new page, and the
	 * office server converts it to PDF.
	 *
	 * @param string[] $sections Escaped HTML fragments, one per schema section.
	 *
	 * @return string Raw PDF bytes.
	 *
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 */
	private function renderPdfDocument(array $sections): string {
		if ($this->officeConverter === null) {
			throw new OfficeServerRequiredException();
		}

		$odt = (new OdtWriter())->fromHtml(html: implode('', $sections), landscape: true);

		return $this->officeConverter->odtToPdf(odtBytes: $odt);
	}//end renderPdfDocument()

	/**
	 * Build an empty import template spreadsheet for a schema
	 *
	 * Generates a spreadsheet that contains only the header row derived from the
	 * schema's properties (the same headers `exportToExcel` would emit), with no
	 * data rows. The returned spreadsheet can be written to either XLSX or CSV.
	 *
	 * @param RegisterInterface|null $register Optional register context (used for translation column expansion)
	 * @param SchemaInterface $schema Schema whose property keys become the header row
	 * @param IUser|null $currentUser Current user (drives admin metadata column inclusion)
	 *
	 * @return Spreadsheet Spreadsheet with a single sheet containing only header cells
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (builds a header-only
	 *       template spreadsheet from a schema's properties, with register-context per-language column expansion)
	 */
	public function buildTemplateSpreadsheet(?RegisterInterface $register, SchemaInterface $schema, ?IUser $currentUser = null): Spreadsheet {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->templateSpreadsheetFor(request: new ExportRequest(register: $register, schema: $schema, currentUser: $currentUser));
	}//end buildTemplateSpreadsheet()

	/**
	 * Build an empty import template spreadsheet for the request's schema
	 *
	 * @param ExportRequest $request The register context, the schema whose property keys become the header row, and the user.
	 *
	 * @return Spreadsheet Spreadsheet with a single sheet containing only header cells
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (builds a header-only
	 *       template spreadsheet from a schema's properties, with register-context per-language column expansion)
	 */
	public function templateSpreadsheetFor(ExportRequest $request): Spreadsheet {
		// Capture register context so getHeaders can emit per-language
		// `field_lang` columns for translatable properties.
		$this->contextRegister = $request->register;

		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0);
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle($request->schema?->getSlug() ?? 'data');

		foreach ($this->getHeaders(schema: $request->schema, currentUser: $request->currentUser) as $col => $header) {
			$sheet->setCellValue(coordinate: $col . '1', value: $header);
		}

		return $spreadsheet;
	}//end templateSpreadsheetFor()

	/**
	 * Render an empty CSV import template for a schema
	 *
	 * Emits a UTF-8 BOM-prefixed CSV string containing only the header row
	 * derived from the schema's properties. Mirrors the BOM convention used
	 * by the export pipeline so Excel opens the file with the correct encoding.
	 *
	 * @param RegisterInterface|null $register Optional register context
	 * @param SchemaInterface $schema Schema whose property keys become the header row
	 * @param IUser|null $currentUser Current user (drives admin metadata column inclusion)
	 *
	 * @return string CSV content with a UTF-8 BOM prefix and a single header row
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (renders the per-schema
	 *       import template as a UTF-8 BOM-prefixed CSV so Excel opens it correctly)
	 */
	public function buildTemplateCsv(?RegisterInterface $register, SchemaInterface $schema, ?IUser $currentUser = null): string {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return $this->templateCsvFor(request: new ExportRequest(register: $register, schema: $schema, currentUser: $currentUser));
	}//end buildTemplateCsv()

	/**
	 * Render an empty CSV import template for the request's schema
	 *
	 * @param ExportRequest $request The register context, the schema whose property keys become the header row, and the user.
	 *
	 * @return string CSV content with a UTF-8 BOM prefix and a single header row
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (renders the per-schema
	 *       import template as a UTF-8 BOM-prefixed CSV so Excel opens it correctly)
	 */
	public function templateCsvFor(ExportRequest $request): string {
		$writer = new Csv($this->templateSpreadsheetFor(request: $request));
		$writer->setUseBOM(true);

		ob_start();
		$writer->save('php://output');
		return ob_get_clean();
	}//end templateCsvFor()

	/**
	 * Populate a worksheet with data
	 *
	 * Uses a two-pass approach for optimal UUID-to-name resolution:
	 * 1. First pass: collect all UUIDs from relation columns across all objects
	 * 2. One bulk CacheHandler::getMultipleObjectNames() call
	 * 3. Second pass: populate the sheet with data and resolved names
	 *
	 * @param Spreadsheet   $spreadsheet The spreadsheet to populate
	 * @param ExportRequest $request     The register, schema, filters and user of this sheet
	 *
	 * @return void
	 */
	private function populateSheet(Spreadsheet $spreadsheet, ExportRequest $request): void {
		// Capture register context so getHeaders / getObjectValue can
		// emit / read per-language `field_lang` columns for translatable
		// properties (register-i18n Phase 3 wire-in).
		$this->contextRegister = $request->register;
		$sheet = $spreadsheet->createSheet();

		$sheetTitle = 'data';
		if ($request->schema !== null) {
			$sheetTitle = $request->schema->getSlug();
		}

		$sheet->setTitle($sheetTitle);

		$headers = $this->getHeaders(schema: $request->schema, currentUser: $request->currentUser);
		foreach ($headers as $col => $header) {
			$sheet->setCellValue(coordinate: $col . '1', value: $header);
		}

		$objects = $this->fetchObjectsForExport(request: $request);

		$this->writeObjectRows(sheet: $sheet, table: $this->exportTable(headers: $headers, objects: $objects));
	}//end populateSheet()

	/**
	 * How many objects an export with these filters would carry.
	 *
	 * Used where the produced artefact cannot be counted after the fact. A
	 * rendered pdf is a box tree, not rows, so the audit trail would otherwise
	 * have to name a guess. Every other format counts off its own
	 * bytes, which is one query cheaper and cannot drift from the file.
	 *
	 * @param RegisterInterface|null $register Optional register to export.
	 * @param SchemaInterface|null   $schema   Optional schema to export.
	 * @param array         $filters  Optional filters to apply.
	 *
	 * @return int The object count this export would write.
	 *
	 * @throws \InvalidArgumentException When the register or schema is not a stored row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function countExportRows(?RegisterInterface $register = null, ?SchemaInterface $schema = null, array $filters = []): int {
		$register = StoredEntity::register(register: $register);
		$schema   = StoredEntity::schema(schema: $schema);

		return count($this->fetchObjectsForExport(request: new ExportRequest(register: $register, schema: $schema, filters: $filters)));
	}//end countExportRows()

	/**
	 * The objects an export with these filters would carry.
	 *
	 * The export profile projects its own field set onto these, so it needs the
	 * entities rather than a written file. Selection stays here: one place
	 * decides what an export sees, and RBAC and multi-tenancy are applied in it.
	 *
	 * @param ExportRequest $request The register, schema and filters to select by.
	 *
	 * @return ObjectEntity[] The objects this export would write.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function fetchExportObjects(ExportRequest $request): array {
		return $this->fetchObjectsForExport(request: $request);
	}//end fetchExportObjects()

	/**
	 * Fetch all objects matching the given register, schema and filters for export.
	 *
	 * Builds the query with RBAC, multi-tenancy and metadata filters, then returns
	 * the full result set (high limit, no pagination).
	 *
	 * @param ExportRequest $request The register, schema and request filters to select by.
	 *
	 * @return ObjectEntity[] Array of matching object entities.
	 */
	private function fetchObjectsForExport(ExportRequest $request): array {
		$register = $request->register;
		$schema   = $request->schema;
		$filters  = $request->filters;
		// Build filters for MagicMapper->findAll() method.
		$objectFilters = [];

		if ($register !== null) {
			$objectFilters['register'] = $register->getId();
		}

		if ($schema !== null) {
			$objectFilters['schema'] = $schema->getId();
		}

		// Apply additional filters. A property filter narrows the export as it
		// narrows the list it came from; it used to be skipped, so a filtered
		// list exported every row the caller could read (openregister#4088).
		// The route's own parameters and `_`-prefixed controls are not filters.
		$propertyFilters = [];
		foreach ($filters as $key => $value) {
			$key = (string) $key;
			if (str_starts_with($key, '@self.') === false) {
				if (str_starts_with($key, '_') === false && str_starts_with($key, '@') === false
					&& in_array($key, self::NON_FILTER_EXPORT_PARAMS, true) === false
				) {
					$propertyFilters[$key] = $value;
				}

				continue;
			}

			// Metadata filter - remove @self. prefix.
			$metaField = substr($key, 6);
			$objectFilters[$metaField] = $value;
		}

		// Check if multitenancy was explicitly requested via _multi parameter.
		$multiExplicitlySet = isset($filters['_multi']) || isset($filters['multi']);
		$multitenancy = true;
		if (isset($filters['_multi']) === true) {
			$multitenancy = filter_var($filters['_multi'], FILTER_VALIDATE_BOOLEAN);
		} elseif (isset($filters['multi']) === true) {
			$multitenancy = filter_var($filters['multi'], FILTER_VALIDATE_BOOLEAN);
		}

		// `_ids` (a list or a comma-separated string) narrows the export to the
		// selected objects; RBAC and multitenancy still apply to each.
		$selected = implode(',', array_map('strval', (array) ($filters['_ids'] ?? [])));
		$selectedIds = array_values(array_filter(array_map('trim', explode(',', $selected)), static fn (string $id): bool => $id !== ''));
		if ($selectedIds === []) {
			$selectedIds = null;
		}

		// Use ObjectService::searchObjects directly with proper RBAC and multi-tenancy filtering.
		// An export needs every row, so it asks for no limit: a number would be
		// cut at the page bound on the multi-schema paths.
		// The export's own keys come first, so no property filter can replace them.
		$query = [
			'@self' => $objectFilters,
			'_limit' => false,
			'_includeDeleted' => false,
			'_multitenancy_explicit' => $multiExplicitlySet,
		] + $propertyFilters;

		return $this->objectService->searchObjects(
			query: $query,
			_rbac: true,
			// Apply RBAC filtering.
			_multitenancy: $multitenancy,
			// Apply multi-tenancy filtering (respects explicit _multi parameter).
			ids: $selectedIds,
			uses: null
		);
	}//end fetchObjectsForExport()


	/**
	 * Identify which header columns are name-companion columns (prefixed with _).
	 *
	 * @param array $headers The header map keyed by column letter.
	 *
	 * @return array Map of column letter to source property name for companion columns.
	 */
	private function identifyNameCompanionColumns(array $headers): array {
		$nameColumns = [];
		foreach ($headers as $col => $header) {
			if (str_starts_with($header, '_') === true && str_starts_with($header, '@') === false) {
				// This is a companion name column; the source property is the header without the _ prefix.
				$nameColumns[$col] = substr($header, 1);
			}
		}

		return $nameColumns;
	}//end identifyNameCompanionColumns()

	/**
	 * Bulk resolve UUIDs to human-readable names for relation columns.
	 *
	 * Pre-seeds the map from already-loaded objects, collects all referenced UUIDs
	 * from relation columns, and resolves any remaining via the cache handler.
	 *
	 * @param ObjectEntity[] $objects The full set of exported objects.
	 * @param array $nameColumns Map of column letter to source property name.
	 *
	 * @return array Map of UUID string to human-readable name.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveUuidNameMap(array $objects, array $nameColumns): array {
		if (empty($nameColumns) === true) {
			return [];
		}

		$uuidToNameMap = [];

		// Pre-seed name map from already-loaded objects (saves DB lookups for self-references).
		foreach ($objects as $object) {
			$uuid = $object->getUuid();
			$name = $object->getName();
			if ($uuid !== null && $name !== null) {
				$uuidToNameMap[$uuid] = $name;
			}
		}

		// Collect all UUIDs from relation columns across all objects.
		$allUuids = [];
		foreach ($objects as $object) {
			$objectData = $object->getObject();
			foreach ($nameColumns as $sourceProperty) {
				$value = $objectData[$sourceProperty] ?? null;
				if ($value === null) {
					continue;
				}

				$this->collectUuids(value: $value, allUuids: $allUuids);
			}
		}

		// Only resolve UUIDs not already in the pre-seeded map.
		$uniqueUuids = array_unique($allUuids);
		$externalUuids = array_diff($uniqueUuids, array_keys($uuidToNameMap));

		if (empty($externalUuids) === false) {
			$externalNames = $this->cacheHandler->getMultipleObjectNames(array_values($externalUuids));
			$uuidToNameMap = array_merge($uuidToNameMap, $externalNames);
		}

		return $uuidToNameMap;
	}//end resolveUuidNameMap()

	/**
	 * The table of one section: its objects, columns and resolved relation names.
	 *
	 * @param array          $headers Header map keyed by column letter.
	 * @param ObjectEntity[] $objects The objects of the section.
	 *
	 * @return ExportTable
	 */
	private function exportTable(array $headers, array $objects): ExportTable {
		$nameColumns = $this->identifyNameCompanionColumns(headers: $headers);

		return new ExportTable(
			objects: $objects,
			headers: $headers,
			nameColumns: $nameColumns,
			uuidToNameMap: $this->resolveUuidNameMap(objects: $objects, nameColumns: $nameColumns)
		);
	}//end exportTable()

	/**
	 * The cell values of one object's row keyed by column letter; companion name columns carry resolved names.
	 *
	 * @param ExportTable  $table  The section table.
	 * @param ObjectEntity $object The object of this row.
	 *
	 * @return array<string, string|null>
	 */
	private function rowValues(ExportTable $table, ObjectEntity $object): array {
		$objectData = $object->getObject();
		$values = [];
		foreach ($table->headers as $col => $header) {
			$values[$col] = $this->getObjectValue(object: $object, header: $header);
			if (isset($table->nameColumns[$col]) === true) {
				$rawValue = $objectData[$table->nameColumns[$col]] ?? null;
				$values[$col] = $this->resolveUuidsToNames(value: $rawValue, uuidToNameMap: $table->uuidToNameMap);
			}
		}

		return $values;
	}//end rowValues()

	/**
	 * Write object data rows to the spreadsheet, starting below the header row.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet to populate.
	 * @param ExportTable $table The section table.
	 *
	 * @return void
	 */
	private function writeObjectRows($sheet, ExportTable $table): void {
		$row = self::FIRST_DATA_ROW;
		foreach ($table->objects as $object) {
			foreach ($this->rowValues(table: $table, object: $object) as $col => $value) {
				$sheet->setCellValue(coordinate: $col . $row, value: $value);
			}

			$row++;
		}
	}//end writeObjectRows()

	/**
	 * Get headers for export
	 *
	 * Detects relation properties (containing UUIDs) from the schema and inserts
	 * companion _propertyName columns immediately after each relation column.
	 * These companion columns will contain human-readable names resolved from UUIDs.
	 *
	 * @param Schema|null $schema Optional schema to export
	 * @param IUser|null $currentUser Current user for permission checks
	 *
	 * @return (int|string)[]
	 *
	 * @psalm-return array<array-key>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function getHeaders(?Schema $schema = null, ?IUser $currentUser = null): array {
		// The export's explicit-reveal columns read masked, as on every other route.
		$this->maskedColumns = array_fill_keys(ExplicitReveal::explicitProperties(schema: $schema), true);

		// Start with id as the first column.
		// Will contain the uuid.
		$headers = [
			'A' => 'id',
		];

		// Initialize column pointer before conditional usage.
		$col = 'B';

		// Add schema fields from the schema properties.
		if ($schema !== null) {
			$properties = $schema->getProperties();

			// Sort properties by their order in the schema.
			foreach (array_keys($properties) as $fieldName) {
				// Skip fields that are already in the default headers.
				if (in_array($fieldName, ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated']) === true) {
					continue;
				}

				// Skip properties that are hidden on collection views.
				if (($properties[$fieldName]['hideOnCollection'] ?? false) === true) {
					continue;
				}

				// Skip properties explicitly marked as not visible.
				if (isset($properties[$fieldName]['visible']) === true
					&& $properties[$fieldName]['visible'] === false
				) {
					continue;
				}

				// Skip properties restricted by authorization rules the current user doesn't satisfy.
				// Uses PropertyRbacHandler (the single source of truth for property-level RBAC).
				// Empty object array causes conditional match rules to fail-closed (safe default for headers).
				if ($this->propertyRbacHandler->canReadProperty(property: new SchemaProperty(schema: $schema, name: $fieldName), request: new PropertyAccessRequest(object: [])) === false
				) {
					continue;
				}

				// Translatable property: emit one column per configured
				// language so the CSV round-trips through TranslationCsvCodec.
				// Falls back to ['nl', 'en'] when the register isn't
				// resolvable (org-wide minimum per CLAUDE.md memory).
				if (($properties[$fieldName]['translatable'] ?? false) === true) {
					$languages = $this->resolveExportLanguages();
					foreach ($languages as $long) {
						$headers[$col] = $fieldName . '_' . $long;
						$col++;
					}

					continue;
				}

				// Always use the property key as the header to ensure consistent data access.
				$headers[$col] = $fieldName;
				$col++;

				// Insert companion _name column if this property contains UUID references.
				if ($this->isRelationProperty(property: $properties[$fieldName]) === true) {
					$headers[$col] = '_' . $fieldName;
					$col++;
				}
			}//end foreach
		}//end if

		// REQUIREMENT: Add @self metadata fields only if user is admin.
		if ($this->isUserAdmin(user: $currentUser) === true) {
			$metadataFields = [
				'created',
				'updated',
				'deleted',
				'locked',
				'owner',
				'organisation',
				'application',
				'folder',
				'size',
				'version',
				'schemaVersion',
				'uri',
				'register',
				'schema',
				'name',
				'description',
				'validation',
				'geo',
				'retention',
				'authorization',
				'groups',
			];

			foreach ($metadataFields as $field) {
				$headers[$col] = '@self.' . $field;
				$col++;
			}
		}//end if

		return $headers;
	}//end getHeaders()

	/**
	 * Get value from object for given header
	 *
	 * @param ObjectEntity $object The object to get value from
	 * @param string $header The header to get value for
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex multi-step value extraction logic
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Value extraction requires many conditional type checks
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple header prefix and value type conditions
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function getObjectValue(ObjectEntity $object, string $header): ?string {
		// Metadata headers: `@self.<field>`, and the legacy `_<field>`.
		foreach (['@self.', '_'] as $prefix) {
			if (str_starts_with(haystack: $header, needle: $prefix) === false) {
				continue;
			}

			$value = $object->getObjectArray()[substr(string: $header, offset: strlen($prefix))] ?? null;
			if (is_string($value) === false
				|| str_contains(haystack: $value, needle: 'T') === false
				|| str_contains(haystack: $value, needle: 'Z') === false
			) {
				return $this->convertValueToString(value: $value);
			}

			// An ISO 8601 timestamp is written as `Y-m-d H:i:s`.
			try {
				return (new DateTime($value))->format('Y-m-d H:i:s');
			} catch (Exception $e) {
				return $value;
			}
		}//end foreach

		// Handle regular fields.
		switch ($header) {
			case 'id':
				// Return uuid for id column.
				return $object->getUuid();
			default:
				// Get value from object data and convert to string.
				$objectData = $object->getObject();

				// Translatable `field_lang` column — extract the
				// language-keyed slot from the JSONB property
				// (register-i18n Phase 3 wire-in).
				$longValue = $this->extractLanguageSlot(objectData: $objectData, header: $header);
				if ($longValue !== null) {
					return $longValue;
				}

				$value = $objectData[$header] ?? null;
				if (isset($this->maskedColumns[$header]) === true && $value !== null && $value !== '') {
					return ExplicitReveal::mask(value: $value);
				}

				return $this->convertValueToString(value: $value);
		}
	}//end getObjectValue()

	/**
	 * Resolve the language list to use for translatable column emission.
	 *
	 * Priority: contextRegister languages → org-wide minimum [nl, en].
	 *
	 * @return string[]
	 */
	private function resolveExportLanguages(): array {
		if ($this->contextRegister !== null) {
			$registerLanguages = $this->contextRegister->getLanguages();
			if (is_array($registerLanguages) === true && count($registerLanguages) > 0) {
				return array_values(array_unique($registerLanguages));
			}
		}

		return ['nl', 'en'];
	}//end resolveExportLanguages()

	/**
	 * Extract `objectData[field][lang]` for a `field_lang` header.
	 *
	 * Returns null when the header doesn't match a known
	 * translatable-property + language pair.
	 *
	 * @param array<string, mixed> $objectData The object data.
	 * @param string $header The header name (field_lang).
	 *
	 * @return string|null The language slot value, or null if not present.
	 */
	private function extractLanguageSlot(array $objectData, string $header): ?string {
		$underscore = strrpos($header, '_');
		if ($underscore === false || $underscore === 0) {
			return null;
		}

		$field = substr($header, 0, $underscore);
		$long = substr($header, $underscore + 1);
		if ($field === '' || $long === ''
			|| preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,15}$/', $long) !== 1
		) {
			return null;
		}

		$value = $objectData[$field] ?? null;
		if (is_array($value) === false || isset($value[$long]) === false) {
			return null;
		}

		$slotValue = $value[$long];
		if (is_scalar($slotValue) === true) {
			return (string)$slotValue;
		}

		return null;
	}//end extractLanguageSlot()

	/**
	 * Convert a value to a string representation
	 *
	 * @param mixed $value The value to convert
	 *
	 * @return string|null
	 */
	private function convertValueToString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if (is_array($value) === true) {
			// Convert array to JSON string.
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if (is_object($value) === true) {
			if (method_exists(object_or_class: $value, method: '__toString') === true) {
				return (string)$value;
			}

			// Convert object to JSON string.
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		// Fallback for any other type.
		return (string)$value;
	}//end convertValueToString()

	/**
	 * Check if a schema property contains UUID references to other objects
	 *
	 * Detects relation properties by checking for:
	 * - format: 'uuid' (single UUID reference)
	 * - $ref field (JSON Schema reference to another schema)
	 * - Array items with format: 'uuid' or $ref (array of UUID references)
	 *
	 * @param array $property The schema property definition
	 *
	 * @return bool True if the property contains UUID references
	 */
	private function isRelationProperty(array $property): bool {
		$format = $property['format'] ?? '';
		$ref = $property['$ref'] ?? '';
		$type = $property['type'] ?? '';

		// Single UUID reference: format is 'uuid' or has a non-empty $ref.
		if ($format === 'uuid' || (empty($ref) === false)) {
			return true;
		}

		// Array of UUID references: items have format 'uuid' or non-empty $ref.
		if ($type === 'array' && isset($property['items']) === true) {
			$items = $property['items'];
			$itemFormat = $items['format'] ?? '';
			$itemRef = $items['$ref'] ?? '';

			if ($itemFormat === 'uuid' || (empty($itemRef) === false)) {
				return true;
			}
		}

		return false;
	}//end isRelationProperty()

	/**
	 * Collect UUIDs from a property value into a flat array
	 *
	 * Handles both single UUID strings and arrays/JSON arrays of UUIDs.
	 *
	 * @param mixed $value The property value (string, array, or JSON string).
	 * @param array $allUuids The array to collect UUIDs into (passed by reference).
	 *
	 * @return void
	 */
	private function collectUuids(mixed $value, array &$allUuids): void {
		if (is_string($value) === true) {
			// Try to decode as JSON array first.
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				foreach ($decoded as $item) {
					if (is_string($item) === true && empty($item) === false) {
						$allUuids[] = $item;
					}
				}

				return;
			}

			// Single UUID string.
			if (empty($value) === false) {
				$allUuids[] = $value;
			}

			return;
		}

		if (is_array($value) === true) {
			foreach ($value as $item) {
				if (is_string($item) === true && empty($item) === false) {
					$allUuids[] = $item;
				}
			}
		}
	}//end collectUuids()

	/**
	 * Resolve UUIDs in a value to human-readable names
	 *
	 * Preserves the same format as the input:
	 * - Single UUID string → single name string
	 * - Array of UUIDs → JSON array of names
	 * - JSON-encoded array of UUIDs → JSON-encoded array of names
	 *
	 * Falls back to the UUID itself if no name is found in the map.
	 *
	 * @param mixed $value The original value containing UUID(s)
	 * @param array $uuidToNameMap Map of UUID → name from bulk resolution
	 *
	 * @return string|null The resolved name(s) in the same format as input
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveUuidsToNames(mixed $value, array $uuidToNameMap): ?string {
		if ($value === null) {
			return null;
		}

		if (is_string($value) === true) {
			// Try to decode as JSON array first.
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $this->namesOfList(items: $decoded, uuidToNameMap: $uuidToNameMap);
			}

			// Single UUID string → single name.
			return $uuidToNameMap[$value] ?? $value;
		}//end if

		if (is_array($value) === true) {
			return $this->namesOfList(items: $value, uuidToNameMap: $uuidToNameMap);
		}

		return $this->convertValueToString(value: $value);
	}//end resolveUuidsToNames()

	/**
	 * A list of UUIDs as a JSON array of names; empty strings are dropped.
	 *
	 * @param array $items         The UUIDs and other values.
	 * @param array $uuidToNameMap Map of UUID to name.
	 *
	 * @return string The JSON array of names.
	 */
	private function namesOfList(array $items, array $uuidToNameMap): string {
		$names = [];
		foreach ($items as $item) {
			if ($item === '') {
				continue;
			}

			if (is_string($item) === true) {
				$names[] = ($uuidToNameMap[$item] ?? $item);
				continue;
			}

			$names[] = $this->convertValueToString(value: $item);
		}

		return (string)json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}//end namesOfList()

	/**
	 * Get all schemas for a register
	 *
	 * @param Register $register The register to get schemas for
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 */
	private function getSchemasForRegister(Register $register): array {
		return $this->registerMapper->getSchemasByRegisterId($register->getId());
	}//end getSchemasForRegister()
}//end class
