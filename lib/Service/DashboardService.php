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
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get statistics for a register/schema combination
     *
     * @param int      $registerId The register ID
     * @param int|null $schemaId   The schema ID (optional)
     *
     * @return array Array containing statistics about objects and logs:
     *               - objects: Array containing object statistics
     *                 - total: Total number of objects
     *                 - size: Total size of all objects in bytes
     *                 - invalid: Number of objects with validation errors
     *                 - deleted: Number of deleted objects
     *                 - locked: Number of locked objects
     *                 - published: Number of published objects
     *               - logs: Array containing log statistics
     *                 - total: Total number of log entries
     *                 - size: Total size of all log entries in bytes
     *               - files: Array containing file statistics
     *                 - total: Total number of files
     *                 - size: Total size of all files in bytes
     *
     * @phpstan-return array{
     *     objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int},
     *     logs: array{total: int, size: int},
     *     files: array{total: int, size: int}
     * }
     */
    private function getStats(int $registerId, ?int $schemaId = null): array
    {
        try {
            // Get object statistics
            $objectStats = $this->objectMapper->getStatistics($registerId, $schemaId);

            // Get audit trail statistics
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction('COUNT(id) as total_logs'),
                $qb->createFunction('SUM(size) as total_size')
            )
            ->from('openregister_audit_trails')
            ->where($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));

            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $result = $qb->executeQuery()->fetch();

            return [
                'objects' => [
                    'total' => $objectStats['total'],
                    'size' => $objectStats['size'],
                    'invalid' => $objectStats['invalid'],
                    'deleted' => $objectStats['deleted'],
                    'locked' => $objectStats['locked'],
                    'published' => $objectStats['published']
                ],
                'logs' => [
                    'total' => (int)($result['total_logs'] ?? 0),
                    'size' => (int)($result['total_size'] ?? 0)
                ],
                'files' => [
                    'total' => 0,
                    'size' => 0
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get statistics: ' . $e->getMessage());
            return [
                'objects' => [
                    'total' => 0,
                    'size' => 0,
                    'invalid' => 0,
                    'deleted' => 0,
                    'locked' => 0,
                    'published' => 0
                ],
                'logs' => [
                    'total' => 0,
                    'size' => 0
                ],
                'files' => [
                    'total' => 0,
                    'size' => 0
                ]
            ];
        }
    }

    /**
     * Get statistics for orphaned items
     *
     * @return array The statistics for orphaned items
     */
    private function getOrphanedStats(): array
    {
        try {
            // Get orphaned object statistics
            $objectStats = $this->objectMapper->getOrphanedStatistics();
            
            // Get orphaned audit trail statistics
            $auditStats = $this->auditTrailMapper->getOrphanedStatistics();

            return [
                'objects' => $objectStats,
                'logs' => $auditStats,
                'files' => [
                    'total' => 0,
                    'size' => 0
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get orphaned statistics: ' . $e->getMessage());
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

            // Add orphaned items statistics as a special "register"
            $orphanedStats = $this->getOrphanedStats();
            $result[] = [
                'id' => 'orphaned',
                'title' => 'Orphaned Items',
                'description' => 'Items that reference non-existent registers, schemas, or invalid register-schema combinations',
                'stats' => $orphanedStats,
                'schemas' => []
            ];

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get registers with schemas: ' . $e->getMessage());
            throw new \Exception('Failed to get registers with schemas: ' . $e->getMessage());
        }
    }

    /**
     * Recalculate sizes for objects in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed objects
     */
    public function recalculateSizes(?int $registerId = null, ?int $schemaId = null): array
    {
        $result = [
            'processed' => 0,
            'failed' => 0
        ];

        try {
            // Build filters array based on provided IDs
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }
            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant objects
            $objects = $this->objectMapper->findAll(filters: $filters);

            // Update each object to trigger size recalculation
            foreach ($objects as $object) {
                try {
                    $this->objectMapper->update($object);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to update object ' . $object->getId() . ': ' . $e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate sizes: ' . $e->getMessage());
            throw new \Exception('Failed to recalculate sizes: ' . $e->getMessage());
        }
    }

    /**
     * Recalculate sizes for audit trail logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed logs
     */
    public function recalculateLogSizes(?int $registerId = null, ?int $schemaId = null): array
    {
        $result = [
            'processed' => 0,
            'failed' => 0
        ];

        try {
            // Build filters array based on provided IDs
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }
            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant logs
            $logs = $this->auditTrailMapper->findAll(filters: $filters);

            // Update each log to trigger size recalculation
            foreach ($logs as $log) {
                try {
                    $this->auditTrailMapper->update($log);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to update log ' . $log->getId() . ': ' . $e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate log sizes: ' . $e->getMessage());
            throw new \Exception('Failed to recalculate log sizes: ' . $e->getMessage());
        }
    }

    /**
     * Recalculate sizes for both objects and logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed items for both objects and logs
     */
    public function recalculateAllSizes(?int $registerId = null, ?int $schemaId = null): array
    {
        try {
            $objectResults = $this->recalculateSizes($registerId, $schemaId);
            $logResults = $this->recalculateLogSizes($registerId, $schemaId);

            return [
                'objects' => $objectResults,
                'logs' => $logResults,
                'total' => [
                    'processed' => $objectResults['processed'] + $logResults['processed'],
                    'failed' => $objectResults['failed'] + $logResults['failed']
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate all sizes: ' . $e->getMessage());
            throw new \Exception('Failed to recalculate all sizes: ' . $e->getMessage());
        }
    }

    /**
     * Calculate sizes for all entities (objects and logs) in the system
     * Optionally filtered by register and/or schema
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing detailed statistics about the calculation process
     */
    public function calculate(?int $registerId = null, ?int $schemaId = null): array
    {
        try {
            // Get the register info if registerId is provided
            $register = null;
            if ($registerId !== null) {
                try {
                    $register = $this->registerMapper->find($registerId);
                } catch (\Exception $e) {
                    throw new \Exception('Register not found: ' . $e->getMessage());
                }
            }

            // Get the schema info if schemaId is provided
            $schema = null;
            if ($schemaId !== null) {
                try {
                    $schema = $this->schemaMapper->find($schemaId);
                    // Verify schema belongs to register if both are provided
                    if ($register !== null && !in_array($schema->getId(), $register->getSchemas())) {
                        throw new \Exception('Schema does not belong to the specified register');
                    }
                } catch (\Exception $e) {
                    throw new \Exception('Schema not found or invalid: ' . $e->getMessage());
                }
            }

            // Perform the calculations
            $results = $this->recalculateAllSizes($registerId, $schemaId);

            // Build the response
            $response = [
                'status' => 'success',
                'timestamp' => (new \DateTime())->format('c'),
                'scope' => [
                    'register' => $register ? [
                        'id' => $register->getId(),
                        'title' => $register->getTitle()
                    ] : null,
                    'schema' => $schema ? [
                        'id' => $schema->getId(),
                        'title' => $schema->getTitle()
                    ] : null
                ],
                'results' => $results,
                'summary' => [
                    'total_processed' => $results['total']['processed'],
                    'total_failed' => $results['total']['failed'],
                    'success_rate' => $results['total']['processed'] + $results['total']['failed'] > 0 
                        ? round(($results['total']['processed'] / ($results['total']['processed'] + $results['total']['failed'])) * 100, 2)
                        : 0
                ]
            ];

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Size calculation failed: ' . $e->getMessage());
            throw new \Exception('Size calculation failed: ' . $e->getMessage());
        }
    }
} 