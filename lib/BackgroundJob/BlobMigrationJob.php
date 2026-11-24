<?php

/**
 * Blob Migration Background Job
 *
 * Recurring background job that migrates objects from the legacy blob table
 * (oc_openregister_objects) to schema-specific magic tables. Runs every 5 minutes
 * and processes up to 100 objects per execution, grouped by register+schema pair.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
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
class BlobMigrationJob extends TimedJob {
	/**
	 * Interval: 5 minutes
	 */
	private const INTERVAL = 5 * 60;

	/**
	 * Maximum objects to process per run
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Blob columns carried over as `_<column>` metadata.
	 *
	 * @var string[]
	 */
	private const METADATA_FIELDS = [
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


	/**
	 * Constructor
	 *
	 * @param ITimeFactory $time Time factory for parent class
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(ITimeFactory $time, private readonly ContainerInterface $container) {
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
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	protected function run($argument): void {
		$run = new BlobMigrationRun(container: $this->container);
		$run->logger->info(message: '[BlobMigrationJob] Starting blob-to-magic migration batch', context: ['file' => __FILE__, 'line' => __LINE__]);

		if ($this->migrationComplete(run: $run) === true) {
			return;
		}

		try {
			$this->migrateBatch(run: $run);
		} catch (Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
			$run->logger->error(message: '[BlobMigrationJob] Migration batch failed', context: $context);
		}
	}//end run()

	/**
	 * Whether an earlier run already finished the migration.
	 *
	 * @param BlobMigrationRun $run The running batch
	 *
	 * @return bool
	 */
	private function migrationComplete(BlobMigrationRun $run): bool {
		if ($run->config(key: 'complete', default: 'false') !== 'true') {
			return false;
		}

		$run->logger->debug(message: '[BlobMigrationJob] Migration already complete, skipping', context: ['file' => __FILE__, 'line' => __LINE__]);
		return true;
	}//end migrationComplete()

	/**
	 * Move one batch of blob rows to their magic tables and record the progress.
	 *
	 * The migration is marked complete when the blob table is gone or empty.
	 *
	 * @param BlobMigrationRun $run The running batch
	 *
	 * @return void
	 */
	private function migrateBatch(BlobMigrationRun $run): void {
		if ($this->blobTableExists(db: $run->db) === false) {
			$this->markComplete(run: $run, message: 'Blob table does not exist, marking migration complete');
			return;
		}

		$objects = $this->fetchBlobObjects(db: $run->db);
		if (empty($objects) === true) {
			$this->markComplete(run: $run, message: 'No objects remaining in blob table, marking migration complete');
			$run->setConfig(key: 'remaining', value: '0');
			return;
		}

		$this->migrateGroups(run: $run, groups: $this->groupByRegisterSchema(objects: $objects, logger: $run->logger));
		$this->recordProgress(run: $run);
	}//end migrateBatch()

	/**
	 * Migrate every group; orphaned rows are counted as skipped.
	 *
	 * @param BlobMigrationRun                                $run    The running batch
	 * @param array<string, array<int, array<string, mixed>>> $groups Rows by group key
	 *
	 * @return void
	 */
	private function migrateGroups(BlobMigrationRun $run, array $groups): void {
		foreach ($groups as $key => $rows) {
			if ($key === 'orphaned') {
				$run->skipped += count($rows);
				continue;
			}

			$this->migrateGroup(run: $run, group: new BlobGroup(key: $key, rows: $rows));
		}
	}//end migrateGroups()

	/**
	 * Log why the migration is complete and mark it so.
	 *
	 * @param BlobMigrationRun $run     The running batch
	 * @param string           $message Reason, after the log prefix
	 *
	 * @return void
	 */
	private function markComplete(BlobMigrationRun $run, string $message): void {
		$run->logger->info(message: '[BlobMigrationJob] '.$message, context: ['file' => __FILE__, 'line' => __LINE__]);
		$run->setConfig(key: 'complete', value: 'true');
	}//end markComplete()

	/**
	 * Save one group's rows to its magic table and delete them from the blob table.
	 *
	 * A group whose register or schema cannot be resolved is skipped.
	 *
	 * @param BlobMigrationRun $run   The running batch
	 * @param BlobGroup        $group Rows of one register and schema
	 *
	 * @return void
	 */
	private function migrateGroup(BlobMigrationRun $run, BlobGroup $group): void {
		$pair = $this->resolveGroup(run: $run, group: $group);
		if ($pair === null) {
			$run->skipped += count($group->rows);
			return;
		}

		[$register, $schema] = $pair;
		$run->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
		$objects = array_map(fn (array $row): array => $this->blobRowToObjectArray(row: $row), $group->rows);
		$table   = new RegisterSchema(register: $register, schema: $schema);
		$saved   = count($run->magicMapper->saveObjectsToRegisterSchemaTable(table: $table, objects: $objects));

		$context = ['file' => __FILE__, 'line' => __LINE__] + $group->ids() + ['count' => $saved];
		$run->logger->info(message: '[BlobMigrationJob] Migrated group to magic table', context: $context);
		$this->deleteBlobRows(db: $run->db, rows: $group->rows);
		$run->migrated += $saved;
	}//end migrateGroup()

	/**
	 * The register and schema of a group, or null (with a warning) when either cannot be loaded.
	 *
	 * @param BlobMigrationRun $run   The running batch
	 * @param BlobGroup        $group Rows of one register and schema
	 *
	 * @return array{0: Register, 1: Schema}|null
	 */
	private function resolveGroup(BlobMigrationRun $run, BlobGroup $group): ?array {
		try {
			$register = $run->registerMapper->find(id: (int)$group->registerId, _rbac: false, _multitenancy: false);
			$schema   = $run->schemaMapper->find(id: (int)$group->schemaId, _rbac: false, _multitenancy: false);
			return [$register, $schema];
		} catch (Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__] + $group->ids() + ['error' => $e->getMessage()];
			$run->logger->warning(message: '[BlobMigrationJob] Could not resolve register/schema, skipping group', context: $context);
			return null;
		}
	}//end resolveGroup()

	/**
	 * Store the processed and remaining counts and the run time, and log the batch.
	 *
	 * @param BlobMigrationRun $run The finished batch
	 *
	 * @return void
	 */
	private function recordProgress(BlobMigrationRun $run): void {
		$previousProcessed = (int)$run->config(key: 'processed', default: '0');
		$remaining         = $this->countBlobRows(db: $run->db);

		$run->setConfig(key: 'processed', value: (string)($previousProcessed + $run->migrated));
		$run->setConfig(key: 'remaining', value: (string)$remaining);
		$run->setConfig(key: 'last_run', value: date('c'));

		$duration = round(microtime(true) - $run->startTime, 2);
		$context  = ['file' => __FILE__, 'line' => __LINE__, 'migrated' => $run->migrated, 'skipped' => $run->skipped, 'remaining' => $remaining];
		$context['execution_time_seconds'] = $duration;
		$run->logger->info(message: '[BlobMigrationJob] Batch completed', context: $context);
	}//end recordProgress()

	/**
	 * Check if the blob table exists in the database.
	 *
	 * @param IDBConnection $db Database connection
	 *
	 * @return bool True if the table exists
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function blobTableExists(IDBConnection $db): bool {
		try {
			$platform = $db->getDatabasePlatform();
			$schema   = 'DATABASE()';
			if (stripos($platform::class, 'PostgreSQL') !== false) {
				$schema = 'current_schema()';
			}

			$sql      = "SELECT 1 FROM information_schema.tables WHERE table_name = '*PREFIX*openregister_objects' AND table_schema = $schema LIMIT 1";

			$stmt = $db->prepare($sql);
			$stmt->execute();
			return $stmt->fetch() !== false;
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
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function fetchBlobObjects(IDBConnection $db): array {
		$qb = $db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_objects')
			->setMaxResults(self::BATCH_SIZE);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
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
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countBlobRows(IDBConnection $db): int {
		$qb = $db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) as count'))
			->from('openregister_objects');

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}//end countBlobRows()

	/**
	 * Group blob rows by their register+schema combination.
	 *
	 * Objects with null/invalid register or schema are placed in the 'orphaned' group.
	 *
	 * @param array<int, array<string, mixed>> $objects Raw blob rows
	 * @param LoggerInterface $logger Logger for warnings
	 *
	 * @return array<string, array<int, array<string, mixed>>> Grouped rows keyed by "registerId_schemaId"
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function groupByRegisterSchema(array $objects, LoggerInterface $logger): array {
		$groups = [];

		foreach ($objects as $row) {
			$registerId = $row['register'] ?? null;
			$schemaId = $row['schema'] ?? null;

			if (empty($registerId) === true || empty($schemaId) === true) {
				$context = ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $row['uuid'] ?? 'unknown', 'registerId' => $registerId, 'schemaId' => $schemaId];
				$logger->warning(message: '[BlobMigrationJob] Orphaned object: null/empty register or schema', context: $context);
				$groups['orphaned'][] = $row;
				continue;
			}

			$groups[$registerId.'_'.$schemaId][] = $row;
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
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function blobRowToObjectArray(array $row): array {
		// The object payload, with the metadata that MagicMapper expects.
		$result = $this->decodeBlobObject(row: $row);
		foreach (self::METADATA_FIELDS as $field) {
			if (isset($row[$field]) === true) {
				$result['_' . $field] = $row[$field];
			}
		}

		// Ensure uuid is set at top level too (required by saveObjectsToRegisterSchemaTable).
		if (isset($row['uuid']) === true) {
			$result['uuid'] = $row['uuid'];
			$result['_uuid'] = $row['uuid'];
		}

		return $result;
	}//end blobRowToObjectArray()

	/**
	 * The decoded JSON object payload of a blob row; empty when absent or not a JSON object.
	 *
	 * @param array<string, mixed> $row Raw blob table row
	 *
	 * @return array<string, mixed>
	 */
	private function decodeBlobObject(array $row): array {
		if (isset($row['object']) === false || is_string($row['object']) === false) {
			return [];
		}

		$decoded = json_decode($row['object'], true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decodeBlobObject()

	/**
	 * Delete migrated rows from the blob table.
	 *
	 * @param IDBConnection $db Database connection
	 * @param array<int, array<string, mixed>> $rows Rows to delete
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function deleteBlobRows(IDBConnection $db, array $rows): void {
		$withId = array_filter($rows, static fn (array $row): bool => isset($row['id']) === true);
		$ids    = array_filter(array_map(static fn (array $row): int => (int)$row['id'], $withId));

		if (empty($ids) === true) {
			return;
		}

		$qb = $db->getQueryBuilder();
		$qb->delete('openregister_objects')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$qb->executeStatement();
	}//end deleteBlobRows()
}//end class
