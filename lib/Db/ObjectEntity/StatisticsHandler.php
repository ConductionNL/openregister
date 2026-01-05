<?php

/**
 * StatisticsHandler
 *
 * Handler for object statistics and chart data generation.
 * Extracted from ObjectEntityMapper to follow Single Responsibility Principle.
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use DateTime;
use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Handles statistics and chart data operations for ObjectEntity.
 *
 * This handler manages:
 * - Object statistics (counts, sizes, validation states)
 * - Register-based chart data
 * - Schema-based chart data
 * - Size distribution chart data
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class StatisticsHandler
{

    /**
     * Database connection.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Table name for objects.
     *
     * @var string
     */
    private string $tableName;

    /**
     * Constructor.
     *
     * @param IDBConnection   $db        Database connection.
     * @param LoggerInterface $logger    Logger instance.
     * @param string          $tableName Table name for objects.
     */
    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        string $tableName='openregister_objects'
    ) {
        $this->db        = $db;
        $this->logger    = $logger;
        $this->tableName = $tableName;
    }//end __construct()

    /**
     * Get statistics for objects.
     *
     * Returns aggregate statistics including total count, total size,
     * number of invalid/deleted/locked/published objects.
     *
     * @param int|array|null $registerId Filter by register ID(s).
     * @param int|array|null $schemaId   Filter by schema ID(s).
     * @param array          $exclude    Array of register/schema combinations to exclude.
     *
     * @return int[] Array containing statistics: total, size, invalid, deleted, locked, published.
     *
     * @psalm-return array{total: int, size: int, invalid: int, deleted: int,
     *     locked: int, published: int}
     */
    public function getStatistics(int|array|null $registerId=null, int|array|null $schemaId=null, array $exclude=[]): array
    {
        try {
            $qb  = $this->db->getQueryBuilder();
            $now = (new DateTime())->format('Y-m-d H:i:s');
            // Build the published condition first (cannot assign inside select()).
            $part1 = "COUNT(CASE WHEN published IS NOT NULL AND published <= '".$now."'";
            $part2 = " AND (depublished IS NULL OR depublished > '".$now."') THEN 1 END) as published";
            $publishedCondition = $part1.$part2;

            $qb->select(
                $qb->createFunction('COUNT(id) as total'),
                $qb->createFunction('COALESCE(SUM(size), 0) as size'),
                $qb->createFunction('COUNT(CASE WHEN validation IS NOT NULL THEN 1 END) as invalid'),
                $qb->createFunction('COUNT(CASE WHEN deleted IS NOT NULL THEN 1 END) as deleted'),
                // Note: locked is a JSON column - if it's NOT NULL, the object is locked.
                $qb->createFunction('COUNT(CASE WHEN locked IS NOT NULL THEN 1 END) as locked'),
                // Only count as published if published <= now and (depublished is null or depublished > now).
                $qb->createFunction($publishedCondition)
            )
                ->from($this->tableName);

            // Add register filter if provided (support int or array).
            // Note: register and schema columns are VARCHAR(255), not BIGINT - they store ID values as strings.
            if ($registerId !== null) {
                if (is_array($registerId) === true) {
                    // Convert array of integers to array of strings.
                    $stringIds = array_map('strval', $registerId);
                    $paramType = \Doctrine\DBAL\Connection::PARAM_STR_ARRAY;
                    $param     = $qb->createNamedParameter($stringIds, $paramType);
                    $qb->andWhere($qb->expr()->in('register', $param));
                } else {
                    $param = $qb->createNamedParameter((string) $registerId, IQueryBuilder::PARAM_STR);
                    $qb->andWhere($qb->expr()->eq('register', $param));
                }
            }

            // Add schema filter if provided (support int or array).
            // Note: register and schema columns are VARCHAR(255), not BIGINT - they store ID values as strings.
            if ($schemaId !== null) {
                if (is_array($schemaId) === true) {
                    // Convert array of integers to array of strings.
                    $stringIds = array_map('strval', $schemaId);
                    $paramType = \Doctrine\DBAL\Connection::PARAM_STR_ARRAY;
                    $param     = $qb->createNamedParameter($stringIds, $paramType);
                    $qb->andWhere($qb->expr()->in('schema', $param));
                } else {
                    $param = $qb->createNamedParameter((string) $schemaId, IQueryBuilder::PARAM_STR);
                    $qb->andWhere($qb->expr()->eq('schema', $param));
                }
            }

            // Add exclusions if provided.
            if (empty($exclude) === false) {
                foreach ($exclude as $combination) {
                    $orConditions = $qb->expr()->orX();

                    // Handle register exclusion.
                    if (($combination['register'] ?? null) !== null) {
                        $orConditions->add($qb->expr()->isNull('register'));
                        $orConditions->add(
                            $qb->expr()->neq(
                                'register',
                                $qb->createNamedParameter(
                                    $combination['register'],
                                    IQueryBuilder::PARAM_INT
                                )
                            )
                        );
                    }

                    // Handle schema exclusion.
                    if (($combination['schema'] ?? null) !== null) {
                        $orConditions->add($qb->expr()->isNull('schema'));
                        $schemaParam = $qb->createNamedParameter($combination['schema'], IQueryBuilder::PARAM_INT);
                        $orConditions->add($qb->expr()->neq('schema', $schemaParam));
                    }

                    // Add the OR conditions to the main query.
                    if ($orConditions->count() > 0) {
                        $qb->andWhere($orConditions);
                    }
                }//end foreach
            }//end if

            $result = $qb->executeQuery()->fetch();

            return [
                'total'     => (int) ($result['total'] ?? 0),
                'size'      => (int) ($result['size'] ?? 0),
                'invalid'   => (int) ($result['invalid'] ?? 0),
                'deleted'   => (int) ($result['deleted'] ?? 0),
                'locked'    => (int) ($result['locked'] ?? 0),
                'published' => (int) ($result['published'] ?? 0),
            ];
        } catch (Exception $e) {
            $this->logger->error('Error getting statistics: '.$e->getMessage());
            return [
                'total'     => 0,
                'size'      => 0,
                'invalid'   => 0,
                'deleted'   => 0,
                'locked'    => 0,
                'published' => 0,
            ];
        }//end try
    }//end getStatistics()

    /**
     * Get chart data for objects grouped by register.
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return (int|mixed|string)[][] Array containing chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>,
     *     series: array<int>}
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Get database platform to determine casting method.
            $platform = $qb->getConnection()->getDatabasePlatform()->getName();

            // Join with registers table to get register names.
            // Note: o.register is VARCHAR, r.id is BIGINT - need explicit cast for PostgreSQL.
            $qb->select(
                'r.title as register_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->tableName, 'o');

            // PostgreSQL requires explicit casting for VARCHAR to BIGINT comparison.
            if ($platform === 'postgresql') {
                $qb->leftJoin('o', 'openregister_registers', 'r', 'CAST(o.register AS BIGINT) = r.id');
            } else {
                // MySQL/MariaDB does implicit type conversion.
                $qb->leftJoin('o', 'openregister_registers', 'r', 'o.register = r.id');
            }

            $qb->groupBy('r.id', 'r.title')->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $registerParam = $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT);
                $qb->andWhere($qb->expr()->eq('o.register', $registerParam));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $schemaParam = $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT);
                $qb->andWhere($qb->expr()->eq('o.schema', $schemaParam));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(
                    function ($row) {
                        return $row['register_name'] ?? 'Unknown';
                    },
                    $results
                ),
                'series' => array_map(
                    function ($row) {
                        return (int) $row['count'];
                    },
                    $results
                ),
            ];
        } catch (Exception $e) {
            $this->logger->error('Error getting register chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try
    }//end getRegisterChartData()

    /**
     * Get chart data for objects grouped by schema.
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return (int|mixed|string)[][] Array containing chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>,
     *     series: array<int>}
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Get database platform to determine casting method.
            $platform = $qb->getConnection()->getDatabasePlatform()->getName();

            // Join with schemas table to get schema names.
            // Note: o.schema is VARCHAR, s.id is BIGINT - need explicit cast for PostgreSQL.
            $qb->select(
                's.title as schema_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->tableName, 'o');

            // PostgreSQL requires explicit casting for VARCHAR to BIGINT comparison.
            if ($platform === 'postgresql') {
                $qb->leftJoin('o', 'openregister_schemas', 's', 'CAST(o.schema AS BIGINT) = s.id');
            } else {
                // MySQL/MariaDB does implicit type conversion.
                $qb->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id');
            }

            $qb->groupBy('s.id', 's.title')->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $registerParam = $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT);
                $qb->andWhere($qb->expr()->eq('o.register', $registerParam));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $schemaParam = $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT);
                $qb->andWhere($qb->expr()->eq('o.schema', $schemaParam));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(
                    function ($row) {
                        return $row['schema_name'] ?? 'Unknown';
                    },
                    $results
                ),
                'series' => array_map(
                    function ($row) {
                        return (int) $row['count'];
                    },
                    $results
                ),
            ];
        } catch (Exception $e) {
            $this->logger->error('Error getting schema chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try
    }//end getSchemaChartData()

    /**
     * Get chart data for objects grouped by size ranges.
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return (int|string)[][] Array containing chart data with 'labels' and 'series' keys.
     *
     * @psalm-return array{labels: list<'0-1 KB'|'1-10 KB'|'10-100 KB'|
     *     '100 KB-1 MB'|'> 1 MB'>, series: list<int>}
     */
    public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            // Define size ranges in bytes.
            $ranges = [
                ['min' => 0, 'max' => 1024, 'label' => '0-1 KB'],
                ['min' => 1024, 'max' => 10240, 'label' => '1-10 KB'],
                ['min' => 10240, 'max' => 102400, 'label' => '10-100 KB'],
                ['min' => 102400, 'max' => 1048576, 'label' => '100 KB-1 MB'],
                ['min' => 1048576, 'max' => null, 'label' => '> 1 MB'],
            ];

            $results = [];
            foreach ($ranges as $range) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*) as count'))->from($this->tableName);

                // Add size range conditions.
                if ($range['min'] !== null) {
                    $minParam = $qb->createNamedParameter($range['min'], IQueryBuilder::PARAM_INT);
                    $qb->andWhere($qb->expr()->gte('size', $minParam));
                }

                if ($range['max'] !== null) {
                    $maxParam = $qb->createNamedParameter($range['max'], IQueryBuilder::PARAM_INT);
                    $qb->andWhere($qb->expr()->lt('size', $maxParam));
                }

                // Add register filter if provided.
                // Register/schema columns are VARCHAR(255) - they store ID values as strings.
                if ($registerId !== null) {
                    $regParam = $qb->createNamedParameter((string) $registerId, IQueryBuilder::PARAM_STR);
                    $qb->andWhere($qb->expr()->eq('register', $regParam));
                }

                // Add schema filter if provided.
                // Register/schema columns are VARCHAR(255) - they store ID values as strings.
                if ($schemaId !== null) {
                    $schemaParam = $qb->createNamedParameter((string) $schemaId, IQueryBuilder::PARAM_STR);
                    $qb->andWhere($qb->expr()->eq('schema', $schemaParam));
                }

                $count     = $qb->executeQuery()->fetchOne();
                $results[] = [
                    'label' => $range['label'],
                    'count' => (int) $count,
                ];
            }//end foreach

            return [
                'labels' => array_map(
                    function ($row) {
                        return $row['label'];
                    },
                    $results
                ),
                'series' => array_map(
                    function ($row) {
                        return $row['count'];
                    },
                    $results
                ),
            ];
        } catch (Exception $e) {
            $this->logger->error('Error getting size distribution chart data: '.$e->getMessage());
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try
    }//end getSizeDistributionChartData()
}//end class
