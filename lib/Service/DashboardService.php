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
use Exception;
use RuntimeException;
use stdClass;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * DashboardService handles dashboard related operations
 *
 * Service for handling dashboard related operations including statistics,
 * register/schema aggregation, and data size calculations.
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
class DashboardService
{

    /**
     * Object entity mapper
     *
     * Handles database operations for object entities.
     *
     * @var ObjectEntityMapper Object entity mapper instance
     */
    private readonly ObjectEntityMapper $objectMapper;

    /**
     * Audit trail mapper
     *
     * Handles database operations for audit trail entries.
     *
     * @var AuditTrailMapper Audit trail mapper instance
     */
    private readonly AuditTrailMapper $auditTrailMapper;

    /**
     * Webhook log mapper
     *
     * Handles database operations for webhook log entries.
     *
     * @var WebhookLogMapper Webhook log mapper instance
     */
    private readonly WebhookLogMapper $webhookLogMapper;

    /**
     * Register mapper
     *
     * Handles database operations for register entities.
     *
     * @var RegisterMapper Register mapper instance
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Logger
     *
     * Used for logging dashboard operations and errors.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Schema mapper
     *
     * Handles database operations for schema entities.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;


    /**
     * Constructor for DashboardService
     *
     * Initializes service with required mappers and logger for dashboard operations.
     *
     * @param ObjectEntityMapper $objectMapper     Object entity mapper for object statistics
     * @param AuditTrailMapper   $auditTrailMapper Audit trail mapper for log statistics
     * @param WebhookLogMapper   $webhookLogMapper Webhook log mapper for webhook statistics
     * @param RegisterMapper     $registerMapper   Register mapper for register operations
     * @param SchemaMapper       $schemaMapper     Schema mapper for schema operations
     * @param LoggerInterface    $logger           Logger instance for error tracking
     *
     * @return void
     */
    public function __construct(
        ObjectEntityMapper $objectMapper,
        AuditTrailMapper $auditTrailMapper,
        WebhookLogMapper $webhookLogMapper,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        LoggerInterface $logger
    ) {
        // Store dependencies for use in service methods.
        $this->objectMapper     = $objectMapper;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->registerMapper   = $registerMapper;
        $this->schemaMapper     = $schemaMapper;
        $this->logger           = $logger;

    }//end __construct()


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
     *     files: array{total: int, size: int},
     *     webhookLogs: array{total: int, size: int}
     * }
     * @psalm-return   array{
     *     objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int},
     *     logs: array{total: int, size: int},
     *     files: array{total: int, size: int},
     *     webhookLogs: array{total: int, size: int}
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
     * @return ((int|mixed|null|string[])[]|int|null|string)[][]
     *
     * @throws \Exception If there is an error getting the registers with schemas
     *
     * @psalm-return list{0: array{id: 'orphaned'|'totals'|int, title: null|string, description: null|string, stats: array{objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}, logs: array{total: int|mixed, size: int|mixed}, files: array{total: int, size: int}, webhookLogs?: array{total: int, size: int}}, schemas: list{0?: mixed,...}, uuid?: null|string, slug?: null|string, version?: null|string, source?: null|string, tablePrefix?: null|string, folder?: null|string, updated?: null|string, created?: null|string, owner?: null|string, application?: null|string, organisation?: null|string, authorization?: array|null, groups?: array<string, list<string>>, quota?: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage?: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted?: null|string, published?: null|string, depublished?: null|string}, 1?: array{id: 'orphaned'|'totals'|int, uuid: null|string, slug: null|string, title: null|string, version: null|string, description: null|string, schemas: list{0?: mixed,...}, source: null|string, tablePrefix: null|string, folder: null|string, updated: null|string, created: null|string, owner: null|string, application: null|string, organisation: null|string, authorization: array|null, groups: array<string, list<string>>, quota: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted: null|string, published: null|string, depublished: null|string, stats: array{objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int}, logs: array{total: int|mixed, size: int|mixed}, files: array{total: int, size: int}, webhookLogs: array{total: int, size: int}}},...}
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
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to get registers with schemas: '.$e->getMessage());
            throw new Exception('Failed to get registers with schemas: '.$e->getMessage());
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
                } catch (Exception $e) {
                    $this->logger->error(message: 'Failed to update object '.$object->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to recalculate sizes: '.$e->getMessage());
            throw new Exception('Failed to recalculate sizes: '.$e->getMessage());
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
                } catch (Exception $e) {
                    $this->logger->error(message: 'Failed to update log '.$log->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to recalculate log sizes: '.$e->getMessage());
            throw new Exception('Failed to recalculate log sizes: '.$e->getMessage());
        }//end try

    }//end recalculateLogSizes()


    /**
     * Recalculate sizes for both objects and logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return int[][]
     *
     * @psalm-return array{objects: array{processed: 0|1|2, failed: 0|1|2}, logs: array{processed: 0|1|2, failed: 0|1|2}, total: array{processed: int, failed: int}}
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
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to recalculate all sizes: '.$e->getMessage());
            throw new Exception('Failed to recalculate all sizes: '.$e->getMessage());
        }

    }//end recalculateAllSizes()


    /**
     * Calculate sizes for all entities (objects and logs) in the system
     * Optionally filtered by register and/or schema
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return ((array|float|mixed|null)[]|string)[]
     *
     * @psalm-return array{status: 'success', timestamp: string, scope: array{register: array{id: int, title: null|string}|null, schema: array{id: int, title: null|string}|null}, results: array{objects: array, logs: array, total: array{processed: mixed, failed: mixed}}, summary: array{total_processed: mixed, total_failed: mixed, success_rate: float}}
     */
    public function calculate(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            // Fetch and validate register and schema.
            $register = $this->fetchRegister($registerId);
            $schema   = $this->fetchSchema($schemaId, $register);

            // Perform the calculations.
            $results = $this->recalculateAllSizes(registerId: $registerId, schemaId: $schemaId);

            // Build the response.
            $response = [
                'status'    => 'success',
                'timestamp' => (new DateTime('now'))->format(format: 'c'),
                'scope'     => $this->buildResponseScope($register, $schema),
                'results'   => $results,
                'summary'   => [
                    'total_processed' => $results['total']['processed'],
                    'total_failed'    => $results['total']['failed'],
                    'success_rate'    => $this->calculateSuccessRate($results),
                ],
            ];

            return $response;
        } catch (Exception $e) {
            $this->logger->error(message: 'Size calculation failed: '.$e->getMessage());
            throw new Exception('Size calculation failed: '.$e->getMessage());
        }//end try

    }//end calculate()


    /**
     * Fetch register by ID with validation
     *
     * @param int|null $registerId Register ID to fetch.
     *
     * @return \OCA\OpenRegister\Db\Register|null Register entity or null if not provided.
     *
     * @throws \Exception If register is not found.
     */
    private function fetchRegister(?int $registerId): ?\OCA\OpenRegister\Db\Register
    {
        if ($registerId === null) {
            return null;
        }

        try {
            return $this->registerMapper->find($registerId);
        } catch (Exception $e) {
            throw new Exception('Register not found: '.$e->getMessage());
        }

    }//end fetchRegister()


    /**
     * Fetch schema by ID with validation
     *
     * @param int|null                           $schemaId Schema ID to fetch.
     * @param \OCA\OpenRegister\Db\Register|null $register Register to validate against.
     *
     * @return \OCA\OpenRegister\Db\Schema|null Schema entity or null if not provided.
     *
     * @throws \Exception If schema is not found or doesn't belong to register.
     */
    private function fetchSchema(?int $schemaId, ?\OCA\OpenRegister\Db\Register $register): ?\OCA\OpenRegister\Db\Schema
    {
        if ($schemaId === null) {
            return null;
        }

        try {
            $schema = $this->schemaMapper->find($schemaId);

            // Verify schema belongs to register if both are provided.
            if ($register !== null && in_array($schema->getId(), $register->getSchemas()) === false) {
                throw new Exception('Schema does not belong to the specified register');
            }

            return $schema;
        } catch (Exception $e) {
            throw new Exception('Schema not found or invalid: '.$e->getMessage());
        }

    }//end fetchSchema()


    /**
     * Build response scope object from register and schema
     *
     * @param \OCA\OpenRegister\Db\Register|null $register Register entity.
     * @param \OCA\OpenRegister\Db\Schema|null   $schema   Schema entity.
     *
     * @return array<string, array{id: int, title: string}|null> Scope object with register and schema info.
     */
    private function buildResponseScope(?\OCA\OpenRegister\Db\Register $register, ?\OCA\OpenRegister\Db\Schema $schema): array
    {
        $registerScope = null;
        if ($register !== null) {
            $registerScope = [
                'id'    => $register->getId(),
                'title' => $register->getTitle(),
            ];
        }

        $schemaScope = null;
        if ($schema !== null) {
            $schemaScope = [
                'id'    => $schema->getId(),
                'title' => $schema->getTitle(),
            ];
        }

        return [
            'register' => $registerScope,
            'schema'   => $schemaScope,
        ];

    }//end buildResponseScope()


    /**
     * Calculate success rate from results
     *
     * @param array<string, mixed> $results Results array with total processed and failed counts.
     *
     * @return float Success rate percentage.
     */
    private function calculateSuccessRate(array $results): float
    {
        if ($results['total']['processed'] > 0) {
            return round(($results['total']['processed'] - $results['total']['failed']) / $results['total']['processed'] * 100, 2);
        }

        return 0.0;

    }//end calculateSuccessRate()


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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to get most active objects: '.$e->getMessage());
            return [
                'objects' => [],
            ];
        }

    }//end getMostActiveObjects()


}//end class
