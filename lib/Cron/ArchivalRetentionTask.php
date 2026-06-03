<?php

/**
 * OpenRegister Archival Retention Task
 *
 * Hourly background job that sweeps all schemas declaring x-openregister-archival,
 * evaluates per-row retention rules, and deletes expired rows via ObjectService.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Hourly sweep that auto-expires objects in archival schemas.
 *
 * Algorithm per run:
 *   1. Enumerate all registers and their schemas.
 *   2. For each schema that declares x-openregister-archival:
 *      a. Resolve the magic table via MagicMapper.
 *      b. Fetch all rows (uuid + created + object columns) with native SQL.
 *      c. Per row, run RetentionEvaluator; if expiresAt < NOW, mark for deletion.
 *      d. Delete via ObjectService::deleteObject(_retentionSweep: true) so the
 *         immutability gate is bypassed and audit trails still fire.
 *      e. Emit one structured log entry: {schemaSlug, scanned, expired, deleted}.
 *
 * @psalm-suppress UnusedClass
 */
class ArchivalRetentionTask extends TimedJob
{

    /**
     * Run every hour.
     */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Constructor — wire dependencies and configure the timed-job settings.
     *
     * @param ITimeFactory       $time               Nextcloud time factory.
     * @param RegisterMapper     $registerMapper     Provides all registers.
     * @param SchemaMapper       $schemaMapper       Provides schema entities.
     * @param MagicMapper        $magicMapper        Resolves magic-table names + runs SQL.
     * @param IDBConnection      $db                 Native DB connection for SELECT queries.
     * @param RetentionEvaluator $retentionEvaluator Computes effectiveRetention per row.
     * @param ObjectService      $objectService      Executes deletions with audit trails.
     * @param LoggerInterface    $logger             Psr logger.
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.1
     */
    public function __construct(
        ITimeFactory $time,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $magicMapper,
        private readonly IDBConnection $db,
        private readonly RetentionEvaluator $retentionEvaluator,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        // Run once per hour.
        $this->setInterval(seconds: self::INTERVAL_SECONDS);

        // Allow deferred execution when the server is under load.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Prevent concurrent instances from double-deleting rows.
        $this->setAllowParallelRuns(allow: false);
    }//end __construct()

