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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Class LogService
 * Service for handling audit trail logs
 */
class LogService
{


    /**
     * Constructor for LogService
     *
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {

    }//end __construct()


    /**
     * Get logs for an object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     * @param array  $config   Configuration array containing:
     *                         - limit: (int) Maximum number of items per page
     *                         - offset: (int|null) Number of items to skip
     *                         - page: (int|null) Current page number
     *                         - filters: (array) Filter parameters
     *                         - sort: (array) Sort parameters ['field' => 'ASC|DESC']
     *                         - search: (string|null) Search term
     *
     * @return array Array of log entries
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     */
    public function getLogs(string $register, string $schema, string $id, array $config=[]): array
    {
        // Get the object to ensure it exists and belongs to the correct register/schema.
        $object = $this->objectEntityMapper->find($id);

        if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
            throw new \InvalidArgumentException('Object does not belong to specified register/schema');
        }

        // Add object ID to filters.
        $filters           = $config['filters'] ?? [];
        $filters['object'] = $object->getId();

        // Get logs from audit trail mapper.
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
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @return int Number of logs
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     */
    public function count(string $register, string $schema, string $id): int
    {
        // Get the object to ensure it exists and belongs to the correct register/schema.
        $object = $this->objectEntityMapper->find($id);

        if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
            throw new \InvalidArgumentException('Object does not belong to specified register/schema');
        }

