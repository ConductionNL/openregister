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

use DateTime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
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
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectMapper;

    /**
     * Audit trail mapper
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

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
    private function getStats(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            // Get object statistics.
            $objectStats = $this->objectMapper->getStatistics(registerId: $registerId, schemaId: $schemaId);

            // Get audit trail statistics.
            $logStats = $this->auditTrailMapper->getStatistics(registerId: $registerId, schemaId: $schemaId);

            // Get webhook log statistics (0 = all webhooks).
            $webhookLogStats = $this->webhookLogMapper->getStatistics(webhookId: 0);

            return [
                'objects'     => [
                    'total'     => $objectStats['total'],
                    'size'      => $objectStats['size'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'locked'    => $objectStats['locked'],
                    'published' => $objectStats['published'],
                ],
                'logs'        => [
                    'total' => $logStats['total'],
                    'size'  => $logStats['size'],
                ],
                'webhookLogs' => [
                    'total' => $webhookLogStats['total'] ?? 0,
                    'size'  => 0,
                ],
                'files'       => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get statistics: '.$e->getMessage());
            return [
                'objects'     => [
                    'total'     => 0,
                    'size'      => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'locked'    => 0,
                    'published' => 0,
                ],
                'logs'        => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'webhookLogs' => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'files'       => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        }//end try

    }//end getStats()


    /**
     * Get statistics for orphaned items
     *
     * @return (int|mixed)[][] The statistics for orphaned items
     *
     * @psalm-return array{
     *     objects: array{
     *         total: int,
     *         size: int,
     *         invalid: int,
     *         deleted: int,
     *         locked: int,
     *         published: int
     *     },
     *     logs: array{
     *         total: 0|mixed,
     *         size: 0|mixed
     *     },
     *     files: array{
     *         total: 0,
     *         size: 0
     *     }
     * }
     */
    private function getOrphanedStats(): array
    {
        try {
            // Get all registers.
            $registers = $this->registerMapper->findAll();

            // Build array of valid register/schema combinations.
            $validCombinations = [];
            foreach ($registers as $register) {
                $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
                foreach ($schemas as $schema) {
                    $validCombinations[] = [
                        'register' => $register->getId(),
                        'schema'   => $schema->getId(),
                    ];
                }
            }

            // Get orphaned object statistics by excluding all valid combinations.
            $objectStats = $this->objectMapper->getStatistics(registerId: null, schemaId: null, exclude: $validCombinations);

            // Get orphaned audit trail statistics using the same exclusions.
            $auditStats = $this->auditTrailMapper->getStatistics(registerId: null, schemaId: null, exclude: $validCombinations);

            return [
                'objects' => [
                    'total'     => $objectStats['total'],
                    'size'      => $objectStats['size'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'locked'    => $objectStats['locked'],
                    'published' => $objectStats['published'],
                ],
                'logs'    => [
                    'total' => $auditStats['total'],
                    'size'  => $auditStats['size'],
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get orphaned statistics: '.$e->getMessage());
            return [
                'objects' => [
                    'total'     => 0,
                    'size'      => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'locked'    => 0,
                    'published' => 0,
                ],
                'logs'    => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        }//end try

    }//end getOrphanedStats()


    /**
     * Get all registers with their schemas and statistics
     *
     * @param int|null $registerId The register ID to filter by
     * @param int|null $schemaId   The schema ID to filter by
     *
     * @return (array|mixed|string)[][] Array of registers with their schemas and statistics
     *
     * @throws \Exception If there is an error getting the registers with schemas
     *
     * @psalm-return list{
     *     0: array{
     *         id: 'orphaned'|'totals'|mixed,
     *         title: 'Orphaned Items'|'System Totals'|mixed,
     *         description: ('Items that reference non-existent registers, schemas, or invalid register-schema combinations')|('Total statistics across all registers and schemas')|mixed,
     *         stats: array,
     *         schemas: list<mixed>,
     *         ...
     *     },
     *     1?: array{
     *         stats: array,
     *         schemas: list<mixed>,
     *         id: 'orphaned'|'totals'|mixed,
     *         title: 'Orphaned Items'|'System Totals'|mixed,
     *         description: ('Items that reference non-existent registers, schemas, or invalid register-schema combinations')|('Total statistics across all registers and schemas')|mixed,
     *         ...
     *     },
     *     ...
     * }
     */
    public function getRegistersWithSchemas(
        ?int $registerId=null,
        ?int $schemaId=null
    ): array {
        try {
            $filters = [];
            if ($registerId !== null) {
                $filters['id'] = $registerId;
            }

            // Get all registers.
            $registers = $this->registerMapper->findAll(
                filters: $filters
            );

            $result = [];

            // Add system totals as the first "register".
            $totalStats = $this->getStats(registerId: $registerId, schemaId: $schemaId);
            $result[]   = [
                'id'          => 'totals',
                'title'       => 'System Totals',
                'description' => 'Total statistics across all registers and schemas',
                'stats'       => $totalStats,
                'schemas'     => [],
            ];

            // For each register, get its schemas and statistics.
            foreach ($registers as $register) {
                $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());

                // Get register-level statistics.
                $registerStats = $this->getStats($register->getId());

                // Convert register to array and add statistics.
                $registerArray          = $register->jsonSerialize();
                $registerArray['stats'] = $registerStats;

                // Process schemas.
                $schemasArray = [];
                foreach ($schemas as $schema) {
                    if ($schemaId !== null &&  $schema->getId() !== $schemaId) {
                        continue;
                    }

                    // Get schema-level statistics.
                    $schemaStats = $this->getStats(registerId: $register->getId(), schemaId: $schema->getId());

                    // Convert schema to array and add statistics.
                    $schemaArray          = $schema->jsonSerialize();
                    $schemaArray['stats'] = $schemaStats;
                    $schemasArray[]       = $schemaArray;
                }

                $registerArray['schemas'] = $schemasArray;
                $result[] = $registerArray;
            }//end foreach

            // Add orphaned items statistics as a special "register".
            $orphanedStats = $this->getOrphanedStats();
            $result[]      = [
                'id'          => 'orphaned',
                'title'       => 'Orphaned Items',
                'description' => 'Items that reference non-existent registers, schemas, or invalid register-schema combinations',
                'stats'       => $orphanedStats,
                'schemas'     => [],
            ];

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get registers with schemas: '.$e->getMessage());
            throw new \Exception('Failed to get registers with schemas: '.$e->getMessage());
        }//end try

    }//end getRegistersWithSchemas()


    /**
     * Recalculate sizes for objects in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return int[] Array containing counts of processed and failed objects
     *
     * @psalm-return array{processed: 0|1|2, failed: 0|1|2}
     */
    public function recalculateSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        $result = [
            'processed' => 0,
            'failed'    => 0,
        ];

        try {
            // Build filters array based on provided IDs.
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }

            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant objects.
            $objects = $this->objectMapper->findAll(filters: $filters);

            // Update each object to trigger size recalculation.
            foreach ($objects as $object) {
                try {
                    $this->objectMapper->update($object);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error(message: 'Failed to update object '.$object->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to recalculate sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate sizes: '.$e->getMessage());
        }//end try

    }//end recalculateSizes()


    /**
     * Recalculate sizes for audit trail logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return int[] Array containing counts of processed and failed logs
     *
     * @psalm-return array{processed: 0|1|2, failed: 0|1|2}
     */
    public function recalculateLogSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        $result = [
            'processed' => 0,
            'failed'    => 0,
        ];

        try {
            // Build filters array based on provided IDs.
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }

            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant logs.
            $logs = $this->auditTrailMapper->findAll(filters: $filters);

            // Update each log to trigger size recalculation.
            foreach ($logs as $log) {
                try {
                    $this->auditTrailMapper->update($log);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error(message: 'Failed to update log '.$log->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to recalculate log sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate log sizes: '.$e->getMessage());
        }//end try

    }//end recalculateLogSizes()


    /**
     * Recalculate sizes for both objects and logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array[] Array containing counts of processed and failed items for both objects and logs
     *
     * @psalm-return array{objects: array, logs: array, total: array{processed: mixed, failed: mixed}}
     */
    public function recalculateAllSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $objectResults = $this->recalculateSizes(registerId: $registerId, schemaId: $schemaId);
            $logResults    = $this->recalculateLogSizes(registerId: $registerId, schemaId: $schemaId);

            return [
                'objects' => $objectResults,
                'logs'    => $logResults,
                'total'   => [
                    'processed' => $objectResults['processed'] + $logResults['processed'],
                    'failed'    => $objectResults['failed'] + $logResults['failed'],
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to recalculate all sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate all sizes: '.$e->getMessage());
        }

    }//end recalculateAllSizes()


    /**
     * Calculate sizes for all entities (objects and logs) in the system
     * Optionally filtered by register and/or schema
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return (array|string)[] Array containing detailed statistics about the calculation process
     *
     * @psalm-return array{
     *     status: 'success',
     *     timestamp: string,
     *     scope: array{
     *         register: mixed,
     *         schema: mixed
     *     },
     *     results: array,
     *     summary: array{
     *         total_processed: mixed,
     *         total_failed: mixed,
     *         success_rate: mixed
     *     }
     * }
     */
    public function calculate(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            // Get the register info if registerId is provided.
            $register = null;
            if ($registerId !== null) {
                try {
                    $register = $this->registerMapper->find($registerId);
                } catch (\Exception $e) {
                    throw new \Exception('Register not found: '.$e->getMessage());
                }
            }

            // Get the schema info if schemaId is provided.
            $schema = null;
            if ($schemaId !== null) {
                try {
                    $schema = $this->schemaMapper->find($schemaId);
                    // Verify schema belongs to register if both are provided.
                    if ($register !== null && in_array($schema->getId(), $register->getSchemas()) === false) {
                        throw new \Exception('Schema does not belong to the specified register');
                    }
                } catch (\Exception $e) {
                    throw new \Exception('Schema not found or invalid: '.$e->getMessage());
                }
            }

            // Perform the calculations.
            $results = $this->recalculateAllSizes(registerId: $registerId, schemaId: $schemaId);

            // Build the response.
            $registerScope = $register !== null ? [
                'id'    => $register->getId(),
                'title' => $register->getTitle(),
            ] : null;
            $schemaScope   = $schema !== null ? [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
            ] : null;
            $successRate   = $results['total']['processed'] > 0 ? round(($results['total']['processed'] - $results['total']['failed']) / $results['total']['processed'] * 100, 2) : 0.0;

            $response = [
                'status'    => 'success',
                'timestamp' => (new \DateTime('now'))->format(format: 'c'),
                'scope'     => [
                    'register' => $registerScope,
                    'schema'   => $schemaScope,
                ],
                'results'   => $results,
                'summary'   => [
                    'total_processed' => $results['total']['processed'],
                    'total_failed'    => $results['total']['failed'],
                    'success_rate'    => $successRate,
                ],
            ];

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Size calculation failed: '.$e->getMessage());
            throw new \Exception('Size calculation failed: '.$e->getMessage());
        }//end try

    }//end calculate()


    /**
     * Get chart data for audit trail actions over time
     *
     * @param DateTime|null $from       Start date for the chart data
     * @param DateTime|null $till       End date for the chart data
     * @param int|null      $registerId Optional register ID to filter by
     * @param int|null      $schemaId   Optional schema ID to filter by
     *
     * @return array Array containing chart data for audit trail actions
     */
    public function getAuditTrailActionChartData(?\DateTime $from=null, ?\DateTime $till=null, ?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            return $this->auditTrailMapper->getActionChartData(from: $from, till: $till, registerId: $registerId, schemaId: $schemaId);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get audit trail action chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }

    }//end getAuditTrailActionChartData()


    /**
     * Get chart data for objects by register
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     *
     * @return array Array containing chart data for objects by register
     */
    public function getObjectsByRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            return $this->objectMapper->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get objects by register chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }

    }//end getObjectsByRegisterChartData()


    /**
     * Get chart data for objects by schema
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     *
     * @return array Array containing chart data for objects by schema
     */
    public function getObjectsBySchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            return $this->objectMapper->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get objects by schema chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }

    }//end getObjectsBySchemaChartData()


    /**
     * Get chart data for objects by size distribution
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     *
     * @return array Array containing chart data for objects by size
     */
    public function getObjectsBySizeChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            return $this->objectMapper->getSizeDistributionChartData(registerId: $registerId, schemaId: $schemaId);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get objects by size chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }

    }//end getObjectsBySizeChartData()


    /**
     * Get audit trail statistics including total counts and recent activity
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $hours      Optional number of hours to look back for recent activity (default: 24)
     *
     * @return array Array containing audit trail statistics:
     *               - total: Total number of audit trails
     *               - creates: Number of create actions in timeframe
     *               - updates: Number of update actions in timeframe
     *               - deletes: Number of delete actions in timeframe
     *               - reads: Number of read actions in timeframe
     */
    public function getAuditTrailStatistics(?int $registerId=null, ?int $schemaId=null, ?int $hours=24): array
    {
        try {
            return $this->auditTrailMapper->getDetailedStatistics(registerId: $registerId, schemaId: $schemaId, hours: $hours);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get audit trail statistics: '.$e->getMessage());
            return [
                'total'   => 0,
                'creates' => 0,
                'updates' => 0,
                'deletes' => 0,
                'reads'   => 0,
            ];
        }

    }//end getAuditTrailStatistics()


    /**
     * Get action distribution data for audit trails with percentages
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $hours      Optional number of hours to look back (default: 24)
     *
     * @return array Array containing action distribution data:
     *               - actions: Array of action data with name, count, and percentage
     */
    public function getAuditTrailActionDistribution(?int $registerId=null, ?int $schemaId=null, ?int $hours=24): array
    {
        try {
            return $this->auditTrailMapper->getActionDistribution(registerId: $registerId, schemaId: $schemaId, hours: $hours);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get audit trail action distribution: '.$e->getMessage());
            return [
                'actions' => [],
            ];
        }

    }//end getAuditTrailActionDistribution()


    /**
     * Get most active objects based on audit trail activity
     *
     * @param int|null $registerId Optional register ID to filter by
     * @param int|null $schemaId   Optional schema ID to filter by
     * @param int|null $limit      Optional limit for number of results (default: 10)
     * @param int|null $hours      Optional number of hours to look back (default: 24)
     *
     * @return array Array containing most active objects:
     *               - objects: Array of object data with name, id, and count
     */
    public function getMostActiveObjects(?int $registerId=null, ?int $schemaId=null, ?int $limit=10, ?int $hours=24): array
    {
        try {
            return $this->auditTrailMapper->getMostActiveObjects(registerId: $registerId, schemaId: $schemaId, limit: $limit, hours: $hours);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to get most active objects: '.$e->getMessage());
            return [
                'objects' => [],
            ];
        }

    }//end getMostActiveObjects()


}//end class
