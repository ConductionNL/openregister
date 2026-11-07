<?php

/**
 * MagicMapper Bulk Operations Handler
 *
 * This handler provides high-performance bulk operations for dynamic schema-based tables.
 * It implements optimized bulk save, update, delete, publish, and depublish operations
 * specifically designed for dynamically created tables.
 *
 * KEY RESPONSIBILITIES:
 * - High-performance bulk save operations for dynamic tables
 * - Optimized bulk update operations with schema-aware field mapping
 * - Bulk delete operations (soft and hard delete support)
 * - Bulk publish/depublish operations
 * - Memory-efficient processing for large datasets
 * - Transaction management for data consistency
 *
 * PERFORMANCE OPTIMIZATIONS:
 * - Schema-specific column mapping for faster operations
 * - Prepared statement reuse for bulk operations
 * - Memory-efficient chunking strategies
 * - Optimized INSERT...ON DUPLICATE KEY UPDATE queries
 * - Reduced SQL overhead compared to generic table operations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper bulk operations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\ObjectVersionHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk operations handler for MagicMapper dynamic tables
 *
 * This class provides high-performance bulk operations specifically optimized
 * for schema-specific dynamic tables, offering better performance than generic
 * table operations due to schema-aware optimizations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class MagicBulkHandler {
	use MagicColumnNameTrait;

	/**
	 * What the update half of an upsert leaves as stored.
	 *
	 * A bulk row carries the writer as owner and the writer's organisation, which
	 * says who created a new row and nothing about an existing one. An owner only
	 * changes through the ownership endpoint.
	 *
	 * @var array<int, string>
	 */
	private const COLUMNS_KEPT_ON_UPDATE = ['_uuid', '_created', '_version', '_owner', '_organisation'];

	/**
	 * Maximum packet size buffer percentage (0.1 = 10%, 0.5 = 50%)
	 * Lower values = more conservative chunk sizes
	 *
	 * @var float
	 */
	private float $maxPacketSizeBuffer = 0.5;

	/**
	 * Cache for table columns to avoid repeated database queries
	 *
	 * @var array<string, array<string>>
	 */
	private array $tableColumnsCache = [];

	/** @var array<string, bool> Tables whose schema has managed slugs (#4037): the upsert keeps a stored `_slug`. */
	private array $managedSlugTables = [];

	/**
	 * Decides the version an inserted row starts on.
	 *
	 * Constructed rather than injected: it is stateless arithmetic over one
	 * string, and version policy belongs in one class rather than being
	 * re-stated wherever a row is written.
	 *
	 * @var ObjectVersionHandler
	 */
	private ObjectVersionHandler $versionHandler;

	/**
	 * Constructor for MagicBulkHandler
	 *
	 * @param IDBConnection $db Database connection for operations
	 * @param LoggerInterface $logger Logger for debugging and error reporting
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for business logic hooks
	 * @param DateTimeNormalizer $dateTimeNormalizer Normaliser for user-supplied datetime input
	 * @param IConfig $config Nextcloud config for reading the table prefix
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly DateTimeNormalizer $dateTimeNormalizer,
		private readonly IConfig $config,
	) {
		// Try to get max_allowed_packet from database configuration.
		$this->initializeMaxPacketSize();
		$this->versionHandler = new ObjectVersionHandler();
	}//end __construct()

	/**
	 * Build the full, prefixed table name for a bare magic table name.
	 *
	 * Uses the configured `dbtableprefix` rather than a hardcoded `oc_` so
	 * raw-SQL paths work on installs with a custom prefix (BUG-DB-3).
	 *
	 * @param string $tableName The bare table name (without prefix).
	 *
	 * @return string The full table name including the configured prefix.
	 */
	private function getFullTableName(string $tableName): string {
		return ((string)$this->config->getSystemValue('dbtableprefix', 'oc_')) . $tableName;
	}//end getFullTableName()

	/**
	 * Prepare objects for dynamic table structure
	 *
	 * @param array $objects Array of object data
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 *
	 * @return (false|int|mixed|null|string)[][] Array of prepared object data
	 *
	 * @psalm-return list<non-empty-array<string, false|int|mixed|null|string>>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Bulk preparation requires handling multiple data type scenarios
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md#requirement-a-property-whose-name-starts-with-an-underscore-is-read-as-a-property-req-mtr-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) MagicBulkUpsertSql is a stateless helper of pure functions.
	 */
	private function prepareObjectsForDynamicTable(array $objects, Register $register, Schema $schema): array {
		$prepared = [];
		$now = new DateTime();
		$columnRoles = new MagicMetadataColumn();

		foreach ($objects as $object) {
			$preparedObject = [];

			// Extract @self metadata.
			// Handle both formats:
			// 1. Objects with @self key (legacy format).
			// 2. Flat selfData arrays (optimized format from TransformationHandler).
			// Object is already a flat selfData array by default - use it directly.
			$selfData = $object;
			if (($object['@self'] ?? null) !== null) {
				$selfData = $object['@self'];
			}

			// Map metadata to prefixed columns with proper fallbacks.
			$uuid = $selfData['uuid'] ?? $selfData['id'] ?? $object['id'] ?? Uuid::v4()->toRfc4122();
			$preparedObject['_uuid'] = $uuid;
			$preparedObject['_register'] = $register->getId();
			$preparedObject['_schema'] = $schema->getId();

			// SECURITY (wave-11 SB2 — defense-in-depth layer 2): _owner and _organisation
			// MUST already have been stamped with authoritative values by SaveObjects::
			// prepareSingleSchemaObject() (the first strip layer).  This second strip
			// ensures that any future code path that calls prepareObjectsForDynamicTable()
			// directly (e.g. ImportService) cannot slip client-supplied values through.
			// $selfData['owner'] and $selfData['organisation'] were already overwritten with
			// the session-user and active-organisation values before this point.
			// We do NOT fall back to $object['owner'] / $object['organisation'] here because
			// those keys come from raw client JSON and must not be trusted.
			$preparedObject['_owner'] = $selfData['owner'] ?? null;
			$preparedObject['_organisation'] = $selfData['organisation'] ?? null;

			// Format datetime fields to MySQL-compatible format (Y-m-d H:i:s).
			$createdValue = $selfData['created'] ?? $object['created'] ?? $now->format('Y-m-d H:i:s');
			$preparedObject['_created'] = $this->formatDateTimeForDatabase(
				value: $createdValue,
				default: $now->format('Y-m-d H:i:s')
			);

			$preparedObject['_updated'] = $now->format('Y-m-d H:i:s');
			$preparedObject = $this->withExpiry(prepared: $preparedObject, object: $object, selfData: $selfData);

			$preparedObject = $this->withDisplayColumns(
				prepared: $preparedObject,
				selfData: $selfData,
				object: $object,
				managedSlug: MagicBulkUpsertSql::hasManagedSlugs(schema: $schema)
			);

			$preparedObject['_version'] = $this->versionHandler->initialVersionFor(candidate: ($selfData['version'] ?? $object['version'] ?? null));

			// Calculate object size for storage analytics.
			// This is the size of the serialized object data for storage analytics.
			$objectSize = strlen(json_encode($object));
			$preparedObject['_size'] = (string)$objectSize;

			// Map relations (scanned UUIDs/URLs from object data).
			$relations = $selfData['relations'] ?? $object['relations'] ?? null;
			if ($relations !== null && is_array($relations) === true) {
				$preparedObject['_relations'] = json_encode(array_values($relations));
			} elseif ($relations !== null && is_string($relations) === true && $relations !== '') {
				// Only use string value if not empty (empty string is invalid JSON for PostgreSQL).
				$preparedObject['_relations'] = $relations;
			}

			// Map ALL object properties to columns (camelCase → snake_case).
			// Properties can be at top level OR in 'object' key (structured format).
			$propertySource = $object['object'] ?? $object;
			foreach ($propertySource as $propertyName => $value) {
				// A declared property that would write a metadata column is skipped
				// with the undeclared `_` keys and `@self`: the metadata columns were
				// stamped above and a client value must not replace them.
				if ($columnRoles->skippedOnWrite(key: (string)$propertyName, schemaProperties: ($schema->getProperties() ?? [])) === true) {
					continue;
				}

				$columnName = $this->sanitizeColumnName(name: $propertyName);

				// Convert empty strings to NULL for all schema properties.
				// This is necessary because:
				// 1. PostgreSQL rejects empty strings for JSON columns
				// 2. Column types might differ from schema (e.g., table created with old schema)
				// 3. Empty strings are semantically equivalent to NULL for most use cases.
				$value = match ($value) { '' => null, default => $value };

				// Convert complex values for database storage.
				$preparedObject[$columnName] = match (true) { is_array($value), is_object($value) => json_encode($value), default => $value };
			}//end foreach

			$prepared[] = $preparedObject;
		}//end foreach

		return $prepared;
	}//end prepareObjectsForDynamicTable()


	/**
	 * Map the six display-metadata columns onto a prepared row.
	 *
	 * Each reads `@self` first and the raw payload second, which is the same
	 * fallback the surrounding method applies to every other metadata column.
	 * Extracted as a group because they share that rule exactly, and because
	 * `prepareObjectsForDynamicTable()` sits on the method-length threshold.
	 *
	 * @param array<string, mixed> $prepared The row being built.
	 * @param array<string, mixed> $selfData The object's `@self` metadata.
	 * @param array<string, mixed> $object The raw object payload.
	 * @param bool $managedSlug Whether the schema has managed slugs; only then is a slug carried onto the row.
	 *
	 * @return array<string, mixed> The row with the display columns set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `managedSlug` is a fact about the schema, carried to the one column that varies on it.
	 * @SuppressWarnings(PHPMD.StaticAccess) MagicBulkUpsertSql is a stateless helper of pure functions.
	 */
	private function withDisplayColumns(array $prepared, array $selfData, array $object, bool $managedSlug=false): array {
		$prepared['_name'] = $selfData['name'] ?? $object['name'] ?? null;
		$prepared['_description'] = $selfData['description'] ?? $object['description'] ?? null;
		$prepared['_summary'] = $selfData['summary'] ?? $object['summary'] ?? null;
		$prepared['_image'] = $selfData['image'] ?? $object['image'] ?? null;
		$prepared['_slug'] = MagicBulkUpsertSql::rowSlug(selfData: $selfData, object: $object, managedSlug: $managedSlug);
		$prepared['_uri'] = $selfData['uri'] ?? $object['uri'] ?? null;

		return $prepared;
	}//end withDisplayColumns()

	/**
	 * Copy an object's expiry into the prepared row's `_expires` column, when it has one.
	 *
	 * An expiry is METADATA, not a property: it lives in the metadata block
	 * (`@self`, or the flat selfData shape whose properties sit under `object`)
	 * and lands in the indexed `_expires` column that purgeExpired() sweeps. It
	 * is deliberately NOT read from a bare property map: a schema is free to
	 * declare its own `expires` property, and that must stay a plain data
	 * column rather than silently scheduling the row for deletion. A row that
	 * carries no expiry gets NO `_expires` key, so an upsert of an existing row
	 * leaves its expiry alone; a row inserted without one keeps NULL and is
	 * never purged.
	 *
	 * @param array $prepared The prepared row so far.
	 * @param array $object   The object as passed to bulkUpsert().
	 * @param array $selfData The object's metadata block.
	 *
	 * @return array The prepared row, with `_expires` set when the object carries an expiry.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	private function withExpiry(array $prepared, array $object, array $selfData): array {
		$hasMetadataBlock = (($object['@self'] ?? null) !== null || isset($object['object']) === true);
		$expiresValue = $selfData['expires'] ?? null;
		if ($expiresValue === null || $hasMetadataBlock === false) {
			return $prepared;
		}

		$prepared['_expires'] = $this->formatDateTimeForDatabase(value: $expiresValue, default: null);

		return $prepared;
	}//end withExpiry()

	/**
	 * Calculate optimal chunk size for bulk operations
	 *
	 * @param array $objects Array of objects to analyze
	 *
	 * @return int Optimal chunk size
	 *
	 * @psalm-return int<5, 500>
	 */
	private function calculateOptimalChunkSize(array $objects): int {
		if ($objects === []) {
			return 50;
		}

		// Sample objects to estimate size.
		$sampleSize = min(10, count($objects));
		$totalSize = 0;

		for ($i = 0; $i < $sampleSize; $i++) {
			$objectSize = strlen(json_encode($objects[$i]));
			$totalSize += $objectSize;
		}

		$averageSize = $totalSize / $sampleSize;

		// Calculate safe chunk size based on packet size.
		$maxPacketSize = $this->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
		$safeChunkSize = intval($maxPacketSize / $averageSize);

		// Keep within reasonable bounds.
		return max(5, min(500, $safeChunkSize));
	}//end calculateOptimalChunkSize()

	/**
	 * Get max_allowed_packet size from database
	 *
	 * @return int Max packet size in bytes
	 */
	private function getMaxAllowedPacketSize(): int {
		// `SHOW VARIABLES` is a MySQL-ism. On PostgreSQL the statement is a
		// syntax error — and although the exception is swallowed here, a
		// failed statement inside an OPEN TRANSACTION aborts the whole
		// transaction on postgres (SQLSTATE 25P02). This runs from the
		// handler CONSTRUCTOR (lazily instantiated mid-save), so it would
		// abort any caller-managed transaction wrapping a save (e.g. the
		// ADR-051 handoff engine). Only probe on MySQL/MariaDB.
		if ($this->db->getDatabaseProvider() !== \OCP\IDBConnection::PLATFORM_MYSQL) {
			return 16777216;
		}

		try {
			$stmt = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
			$result = $stmt->fetch();

			if ($result !== false && (($result['Value'] ?? null) !== null)) {
				return (int)$result['Value'];
			}
		} catch (\Exception $e) {
			// Log error but continue with default.
		}

		// Default fallback value (16MB).
		return 16777216;
	}//end getMaxAllowedPacketSize()

	/**
	 * Initialize max packet size buffer based on database configuration
	 *
	 * @return void
	 */
	private function initializeMaxPacketSize(): void {
		try {
			$maxPacketSize = $this->getMaxAllowedPacketSize();

			// Adjust buffer based on detected packet size.
			// 30% buffer for smaller packet sizes.
			$this->maxPacketSizeBuffer = 0.3;
			if ($maxPacketSize > 67108864) {
				// > 64MB.
				// 60% buffer.
				$this->maxPacketSizeBuffer = 0.6;
			} elseif ($maxPacketSize > 33554432) {
				// > 32MB.
				// 50% buffer.
				$this->maxPacketSizeBuffer = 0.5;
			} elseif ($maxPacketSize > 16777216) {
				// > 16MB.
				// 40% buffer.
				$this->maxPacketSizeBuffer = 0.4;
			}
		} catch (\Exception $e) {
			// Use default buffer on error.
		}//end try
	}//end initializeMaxPacketSize()

	/**
	 * Perform bulk upsert operation on dynamic table
	 *
	 * This method provides high-performance INSERT...ON CONFLICT DO UPDATE (PostgreSQL)
	 * or INSERT...ON DUPLICATE KEY UPDATE (MySQL/MariaDB) operations for dynamic tables.
	 * It returns complete objects with database-computed classification (created/updated/unchanged).
	 *
	 * @param array $objects Array of object data in standard format
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 * @param string $tableName Target table name
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *                                  See the SuppressWarnings note below.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @psalm-return list<array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $needsPreUpdateState reports a
	 * FACT about the caller — whether anything will read the pre-update rows —
	 * rather than switching this method between two behaviours. The rows are
	 * fetched for an audit changeset or an update event; a caller with both off
	 * reads only the uuid, and this is how that reaches the query. Inverting it
	 * into two methods would duplicate the whole chunk path to vary one column
	 * list.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) MagicBulkUpsertSql is a stateless helper of pure functions.
	 */
	public function bulkUpsert(
		array $objects,
		Register $register,
		Schema $schema,
		string $tableName,
		bool $needsPreUpdateState = true,
	): array {
		if ($objects === []) {
			return [];
		}

		// Prepare objects for dynamic table structure.
		$preparedObjects = $this->prepareObjectsForDynamicTable(
			objects: $objects,
			register: $register,
			schema: $schema
		);

		if ($preparedObjects === []) {
			return [];
		}

		$numberColumnSizes = (new MagicNumberColumnSizes(db: $this->db, tablePrefix: $this->getFullTableName(tableName: '')))->sizes(tableName: $tableName);
		foreach ($preparedObjects as $preparedObject) {
			(new MagicNumberColumn())->assertRowFits(row: $preparedObject, columnSizes: $numberColumnSizes);
		}

		// Determine optimal chunk size.
		$chunkSize = $this->calculateOptimalChunkSize(objects: $preparedObjects);
		$chunks = array_chunk($preparedObjects, $chunkSize);
		$this->managedSlugTables[$tableName] = MagicBulkUpsertSql::hasManagedSlugs(schema: $schema);

		$allResults = [];

		// Process each chunk.
		// BUG-DB-5: wrap each chunk in a transaction so a mid-chunk failure
		// (e.g. a constraint violation on one row) rolls back the whole chunk
		// instead of leaving a partial, inconsistent set of rows persisted.
		foreach ($chunks as $chunkIndex => $chunk) {
			$this->db->beginTransaction();
			try {
				$chunkResults = $this->executeUpsertChunk(
					chunk: $chunk,
					tableName: $tableName,
					chunkNumber: ($chunkIndex + 1),
					needsPreUpdateState: $needsPreUpdateState
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				$this->logger->error(
					message: '[MagicBulkHandler] Rolled back bulk upsert chunk after failure',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'chunk' => ($chunkIndex + 1),
						'table' => $tableName,
						'error' => $e->getMessage(),
					]
				);
				throw $e;
			}//end try

			$allResults = array_merge($allResults, $chunkResults);
		}//end foreach

		// PERFORMANCE: Event dispatching disabled by default.
		// Dispatching events for 20k+ objects causes 10x slowdown (5000 obj/s -> 500 obj/s).
		// Enable via $dispatchEvents parameter when business logic hooks are needed.
		// $this->dispatchBulkEvents(results: $allResults, register: $register, schema: $schema).
		return $allResults;
	}//end bulkUpsert()

	/**
	 * Execute upsert operation for a single chunk
	 *
	 * @param array $chunk Chunk of prepared objects
	 * @param string $tableName Target table name
	 * @param int $chunkNumber Chunk number for logging
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows. False narrows the fetch
	 *                                  to the uuid. See the SuppressWarnings note.
	 *
	 * @return array Array of complete objects with object_status
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @psalm-return list<array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) See bulkUpsert(): a fact about
	 * the caller, carried to the one statement that varies on it.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	private function executeUpsertChunk(
		array $chunk,
		string $tableName,
		int $chunkNumber,
		bool $needsPreUpdateState = true,
	): array {
		if ($chunk === []) {
			return [];
		}

		$run = new MagicUpsertChunkRun(tableName: $tableName, chunkNumber: $chunkNumber);
		$run->needsPreUpdateState = $needsPreUpdateState;
		$run->objectCount = count($chunk);
		// Record operation start time for precise created/updated detection.
		$run->operationStartTime = (new DateTime())->format('Y-m-d H:i:s');

		$filteredChunk = $this->filterToTableColumns(chunk: $chunk, tableName: $tableName);
		if (empty($filteredChunk) === true) {
			return [];
		}

		$run->rows = $this->deduplicateByUuid(rows: $filteredChunk);
		if (empty($run->rows) === true) {
			return [];
		}

		$run->collectColumnsAndUuids();
		$run->isPostgres = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
		$run->fullTableName = $this->getFullTableName(tableName: $tableName);

		if (empty($run->uuids) === false) {
			$this->loadExistingRows(run: $run);
		}

		$this->executeUpsertStatement(run: $run);

		if (empty($run->uuids) === true) {
			return [];
		}

		return $this->readBackClassified(run: $run);
	}//end executeUpsertChunk()

	/**
	 * Keep only the columns the table has, dropping objects left without any.
	 *
	 * @param array  $chunk     Chunk of prepared objects.
	 * @param string $tableName Target table name.
	 *
	 * @return array<int, array<string, mixed>> The filtered objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function filterToTableColumns(array $chunk, string $tableName): array {
		$tableColumns = $this->getTableColumns(tableName: $tableName);

		$filteredChunk = [];
		foreach ($chunk as $objectData) {
			$filteredObject = [];
			foreach ($objectData as $columnName => $value) {
				if (in_array($columnName, $tableColumns, true) === true) {
					$filteredObject[$columnName] = $value;
					continue;
				}

				$this->logger->debug(
					message: '[MagicBulkHandler] Dropping column not in table',
					context: ['file' => __FILE__, 'line' => __LINE__, 'column' => $columnName, 'table' => $tableName]
				);
			}

			if (empty($filteredObject) === false) {
				$filteredChunk[] = $filteredObject;
			}
		}

		return $filteredChunk;
	}//end filterToTableColumns()

	/**
	 * Deduplicate rows by `_uuid`, keeping the last occurrence in the place of
	 * the first, and give a row without a uuid a new one.
	 *
	 * Duplicates would make PostgreSQL refuse the upsert ("cannot affect row a
	 * second time"). BUG-DB-6: a row without a `_uuid` is persisted under a
	 * generated uuid instead of being dropped.
	 *
	 * @param array<int, array<string, mixed>> $rows The filtered rows.
	 *
	 * @return array<int, array<string, mixed>> The deduplicated rows, re-indexed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function deduplicateByUuid(array $rows): array {
		$deduplicated = [];
		$seenUuids = [];
		foreach ($rows as $objectData) {
			$uuid = $objectData['_uuid'] ?? null;
			if ($uuid === null || $uuid === '') {
				$uuid = Uuid::v4()->toRfc4122();
				$objectData['_uuid'] = $uuid;
				$this->logger->debug(
					message: '[MagicBulkHandler] Generated missing _uuid for bulk object',
					context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid]
				);
			}

			if (isset($seenUuids[$uuid]) === true) {
				$deduplicated[$seenUuids[$uuid]] = $objectData;
				continue;
			}

			$seenUuids[$uuid] = count($deduplicated);
			$deduplicated[] = $objectData;
		}//end foreach

		return array_values($deduplicated);
	}//end deduplicateByUuid()

	/**
	 * Find which uuids exist before the upsert, so created and updated rows are
	 * told apart whatever their timestamps (CSV imports keep historical
	 * `_created` dates), and keep the full pre-update rows when the caller
	 * records an old-vs-new changeset.
	 *
	 * Only `_uuid` is selected when nothing reads the pre-update state: that
	 * read is answered from the `_uuid` index alone (Index Only Scan, measured
	 * 0.537 ms against 1.574 ms per 100 rows for `SELECT *` on 2.3 KB rows).
	 * A failed check is logged and leaves classification to the timestamps.
	 *
	 * @param MagicUpsertChunkRun $run The chunk being upserted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadExistingRows(MagicUpsertChunkRun $run): void {
		$needsPreUpdateState = $run->needsPreUpdateState;
		$existsColumns = '*';
		if ($needsPreUpdateState === false) {
			$existsColumns = ['`_uuid`', '"_uuid"'][(int)$run->isPostgres];
		}

		try {
			$existsStmt = $this->db->prepare($run->selectByUuidSql(columns: $existsColumns));
			$existsStmt->execute(array_values($run->uuids));
			foreach ($existsStmt->fetchAll() as $row) {
				$run->existingUuids[$row['_uuid']] = true;
				if ($needsPreUpdateState === true) {
					// Left empty on the narrow path: a changeset built from
					// uuid-only rows would read as "every field became null".
					$run->preUpdateRows[$row['_uuid']] = $row;
				}
			}

			$this->logger->debug(
				message: '[MagicBulkHandler] Pre-upsert UUID check',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunk' => $run->chunkNumber,
					'total_uuids' => count($run->uuids),
					'existing_uuids' => count($run->existingUuids),
					'new_uuids' => count($run->uuids) - count($run->existingUuids),
				]
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicBulkHandler] Failed to check existing UUIDs, will use timestamp-based classification',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try
	}//end loadExistingRows()

	/**
	 * Build and run the INSERT ... ON DUPLICATE KEY UPDATE (MySQL/MariaDB) or
	 * INSERT ... ON CONFLICT DO UPDATE (PostgreSQL) statement of the chunk.
	 *
	 * @param MagicUpsertChunkRun $run The chunk being upserted.
	 *
	 * @return void
	 *
	 * @throws \Exception When the statement fails; the failure is logged first.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	private function executeUpsertStatement(MagicUpsertChunkRun $run): void {
		[$valuesClause, $parameters] = $run->valuesAndParameters();

		$sql = "INSERT INTO `{$run->fullTableName}` ({$run->quotedColumnList()}) VALUES " . $valuesClause;
		$sql .= ' ON DUPLICATE KEY UPDATE ';
		if ($run->isPostgres === true) {
			$sql = "INSERT INTO \"{$run->fullTableName}\" ({$run->quotedColumnList()}) VALUES " . $valuesClause;
			$sql .= ' ON CONFLICT (_uuid) DO UPDATE SET ';
		}

		$sql .= implode(', ', $this->buildUpsertUpdateClauses(run: $run));

		try {
			$stmt = $this->db->prepare($sql);
			$stmt->execute($parameters);

			$this->logger->info(
				message: '[MagicBulkHandler] Executed UPSERT chunk',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunk' => $run->chunkNumber,
					'objects' => $run->objectCount,
					'table' => $run->tableName,
					'sql_size_kb' => round(strlen($sql) / 1024, 2),
					'sql_preview' => substr($sql, 0, 150),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[MagicBulkHandler] UPSERT chunk failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunk' => $run->chunkNumber,
					'table' => $run->tableName,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end executeUpsertStatement()

	/**
	 * The SET clauses of the upsert's update half.
	 *
	 * COLUMNS_KEPT_ON_UPDATE are never updated: an existing row keeps the version
	 * it reached, so a re-import does not walk it back to the starting version the
	 * INSERT half stamps on a new row, and it keeps its owner and organisation.
	 *
	 * @param MagicUpsertChunkRun $run The chunk being upserted.
	 *
	 * @return array<int, string> The clauses.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) MagicBulkUpsertSql is a stateless helper of pure functions.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	private function buildUpsertUpdateClauses(MagicUpsertChunkRun $run): array {
		$updateClauses = [];
		foreach ($run->columns as $column) {
			if (in_array($column, self::COLUMNS_KEPT_ON_UPDATE, true) === true) {
				continue;
			}

			$updateClauses[] = MagicBulkUpsertSql::updateClause(
				column: $column,
				isPostgres: $run->isPostgres,
				fullTableName: $run->fullTableName,
				freezeSlug: ($this->managedSlugTables[$run->tableName] ?? false)
			);
		}

		return $updateClauses;
	}//end buildUpsertUpdateClauses()

	/**
	 * Read the written rows back and classify each one.
	 *
	 * A uuid that did not exist before is `created`; one that did is `updated`
	 * when its `_updated` is at or after the operation start, else `unchanged`.
	 * An updated row carries its pre-update row for the audit changeset.
	 *
	 * @param MagicUpsertChunkRun $run The chunk being upserted.
	 *
	 * @return array<int, array<string, mixed>> The classified rows.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function readBackClassified(MagicUpsertChunkRun $run): array {
		$stmt = $this->db->prepare($run->selectByUuidSql(columns: '*'));
		$stmt->execute(array_values($run->uuids));

		$completeObjects = [];
		foreach ($stmt->fetchAll() as $obj) {
			$completeObjects[] = $run->classify(obj: $obj);
		}

		$counts = array_count_values(array_column($completeObjects, 'object_status'));
		$this->logger->info(
			message: '[MagicBulkHandler] Classification complete (using pre-upsert UUID check)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'chunk' => $run->chunkNumber,
				'uuids_requested' => count($run->uuids),
				'objects_returned' => count($completeObjects),
				'created' => $counts['created'] ?? 0,
				'updated' => $counts['updated'] ?? 0,
				'unchanged' => $counts['unchanged'] ?? 0,
			]
		);

		return $completeObjects;
	}//end readBackClassified()

	/**
	 * Get list of columns that exist in a table
	 *
	 * @param string $tableName The table name
	 *
	 * @return array List of column names
	 *
	 * @psalm-return list<mixed>
	 */
	private function getTableColumns(string $tableName): array {
		try {
			$platform = $this->db->getDatabasePlatform();
			$isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

			// Get full table name with the configured database prefix.
			$fullTableName = $this->getFullTableName(tableName: $tableName);

			// MySQL/MariaDB: use SHOW COLUMNS.
			$sql = "SHOW COLUMNS FROM `$fullTableName`";
			if ($isPostgres === true) {
				// PostgreSQL: query information_schema with full table name.
				$sql = "SELECT column_name
                        FROM information_schema.columns
                        WHERE table_name = ? AND table_schema = 'public'";
			}

			$stmt = $this->db->prepare($sql);
			// MySQL/MariaDB: SHOW COLUMNS doesn't need parameters.
			$stmtParams = [];
			if ($isPostgres === true) {
				// PostgreSQL information_schema expects table name WITH prefix.
				$stmtParams = [$fullTableName];
			}

			$stmt->execute($stmtParams);

			$columns = [];
			$row = $stmt->fetch();
			while ($row !== false) {
				$columnKey = 'Field';
				if ($isPostgres === true) {
					$columnKey = 'column_name';
				}

				$columns[] = $row[$columnKey];

				$row = $stmt->fetch();
			}

			$this->logger->debug(
				message: '[MagicBulkHandler] Retrieved columns',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $tableName, 'columns' => $columns]
			);
			$this->tableColumnsCache[$tableName] = $columns;

			return $columns;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[MagicBulkHandler] Failed to get table columns',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $tableName,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try
	}//end getTableColumns()

	/**
	 * Format a datetime value to MySQL-compatible format
	 *
	 * Converts ISO 8601 datetime strings (with 'T' and timezone) to MySQL format (Y-m-d H:i:s).
	 *
	 * @param mixed $value The datetime value (string, DateTime object, or null)
	 * @param string|null $default Default value if conversion fails
	 *
	 * @return string|null Formatted datetime string or default value
	 */
	private function formatDateTimeForDatabase(mixed $value, ?string $default): ?string {
		// Delegate to DateTimeNormalizer — empty/whitespace/null input returns null
		// instead of silently becoming "now" via PHP's new DateTime('').
		$formatted = $this->dateTimeNormalizer->formatForDatabase($value);
		return $formatted ?? $default;
	}//end formatDateTimeForDatabase()

	/**
	 * Hard-delete every row of a register+schema table whose `_expires` has passed.
	 *
	 * The counterpart of a raw append: rows written through `bulkUpsert()` with an
	 * expiry carry no audit trail, so there is nothing to tombstone and nothing to
	 * soft-delete. They are simply removed. Rows with a NULL `_expires` are never
	 * touched, and the comparison is done by the database clock (`NOW()`), the
	 * same clock that `SearchTrailMapper::clearLogs()` sweeps by, so a PHP/DB
	 * timezone mismatch cannot widen the window.
	 *
	 * @param Register $register  The register context.
	 * @param Schema   $schema    The schema context.
	 * @param string   $tableName The bare (unprefixed) table name to sweep.
	 *
	 * @return int The number of rows removed.
	 *
	 * @throws \OCP\DB\Exception If the delete fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	public function purgeExpired(Register $register, Schema $schema, string $tableName): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($tableName)
			->where($qb->expr()->eq('_register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('_schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('_expires'))
			->andWhere($qb->expr()->lt('_expires', $qb->createFunction('NOW()')));

		$purged = $qb->executeStatement();

		// Info, not debug: this is destruction, and the count is the only record of it.
		$this->logger->info(
			message: '[MagicBulkHandler] Purged expired raw rows',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'register' => $register->getId(),
				'schema' => $schema->getId(),
				'table' => $tableName,
				'purged' => $purged,
			]
		);

		return $purged;
	}//end purgeExpired()
}//end class
