<?php

/**
 * Export Handler
 *
 * Handles object export, import, and file download operations.
 * Coordinates between controller and specialized export/import services.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\FileService;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ExportHandler
 *
 * Responsible for coordinating export and import operations.
 *
 * RESPONSIBILITIES:
 * - Export objects to CSV/Excel
 * - Import objects from CSV/Excel
 * - Download object files as ZIP
 * - Coordinate between Export/Import/File services
 *
 * NOTE: This handler is thin by design. Heavy lifting is done by:
 * - ExportService (export logic)
 * - ImportService (import logic)
 * - FileService (file operations)
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class ExportHandler
{
    /**
     * Constructor
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper
     * @param SchemaMapper       $schemaMapper       Schema mapper
     * @param ExportService      $exportService      Export service
     * @param ImportService      $importService      Import service
     * @param FileService        $fileService        File service
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ExportService $exportService,
        private readonly ImportService $importService,
        private readonly FileService $fileService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Export objects to specified format
     *
     * @param Register   $register    Register entity
     * @param Schema     $schema      Schema entity
     * @param array      $filters     Export filters
     * @param string     $type        Export type ('csv' or 'excel')
     * @param IUser|null $currentUser Current user (for RBAC)
     *
     * @return (false|string)[] Export data ['content' => string, 'filename' => string, 'mimetype' => string]
     *
     * @throws \Exception If export fails
     *
     * @psalm-return array{content: false|string, filename: string,
     *     mimetype: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'|
     *     'text/csv'}
     */
    public function export(
        Register $register,
        Schema $schema,
        array $filters,
        string $type='excel',
        ?IUser $currentUser=null
    ): array {
        $this->logger->info(
            message: '[ExportHandler] Starting export',
            context: [
                'register' => $register->getSlug(),
                'schema'   => $schema->getSlug(),
                'type'     => $type,
                'filters'  => array_keys($filters),
            ]
        );

        try {
            // Generate filename base.
            $filenameBase = sprintf(
                '%s_%s_%s',
                $register->getSlug() ?? 'register',
                $schema->getSlug() ?? 'schema',
                (new DateTime())->format('Y-m-d_His')
            );

            // Handle export based on type.
            if ($type === 'csv') {
                $content = $this->exportService->exportToCsv(
                    register: $register,
                    schema: $schema,
                    filters: $filters,
                    currentUser: $currentUser
                );

                $result = [
                    'content'  => $content,
                    'filename' => "{$filenameBase}.csv",
                    'mimetype' => 'text/csv',
                ];
            } else {
                // Default to Excel.
                $spreadsheet = $this->exportService->exportToExcel(
                    register: $register,
                    schema: $schema,
                    filters: $filters,
                    currentUser: $currentUser
                );

                // Create Excel writer and get content.
                $writer = new Xlsx($spreadsheet);
                ob_start();
                $writer->save('php://output');
                $content = ob_get_clean();

                $result = [
                    'content'  => $content,
                    'filename' => "{$filenameBase}.xlsx",
                    'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ];
            }//end if

            $this->logger->info(
                message: '[ExportHandler] Export completed',
                context: [
                    'register' => $register->getSlug(),
                    'schema'   => $schema->getSlug(),
                    'type'     => $type,
                    'filename' => $result['filename'],
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExportHandler] Export failed',
                context: [
                    'register' => $register->getSlug(),
                    'schema'   => $schema->getSlug(),
                    'type'     => $type,
                    'error'    => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end export()

    /**
     * Import objects from uploaded file
     *
     * @param Register    $register     Register entity
     * @param array       $uploadedFile Uploaded file data
     * @param Schema|null $schema       Schema entity (optional for Excel, required for CSV unless auto-detected)
     * @param bool        $validation   Enable validation
     * @param bool        $events       Enable events
     * @param bool        $rbac         Apply RBAC checks
     * @param bool        $multitenancy Apply multitenancy filtering
     * @param bool        $publish      Publish imported objects (Excel only)
     * @param IUser|null  $currentUser  Current user
     *
     * @return (array|int|null|string)[][] Import result with summary
     *
     * @throws \Exception If import fails
     *
     * @psalm-return array<string,
     *     array{created: array, errors: array, found: int, unchanged?: array,
     *     updated: array, deduplication_efficiency?: string,
     *     schema?: array{id: int, title: null|string, slug: null|string}|null,
     *     debug?: array{headers: array<never, never>,
     *     processableHeaders: array<never, never>,
     *     schemaProperties: list<array-key>},
     *     performance?: array{efficiency: 0|float, objectsPerSecond: float,
     *     totalFound: int<0, max>, totalProcessed: int<0, max>,
     *     totalTime: float, totalTimeMs: float}}>
     */
    public function import(
        Register $register,
        array $uploadedFile,
        ?Schema $schema=null,
        bool $validation=false,
        bool $events=false,
        bool $rbac=true,
        bool $multitenancy=true,
        bool $publish=false,
        ?IUser $currentUser=null
    ): array {
        $filename = $uploadedFile['name'] ?? 'unknown';

        $this->logger->info(
            message: '[ExportHandler] Starting import',
            context: [
                'register'     => $register->getSlug(),
                'filename'     => $filename,
                'schema'       => $schema?->getSlug(),
                'validation'   => $validation,
                'events'       => $events,
                'rbac'         => $rbac,
                'multitenancy' => $multitenancy,
            ]
        );

        try {
            // Determine file type.
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $filePath  = $uploadedFile['tmp_name'];

            // For CSV: If no schema provided, get first available schema from register.
            if ($extension === 'csv' && $schema === null) {
                $schemas = $register->getSchemas();
                if (empty($schemas) === true) {
                    throw new InvalidArgumentException('No schema found for register');
                }

                $schemaId = reset($schemas);
                $schema   = $this->schemaMapper->find($schemaId);

                $this->logger->debug(
                    message: '[ExportHandler] Auto-selected schema for CSV import',
                    context: [
                        'schema' => $schema->getSlug(),
                    ]
                );
            }

            // Delegate to ImportService based on file type.
            if (in_array($extension, ['xlsx', 'xls'], true) === true) {
                $result = $this->importService->importFromExcel(
                    filePath: $filePath,
                    register: $register,
                    schema: $schema,
                    validation: $validation,
                    events: $events,
                    _rbac: $rbac,
                    _multitenancy: $multitenancy,
                    publish: $publish,
                    currentUser: $currentUser
                );
            } else if ($extension === 'csv') {
                $result = $this->importService->importFromCsv(
                    filePath: $filePath,
                    register: $register,
                    schema: $schema,
                    validation: $validation,
                    events: $events,
                    _rbac: $rbac,
                    _multitenancy: $multitenancy,
                    publish: $publish,
                    currentUser: $currentUser
                );
            } else {
                throw new InvalidArgumentException("Unsupported file type: {$extension}");
            }//end if

            $this->logger->info(
                message: '[ExportHandler] Import completed',
                context: [
                    'register' => $register->getSlug(),
                    'filename' => $filename,
                    'summary'  => $result['summary'] ?? [],
                ]
            );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExportHandler] Import failed',
                context: [
                    'register' => $register->getSlug(),
                    'filename' => $filename,
                    'error'    => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end import()

    /**
     * Download all files of an object as ZIP
     *
     * Creates a ZIP archive containing all files associated with an object.
     *
     * @param string $objectId Object ID or UUID
     *
     * @return (false|string)[] Download data ['content' => string, 'filename' => string, 'mimetype' => string]
     *
     * @throws \Exception If download fails
     *
     * @psalm-return array{content: false|string, filename: string, mimetype: 'application/zip'}
     */
    public function downloadObjectFiles(string $objectId): array
    {
        $this->logger->info(
            message: '[ExportHandler] Starting file download',
            context: ['object_id' => $objectId]
        );

        try {
            // Find object.
            $object = $this->objectEntityMapper->find((int) $objectId);
            // Find() throws DoesNotExistException, never returns null.
            // Get object directory.
            $objectDir = $this->fileService->getObjectDirectory(object: $object);

            // Check if directory exists and has files.
            if (is_dir($objectDir) === false) {
                throw new Exception('Object has no files');
            }

            // Create ZIP of object files.
            $zipPath = $this->fileService->createZipFromDirectory(directory: $objectDir);

            // Read ZIP content.
            $content = file_get_contents($zipPath);

            // Generate filename.
            $timestamp = (new DateTime())->format('Y-m-d_His');
            $filename  = "object_{$objectId}_files_{$timestamp}.zip";

            // Clean up temporary ZIP.
            unlink($zipPath);

            $this->logger->info(
                message: '[ExportHandler] File download completed',
                context: [
                    'object_id' => $objectId,
                    'filename'  => $filename,
                ]
            );

            return [
                'content'  => $content,
                'filename' => $filename,
                'mimetype' => 'application/zip',
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExportHandler] File download failed',
                context: [
                    'object_id' => $objectId,
                    'error'     => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end downloadObjectFiles()
}//end class