        // Get logs using findAll with a filter for the object.
        $logs = $this->auditTrailMapper->findAll(
            filters: ['object' => $object->getId()]
        );

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
     * @return array Array of audit trail entries
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
     * @param string $format  Export format: 'csv', 'json', 'xml', 'txt'
     * @param array  $config  Configuration array containing:
     *                        - filters: (array) Filter parameters
     *                        - includeChanges: (bool) Whether to include change data
     *                        - includeMetadata: (bool) Whether to include metadata
     *                        - search: (string|null) Search term
     *
     * @return array Array containing:
     *               - content: (string) Exported content
     *               - filename: (string) Suggested filename
     *               - contentType: (string) MIME content type
     * @throws \InvalidArgumentException If unsupported format is specified
     */
    public function exportLogs(string $format, array $config = []): array
    {
        // Get all logs with current filters
        $logs = $this->auditTrailMapper->findAll(
            filters: $config['filters'] ?? [],
            sort: ['created' => 'DESC'],
            search: $config['search'] ?? null
        );

        // Process logs for export
        $exportData = $this->prepareLogsForExport($logs, $config);

        // Generate content based on format
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCsv($exportData);
            case 'json':
                return $this->exportToJson($exportData);
            case 'xml':
                return $this->exportToXml($exportData);
            case 'txt':
                return $this->exportToTxt($exportData);
            default:
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }

    }//end exportLogs()


    /**
     * Delete a single audit trail log by ID
     *
     * @param int $id The audit trail ID to delete
     *
     * @return bool True if deletion was successful
     * @throws \OCP\AppFramework\Db\DoesNotExistException If audit trail not found
     */
    public function deleteLog(int $id): bool
    {
        try {
            $log = $this->auditTrailMapper->find($id);
            $this->auditTrailMapper->delete($log);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete audit trail: " . $e->getMessage());
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
     * @return array Array containing:
     *               - deleted: (int) Number of logs deleted
     *               - failed: (int) Number of logs that failed to delete
     * @throws \Exception If mass deletion fails
     */
    public function deleteLogs(array $config = []): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            // If specific IDs are provided, use those
            if (!empty($config['ids']) && is_array($config['ids'])) {
                foreach ($config['ids'] as $id) {
                    try {
                        $log = $this->auditTrailMapper->find($id);
                        $this->auditTrailMapper->delete($log);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                    }
                }
            } else {
                // Otherwise, use filters to find logs to delete
                $logs = $this->auditTrailMapper->findAll(
                    filters: $config['filters'] ?? [],
                    search: $config['search'] ?? null
                );

                foreach ($logs as $log) {
                    try {
                        $this->auditTrailMapper->delete($log);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                    }
                }
            }

            return [
                'deleted' => $deleted,
                'failed' => $failed,
                'total' => $deleted + $failed
            ];
        } catch (\Exception $e) {
            throw new \Exception("Mass deletion failed: " . $e->getMessage());
        }

    }//end deleteLogs()


    /**
     * Prepare logs data for export by filtering and formatting fields
     *
     * @param array $logs   Array of audit trail logs
     * @param array $config Export configuration
     *
     * @return array Prepared data for export
     */
    private function prepareLogsForExport(array $logs, array $config): array
    {
        $includeChanges = $config['includeChanges'] ?? true;
        $includeMetadata = $config['includeMetadata'] ?? false;

        $exportData = [];
        foreach ($logs as $log) {
            $logData = $log->jsonSerialize();
            
            // Always include basic fields
            $exportRow = [
                'id' => $logData['id'] ?? '',
                'uuid' => $logData['uuid'] ?? '',
                'action' => $logData['action'] ?? '',
                'object' => $logData['object'] ?? '',
                'register' => $logData['register'] ?? '',
                'schema' => $logData['schema'] ?? '',
                'user' => $logData['user'] ?? '',
                'userName' => $logData['userName'] ?? '',
                'created' => $logData['created'] ?? '',
                'size' => $logData['size'] ?? '',
            ];

            // Include changes if requested
            if ($includeChanges && !empty($logData['changed'])) {
                $exportRow['changes'] = is_array($logData['changed']) 
                    ? json_encode($logData['changed'])
                    : $logData['changed'];
            }

            // Include metadata if requested
            if ($includeMetadata) {
                $exportRow['session'] = $logData['session'] ?? '';
                $exportRow['request'] = $logData['request'] ?? '';
                $exportRow['ipAddress'] = $logData['ipAddress'] ?? '';
                $exportRow['version'] = $logData['version'] ?? '';
            }

            $exportData[] = $exportRow;
        }

        return $exportData;

    }//end prepareLogsForExport()


    /**
     * Export data to CSV format
     *
     * @param array $data Prepared export data
     *
     * @return array Export result
     */
    private function exportToCsv(array $data): array
    {
        if (empty($data)) {
            return [
                'content' => '',
                'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.csv',
                'contentType' => 'text/csv'
            ];
        }

        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, array_keys($data[0]));
        
        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return [
            'content' => $content,
            'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.csv',
            'contentType' => 'text/csv'
        ];

    }//end exportToCsv()


    /**
     * Export data to JSON format
     *
     * @param array $data Prepared export data
     *
     * @return array Export result
     */
    private function exportToJson(array $data): array
    {
        return [
            'content' => json_encode($data, JSON_PRETTY_PRINT),
            'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.json',
            'contentType' => 'application/json'
        ];

    }//end exportToJson()


    /**
     * Export data to XML format
     *
     * @param array $data Prepared export data
     *
     * @return array Export result
     */
    private function exportToXml(array $data): array
    {
        $xml = new \SimpleXMLElement('<auditTrails/>');
        
        foreach ($data as $logData) {
            $logElement = $xml->addChild('auditTrail');
            foreach ($logData as $key => $value) {
                // Handle special characters and ensure valid XML
                $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                $logElement->addChild($cleanKey, htmlspecialchars($value ?? ''));
            }
        }

        return [
            'content' => $xml->asXML(),
            'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.xml',
            'contentType' => 'application/xml'
        ];

    }//end exportToXml()


    /**
     * Export data to plain text format
     *
     * @param array $data Prepared export data
     *
     * @return array Export result
     */
    private function exportToTxt(array $data): array
    {
        $content = "Audit Trail Export - Generated on " . date('Y-m-d H:i:s') . "\n";
        $content .= str_repeat('=', 60) . "\n\n";

        foreach ($data as $index => $logData) {
            $content .= "Entry #" . ($index + 1) . "\n";
            $content .= str_repeat('-', 20) . "\n";
            
            foreach ($logData as $key => $value) {
                $content .= ucfirst($key) . ': ' . ($value ?? 'N/A') . "\n";
            }
            $content .= "\n";
        }

        return [
            'content' => $content,
            'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.txt',
            'contentType' => 'text/plain'
        ];

    }//end exportToTxt()


}//end class
