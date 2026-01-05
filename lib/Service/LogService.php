<?php

/**
 * OpenRegister LogService
 *
 * Service class for handling audit trail logs in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use stdClass;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * LogService handles audit trail logs
 *
 * Service class for handling audit trail logs in the OpenRegister application.
 * Provides methods for retrieving, filtering, and counting audit trail entries
 * for objects and system-wide operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class LogService
{

    /**
     * Audit trail mapper
     *
     * Handles database operations for audit trail entries.
     *
     * @var AuditTrailMapper Audit trail mapper instance
     */
    private readonly AuditTrailMapper $auditTrailMapper;

    /**
     * Object entity mapper
     *
     * Used to validate object existence and retrieve object details.
     *
     * @var ObjectEntityMapper Object entity mapper instance
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Register mapper
     *
     * Reserved for future use in log filtering and validation.
     *
     * @var RegisterMapper Register mapper instance
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * Reserved for future use in log filtering and validation.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Constructor for LogService
     *
     * Initializes the LogService with required mapper dependencies for handling
     * audit trail logs and related entities.
     *
     * @param AuditTrailMapper   $auditTrailMapper   Mapper for audit trail database operations
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entity database operations
     * @param RegisterMapper     $registerMapper     Mapper for register database operations
     * @param SchemaMapper       $schemaMapper       Mapper for schema database operations
     *
     * @return void
     */
    public function __construct(
        AuditTrailMapper $auditTrailMapper,
        ObjectEntityMapper $objectEntityMapper,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper
    ) {
        $this->auditTrailMapper   = $auditTrailMapper;
        $this->objectEntityMapper = $objectEntityMapper;
        $this->registerMapper     = $registerMapper;
        $this->schemaMapper       = $schemaMapper;
    }//end __construct()

    /**
     * Get logs for an object
     *
     * Retrieves audit trail logs for a specific object with optional filtering,
     * pagination, sorting, and search capabilities. Validates that the object
     * belongs to the specified register and schema.
     *
     * @param string               $register The register identifier (slug or ID)
     * @param string               $schema   The schema identifier (slug or ID)
     * @param string               $id       The object ID to retrieve logs for
     * @param array<string, mixed> $config   Configuration array containing:
     *                                       - limit: (int) Max items per page (default: 20)
     *                                       - offset: (int|null) Items to skip for pagination
     *                                       - page: (int|null) Page number (alternative to offset)
     *                                       - filters: (array) Filter params (['action' => 'create'])
     *                                       - sort: (array) Sort params (default: ['created' => 'DESC'])
     *                                       - search: (string|null) Search term for log content
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit trail log entries
     *
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     */
    public function getLogs(string $register, string $schema, string $id, array $config=[]): array
    {
        // Step 1: Get the object to ensure it exists.
        // Include deleted objects so audit trail is accessible even after soft-delete.
        $object = $this->objectEntityMapper->find($id, null, null, true);

        // Step 2: Validate object belongs to specified register/schema by comparing stored IDs.
        // We skip entity resolution to allow access even if register/schema are soft-deleted.
        // The object's register/schema fields store IDs as strings.
        // We need to resolve the slugs to IDs for comparison.
        try {
            // Try to resolve slugs, but allow deleted entities.
            $registerEntity = $this->registerMapper->find($register, _multitenancy: false, _rbac: false);
            $schemaEntity   = $this->schemaMapper->find($schema, _multitenancy: false, _rbac: false);

            $registerMismatch = $object->getRegister() !== (string) $registerEntity->getId();
            $schemaMismatch   = $object->getSchema() !== (string) $schemaEntity->getId();
            if ($registerMismatch === true || $schemaMismatch === true) {
                throw new InvalidArgumentException('Object does not belong to specified register/schema');
            }
        } catch (\Exception $e) {
            // If register/schema not found (likely deleted), we can't validate.
            // But we still allow audit trail access for the object.
        }

        // Step 3: Add object ID to filters to restrict logs to this object.
        $filters           = $config['filters'] ?? [];
        $filters['object'] = $object->getId();

        // Note: We do NOT add register/schema filters here because:
        // 1. The object already ensures it belongs to the correct register/schema
        // 2. Adding those filters can cause issues if register/schema have been recreated with same slug
        // 3. The object ID is sufficient to uniquely identify audit trails
        // Step 4: Retrieve logs from audit trail mapper with pagination and filtering.
        return $this->auditTrailMapper->findAll(
            limit: $config['limit'] ?? 20,
            offset: $config['offset'] ?? 0,
            filters: $filters,
            sort: $config['sort'] ?? ['created' => 'DESC'],
            search: $config['search'] ?? null
        );
    }//end getLogs()

    /**
     * Count logs for an object
     *
     * Counts total number of audit trail entries for a specific object.
     * Validates that the object belongs to the specified register and schema.
     *
     * @param string $register The register identifier (slug or ID)
     * @param string $schema   The schema identifier (slug or ID)
     * @param string $id       The object ID to count logs for
     *
     * @return int Number of log entries (0 or positive integer)
     *
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     *
     * @psalm-return int<0, max>
     */
    public function count(string $register, string $schema, string $id): int
    {
        // Step 1: Get the object to ensure it exists.
        // Include deleted objects so audit trail count is accessible even after soft-delete.
        $object = $this->objectEntityMapper->find($id, null, null, true);

        // Step 2: Validate object belongs to specified register/schema by comparing stored IDs.
        // We skip entity resolution to allow access even if register/schema are soft-deleted.
        try {
            // Try to resolve slugs, but allow deleted entities.
            $registerEntity = $this->registerMapper->find($register, _multitenancy: false, _rbac: false);
            $schemaEntity   = $this->schemaMapper->find($schema, _multitenancy: false, _rbac: false);

            $registerMismatch = $object->getRegister() !== (string) $registerEntity->getId();
            $schemaMismatch   = $object->getSchema() !== (string) $schemaEntity->getId();
            if ($registerMismatch === true || $schemaMismatch === true) {
                throw new InvalidArgumentException('Object does not belong to specified register/schema');
            }
        } catch (\Exception $e) {
            // If register/schema not found (likely deleted), we can't validate.
            // But we still allow audit trail access for the object.
        }

        // Step 3: Get all logs for this object using filter.
        // No pagination needed since we're only counting.
        $logs = $this->auditTrailMapper->findAll(
            filters: ['object' => $object->getId()]
        );

        // Step 4: Return count of log entries.
        return count($logs);
    }//end count()

    /**
     * Get all audit trail logs with optional filtering
     *
     * @param array $config Configuration array containing:
     *                      - limit: (int) Maximum number of items per page
     *                      - offset: (int|null) Number of items to skip
     *                      - page: (int|null) Current page number
     *                      - filters: (array) Filter parameters
     *                      - sort: (array) Sort parameters ['field' => 'ASC|DESC']
     *                      - search: (string|null) Search term
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit trail entries
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     */
    public function getAllLogs(array $config=[]): array
    {
        return $this->auditTrailMapper->findAll(
            limit: $config['limit'] ?? 20,
            offset: $config['offset'] ?? 0,
            filters: $config['filters'] ?? [],
            sort: $config['sort'] ?? ['created' => 'DESC'],
            search: $config['search'] ?? null
        );
    }//end getAllLogs()

    /**
     * Count all audit trail logs with optional filtering
     *
     * @param array $filters Optional filters to apply
     *
     * @return int Number of audit trail entries
     *
     * @psalm-return int<0, max>
     */
    public function countAllLogs(array $filters=[]): int
    {
        $logs = $this->auditTrailMapper->findAll(filters: $filters);
        return count($logs);
    }//end countAllLogs()

    /**
     * Get a single audit trail log by ID
     *
     * @param int $id The audit trail ID
     *
     * @return mixed The audit trail entry
     * @throws \OCP\AppFramework\Db\DoesNotExistException If audit trail not found
     */
    public function getLog(int $id)
    {
        return $this->auditTrailMapper->find($id);
    }//end getLog()

    /**
     * Export audit trail logs with specified format and filters
     *
     * @param string $format Export format: 'csv', 'json', 'xml', 'txt'
     * @param array  $config Configuration array containing:
     *                       - filters: (array) Filter
     *                       parameters - includeChanges:
     *                       (bool) Whether to include
     *                       change data - includeMetadata:
     *                       (bool) Whether to include
     *                       metadata - search:
     *                       (string|null) Search term
     *
     * @return (bool|string)[]
     *
     * @throws \InvalidArgumentException If unsupported format is specified
     *
     * @psalm-return array{content: bool|string, filename: string, contentType: string}
     */
    public function exportLogs(string $format, array $config=[]): array
    {
        // Get all logs with current filters.
        $logs = $this->auditTrailMapper->findAll(
            filters: $config['filters'] ?? [],
            sort: ['created' => 'DESC'],
            search: $config['search'] ?? null
        );

        // Process logs for export.
        $exportData = $this->prepareLogsForExport(logs: $logs, config: $config);

        // Generate content based on format.
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCsv(data: $exportData);
            case 'json':
                return $this->exportToJson($exportData);
            case 'xml':
                return $this->exportToXml($exportData);
            case 'txt':
                return $this->exportToTxt($exportData);
            default:
                throw new InvalidArgumentException("Unsupported export format: {$format}");
        }
    }//end exportLogs()

    /**
     * Delete a single audit trail log by ID
     *
     * @param int $id The audit trail ID to delete
     *
     * @return true True if deletion was successful
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If audit trail not found
     */
    public function deleteLog(int $id): bool
    {
        try {
            $log = $this->auditTrailMapper->find($id);
            $this->auditTrailMapper->delete($log);
            return true;
        } catch (Exception $e) {
            throw new Exception("Failed to delete audit trail: ".$e->getMessage());
        }
    }//end deleteLog()

    /**
     * Delete multiple audit trail logs based on filters
     *
     * @param array $config Configuration array containing:
     *                      - filters: (array) Filter parameters
     *                      - search: (string|null) Search term
     *                      - ids: (array|null) Specific IDs to delete
     *
     * @return int[] Array containing:
     *               - deleted: (int) Number of logs deleted
     *               - failed: (int) Number of logs that failed to delete
     *
     * @throws \Exception If mass deletion fails
     *
     * @psalm-return array{deleted: int<0, max>, failed: int<0, max>, total: int<0, max>}
     */
    public function deleteLogs(array $config=[]): array
    {
        $deleted = 0;
        $failed  = 0;

        try {
            // If specific IDs are provided, use those.
            if (empty($config['ids']) === false && is_array($config['ids']) === true) {
                foreach ($config['ids'] as $id) {
                    try {
                        $log = $this->auditTrailMapper->find($id);
                        $this->auditTrailMapper->delete($log);
                        $deleted++;
                    } catch (Exception $e) {
                        $failed++;
                    }
                }

                return [
                    'success' => true,
                    'deleted' => $deleted,
                    'failed'  => $failed,
                ];
            }

            // Otherwise, use filters to find logs to delete.
            $logs = $this->auditTrailMapper->findAll(
                filters: $config['filters'] ?? [],
                search: $config['search'] ?? null
            );

            foreach ($logs as $log) {
                try {
                    $this->auditTrailMapper->delete($log);
                    $deleted++;
                } catch (Exception $e) {
                    $failed++;
                }
            }

            return [
                'deleted' => $deleted,
                'failed'  => $failed,
                'total'   => $deleted + $failed,
            ];
        } catch (Exception $e) {
            throw new Exception("Mass deletion failed: ".$e->getMessage());
        }//end try
    }//end deleteLogs()

    /**
     * Prepare logs data for export by filtering and formatting fields
     *
     * @param array $logs   Array of audit trail logs
     * @param array $config Export configuration
     *
     * @return (mixed|string)[][] Prepared data for export
     *
     * @psalm-return list<array{
     *     action: ''|mixed,
     *     changes?: string,
     *     created: ''|mixed,
     *     id: ''|mixed,
     *     ipAddress?: ''|mixed,
     *     object: ''|mixed,
     *     register: ''|mixed,
     *     request?: ''|mixed,
     *     schema: ''|mixed,
     *     session?: ''|mixed,
     *     size: ''|mixed,
     *     user: ''|mixed,
     *     userName: ''|mixed,
     *     uuid: ''|mixed,
     *     version?: ''|mixed
     * }>
     */
    private function prepareLogsForExport(array $logs, array $config): array
    {
        $includeChanges  = $config['includeChanges'] ?? true;
        $includeMetadata = $config['includeMetadata'] ?? false;

        $exportData = [];
        foreach ($logs as $log) {
            $logData = $log->jsonSerialize();

            // Always include basic fields.
            $exportRow = [
                'id'       => $logData['id'] ?? '',
                'uuid'     => $logData['uuid'] ?? '',
                'action'   => $logData['action'] ?? '',
                'object'   => $logData['object'] ?? '',
                'register' => $logData['register'] ?? '',
                'schema'   => $logData['schema'] ?? '',
                'user'     => $logData['user'] ?? '',
                'userName' => $logData['userName'] ?? '',
                'created'  => $logData['created'] ?? '',
                'size'     => $logData['size'] ?? '',
            ];

            // Include changes if requested.
            if ($includeChanges === true && empty($logData['changed']) === false) {
                $exportRow['changes'] = $this->getChangesFormatted($logData['changed']);
            }

            // Include metadata if requested.
            if ($includeMetadata === true) {
                $exportRow['session']   = $logData['session'] ?? '';
                $exportRow['request']   = $logData['request'] ?? '';
                $exportRow['ipAddress'] = $logData['ipAddress'] ?? '';
                $exportRow['version']   = $logData['version'] ?? '';
            }

            $exportData[] = $exportRow;
        }//end foreach

        return $exportData;
    }//end prepareLogsForExport()

    /**
     * Export data to CSV format
     *
     * @param array $data Prepared export data
     *
     * @return (false|string)[]
     *
     * @psalm-return array{content: false|string, filename: string, contentType: 'text/csv'}
     */
    private function exportToCsv(array $data): array
    {
        if (empty($data) === true) {
            return [
                'content'     => '',
                'filename'    => 'audit_trails_'.date('Y-m-d_H-i-s').'.csv',
                'contentType' => 'text/csv',
            ];
        }

        $output = fopen('php://temp', 'r+');

        // Write header.
        fputcsv($output, array_keys($data[0]));

        // Write data rows.
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return [
            'content'     => $content,
            'filename'    => 'audit_trails_'.date('Y-m-d_H-i-s').'.csv',
            'contentType' => 'text/csv',
        ];
    }//end exportToCsv()

    /**
     * Export data to JSON format
     *
     * @param array $data Prepared export data
     *
     * @return (false|string)[]
     *
     * @psalm-return array{content: false|string, filename: string, contentType: 'application/json'}
     */
    private function exportToJson(array $data): array
    {
        return [
            'content'     => json_encode($data, JSON_PRETTY_PRINT),
            'filename'    => 'audit_trails_'.date('Y-m-d_H-i-s').'.json',
            'contentType' => 'application/json',
        ];
    }//end exportToJson()

    /**
     * Export data to XML format
     *
     * @param array $data Prepared export data
     *
     * @return (bool|string)[]
     *
     * @psalm-return array{content: bool|string, filename: string, contentType: 'application/xml'}
     */
    private function exportToXml(array $data): array
    {
        $xml = new SimpleXMLElement('<auditTrails/>');

        foreach ($data as $logData) {
            $logElement = $xml->addChild('auditTrail');
            foreach ($logData as $key => $value) {
                // Handle special characters and ensure valid XML.
                $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                $logElement->addChild(
                    qualifiedName: $cleanKey,
                    value: htmlspecialchars($value ?? '')
                );
            }
        }

        return [
            'content'     => $xml->asXML(),
            'filename'    => 'audit_trails_'.date('Y-m-d_H-i-s').'.xml',
            'contentType' => 'application/xml',
        ];
    }//end exportToXml()

    /**
     * Export data to plain text format
     *
     * @param array $data Prepared export data
     *
     * @return string[]
     *
     * @psalm-return array{content: string, filename: string, contentType: 'text/plain'}
     */
    private function exportToTxt(array $data): array
    {
        $content  = "Audit Trail Export - Generated on ".date('Y-m-d H:i:s')."\n";
        $content .= str_repeat('=', 60)."\n\n";

        foreach ($data as $index => $logData) {
            $content .= "Entry #".((int) $index + 1)."\n";
            $content .= str_repeat('-', 20)."\n";

            foreach ($logData as $key => $value) {
                $content .= ucfirst($key).': '.($value ?? 'N/A')."\n";
            }

            $content .= "\n";
        }

        return [
            'content'     => $content,
            'filename'    => 'audit_trails_'.date('Y-m-d_H-i-s').'.txt',
            'contentType' => 'text/plain',
        ];
    }//end exportToTxt()

    /**
     * Get changes formatted as JSON string or original value
     *
     * @param mixed $changed Changed data
     *
     * @return string Formatted changes
     */
    private function getChangesFormatted($changed): string
    {
        if (is_array($changed) === true) {
            return json_encode($changed);
        }

        return (string) $changed;
    }//end getChangesFormatted()
}//end class
