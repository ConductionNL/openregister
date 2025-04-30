<?php
/**
 * OpenRegister Dashboard Service
 *
 * This file contains the service class for handling dashboard related operations
 * in the OpenRegister application.
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Class DashboardService
 *
 * Service for handling dashboard related operations.
 *
 * @package OCA\OpenRegister\Service
 */
class DashboardService
{
    /**
     * Register mapper instance for handling register operations.
     *
     * @var RegisterMapper The register mapper instance.
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper instance for handling schema operations.
     *
     * @var SchemaMapper The schema mapper instance.
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Object entity mapper instance for handling object operations.
     *
     * @var ObjectEntityMapper The object entity mapper instance.
     */
    private readonly ObjectEntityMapper $objectMapper;

    /**
     * Audit trail mapper instance for handling audit trail operations.
     *
     * @var AuditTrailMapper The audit trail mapper instance.
     */
    private readonly AuditTrailMapper $auditTrailMapper;

    /**
     * Database connection instance.
     *
     * @var IDBConnection The database connection instance.
     */
    private readonly IDBConnection $db;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor for DashboardService
     *
     * @param RegisterMapper    $registerMapper   The register mapper instance
     * @param SchemaMapper     $schemaMapper    The schema mapper instance
     * @param ObjectEntityMapper $objectMapper    The object entity mapper instance
     * @param AuditTrailMapper  $auditTrailMapper The audit trail mapper instance
     * @param IDBConnection     $db              The database connection instance
     * @param LoggerInterface   $logger          The logger instance
     *
     * @return void
     */
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        ObjectEntityMapper $objectMapper,
        AuditTrailMapper $auditTrailMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;
        $this->objectMapper = $objectMapper;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Get statistics for a specific register and schema combination
     *
     * @param int      $registerId The register ID
     * @param int|null $schemaId   The schema ID (optional)
     *
     * @return array The statistics for the register/schema combination
     */
    private function getStats(int $registerId, ?int $schemaId = null): array
    {
        try {
            // Get object statistics
            $objectStats = $this->objectMapper->getStatistics($registerId, $schemaId);
            
            // Get audit trail statistics
            $auditStats = $this->auditTrailMapper->getStatistics($registerId, $schemaId);

            // Combine results
            return [
                'objects' => $objectStats,
                'logs' => $auditStats,
                'files' => [
                    'total' => 0,
                    'size' => 0
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get statistics: ' . $e->getMessage());
            return [
                'objects' => ['total' => 0, 'size' => 0, 'invalid' => 0, 'deleted' => 0],
                'logs' => ['total' => 0, 'size' => 0],
                'files' => ['total' => 0, 'size' => 0]
            ];
        }
    }

    /**
     * Get all registers with their schemas and statistics
     *
     * @param int|null    $limit            The number of registers to return
     * @param int|null    $offset           The offset of the registers to return
     * @param array|null  $filters          The filters to apply to the registers
     * @param array|null  $searchConditions The search conditions to apply to the registers
     * @param array|null  $searchParams     The search parameters to apply to the registers
     * 
     * @return array Array of registers with their schemas and statistics
     * @throws \Exception If there is an error getting the registers with schemas
     */
    public function getRegistersWithSchemas(
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = []
    ): array {
        try {
            // Get all registers
            $registers = $this->registerMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams
            );

            $result = [];

            // For each register, get its schemas and statistics
            foreach ($registers as $register) {
                $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
                
                // Get register-level statistics
                $registerStats = $this->getStats($register->getId());
                
                // Convert register to array and add statistics
                $registerArray = $register->jsonSerialize();
                $registerArray['stats'] = $registerStats;
                
                // Process schemas
                $schemasArray = [];
                foreach ($schemas as $schema) {
                    // Get schema-level statistics
                    $schemaStats = $this->getStats($register->getId(), $schema->getId());
                    
                    // Convert schema to array and add statistics
                    $schemaArray = $schema->jsonSerialize();
                    $schemaArray['stats'] = $schemaStats;
                    $schemasArray[] = $schemaArray;
                }
                
                $registerArray['schemas'] = $schemasArray;
                $result[] = $registerArray;
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get registers with schemas: ' . $e->getMessage());
            throw new \Exception('Failed to get registers with schemas: ' . $e->getMessage());
        }
    }
} 