    /**
     * Entry point called by the Nextcloud background-job runner.
     *
     * @param mixed $argument Job argument (unused for timed jobs).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.2
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.3
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.4
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.5
     */
    protected function run($argument): void
    {
        $this->logger->info('[ArchivalRetentionTask] Starting archival retention sweep');

        try {
            $registers = $this->registerMapper->findAll();
        } catch (Exception $e) {
            $this->logger->error(
                '[ArchivalRetentionTask] Failed to load registers: '.$e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        $now = new DateTimeImmutable();

        foreach ($registers as $register) {
            $this->sweepRegister(register: $register, now: $now);
        }

        $this->logger->info('[ArchivalRetentionTask] Archival retention sweep complete');
    }//end run()

    /**
     * Sweep all archival schemas in a single register.
     *
     * @param Register          $register The register to sweep.
     * @param DateTimeImmutable $now      Reference timestamp for expiry comparisons.
     *
     * @return void
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.2
     */
    private function sweepRegister(Register $register, DateTimeImmutable $now): void
    {
        $schemaIds = $register->getSchemas();
        if (empty($schemaIds) === true) {
            return;
        }

        foreach ($schemaIds as $schemaId) {
            try {
                $schema = $this->schemaMapper->find(id: (int) $schemaId);
            } catch (Exception $e) {
                $this->logger->warning(
                    '[ArchivalRetentionTask] Could not load schema, skipping',
                    ['schemaId' => $schemaId, 'error' => $e->getMessage()]
                );
                continue;
            }

            $config = $schema->getConfiguration() ?? [];
            if (isset($config['x-openregister-archival']) === false) {
                continue;
            }

            $this->sweepSchema(
                register: $register,
                schema: $schema,
                annotation: $config['x-openregister-archival'],
                now: $now
            );
        }//end foreach
    }//end sweepRegister()

    /**
     * Sweep a single (register, schema) pair: fetch rows, evaluate retention, delete expired.
     *
     * @param Register          $register   The containing register.
     * @param Schema            $schema     The archival schema.
     * @param array             $annotation The x-openregister-archival annotation block.
     * @param DateTimeImmutable $now        Reference timestamp.
     *
     * @return void
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.3
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.4
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5.5
     */
    private function sweepSchema(
        Register $register,
        Schema $schema,
        array $annotation,
        DateTimeImmutable $now
    ): void {
        $schemaSlug = $schema->getSlug() ?? (string) $schema->getId();

        // Resolve the magic table; skip schema if no specialised table exists.
        if ($this->magicMapper->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
            $this->logger->debug(
                '[ArchivalRetentionTask] No magic table for schema, skipping',
                ['schema' => $schemaSlug]
            );
            return;
        }

        $tableName = $this->magicMapper->getTableNameForRegisterSchema(
            register: $register,
            schema: $schema
        );

        // Fetch all rows (uuid, created, plus the object blob for condition evaluation).
        $rows = $this->fetchRows(tableName: $tableName);

        $scanned = count($rows);
        $expired = 0;
        $deleted = 0;

        foreach ($rows as $row) {
            $uuid    = $row['_uuid'] ?? ($row['uuid'] ?? null);
            $created = $row['_created'] ?? ($row['created'] ?? null);

            if ($uuid === null || $created === null) {
                continue;
            }

            try {
                $createdAt = new DateTimeImmutable($created);
            } catch (Exception $e) {
                $this->logger->warning(
                    '[ArchivalRetentionTask] Could not parse _created for row, skipping',
                    ['uuid' => $uuid, 'created' => $created]
                );
                continue;
            }

            // Build the row field map for condition evaluation (merge object blob if available).
            $fieldMap = $this->buildFieldMap(row: $row);

            $result = $this->retentionEvaluator->evaluate(
                annotation: $annotation,
                row: $fieldMap,
                createdAt: $createdAt
            );

            $expiresAtStr = $result['expiresAt'];

            try {
                $expiresAt = new DateTimeImmutable($expiresAtStr);
            } catch (Exception $e) {
                continue;
            }

            if ($expiresAt >= $now) {
                // Row is still within its retention window.
                continue;
            }

            $expired++;

            // Delete via ObjectService with the sweep flag so audit trails fire
            // but the immutability gate is bypassed.
            try {
                $this->objectService->setRegister(register: $register);
                $this->objectService->setSchema(schema: $schema);

                $this->objectService->deleteObject(
                    uuid: $uuid,
                    _rbac: false,
                    _multitenancy: false,
                    _retentionSweep: true
                );
                $deleted++;
            } catch (Exception $e) {
                $this->logger->error(
                    '[ArchivalRetentionTask] Failed to delete expired row',
                    ['uuid' => $uuid, 'schema' => $schemaSlug, 'error' => $e->getMessage()]
                );
            }
        }//end foreach

        $this->logger->info(
            '[ArchivalRetentionTask] Schema sweep complete',
            [
                'schemaSlug' => $schemaSlug,
                'scanned'    => $scanned,
                'expired'    => $expired,
                'deleted'    => $deleted,
            ]
        );
    }//end sweepSchema()

    /**
     * Fetch all rows from the magic table with their metadata columns.
     *
     * @param string $tableName The fully-qualified magic table name.
     *
     * @return array<int, array<string, mixed>> Array of row associative arrays.
     */
    private function fetchRows(string $tableName): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName);

            $result = $qb->executeQuery();
            $rows   = $result->fetchAllAssociative();
            $result->closeCursor();

            return $rows;
        } catch (Exception $e) {
            $this->logger->error(
                '[ArchivalRetentionTask] Failed to query magic table',
                ['table' => $tableName, 'error' => $e->getMessage()]
            );
            return [];
        }
    }//end fetchRows()

    /**
     * Build a flat field map for condition evaluation.
     *
     * The magic table stores user fields in a JSON 'object' blob column alongside
     * explicit columns. Merge both so conditions can reference any field name.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Merged field map.
     */
    private function buildFieldMap(array $row): array
    {
        $objectBlob = $row['object'] ?? $row['_object'] ?? null;
        if (is_string($objectBlob) === true) {
            $decoded = json_decode($objectBlob, true);
            if (is_array($decoded) === true) {
                return array_merge($decoded, $row);
            }
        }

        return $row;
    }//end buildFieldMap()
}//end class
