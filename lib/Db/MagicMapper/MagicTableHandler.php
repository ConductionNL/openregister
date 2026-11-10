<?php

/**
 * MagicMapper Table Management Handler
 *
 * This handler provides table lifecycle management for dynamic schema-based tables.
 * It implements table creation, existence checking, synchronization, caching,
 * and configuration checking specifically designed for register+schema table combinations.
 *
 * KEY RESPONSIBILITIES:
 * - Table existence checking with intelligent caching
 * - Table creation and recreation (force mode)
 * - Table synchronization when schemas change
 * - Cache management for table metadata
 * - Discovery of existing register+schema tables
 * - Magic mapping configuration checking
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
 * @since 2.0.0 Initial implementation for MagicMapper table management
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Table management handler for MagicMapper dynamic tables
 *
 * This class provides table lifecycle operations specifically optimized
 * for register+schema dynamic tables, including creation, synchronization,
 * existence checking, and cache management.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Table lifecycle requires IDBConnection, IAppConfig,
 *   LoggerInterface, MagicMapper, Register, and Schema — each used for a distinct concern (DDL, feature
 *   flags, audit, column building, and schema metadata).
 * @SuppressWarnings(PHPMD.StaticAccess)           MagicMapper::isTableColumnsVerified / setTableColumnsVerified
 *   are process-scoped static caches with no injectable alternative in the current design.
 */
class MagicTableHandler implements MagicTableHandlerInterface {

	/**
	 * The database table prefix the table listing assumes (the Nextcloud default).
	 *
	 * @var string
	 */
	private const DEFAULT_DB_PREFIX = 'oc_';

	/**
	 * Constructor for MagicTableHandler
	 *
	 * @param MagicTableSql                  $sql             The connection, the table prefix and the logger
	 * @param IAppConfig                     $appConfig       App configuration for feature flags
	 * @param MagicMapperInterface           $magicMapper     Parent MagicMapper for callback access to helpers
	 * @param MagicMetadataColumnHealer|null $metadataColumns Adds missing metadata columns on first use of a table
	 */
	public function __construct(
		private readonly MagicTableSql $sql,
		private readonly IAppConfig $appConfig,
		private readonly MagicMapperInterface $magicMapper,
		private readonly ?MagicMetadataColumnHealer $metadataColumns = null,
	) {
	}//end __construct()


