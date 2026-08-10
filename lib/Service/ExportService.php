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

use DateTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use React\EventLoop\Loop;
use RuntimeException;

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
class ExportService
{

    /**
     * Maximum number of objects a single PDF export may render.
     *
     * PDF rendering (Dompdf) builds a full in-memory HTML/CSS box-tree per
     * row, unlike the streaming CSV writer or PhpSpreadsheet's XLSX writer,
     * making it meaningfully more memory-heavy per row. Requests whose
     * object count exceeds this cap MUST fail fast with
     * {@see ExportTooLargeException} before any HTML construction or
     * Dompdf rendering begins.
     *
     * @var int
     */
    public const MAX_PDF_EXPORT_ROWS = 5000;

    /**
     * Register mapper instance
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Group manager for checking admin group membership
     *
     * @var IGroupManager
     */
    private readonly IGroupManager $groupManager;

    /**
     * Object service for optimized object operations
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Cache handler for UUID-to-name resolution
     *
     * @var CacheHandler
     */
    private readonly CacheHandler $cacheHandler;

    /**
     * Property RBAC handler for property-level authorization checks
     *
     * @var PropertyRbacHandler
     */
    private readonly PropertyRbacHandler $propertyRbacHandler;

    /**
     * Translation handler for column projection during export.
     *
     * @var \OCA\OpenRegister\Service\Object\TranslationHandler
     */
    private readonly \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler;

    /**
     * Optional register context used during sheet population.
     *
     * @var Register|null
     */
    private ?Register $contextRegister = null;

