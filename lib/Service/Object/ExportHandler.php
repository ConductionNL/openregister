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
use RuntimeException;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Export operations require complex format handling
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Export requires coordination with multiple services
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
     * @return array Export result with content, filename, and mimetype.
     *
     * @throws \Exception If export fails.
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

                return [
                    'content'  => $content,
                    'filename' => "{$filenameBase}.csv",
                    'mimetype' => 'text/csv',
                ];
            }

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
     * @return array Import result with created, updated, errors, and performance stats.
     *
     * @throws \Exception If import fails.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Import options require multiple boolean flags for configuration
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple file type handlers require conditional branching
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Import orchestration requires comprehensive error handling
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
            }//end if

            if ($result === null) {
                throw new InvalidArgumentException("Unsupported file type: {$extension}");
            }

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
     * @return array Download result with content, filename, and mimetype.
     *
     * @throws \Exception If download fails.
     */
    public function downloadObjectFiles(string $objectId)
    {
        $this->logger->info(
            message: '[ExportHandler] Starting file download',
            context: ['object_id' => $objectId]
        );

        try {
            // Find object.
            $object = $this->objectEntityMapper->find((int) $objectId);
            // Find() throws DoesNotExistException, never returns null.
            // TODO: Implement file download when FileService methods are available.
            // getObjectDirectory() and createZipFromDirectory() are not yet implemented.
            $message  = 'File download not yet implemented - FileService::getObjectDirectory() and ';
            $message .= 'FileService::createZipFromDirectory() not available. Object ID: '.$objectId;
            throw new RuntimeException($message);

            // Original implementation (commented out until FileService methods exist):
            // $objectDir = $this->fileService->getObjectDirectory(object: $object);
            // if (is_dir($objectDir) === false) {
            // throw new Exception('Object has no files');
            // }
            // $zipPath = $this->fileService->createZipFromDirectory(directory: $objectDir);
            // Suppress unused variable warning - $object needed when FileService is implemented.
            unset($object);
            $zipPath = '';

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
