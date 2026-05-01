<?php

/**
 * Blob Migration Background Job
 *
 * Recurring background job that migrates objects from the legacy blob table
 * (oc_openregister_objects) to schema-specific magic tables. Runs every 5 minutes
 * and processes up to 100 objects per execution, grouped by register+schema pair.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Migrates blob-table objects to magic tables in batches
 *
 * This job runs every 5 minutes and processes up to 100 objects from the
 * legacy oc_openregister_objects blob table. Objects are grouped by their
 * register+schema combination and upserted into the corresponding magic table.
 *
 * Orphaned objects (null/invalid register or schema) are logged and skipped.
 * Progress is tracked in appconfig for admin visibility.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BlobMigrationJob extends TimedJob
{
    /**
     * Interval: 5 minutes
     */
    private const INTERVAL = 5 * 60;

    /**
     * Maximum objects to process per run
     */
    private const BATCH_SIZE = 100;

    /**
     * App config key prefix
     */
    private const CONFIG_PREFIX = 'blob_migration_';

    /**
     * Constructor
     *
     * @param ITimeFactory $time Time factory for parent class
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Execute the blob migration job
     *
     * Fetches up to 100 objects from the blob table, groups them by register+schema,
     * ensures magic tables exist, upserts objects, and deletes migrated rows.
     *
     * @param mixed $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    protected function run($argument): void
    {
        $startTime = microtime(true);

        $logger    = \OC::$server->get(LoggerInterface::class);
        $db        = \OC::$server->get(IDBConnection::class);
        $appConfig = \OC::$server->get(IAppConfig::class);
        $registerMapper = \OC::$server->get(RegisterMapper::class);
        $schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $magicMapper    = \OC::$server->get(MagicMapper::class);

        $logger->info(
            message: '[BlobMigrationJob] Starting blob-to-magic migration batch',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Check if migration is already complete.
        $complete = $appConfig->getValueString(
            app: 'openregister',
            key: self::CONFIG_PREFIX.'complete',
            default: 'false'
        );

        if ($complete === 'true') {
            $logger->debug(
                message: '[BlobMigrationJob] Migration already complete, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        try {
            // Check if the blob table exists.
            if ($this->blobTableExists(db: $db) === false) {
                $logger->info(
                    message: '[BlobMigrationJob] Blob table does not exist, marking migration complete',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $appConfig->setValueString(app: 'openregister', key: self::CONFIG_PREFIX.'complete', value: 'true');
                return;
            }

            // Fetch batch of objects from blob table.
            $objects = $this->fetchBlobObjects(db: $db);

            if (empty($objects) === true) {
                $logger->info(
                    message: '[BlobMigrationJob] No objects remaining in blob table, marking migration complete',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $appConfig->setValueString(app: 'openregister', key: self::CONFIG_PREFIX.'complete', value: 'true');
                $appConfig->setValueString(app: 'openregister', key: self::CONFIG_PREFIX.'remaining', value: '0');
                return;
            }

            // Group objects by register+schema pair.
            $groups   = $this->groupByRegisterSchema(objects: $objects, logger: $logger);
            $migrated = 0;
            $skipped  = 0;

            foreach ($groups as $key => $group) {
                if ($key === 'orphaned') {
                    $skipped += count($group);
                    continue;
                }

                [$registerId, $schemaId] = explode('_', $key);

                try {
                    $register = $registerMapper->find(
                        id: (int) $registerId,
                        _rbac: false,
                        _multitenancy: false
                    );
                    $schema   = $schemaMapper->find(
                        id: (int) $schemaId,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (Exception $e) {
                    $logger->warning(
                        message: '[BlobMigrationJob] Could not resolve register/schema, skipping group',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                            'error'      => $e->getMessage(),
                        ]
                    );
                    $skipped += count($group);
                    continue;
                }//end try

                // Ensure magic table exists for this register+schema.
                $magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);

                // Save objects to magic table.
                $objectArrays = array_map(
                    function (array $row) {
                        return $this->blobRowToObjectArray(row: $row);
                    },
                    $group
                );

                $savedUuids = $magicMapper->saveObjectsToRegisterSchemaTable(
                    objects: $objectArrays,
                    register: $register,
                    schema: $schema
                );

                $logger->info(
                    message: '[BlobMigrationJob] Migrated group to magic table',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'count'      => count($savedUuids),
                    ]
                );

                // Delete migrated rows from blob table.
                $this->deleteBlobRows(db: $db, rows: $group);
                $migrated += count($savedUuids);
            }//end foreach

            // Update progress in appconfig.
            $previousProcessed = (int) $appConfig->getValueString(
                app: 'openregister',
                key: self::CONFIG_PREFIX.'processed',
                default: '0'
            );

            $remaining = $this->countBlobRows(db: $db);

            $appConfig->setValueString(
                app: 'openregister',
                key: self::CONFIG_PREFIX.'processed',
                value: (string) ($previousProcessed + $migrated)
            );
            $appConfig->setValueString(
                app: 'openregister',
                key: self::CONFIG_PREFIX.'remaining',
                value: (string) $remaining
            );
            $appConfig->setValueString(
                app: 'openregister',
                key: self::CONFIG_PREFIX.'last_run',
                value: date('c')
            );

            $executionTime = microtime(true) - $startTime;

            $logger->info(
                message: '[BlobMigrationJob] Batch completed',
                context: [
                    'file'                   => __FILE__,
                    'line'                   => __LINE__,
                    'migrated'               => $migrated,
                    'skipped'                => $skipped,
                    'remaining'              => $remaining,
                    'execution_time_seconds' => round($executionTime, 2),
                ]
            );
        } catch (Exception $e) {
            $logger->error(
                message: '[BlobMigrationJob] Migration batch failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }//end try
    }//end run()

    /**
     * Check if the blob table exists in the database.
     *
     * @param IDBConnection $db Database connection
     *
     * @return bool True if the table exists
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function blobTableExists(IDBConnection $db): bool
    {
        try {
            $platform   = $db->getDatabasePlatform();
            $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

            // phpcs:ignore Generic.Files.LineLength.TooLong -- SQL query.
            $sql = "SELECT 1 FROM information_schema.tables WHERE table_name = 'oc_openregister_objects' AND table_schema = DATABASE() LIMIT 1";
            if ($isPostgres === true) {
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                $sql = "SELECT 1 FROM information_schema.tables WHERE table_name = 'oc_openregister_objects' AND table_schema = current_schema() LIMIT 1";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();

            return $result !== false;
        } catch (Exception $e) {
            return false;
        }//end try
    }//end blobTableExists()

    /**
     * Fetch a batch of objects from the blob table.
     *
     * @param IDBConnection $db Database connection
     *
     * @return array<int, array<string, mixed>> Raw rows from the blob table
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function fetchBlobObjects(IDBConnection $db): array
    {
        $qb = $db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->setMaxResults(self::BATCH_SIZE);

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }//end fetchBlobObjects()

    /**
     * Count remaining rows in the blob table.
     *
     * @param IDBConnection $db Database connection
     *
     * @return int Number of remaining rows
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function countBlobRows(IDBConnection $db): int
    {
        $qb = $db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as count'))
            ->from('openregister_objects');

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['count'] ?? 0);
    }//end countBlobRows()

    /**
     * Group blob rows by their register+schema combination.
     *
     * Objects with null/invalid register or schema are placed in the 'orphaned' group.
     *
     * @param array<int, array<string, mixed>> $objects Raw blob rows
     * @param LoggerInterface                  $logger  Logger for warnings
     *
     * @return array<string, array<int, array<string, mixed>>> Grouped rows keyed by "registerId_schemaId"
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function groupByRegisterSchema(array $objects, LoggerInterface $logger): array
    {
        $groups = [];

        foreach ($objects as $row) {
            $registerId = $row['register'] ?? null;
            $schemaId   = $row['schema'] ?? null;
            $uuid       = $row['uuid'] ?? 'unknown';

            if (empty($registerId) === true || empty($schemaId) === true) {
                $logger->warning(
                    message: '[BlobMigrationJob] Orphaned object: null/empty register or schema',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'uuid'       => $uuid,
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                    ]
                );
                $groups['orphaned'][] = $row;
                continue;
            }

            $key            = $registerId.'_'.$schemaId;
            $groups[$key][] = $row;
        }//end foreach

        return $groups;
    }//end groupByRegisterSchema()

    /**
     * Convert a blob table row to an object array suitable for MagicMapper.
     *
     * @param array<string, mixed> $row Raw blob table row
     *
     * @return array<string, mixed> Object array for saving to magic table
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function blobRowToObjectArray(array $row): array
    {
        // Decode the JSON object payload.
        $objectData = [];
        if (isset($row['object']) === true && is_string($row['object']) === true) {
            $decoded = json_decode($row['object'], true);
            if (is_array($decoded) === true) {
                $objectData = $decoded;
            }
        }

        // Build the object array with metadata that MagicMapper expects.
        $result = $objectData;

        // Preserve metadata fields.
        $metadataFields = [
            'uuid',
            'register',
            'schema',
            'uri',
            'version',
            'organisation',
            'owner',
            'authorization',
            'updated',
            'created',
            'folder',
            'textRepresentation',
            'locked',
            'relations',
        ];

        foreach ($metadataFields as $field) {
            if (isset($row[$field]) === true) {
                $result['_'.$field] = $row[$field];
            }
        }

        // Ensure uuid is set at top level too (required by saveObjectsToRegisterSchemaTable).
        if (isset($row['uuid']) === true) {
            $result['uuid']  = $row['uuid'];
            $result['_uuid'] = $row['uuid'];
        }

        return $result;
    }//end blobRowToObjectArray()

    /**
     * Delete migrated rows from the blob table.
     *
     * @param IDBConnection                    $db   Database connection
     * @param array<int, array<string, mixed>> $rows Rows to delete
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-9
     */
    private function deleteBlobRows(IDBConnection $db, array $rows): void
    {
        $ids = array_filter(
            array_map(
                function (array $row): ?int {
                    if (isset($row['id']) === true) {
                        return (int) $row['id'];
                    }

                    return null;
                },
                $rows
            )
        );

        if (empty($ids) === true) {
            return;
        }

        $qb = $db->getQueryBuilder();
        $qb->delete('openregister_objects')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        $qb->executeStatement();
    }//end deleteBlobRows()
}//end class