    /**
     * Constructor for the ExportService.
     *
     * @param RegisterMapper                                      $registerMapper      The register mapper.
     * @param IUserManager                                        $_userManager        The user manager (unused).
     * @param IGroupManager                                       $groupManager        The group manager.
     * @param ObjectService                                       $objectService       The object service.
     * @param CacheHandler                                        $cacheHandler        The cache handler for name resolution.
     * @param PropertyRbacHandler                                 $propertyRbacHandler The property RBAC handler.
     * @param \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler  The translation handler.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        RegisterMapper $registerMapper,
        IUserManager $_userManager,
        IGroupManager $groupManager,
        ObjectService $objectService,
        CacheHandler $cacheHandler,
        PropertyRbacHandler $propertyRbacHandler,
        \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler
    ) {
        $this->registerMapper      = $registerMapper;
        $this->groupManager        = $groupManager;
        $this->objectService       = $objectService;
        $this->cacheHandler        = $cacheHandler;
        $this->propertyRbacHandler = $propertyRbacHandler;
        $this->translationHandler  = $translationHandler;
    }//end __construct()

    /**
     * Check if the given user is in the admin group
     *
     * @param IUser|null $user The user to check (null means anonymous/no user)
     *
     * @return bool True if user is admin, false otherwise
     */
    private function isUserAdmin(?IUser $user): bool
    {
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
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return Spreadsheet
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Export requires handling multiple input combinations
     *
     * @spec openspec/specs/data-import-export/spec.md
     * @spec openspec/specs/data-import-export/spec.md
     */
    public function exportToExcel(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): Spreadsheet {
        // Create new spreadsheet.
        $spreadsheet = new Spreadsheet();

        // Remove default sheet.
        $spreadsheet->removeSheetByIndex(0);

        if ($register !== null && $schema === null) {
            // Export all schemas in register.
            $schemas = $this->getSchemasForRegister(register: $register);
            foreach ($schemas as $schema) {
                $this->populateSheet(
                    spreadsheet: $spreadsheet,
                    register: $register,
                    schema: $schema,
                    filters: $filters,
                    currentUser: $currentUser
                );
            }

            return $spreadsheet;
        }

        // Export single schema.
        $this->populateSheet(
            spreadsheet: $spreadsheet,
            register: $register,
            schema: $schema,
            filters: $filters,
            currentUser: $currentUser
        );

        return $spreadsheet;
    }//end exportToExcel()

    /**
     * Export data to CSV format
     *
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return string CSV content
     *
     * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    public function exportToCsv(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): string {
        if ($register !== null && $schema === null) {
            throw new InvalidArgumentException('Cannot export multiple schemas to CSV format.');
        }

        $spreadsheet = $this->exportToExcel(
            register: $register,
            schema: $schema,
            filters: $filters,
            currentUser: $currentUser
        );
        $writer      = new Csv($spreadsheet);

        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }//end exportToCsv()

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
     * @param Schema|null   $schema   Schema whose objects are exported (required)
     * @param array         $filters  Optional `@self.*` metadata filters
     *
     * @return string Pretty-printed JSON array of objects
     *
     * @throws InvalidArgumentException When no schema is given (JSON export is single-schema, like CSV)
     *
     * @spec exclude Retrofit — JSON object export added alongside the existing Excel/CSV exporters; no dedicated openspec change.
     */
    public function exportToJson(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[]
    ): string {
        if ($schema === null) {
            throw new InvalidArgumentException('JSON export requires a specific schema.');
        }

        $objects = $this->fetchObjectsForExport(register: $register, schema: $schema, filters: $filters);

        $rows = array_map(
            static fn(ObjectEntity $object): array => $object->jsonSerialize(),
            $objects
        );

        return json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }//end exportToJson()

    /**
     * Export data to PDF format
     *
     * Renders the same tabular data the CSV/Excel exporters produce into an
     * A4-landscape PDF via Dompdf (already an OpenRegister dependency, used
     * by `PdfReportWriter`). Reuses the existing column/data-extraction
     * pipeline (`fetchObjectsForExport()`, `getHeaders()`,
     * `identifyNameCompanionColumns()`, `resolveUuidNameMap()`,
     * `getObjectValue()`, `resolveUuidsToNames()`) — only the renderer is
     * new. When `$register` is given without `$schema`, one table section
     * per schema is rendered (the PDF analogue of `exportToExcel()`'s
     * one-sheet-per-schema behaviour).
     *
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return string Raw PDF bytes
     *
     * @throws ExportTooLargeException When the object count exceeds {@see self::MAX_PDF_EXPORT_ROWS}.
     *
     * @spec openspec/specs/export-pdf-format/spec.md
     */
    public function exportToPdf(
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): string {
        // Capture register context so getHeaders/getObjectValue can emit
        // per-language columns for translatable properties, same as the
        // Excel/CSV pipeline.
        $this->contextRegister = $register;

        if ($register !== null && $schema === null) {
            // Register-level export without a schema: one section per schema,
            // mirroring exportToExcel()'s one-sheet-per-schema behaviour.
            $sections = $this->buildPdfSectionsForRegister(
                register: $register,
                filters: $filters,
                currentUser: $currentUser
            );

            return $this->renderPdfDocument(sections: $sections);
        }

        $objects = $this->fetchObjectsForExport(register: $register, schema: $schema, filters: $filters);

        $this->guardPdfRowCap(rowCount: count($objects));

        $section = $this->buildPdfSection(
            register: $register,
            schema: $schema,
            objects: $objects,
            currentUser: $currentUser
        );

        return $this->renderPdfDocument(sections: [$section]);
    }//end exportToPdf()

    /**
     * Build one PDF section per schema for a register-level export (no
     * single schema selected), mirroring `exportToExcel()`'s
     * one-sheet-per-schema behaviour. The row-count cap is enforced on the
     * combined total across all schemas before any section HTML is built.
     *
     * @param Register   $register    The register whose schemas are exported.
     * @param array      $filters     Optional filters to apply to each schema's fetch.
     * @param IUser|null $currentUser Current user for permission checks.
     *
     * @return string[] One HTML section fragment per schema.
     *
     * @throws ExportTooLargeException When the combined object count exceeds {@see self::MAX_PDF_EXPORT_ROWS}.
     */
    private function buildPdfSectionsForRegister(Register $register, array $filters, ?IUser $currentUser): array
    {
        $schemas      = $this->getSchemasForRegister(register: $register);
        $sectionInput = [];
        $totalRows    = 0;

        foreach ($schemas as $schemaItem) {
            $objects        = $this->fetchObjectsForExport(register: $register, schema: $schemaItem, filters: $filters);
            $totalRows     += count($objects);
            $sectionInput[] = ['schema' => $schemaItem, 'objects' => $objects];
        }

        $this->guardPdfRowCap(rowCount: $totalRows);

        $sections = [];
        foreach ($sectionInput as $input) {
            $sections[] = $this->buildPdfSection(
                register: $register,
                schema: $input['schema'],
                objects: $input['objects'],
                currentUser: $currentUser
            );
        }

        return $sections;
    }//end buildPdfSectionsForRegister()

    /**
     * Guard the PDF row-count cap.
     *
     * Extracted as its own method (rather than inlined at each call site)
     * so the boundary condition can be unit-tested directly without
     * exercising the expensive Dompdf render path — Dompdf's table layout
     * (`Cellmap`) has real per-row memory cost, so tests that need to
     * assert "N rows over the cap throws" or "N rows at the cap doesn't
     * throw" should call this method directly rather than rendering
     * thousands of rows just to observe whether an exception was thrown.
     *
     * @param int $rowCount The object count to check against {@see self::MAX_PDF_EXPORT_ROWS}.
     *
     * @return void
     *
     * @throws ExportTooLargeException When `$rowCount` exceeds the configured cap.
     */
    private function guardPdfRowCap(int $rowCount): void
    {
        if ($rowCount > self::MAX_PDF_EXPORT_ROWS) {
            throw new ExportTooLargeException(rowCount: $rowCount, maxRows: self::MAX_PDF_EXPORT_ROWS);
        }
    }//end guardPdfRowCap()

    /**
     * Build the escaped HTML for a single schema's export section.
     *
     * @param Register|null  $register    Optional register context (used for the title only).
     * @param Schema|null    $schema      Optional schema context.
     * @param ObjectEntity[] $objects     The already-fetched, already-capped object set for this section.
     * @param IUser|null     $currentUser Current user for permission checks (drives header visibility).
     *
     * @return string Escaped HTML for the section (title, meta line, table).
     */
    private function buildPdfSection(
        ?Register $register,
        ?Schema $schema,
        array $objects,
        ?IUser $currentUser
    ): string {
        $headers       = $this->getHeaders(schema: $schema, currentUser: $currentUser);
        $nameColumns   = $this->identifyNameCompanionColumns(headers: $headers);
        $uuidToNameMap = $this->resolveUuidNameMap(objects: $objects, nameColumns: $nameColumns);

        $titleParts = [];
        if ($register !== null) {
            $titleParts[] = $register->getTitle() ?? $register->getSlug() ?? 'Register';
        }

        if ($schema !== null) {
            $titleParts[] = $schema->getTitle() ?? $schema->getSlug() ?? 'Schema';
        }

        $title = implode(' — ', $titleParts);
        if ($title === '') {
            $title = 'Export';
        }

        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $count     = count($objects);

        $html  = '<div class="pdf-section">';
        $html .= '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>';
        $html .= '<p class="meta">'
            .'Exported: '.htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8')
            .' &middot; Objects: '.$count
            .'</p>';
        $html .= '<table><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>'.htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8').'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($objects as $object) {
            $objectData = $object->getObject();
            $html      .= '<tr>';

            foreach ($headers as $col => $header) {
                $value = $this->getObjectValue(object: $object, header: $header);

                if (isset($nameColumns[$col]) === true) {
                    // This is a companion name column — resolve UUIDs to names,
                    // same as writeObjectRows() does for the Excel/CSV pipeline.
                    $sourceProperty = $nameColumns[$col];
                    $rawValue       = $objectData[$sourceProperty] ?? null;
                    $value          = $this->resolveUuidsToNames(value: $rawValue, uuidToNameMap: $uuidToNameMap);
                }

                $html .= '<td>'.htmlspecialchars($this->truncatePdfCellValue(value: $value), ENT_QUOTES, 'UTF-8').'</td>';
            }

            $html .= '</tr>';
        }//end foreach

        $html .= '</tbody></table></div>';

        return $html;
    }//end buildPdfSection()

    /**
     * Truncate a cell value for PDF display so a single long value cannot
     * blow out the table layout or the render time.
     *
     * @param string|null $value The raw cell value.
     *
     * @return string The value, truncated to 200 characters with an ellipsis if longer.
     */
    private function truncatePdfCellValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $maxLength = 200;
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength).'…';
        }

        return $value;
    }//end truncatePdfCellValue()

    /**
     * Render one or more section HTML fragments into a single A4-landscape PDF.
     *
     * Uses the same Dompdf sandboxing pattern established by
     * `PdfReportWriter`: no remote fetches, no PHP execution. Page numbers
     * are added via `Canvas::page_text()`'s safe `{PAGE_NUM}`/`{PAGE_COUNT}`
     * placeholder substitution — not the PHP-script-in-HTML mechanism,
     * which stays disabled for security.
     *
     * @param string[] $sections Escaped HTML fragments, one per schema section.
     *
     * @return string Raw PDF bytes.
     */
    private function renderPdfDocument(array $sections): string
    {
        $style = '@page { margin: 12mm; }'
            ."body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1a1a; }"
            .'h1 { font-size: 14pt; margin: 0 0 2mm 0; }'
            .'p.meta { font-size: 8pt; color: #555555; margin: 0 0 4mm 0; }'
            .'table { width: 100%; border-collapse: collapse; }'
            .'th, td { border: 0.25pt solid #cccccc; padding: 1.5mm 2mm; text-align: left; word-wrap: break-word; }'
            .'th { background-color: #2c3e50; color: #ffffff; font-weight: bold; }'
            .'tbody tr:nth-child(even) { background-color: #f2f2f2; }'
            .'.pdf-section { page-break-after: always; }'
            .'.pdf-section:last-child { page-break-after: auto; }';

        $body = implode('', $sections);
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'.$style.'</style></head><body>'.$body.'</body></html>';

        $options = new Options();
        // No remote stylesheet/image fetches — keeps the renderer hermetic,
        // same rationale as PdfReportWriter.
        $options->set('isRemoteEnabled', false);
        // PHP execution in templates stays disabled; page numbers use
        // Canvas::page_text() placeholders instead (see below).
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultPaperOrientation', 'landscape');

        // SECURITY: assert sandbox flags didn't drift via a future refactor.
        // Dompdf has a history of SSRF / file-disclosure CVEs
        // (CVE-2022-41343, CVE-2023-23924); these flags are the primary
        // mitigation and must stay false.
        if ($options->getIsRemoteEnabled() !== false || $options->getIsPhpEnabled() !== false) {
            throw new RuntimeException(
                'ExportService PDF sandbox configuration drifted; remote-fetch / PHP execution must remain disabled.'
            );
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font   = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 70,
            $canvas->get_height() - 20,
            'Page {PAGE_NUM} / {PAGE_COUNT}',
            $font,
            8,
            [0.3, 0.3, 0.3]
        );

        $output = $dompdf->output();
        return $output ?? '';
    }//end renderPdfDocument()

    /**
     * Build an empty import template spreadsheet for a schema
     *
     * Generates a spreadsheet that contains only the header row derived from the
     * schema's properties (the same headers `exportToExcel` would emit), with no
     * data rows. The returned spreadsheet can be written to either XLSX or CSV.
     *
     * @param Register|null $register    Optional register context (used for translation column expansion)
     * @param Schema        $schema      Schema whose property keys become the header row
     * @param IUser|null    $currentUser Current user (drives admin metadata column inclusion)
     *
     * @return Spreadsheet Spreadsheet with a single sheet containing only header cells
     *
     * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (builds a header-only
     *       template spreadsheet from a schema's properties, with register-context per-language column expansion)
     */
    public function buildTemplateSpreadsheet(
        ?Register $register,
        Schema $schema,
        ?IUser $currentUser=null
    ): Spreadsheet {
        // Capture register context so getHeaders can emit per-language
        // `field_lang` columns for translatable properties.
        $this->contextRegister = $register;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($schema->getSlug() ?? 'data');

        $headers = $this->getHeaders(schema: $schema, currentUser: $currentUser);
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(coordinate: $col.'1', value: $header);
        }

        return $spreadsheet;
    }//end buildTemplateSpreadsheet()

    /**
     * Render an empty CSV import template for a schema
     *
     * Emits a UTF-8 BOM-prefixed CSV string containing only the header row
     * derived from the schema's properties. Mirrors the BOM convention used
     * by the export pipeline so Excel opens the file with the correct encoding.
     *
     * @param Register|null $register    Optional register context
     * @param Schema        $schema      Schema whose property keys become the header row
     * @param IUser|null    $currentUser Current user (drives admin metadata column inclusion)
     *
     * @return string CSV content with a UTF-8 BOM prefix and a single header row
     *
     * @spec openspec/specs/data-import-export/spec.md#import-templates-must-be-downloadable-per-schema (renders the per-schema
     *       import template as a UTF-8 BOM-prefixed CSV so Excel opens it correctly)
     */
    public function buildTemplateCsv(
        ?Register $register,
        Schema $schema,
        ?IUser $currentUser=null
    ): string {
        $spreadsheet = $this->buildTemplateSpreadsheet(
            register: $register,
            schema: $schema,
            currentUser: $currentUser
        );
        $writer      = new Csv($spreadsheet);
        $writer->setUseBOM(true);

        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }//end buildTemplateCsv()

    /**
     * Populate a worksheet with data
     *
     * Uses a two-pass approach for optimal UUID-to-name resolution:
     * 1. First pass: collect all UUIDs from relation columns across all objects
     * 2. One bulk CacheHandler::getMultipleObjectNames() call
     * 3. Second pass: populate the sheet with data and resolved names
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to populate
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     * @param IUser|null    $currentUser Current user for permission checks
     *
     * @return void
     */
    private function populateSheet(
        Spreadsheet $spreadsheet,
        ?Register $register=null,
        ?Schema $schema=null,
        array $filters=[],
        ?IUser $currentUser=null
    ): void {
        // Capture register context so getHeaders / getObjectValue can
        // emit / read per-language `field_lang` columns for translatable
        // properties (register-i18n Phase 3 wire-in).
        $this->contextRegister = $register;
        $sheet = $spreadsheet->createSheet();

        $sheetTitle = 'data';
        if ($schema !== null) {
            $sheetTitle = $schema->getSlug();
        }

        $sheet->setTitle($sheetTitle);

        $headers = $this->getHeaders(schema: $schema, currentUser: $currentUser);
        $row     = 1;

        // Set headers.
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(coordinate: $col.$row, value: $header);
        }

        $row++;

        // Query all matching objects.
        $objects = $this->fetchObjectsForExport(register: $register, schema: $schema, filters: $filters);

        // Identify which headers are name-companion columns (prefixed with _).
        $nameColumns = $this->identifyNameCompanionColumns(headers: $headers);

        // Bulk resolve UUIDs to names if there are relation columns.
        $uuidToNameMap = $this->resolveUuidNameMap(objects: $objects, nameColumns: $nameColumns);

        // Populate the sheet with data and resolved names.
        $this->writeObjectRows(
            sheet: $sheet,
            objects: $objects,
            headers: $headers,
            nameColumns: $nameColumns,
            uuidToNameMap: $uuidToNameMap,
            startRow: $row
        );
    }//end populateSheet()

    /**
     * Fetch all objects matching the given register, schema and filters for export.
     *
     * Builds the query with RBAC, multi-tenancy and metadata filters, then returns
     * the full result set (high limit, no pagination).
     *
     * @param Register|null $register Optional register to filter by.
     * @param Schema|null   $schema   Optional schema to filter by.
     * @param array         $filters  Additional filters from the request.
     *
     * @return ObjectEntity[] Array of matching object entities.
     */
    private function fetchObjectsForExport(?Register $register, ?Schema $schema, array $filters): array
    {
        // Build filters for MagicMapper->findAll() method.
        $objectFilters = [];

        if ($register !== null) {
            $objectFilters['register'] = $register->getId();
        }

        if ($schema !== null) {
            $objectFilters['schema'] = $schema->getId();
        }

        // Apply additional filters.
        foreach ($filters as $key => $value) {
            if (str_starts_with($key, '@self.') === false) {
                // These are JSON object property filters - not supported by findAll.
                // For now, we'll skip them to get basic functionality working.
                // TODO: Add support for JSON property filtering in MagicMapper.
                continue;
            }

            // Metadata filter - remove @self. prefix.
            $metaField = substr($key, 6);
            $objectFilters[$metaField] = $value;
        }

        // Check if multitenancy was explicitly requested via _multi parameter.
        $multiExplicitlySet = isset($filters['_multi']) || isset($filters['multi']);
        $multitenancy       = true;
        if (isset($filters['_multi']) === true) {
            $multitenancy = filter_var($filters['_multi'], FILTER_VALIDATE_BOOLEAN);
        } else if (isset($filters['multi']) === true) {
            $multitenancy = filter_var($filters['multi'], FILTER_VALIDATE_BOOLEAN);
        }

        // Use ObjectService::searchObjects directly with proper RBAC and multi-tenancy filtering.
        // Set a very high limit to get all objects (export needs all data).
        $query = [
            '@self'                  => $objectFilters,
            '_limit'                 => 999999,
            // Very high limit to get all objects.
            '_includeDeleted'        => false,
            '_multitenancy_explicit' => $multiExplicitlySet,
        ];

        return $this->objectService->searchObjects(
            query: $query,
            _rbac: true,
            // Apply RBAC filtering.
            _multitenancy: $multitenancy,
            // Apply multi-tenancy filtering (respects explicit _multi parameter).
            ids: null,
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
    private function identifyNameCompanionColumns(array $headers): array
    {
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
     * @param ObjectEntity[] $objects     The full set of exported objects.
     * @param array          $nameColumns Map of column letter to source property name.
     *
     * @return array Map of UUID string to human-readable name.
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    private function resolveUuidNameMap(array $objects, array $nameColumns): array
    {
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
        $uniqueUuids   = array_unique($allUuids);
        $externalUuids = array_diff($uniqueUuids, array_keys($uuidToNameMap));

        if (empty($externalUuids) === false) {
            $externalNames = $this->cacheHandler->getMultipleObjectNames(array_values($externalUuids));
            $uuidToNameMap = array_merge($uuidToNameMap, $externalNames);
        }

        return $uuidToNameMap;
    }//end resolveUuidNameMap()

    /**
     * Write object data rows to the spreadsheet.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet         The worksheet to populate.
     * @param ObjectEntity[]                                $objects       The objects to write.
     * @param array                                         $headers       Header map keyed by column letter.
     * @param array                                         $nameColumns   Map of companion name columns.
     * @param array                                         $uuidToNameMap Map of UUID to human-readable name.
     * @param int                                           $startRow      The first data row number.
     *
     * @return void
     */
    private function writeObjectRows(
        $sheet,
        array $objects,
        array $headers,
        array $nameColumns,
        array $uuidToNameMap,
        int $startRow
    ): void {
        $row = $startRow;

        foreach ($objects as $object) {
            $objectData = $object->getObject();

            foreach ($headers as $col => $header) {
                $value = $this->getObjectValue(object: $object, header: $header);
                $sheet->setCellValue(coordinate: $col.$row, value: $value);
                if (isset($nameColumns[$col]) === true) {
                    // This is a companion name column — resolve UUIDs to names.
                    $sourceProperty = $nameColumns[$col];
                    $value          = $objectData[$sourceProperty] ?? null;
                    $sheet->setCellValue(
                        coordinate: $col.$row,
                        value: $this->resolveUuidsToNames(value: $value, uuidToNameMap: $uuidToNameMap)
                    );
                }
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
     * @param Schema|null $schema      Optional schema to export
     * @param IUser|null  $currentUser Current user for permission checks
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
     */
    private function getHeaders(?Schema $schema=null, ?IUser $currentUser=null): array
    {
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
                if ($this->propertyRbacHandler->canReadProperty(
                    schema: $schema,
                    property: $fieldName,
                    object: []
                ) === false
                ) {
                    continue;
                }

                // Translatable property: emit one column per configured
                // language so the CSV round-trips through TranslationCsvCodec.
                // Falls back to ['nl', 'en'] when the register isn't
                // resolvable (org-wide minimum per CLAUDE.md memory).
                if (($properties[$fieldName]['translatable'] ?? false) === true) {
                    $languages = $this->resolveExportLanguages();
                    foreach ($languages as $lang) {
                        $headers[$col] = $fieldName.'_'.$lang;
                        $col++;
                    }

                    continue;
                }

                // Always use the property key as the header to ensure consistent data access.
                $headers[$col] = $fieldName;
                $col++;

                // Insert companion _name column if this property contains UUID references.
                if ($this->isRelationProperty(property: $properties[$fieldName]) === true) {
                    $headers[$col] = '_'.$fieldName;
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
                $headers[$col] = '@self.'.$field;
                $col++;
            }
        }//end if

        return $headers;
    }//end getHeaders()

    /**
     * Get value from object for given header
     *
     * @param ObjectEntity $object The object to get value from
     * @param string       $header The header to get value for
     *
     * @return string|null
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex multi-step value extraction logic
     * @SuppressWarnings(PHPMD.NPathComplexity)       Value extraction requires many conditional type checks
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple header prefix and value type conditions
     */
    private function getObjectValue(ObjectEntity $object, string $header): ?string
    {
        // Handle metadata fields with @self. prefix.
        if (str_starts_with(haystack: $header, needle: '@self.') === true) {
            // Remove the @self. prefix (6 characters).
            $fieldName = substr(string: $header, offset: 6);

            // Get the object array which contains all metadata.
            $objectArray = $object->getObjectArray();

            // Check if the field exists in the object array.
            if (($objectArray[$fieldName] ?? null) !== null) {
                $value = $objectArray[$fieldName];

                // Handle DateTime objects (they come as ISO strings from getObjectArray).
                if (is_string($value) === true
                    && str_contains(haystack: $value, needle: 'T') === true
                    && str_contains(haystack: $value, needle: 'Z') === true
                ) {
                    // Convert ISO 8601 to our preferred format.
                    try {
                        $date = new DateTime($value);
                        return $date->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        // Return as-is if parsing fails.
                        return $value;
                    }
                }

                // Handle arrays and objects.
                if (is_array($value) === true || is_object($value) === true) {
                    return $this->convertValueToString(value: $value);
                }

                // Handle scalar values.
                if ($value !== null) {
                    return (string) $value;
                }

                return null;
            }//end if

            // Fallback for fields that might not exist.
            return null;
        }//end if

        // Handle legacy metadata fields with _ prefix for backward compatibility.
        if (str_starts_with(haystack: $header, needle: '_') === true) {
            // Remove the _ prefix.
            $fieldName = substr(string: $header, offset: 1);

            // Get the object array which contains all metadata.
            $objectArray = $object->getObjectArray();

            // Check if the field exists in the object array.
            if (($objectArray[$fieldName] ?? null) !== null) {
                $value = $objectArray[$fieldName];

                // Handle DateTime objects (they come as ISO strings from getObjectArray).
                if (is_string($value) === true
                    && str_contains(haystack: $value, needle: 'T') === true
                    && str_contains(haystack: $value, needle: 'Z') === true
                ) {
                    // Convert ISO 8601 to our preferred format.
                    try {
                        $date = new DateTime($value);
                        return $date->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        // Return as-is if parsing fails.
                        return $value;
                    }
                }

                // Handle arrays and objects.
                if (is_array($value) === true || is_object($value) === true) {
                    return $this->convertValueToString(value: $value);
                }

                // Handle scalar values.
                if ($value !== null) {
                    return (string) $value;
                }

                return null;
            }//end if

            // Fallback for fields that might not exist.
            return null;
        }//end if

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
                $langValue = $this->extractLanguageSlot(objectData: $objectData, header: $header);
                if ($langValue !== null) {
                    return $langValue;
                }

                $value = $objectData[$header] ?? null;
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
    private function resolveExportLanguages(): array
    {
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
     * @param string               $header     The header name (field_lang).
     *
     * @return string|null The language slot value, or null if not present.
     */
    private function extractLanguageSlot(array $objectData, string $header): ?string
    {
        $underscore = strrpos($header, '_');
        if ($underscore === false || $underscore === 0) {
            return null;
        }

        $field = substr($header, 0, $underscore);
        $lang  = substr($header, $underscore + 1);
        if ($field === '' || $lang === ''
            || preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,15}$/', $lang) !== 1
        ) {
            return null;
        }

        $value = $objectData[$field] ?? null;
        if (is_array($value) === false || isset($value[$lang]) === false) {
            return null;
        }

        $slotValue = $value[$lang];
        if (is_scalar($slotValue) === true) {
            return (string) $slotValue;
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
    private function convertValueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        if (is_array($value) === true) {
            // Convert array to JSON string.
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value) === true) {
            if (method_exists(object_or_class: $value, method: '__toString') === true) {
                return (string) $value;
            }

            // Convert object to JSON string.
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Fallback for any other type.
        return (string) $value;
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
    private function isRelationProperty(array $property): bool
    {
        $format = $property['format'] ?? '';
        $ref    = $property['$ref'] ?? '';
        $type   = $property['type'] ?? '';

        // Single UUID reference: format is 'uuid' or has a non-empty $ref.
        if ($format === 'uuid' || (empty($ref) === false)) {
            return true;
        }

        // Array of UUID references: items have format 'uuid' or non-empty $ref.
        if ($type === 'array' && isset($property['items']) === true) {
            $items      = $property['items'];
            $itemFormat = $items['format'] ?? '';
            $itemRef    = $items['$ref'] ?? '';

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
     * @param mixed $value    The property value (string, array, or JSON string).
     * @param array $allUuids The array to collect UUIDs into (passed by reference).
     *
     * @return void
     */
    private function collectUuids(mixed $value, array &$allUuids): void
    {
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
     * @param mixed $value         The original value containing UUID(s)
     * @param array $uuidToNameMap Map of UUID → name from bulk resolution
     *
     * @return string|null The resolved name(s) in the same format as input
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    private function resolveUuidsToNames(mixed $value, array $uuidToNameMap): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === true) {
            // Try to decode as JSON array first.
            $decoded = json_decode($value, true);
            if (is_array($decoded) === true) {
                $names = array_map(
                    function ($item) use ($uuidToNameMap) {
                        if (is_string($item) === true) {
                            return $uuidToNameMap[$item] ?? $item;
                        }

                        return $this->convertValueToString(value: $item);
                    },
                    $decoded
                );

                return json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Single UUID string → single name.
            return $uuidToNameMap[$value] ?? $value;
        }//end if

        if (is_array($value) === true) {
            $names = array_map(
                function ($item) use ($uuidToNameMap) {
                    if (is_string($item) === true) {
                        return $uuidToNameMap[$item] ?? $item;
                    }

                        return $this->convertValueToString(value: $item);
                },
                $value
            );

            return json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->convertValueToString(value: $value);
    }//end resolveUuidsToNames()

    /**
     * Get all schemas for a register
     *
     * @param Register $register The register to get schemas for
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    private function getSchemasForRegister(Register $register): array
    {
        return $this->registerMapper->getSchemasByRegisterId($register->getId());
    }//end getSchemasForRegister()
}//end class