	/**
	 * Ensure a specialized table exists for register+schema combination
	 *
	 * Creates the table if it doesn't exist, updates it if schema changed,
	 * and optionally recreates it if force flag is set.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 * @param bool $force Force table recreation even if exists
	 *
	 * @return bool True if table exists/was created successfully
	 *
	 * @throws Exception If table creation/update fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
	 */
	public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force = false): bool {
		return $this->ensureTarget(target: new MagicTableTarget(register: $register, schema: $schema), force: $force);
	}//end ensureTableForRegisterSchema()

	/**
	 * Ensure the table of one register+schema target exists and is current.
	 *
	 * @param MagicTableTarget $target The register and schema of the table.
	 * @param bool             $force  Force table recreation even if exists.
	 *
	 * @return bool True if table exists/was created successfully
	 *
	 * @throws Exception If table creation/update fails
	 */
	private function ensureTarget(MagicTableTarget $target, bool $force): bool {
		$target->cacheKey = $this->magicMapper->getCacheKey(registerId: $target->register->getId(), schemaId: $target->schema->getId());

		// Verified once per process, checked FIRST.
		//
		// Every insert, update and find path in MagicMapper calls this method, so
		// it runs once per object operation rather than once per register+schema.
		// Importing OpenCatalogi's configuration produced 1,137 calls for 225
		// object saves — and each one built a table name, resolved two slugs, and
		// wrote an info log before reaching the identical fast path inside
		// handleExistingTable() and returning true.
		//
		// This is that exact predicate, hoisted: same two conditions, same
		// meaning, evaluated before the work rather than after it. It does not
		// reduce how often callers CALL this method; it makes the redundant calls
		// cost two comparisons instead of a log write and an existence check.
		//
		// Both conditions are load-bearing and neither may be dropped.
		// hasRegisterSchemaChanged() compares the stored schema VERSION, so a
		// schema updated mid-process (an import bumping a version, then saving
		// objects against it) still falls through and re-syncs its columns.
		// isTableColumnsVerified() is process-scoped, so a fresh request always
		// re-verifies at least once. `force` bypasses both, as before.
		if ($force === false && $this->isVerifiedAndCurrent(target: $target) === true) {
			return true;
		}

		$target->tableName = $this->getTableNameForRegisterSchema(register: $target->register, schema: $target->schema);
		$this->logEnsure(target: $target, force: $force);

		try {
			return $this->ensureTable(target: $target, force: $force);
		} catch (Exception $e) {
			throw $this->ensureFailure(target: $target, error: $e);
		}
	}//end ensureTarget()

	/**
	 * Whether the table's columns were verified this process and its schema version is unchanged.
	 *
	 * @param MagicTableTarget $target The table, with its cache key.
	 *
	 * @return bool True when the table needs no work.
	 */
	private function isVerifiedAndCurrent(MagicTableTarget $target): bool {
		return MagicMapper::isTableColumnsVerified(cacheKey: $target->cacheKey) === true
			&& $this->magicMapper->hasRegisterSchemaChanged(register: $target->register, schema: $target->schema) === false;
	}//end isVerifiedAndCurrent()

	/**
	 * Log that a table is being created or updated.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param bool             $force  Whether the table is recreated.
	 *
	 * @return void
	 */
	private function logEnsure(MagicTableTarget $target, bool $force): void {
		$this->sql->logger->info(
			message: '[MagicTableHandler] Creating/updating table for register+schema',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $target->register->getId(),
				'schemaId' => $target->schema->getId(),
				'registerSlug' => $target->register->getSlug(),
				'schemaSlug' => $target->schema->getSlug(),
				'tableName' => $target->tableName,
				'force' => $force,
			]
		);
	}//end logEnsure()

	/**
	 * Use an existing table, recreate it when forced, or create it.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param bool             $force  Whether an existing table is dropped and recreated.
	 *
	 * @return bool True when the table is ready to use.
	 *
	 * @throws Exception If table creation/update fails
	 */
	private function ensureTable(MagicTableTarget $target, bool $force): bool {
		// Check if table exists using cached method.
		if ($this->tableExistsForRegisterSchema(register: $target->register, schema: $target->schema) === true) {
			if ($force === false) {
				return $this->handleExistingTable(target: $target);
			}

			// Recreate if forced.
			$this->magicMapper->dropTable(tableName: $target->tableName);
			$this->magicMapper->invalidateTableCache(cacheKey: $target->cacheKey);
		}

		return $this->magicMapper->createTableForRegisterSchema(register: $target->register, schema: $target->schema);
	}//end ensureTable()

	/**
	 * Log a failed ensure and wrap the cause in an exception naming the register and schema.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param Exception        $error  The cause.
	 *
	 * @return Exception The exception to throw.
	 */
	private function ensureFailure(MagicTableTarget $target, Exception $error): Exception {
		$context = $target->logContext(file: __FILE__, line: __LINE__) + ['error' => $error->getMessage()];
		$this->sql->logger->error(message: '[MagicTableHandler] Failed to ensure table for register+schema', context: $context);

		$regTitle = $target->register->getTitle();
		$schTitle = $target->schema->getTitle();
		$msg = "Failed to create/update table for register '{$regTitle}' ";
		$msg .= "+ schema '{$schTitle}': " . $error->getMessage();
		return new Exception($msg, 0, $error);
	}//end ensureFailure()

	/**
	 * Handle an existing table: verify columns or sync if schema has changed.
	 *
	 * Called only when the table exists and force=false. Returns true when the
	 * table is already up to date; triggers a sync and returns its result when
	 * columns are missing or need retyping.
	 *
	 * @param MagicTableTarget $target The table, with its name and cache key.
	 *
	 * @return bool True when the table is ready to use.
	 */
	private function handleExistingTable(MagicTableTarget $target): bool {
		// Fast path: schema unchanged and columns already verified this process.
		if ($this->magicMapper->hasRegisterSchemaChanged(register: $target->register, schema: $target->schema) === false) {
			if (MagicMapper::isTableColumnsVerified(cacheKey: $target->cacheKey) === true) {
				return true;
			}

			if ($this->verifyColumns(target: $target) === true) {
				return true;
			}
		}

		// Schema changed or columns missing/wrong type: sync.
		$result = $this->syncTableForRegisterSchema(register: $target->register, schema: $target->schema);
		return $result['success'] ?? true;
	}//end handleExistingTable()

	/**
	 * Sanity check: verify all schema columns exist and that object-typed
	 * columns aren't still on the legacy JSONB storage type (issue #1720).
	 *
	 * Marks the table verified when nothing is missing or mistyped; otherwise
	 * logs why a sync is forced.
	 *
	 * @param MagicTableTarget $target The table, with its name and cache key.
	 *
	 * @return bool True when the table's columns match its schema.
	 */
	private function verifyColumns(MagicTableTarget $target): bool {
		$columns = [
			'required' => $this->magicMapper->buildTableColumnsFromSchema(schema: $target->schema),
			'current' => $this->magicMapper->getExistingTableColumns(tableName: $target->tableName),
		];
		// Compare on PHYSICAL column names: required columns are keyed by
		// property name (camelCase) while the live table reports snake_case,
		// so a raw array_diff_key() flags every camelCase property as missing
		// and the forced sync below re-fires on every request forever.
		$missingColumns = $this->magicMapper->findMissingColumns(currentColumns: $columns['current'], requiredColumns: $columns['required']);
		$retypeColumns = $this->retypeColumns(tableName: $target->tableName, columns: $columns);
		if (empty($missingColumns) === true && empty($retypeColumns) === true) {
			MagicMapper::setTableColumnsVerified(cacheKey: $target->cacheKey);
			return true;
		}

		$this->logForcedSync(tableName: $target->tableName, drift: ['missingColumns' => array_keys($missingColumns), 'retypeColumns' => $retypeColumns]);
		return false;
	}//end verifyColumns()

	/**
	 * The columns that need retyping, migrating relation columns to VARCHAR first.
	 *
	 * A property that BECOMES a relation stops holding a document and starts
	 * holding a bare UUID, so its column must go from json to VARCHAR. Without
	 * this the table stays broken forever after such an edit — every save is
	 * rejected with "invalid input syntax for type json".
	 *
	 * @param string                                  $tableName Physical table name.
	 * @param array{required: array, current: array} $columns   The schema's and the live table's columns.
	 *
	 * @return array The columns needing a retype.
	 */
	private function retypeColumns(string $tableName, array $columns): array {
		$current = $columns['current'];
		$required = $columns['required'];
		$retypeColumns = $this->magicMapper->findJsonbColumnsNeedingRetype(currentColumns: $current, requiredColumns: $required);
		$relationRetype = $this->magicMapper->findRelationColumnsNeedingRetype(currentColumns: $current, requiredColumns: $required);
		if (empty($relationRetype) === false) {
			$this->magicMapper->migrateRelationColumnsToVarchar(tableName: $tableName, columns: new MagicColumnDrift(current: $current, required: $required));
			$retypeColumns = array_merge($retypeColumns, $relationRetype);
		}

		return $retypeColumns;
	}//end retypeColumns()

	/**
	 * Warn that an unchanged schema version still has missing or mistyped columns.
	 *
	 * @param string $tableName Physical table name.
	 * @param array  $drift     missingColumns and retypeColumns.
	 *
	 * @return void
	 */
	private function logForcedSync(string $tableName, array $drift): void {
		$warnMsg = '[MagicTableHandler] Schema version unchanged';
		$warnMsg .= ' but columns missing or need retype, forcing sync';
		$this->sql->logger->warning(
			message: $warnMsg,
			context: ['file' => __FILE__, 'line' => __LINE__, 'tableName' => $tableName] + $drift
		);
	}//end logForcedSync()


	/**
	 * Get table name for a specific register+schema combination
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return string The table name for the register+schema combination
	 */
	public function getTableNameForRegisterSchema(Register $register, Schema $schema): string {
		$registerId = $register->getId();
		$schemaId = $schema->getId();

		// Use numeric IDs for consistent, shorter table names.
		$tableName = MagicMapper::TABLE_PREFIX . $registerId . '_' . $schemaId;

		// Ensure table name doesn't exceed maximum length (should be fine with numeric IDs).
		if (strlen($tableName) > MagicMapper::MAX_TABLE_NAME_LENGTH) {
			// This should rarely happen with numeric IDs, but handle it safely.
			$hash = substr(md5($registerId . '_' . $schemaId), 0, 8);
			$tableName = MagicMapper::TABLE_PREFIX . $hash;
		}

		// Cache the table name for this register+schema combination.
		$cacheKey = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);
		MagicMapper::setRegSchemaTableCache(key: $cacheKey, value: $tableName);

		return $tableName;
	}//end getTableNameForRegisterSchema()

	/**
	 * Check if a specialized table exists for register+schema combination
	 *
	 * This method provides fast existence checking with intelligent caching
	 * to avoid repeated database calls. Cache is automatically invalidated
	 * when tables are created, updated, or dropped.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if specialized table exists, false if should use generic storage
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function existsTableForRegisterSchema(Register $register, Schema $schema): bool {
		$cacheKey = $this->magicMapper->getCacheKey(registerId: $register->getId(), schemaId: $schema->getId());

		// Check cache first (with timeout).
		if ($this->hasFreshExistsCache(cacheKey: $cacheKey) === true) {
			$this->ensureMetadataColumns(register: $register, schema: $schema);
			return true;
		}

		// Check database for table existence.
		$tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$exists = $this->magicMapper->checkTableExistsInDatabase(tableName: $tableName);

		if ($exists === true) {
			// Cache positive result.
			MagicMapper::setTableExistsCache(key: $cacheKey, value: time());
			$this->ensureMetadataColumns(register: $register, schema: $schema);
		}

		return $exists;
	}//end existsTableForRegisterSchema()

	/**
	 * Whether the table-exists cache holds an unexpired entry; an expired one is removed.
	 *
	 * @param string $cacheKey Per-register+schema cache key.
	 *
	 * @return bool True when the cached answer may be used.
	 */
	private function hasFreshExistsCache(string $cacheKey): bool {
		$cachedTime = MagicMapper::getTableExistsCache(key: $cacheKey);
		if ($cachedTime === null) {
			return false;
		}

		if ((time() - $cachedTime) < MagicMapper::TABLE_CACHE_TIMEOUT) {
			return true;
		}

		// Cache expired, remove it.
		MagicMapper::unsetTableExistsCache(key: $cacheKey);
		return false;
	}//end hasFreshExistsCache()

	/**
	 * Give an existing table any metadata column it lacks, before it is queried.
	 *
	 * Every list, count, facet and cross-table search asks
	 * existsTableForRegisterSchema() before it queries a table, and every one
	 * of those queries carries `_archived IS NULL`. Checking here means a table
	 * that never got the column (an upgrade step that did not run, a restore
	 * from an old backup) heals on its first use instead of failing every
	 * request. The healer answers from a memo after the first call, so this
	 * costs an array lookup per call in the normal case.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	private function ensureMetadataColumns(Register $register, Schema $schema): void {
		if ($this->metadataColumns === null) {
			return;
		}

		$this->metadataColumns->ensure(
			tableName: $this->getTableNameForRegisterSchema(register: $register, schema: $schema)
		);
	}//end ensureMetadataColumns()

	/**
	 * Check if register+schema table exists (with caching)
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return bool True if table exists
	 */
	public function tableExistsForRegisterSchema(Register $register, Schema $schema): bool {
		return $this->existsTableForRegisterSchema(register: $register, schema: $schema);
	}//end tableExistsForRegisterSchema()

	/**
	 * Sync table structure for register+schema combination
	 *
	 * Creates the table if it doesn't exist, or updates its structure
	 * if the schema has changed. Returns statistics about the changes made.
	 *
	 * @param Register $register The register context
	 * @param Schema $schema The schema context
	 *
	 * @return array Statistics about what was changed
	 *
	 * @throws Exception If table sync fails
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function syncTableForRegisterSchema(Register $register, Schema $schema): array {
		$target = new MagicTableTarget(register: $register, schema: $schema);
		$target->tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$target->cacheKey = $this->magicMapper->getCacheKey(registerId: $register->getId(), schemaId: $schema->getId());

		$this->sql->logger->info(
			message: '[MagicTableHandler] Syncing register+schema table',
			context: $target->logContext(file: __FILE__, line: __LINE__)
		);

		try {
			return $this->syncTable(target: $target);
		} catch (Exception $e) {
			throw $this->loggedSyncFailure(target: $target, error: $e);
		}
	}//end syncTableForRegisterSchema()

	/**
	 * Log a failed sync and hand the exception back to be rethrown unchanged.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param Exception        $error  The failure.
	 *
	 * @return Exception The same exception.
	 */
	private function loggedSyncFailure(MagicTableTarget $target, Exception $error): Exception {
		$this->sql->logger->error(
			message: '[MagicTableHandler] Failed to update register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'tableName' => $target->tableName,
				'error' => $error->getMessage(),
			]
		);

		return $error;
	}//end loggedSyncFailure()

	/**
	 * Create the table when it does not exist, else bring its structure and indexes in line.
	 *
	 * @param MagicTableTarget $target The table, with its name and cache key.
	 *
	 * @return array Statistics about what was changed
	 *
	 * @throws Exception If table sync fails
	 */
	private function syncTable(MagicTableTarget $target): array {
		if ($this->tableExistsForRegisterSchema(register: $target->register, schema: $target->schema) === false) {
			return $this->createMissingTable(target: $target);
		}

		$this->sql->logger->info(
			message: '[MagicTableHandler] Table exists, updating structure',
			context: $target->logContext(file: __FILE__, line: __LINE__)
		);

		$result = $this->alterTable(tableName: $target->tableName, schema: $target->schema);
		$this->magicMapper->updateTableIndexes(tableName: $target->tableName, register: $target->register, schema: $target->schema);
		$this->markSynced(target: $target);
		$this->logSynced(target: $target, stats: $result);

		return $result;
	}//end syncTable()

	/**
	 * Log a successful structure update.
	 *
	 * @param MagicTableTarget $target The table, with its cache key.
	 * @param array            $stats  Statistics about what was changed.
	 *
	 * @return void
	 */
	private function logSynced(MagicTableTarget $target, array $stats): void {
		$this->sql->logger->info(
			message: '[MagicTableHandler] Successfully updated register+schema table',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'tableName' => $target->tableName,
				'cacheKey' => $target->cacheKey,
				'stats' => $stats,
			]
		);
	}//end logSynced()

	/**
	 * Create a table that does not exist yet.
	 *
	 * @param MagicTableTarget $target The table.
	 *
	 * @return array Statistics: every column was added.
	 */
	private function createMissingTable(MagicTableTarget $target): array {
		$this->sql->logger->info(
			message: '[MagicTableHandler] Table does not exist, creating it',
			context: $target->logContext(file: __FILE__, line: __LINE__)
		);

		$this->magicMapper->createTableForRegisterSchema(register: $target->register, schema: $target->schema);
		$columns = new TableSyncColumns(required: $this->magicMapper->buildTableColumnsFromSchema(schema: $target->schema));
		return $columns->createdStatistics();
	}//end createMissingTable()

	/**
	 * Store the updated schema version and refresh the caches after a sync.
	 *
	 * @param MagicTableTarget $target The table, with its cache key.
	 *
	 * @return void
	 */
	private function markSynced(MagicTableTarget $target): void {
		$this->magicMapper->storeRegisterSchemaVersion(register: $target->register, schema: $target->schema);
		MagicMapper::setTableExistsCache(key: $target->cacheKey, value: time());

		MagicMapper::setTableColumnsVerified(cacheKey: $target->cacheKey);
	}//end markSynced()


	/**
	 * Bring an existing table's columns in line with its schema.
	 *
	 * @param string $tableName The magic table name
	 * @param Schema $schema    The schema the table stores
	 *
	 * @return array Statistics about what was changed
	 */
	private function alterTable(string $tableName, Schema $schema): array {
		$currentColumns = $this->magicMapper->getExistingTableColumns(tableName: $tableName);
		$requiredColumns = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);
		$changes = $this->magicMapper->updateTableStructure(
			tableName: $tableName,
			currentColumns: $currentColumns,
			requiredColumns: $requiredColumns
		);

		$columns = new TableSyncColumns(required: $requiredColumns, changes: $changes);
		return $columns->updatedStatistics(current: $currentColumns);
	}//end alterTable()


	/**
	 * Clear all caches for MagicMapper
	 *
	 * @param int|null $registerId Optional register ID to clear cache for specific register
	 * @param int|null $schemaId Optional schema ID to clear cache for specific schema
	 *
	 * @return void
	 */
	public function clearCache(?int $registerId = null, ?int $schemaId = null): void {
		if ($registerId === null || $schemaId === null) {
			$this->clearAllCaches();
			return;
		}

		$this->clearOneCache(registerId: $registerId, schemaId: $schemaId);
	}//end clearCache()

	/**
	 * Clear the caches of one register+schema combination.
	 *
	 * @param int $registerId The register ID.
	 * @param int $schemaId   The schema ID.
	 *
	 * @return void
	 */
	private function clearOneCache(int $registerId, int $schemaId): void {
		$cacheKey = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);
		$this->magicMapper->invalidateTableCache(cacheKey: $cacheKey);

		$this->sql->logger->debug(
			message: '[MagicTableHandler] Cleared MagicMapper cache for register+schema',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'cacheKey' => $cacheKey,
			]
		);
	}//end clearOneCache()

	/**
	 * Clear every MagicMapper cache.
	 *
	 * @return void
	 */
	private function clearAllCaches(): void {
		MagicMapper::clearAllStaticCaches();

		$this->sql->logger->debug(message: '[MagicTableHandler] Cleared all MagicMapper caches', context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end clearAllCaches()

	/**
	 * Get all existing register+schema tables
	 *
	 * This method scans the database for all tables matching our naming pattern
	 * and returns them as an array of register+schema combinations.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 */
	public function getExistingRegisterSchemaTables(): array {
		try {
			return $this->listRegisterSchemaTables();
		} catch (Exception $e) {
			$this->sql->logger->error(
				message: '[MagicTableHandler] Failed to get existing register+schema tables',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return [];
		}
	}//end getExistingRegisterSchemaTables()

	/**
	 * List the register+schema tables and log how many were found.
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 *
	 * @throws Exception When the tables cannot be listed.
	 */
	private function listRegisterSchemaTables(): array {
		$registerSchemaTables = $this->registerSchemaTablesIn(rows: $this->fetchMagicTableRows());

		$this->sql->logger->info(
			message: '[MagicTableHandler] Found existing register+schema tables',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'tableCount' => count($registerSchemaTables),
			]
		);

		return $registerSchemaTables;
	}//end listRegisterSchemaTables()

	/**
	 * List the database tables whose name starts with the magic table prefix.
	 *
	 * @return array The rows, each with a `tablename`.
	 */
	private function fetchMagicTableRows(): array {
		// Use direct SQL to list tables (Nextcloud 32 compatible).
		// NOTE: We use raw SQL here because pg_tables is a system table that should not be prefixed.
		$searchPattern = self::DEFAULT_DB_PREFIX . MagicMapper::TABLE_PREFIX . '%';

		$sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?";
		$stmt = $this->sql->db->prepare($sql);
		$stmt->execute([$searchPattern]);
		return $stmt->fetchAll();
	}//end fetchMagicTableRows()

	/**
	 * The register+schema tables among the listed rows, pre-populating the caches for each.
	 *
	 * @param array $rows The rows from fetchMagicTableRows().
	 *
	 * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
	 */
	private function registerSchemaTablesIn(array $rows): array {
		$registerSchemaTables = [];
		foreach ($rows as $row) {
			$entry = $this->registerSchemaEntry(tableName: $row['tablename']);
			if ($entry === null) {
				continue;
			}

			$registerSchemaTables[] = $entry;

			// Pre-populate cache while we're at it.
			$cacheKey = $this->magicMapper->getCacheKey(registerId: $entry['registerId'], schemaId: $entry['schemaId']);
			MagicMapper::setTableExistsCache(key: $cacheKey, value: time());
			MagicMapper::setRegSchemaTableCache(key: $cacheKey, value: $entry['tableName']);
		}

		return $registerSchemaTables;
	}//end registerSchemaTablesIn()

	/**
	 * The register and schema IDs a table name encodes, or null for any other table.
	 *
	 * @param string $tableName The full table name.
	 *
	 * @return array{registerId: int, schemaId: int, tableName: string}|null The entry, or null.
	 */
	private function registerSchemaEntry(string $tableName): array|null {
		$fullPrefix = self::DEFAULT_DB_PREFIX . MagicMapper::TABLE_PREFIX;
		if (str_starts_with($tableName, $fullPrefix) === false) {
			return null;
		}

		// Extract register and schema IDs from table name. Expected format: {registerId}_{schemaId}.
		$suffix = substr($tableName, strlen($fullPrefix));
		if (preg_match('/^(\d+)_(\d+)$/', $suffix, $matches) !== 1) {
			return null;
		}

		return ['registerId' => (int)$matches[1], 'schemaId' => (int)$matches[2], 'tableName' => $tableName];
	}//end registerSchemaEntry()

	/**
	 * Check if MagicMapper is enabled for a register+schema combination
	 *
	 * @param Register $_register The register to check
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if MagicMapper should be used for this register+schema
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $_register is for future per-register overrides; check uses schema config and global flag only
	 */
	public function isMagicMappingEnabled(Register $_register, Schema $schema): bool {
		return $this->schemaOrGlobalMagicMapping(schema: $schema);
	}//end isMagicMappingEnabled()

	/**
	 * BACKWARD COMPATIBILITY: Check if MagicMapper is enabled for a schema only
	 *
	 * @param Schema $schema The schema to check
	 *
	 * @deprecated Use isMagicMappingEnabled(Register, Schema) instead
	 * @return bool True if MagicMapper should be used for this schema
	 */
	public function isMagicMappingEnabledForSchema(Schema $schema): bool {
		// For backward compatibility, just check schema config without register context.
		return $this->schemaOrGlobalMagicMapping(schema: $schema);
	}//end isMagicMappingEnabledForSchema()

	/**
	 * Whether the schema's configuration enables magic mapping, else whether it is enabled globally.
	 *
	 * @param Schema $schema The schema to check
	 *
	 * @return bool True if MagicMapper should be used for this schema
	 */
	private function schemaOrGlobalMagicMapping(Schema $schema): bool {
		// Enable magic mapping if explicitly enabled in schema config.
		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === true && ($configuration['magicMapping'] ?? null) === true) {
			return true;
		}

		// Check global configuration.
		$globalEnabled = $this->appConfig->getValueString('openregister', 'magic_mapping_enabled', 'false');

		return $globalEnabled === 'true';
	}//end schemaOrGlobalMagicMapping()
}//end class
