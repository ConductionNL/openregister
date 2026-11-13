<?php

/**
 * MagicTableIndexer: the indexes of a magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Create the base, platform and property indexes of a magic table.
 *
 * A failed index never fails table creation.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableIndexer {
	use MagicColumnNameTrait;

	/**
	 * Metadata fields indexed on every magic table besides UUID, register/schema,
	 * organisation and owner. `slug` and `uri` are here because the single-object
	 * lookup ORs `_id OR _uuid OR _slug OR _uri`, and Postgres only uses an index
	 * for a disjunction when every branch has one (measured: 2.5ms Seq Scan
	 * against 0.107ms by `_uuid` alone on a 2,962-row register).
	 */
	private const INDEXED_METADATA_FIELDS = ['created', 'updated', 'name', 'slug', 'uri', 'last_user_activity'];

	/**
	 * Build the indexer.
	 *
	 * @param IDBConnection    $db     The connection.
	 * @param LoggerInterface  $logger Receives the outcome.
	 * @param MagicPgTrgmProbe $trgm   Says whether trigram indexes can be made.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly MagicPgTrgmProbe $trgm,
	) {
	}//end __construct()

	/**
	 * Create the indexes of a table; a failure is logged and stops the pass.
	 *
	 * @param MagicTableIndexRun $run    The index-creation pass.
	 * @param Schema             $schema The schema whose properties ask for indexes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function create(MagicTableIndexRun $run, Schema $schema): void {
		try {
			$this->createBaseIndexes(run: $run);
			$this->createPlatformIndexes(run: $run);
			$this->createSchemaIndexes(run: $run, schema: $schema);
			$this->logCreated(run: $run);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to create some table indexes',
				context: ['tableName' => $run->tableName, 'error' => $e->getMessage()]
			);
		}
	}//end create()

	/**
	 * Report what the pass created.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function logCreated(MagicTableIndexRun $run): void {
		$this->logger->debug(
			message: '[MagicMapper] Created table indexes',
			context: [
				'tableName' => $run->tableName,
				'baseIndexCount' => 5 + count(self::INDEXED_METADATA_FIELDS),
				'relationIndexes' => $run->relationIndexes,
				'facetIndexes' => $run->facetIndexes,
				'searchableIndexes' => $run->searchableIndexes,
			]
		);
	}//end logCreated()

	/**
	 * Create the indexes every magic table carries: the unique UUID index, the
	 * multitenancy and RBAC indexes and the frequently filtered metadata fields.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function createBaseIndexes(MagicTableIndexRun $run): void {
		$table = $run->tableName;
		$full = $run->fullTableName;

		$this->db->executeStatement("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_uuid_idx ON {$full} (_uuid)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$table}_register_schema_idx ON {$full} (_register, _schema)");

		// Organisation for multitenancy, owner for RBAC, then the frequently filtered fields.
		foreach (array_merge(['organisation', 'owner'], self::INDEXED_METADATA_FIELDS) as $field) {
			$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$table}_{$field}_idx ON {$full} (_{$field})");
		}
	}//end createBaseIndexes()

	/**
	 * Create the indexes that differ per platform.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function createPlatformIndexes(MagicTableIndexRun $run): void {
		$platform = $this->db->getDatabasePlatform();
		$run->isPostgres = stripos($platform::class, 'PostgreSQL') !== false;
		if ($run->isPostgres === true) {
			$this->createPostgresIndexes(run: $run);
			return;
		}

		$this->createMySqlDeletedOwnerIndex(run: $run);
	}//end createPlatformIndexes()

	/**
	 * Create the indexes each schema property asks for; an entry that is not
	 * a property definition has no column, so it gets no index.
	 *
	 * @param MagicTableIndexRun $run    The index-creation pass.
	 * @param Schema             $schema The schema.
	 *
	 * @return void
	 */
	private function createSchemaIndexes(MagicTableIndexRun $run, Schema $schema): void {
		$sql = new MagicColumnSql(isPostgres: $run->isPostgres);
		foreach ($schema->getProperties() as $propertyName => $propertyConfig) {
			if (is_array($propertyConfig) === false) {
				continue;
			}

			$run->column = $this->sanitizeColumnName(name: $propertyName);
			$run->quotedColumn = $sql->quote(name: $run->column);
			$this->createPropertyIndexes(run: $run, propertyConfig: $propertyConfig);
		}
	}//end createSchemaIndexes()

	/**
	 * Create the PostgreSQL-only indexes: the GIN index on `_relations` for
	 * containment lookups, the partial live-owner index and the `_name` trigram index.
	 *
	 * Every list and search query filters `_deleted IS NULL`, so a partial index
	 * on `_owner` restricted to live rows serves "WHERE _owner = ? AND _deleted IS NULL".
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function createPostgresIndexes(MagicTableIndexRun $run): void {
		$table = $run->tableName;
		$full = $run->fullTableName;

		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$table}_relations_gin_idx ON {$full} USING GIN (_relations)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$table}_live_owner_idx ON {$full} (_owner) WHERE _deleted IS NULL");

		// The name column orders case-insensitively (MagicSearchHandler::addMetadataOrder),
		// so a lower() expression index keeps that order indexable (REQ-CIO-001).
		$this->db->executeStatement(
			"CREATE INDEX IF NOT EXISTS {$table}_name_lower_idx ON {$full} (LOWER(CAST(_name AS TEXT)))"
		);

		if ($this->trgm->available() === true) {
			$this->createNameTrigramIndex(run: $run);
		}
	}//end createPostgresIndexes()

	/**
	 * Create the baseline pg_trgm GIN index on the `_name` metadata column.
	 *
	 * `_name` is searched for every schema by
	 * MagicSearchHandler::buildSearchConditionSql() (the ILIKE path and the
	 * `_fuzzy=true` similarity() path), so a rare-term search without this
	 * index is a sequential scan (measured 268ms; ~1.5ms once GIN-backed on an
	 * 81,950-row table). The index is on an existing varchar/text column, so it
	 * stays invisible to Doctrine's introspectSchema(). A missing extension or
	 * incompatible column never fails table creation.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function createNameTrigramIndex(MagicTableIndexRun $run): void {
		try {
			$this->db->executeStatement(
				"CREATE INDEX IF NOT EXISTS {$run->tableName}_name_trgm_idx ON {$run->fullTableName} USING GIN (_name gin_trgm_ops)"
			);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[MagicMapper] Skipped baseline _name trgm index',
				context: ['tableName' => $run->tableName, 'error' => $e->getMessage()]
			);
		}
	}//end createNameTrigramIndex()

	/**
	 * Create the composite (_deleted, _owner) index on MySQL/MariaDB.
	 *
	 * MySQL/MariaDB have no partial indexes, so the "WHERE _owner = ? AND
	 * _deleted IS NULL" access pattern gets the closest composite index.
	 * `CREATE INDEX IF NOT EXISTS` is not portable across older MySQL, so a
	 * duplicate-index error is treated as a no-op.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass.
	 *
	 * @return void
	 */
	private function createMySqlDeletedOwnerIndex(MagicTableIndexRun $run): void {
		try {
			$this->db->executeStatement(
				"CREATE INDEX {$run->tableName}_deleted_owner_idx ON {$run->fullTableName} (_deleted, _owner)"
			);
		} catch (\Exception $e) {
			// Index already exists (no IF NOT EXISTS on MySQL): ignore.
			$this->logger->debug(
				message: '[MagicMapper] Skipped _deleted/_owner index',
				context: ['tableName' => $run->tableName, 'error' => $e->getMessage()]
			);
		}
	}//end createMySqlDeletedOwnerIndex()

	/**
	 * Create the indexes one property asks for: relation, array GIN, facet and searchable.
	 *
	 * @param MagicTableIndexRun $run            The index-creation pass, positioned on the property's column.
	 * @param array              $propertyConfig The property definition.
	 *
	 * @return void
	 */
	private function createPropertyIndexes(MagicTableIndexRun $run, array $propertyConfig): void {
		if ($this->isSingleRelation(propertyConfig: $propertyConfig) === true
			&& $this->tryCreateColumnIndex(run: $run, suffix: 'rel_idx') === true
		) {
			$run->relationIndexes[] = $run->column;
		}

		if ($this->wantsArrayGinIndex(run: $run, propertyConfig: $propertyConfig) === true
			&& $this->tryCreateColumnIndex(run: $run, suffix: 'arr_gin_idx') === true
		) {
			$run->relationIndexes[] = $run->column . ' (GIN)';
		}

		$this->createOptInIndexes(run: $run, propertyConfig: $propertyConfig);
	}//end createPropertyIndexes()

	/**
	 * Create the indexes a property opts into with `facetable` and `searchable`.
	 *
	 * @param MagicTableIndexRun $run            The index-creation pass, positioned on the property's column.
	 * @param array              $propertyConfig The property definition.
	 *
	 * @return void
	 */
	private function createOptInIndexes(MagicTableIndexRun $run, array $propertyConfig): void {
		if (($propertyConfig['facetable'] ?? false) === true
			&& $this->tryCreateColumnIndex(run: $run, suffix: 'facet_idx') === true
		) {
			$run->facetIndexes[] = $run->column;
		}

		if (($propertyConfig['searchable'] ?? false) === true && $this->trgm->available() === true) {
			$this->createSearchableIndex(run: $run);
		}

		$this->createLowerOrderingIndex(run: $run, propertyConfig: $propertyConfig);
	}//end createOptInIndexes()

	/**
	 * Create a `lower()` expression index for an indexed text column on PostgreSQL.
	 *
	 * A text property orders case-insensitively
	 * (MagicSearchHandler::buildPropertySortExpression), so a string column that
	 * already carries an index (because it is facetable or searchable) gets a
	 * matching `lower()` btree index, keeping that order indexable. MySQL/MariaDB
	 * get none: the task scopes the expression index to PostgreSQL (REQ-CIO-001).
	 *
	 * @param MagicTableIndexRun $run            The index-creation pass, positioned on the property's column.
	 * @param array              $propertyConfig The property definition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
	 */
	private function createLowerOrderingIndex(MagicTableIndexRun $run, array $propertyConfig): void {
		if ($run->isPostgres === false || $this->isTextProperty(propertyConfig: $propertyConfig) === false) {
			return;
		}

		$isIndexed = ($propertyConfig['facetable'] ?? false) === true
			|| (($propertyConfig['searchable'] ?? false) === true && $this->trgm->available() === true);
		if ($isIndexed === false) {
			return;
		}

		try {
			$this->db->executeStatement(
				"CREATE INDEX IF NOT EXISTS {$run->columnIndexName(suffix: 'lower_idx')} ON {$run->fullTableName} "
				. "(LOWER(CAST({$run->quotedColumn} AS TEXT)))"
			);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[MagicMapper] Skipped lower() ordering index',
				context: ['tableName' => $run->tableName, 'column' => $run->column, 'error' => $e->getMessage()]
			);
		}
	}//end createLowerOrderingIndex()

	/**
	 * Whether a property is a text property: a `string` that is not a date.
	 *
	 * @param array $propertyConfig The property definition.
	 *
	 * @return bool
	 */
	private function isTextProperty(array $propertyConfig): bool {
		return ($propertyConfig['type'] ?? 'string') === 'string'
			&& in_array($propertyConfig['format'] ?? null, ['date', 'date-time'], true) === false;
	}//end isTextProperty()

	/**
	 * Whether a property is a single object reference that relates objects.
	 *
	 * @param array $propertyConfig The property definition.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectHandling::relates() is a pure enum-style helper
	 */
	private function isSingleRelation(array $propertyConfig): bool {
		$handling = ($propertyConfig['objectConfiguration'] ?? [])['handling'] ?? null;

		return ($propertyConfig['type'] ?? 'string') === 'object'
			&& isset($propertyConfig['$ref']) === true
			&& ObjectHandling::relates($handling) === true;
	}//end isSingleRelation()

	/**
	 * Whether a property is an array of references (or an inversedBy relation)
	 * on PostgreSQL, which gets a GIN index.
	 *
	 * @param MagicTableIndexRun $run            The index-creation pass.
	 * @param array              $propertyConfig The property definition.
	 *
	 * @return bool
	 */
	private function wantsArrayGinIndex(MagicTableIndexRun $run, array $propertyConfig): bool {
		if (($propertyConfig['type'] ?? 'string') !== 'array' || $run->isPostgres === false) {
			return false;
		}

		$items = $propertyConfig['items'] ?? [];

		return ($items['$ref'] ?? null) !== null
			|| ($items['inversedBy'] ?? ($propertyConfig['inversedBy'] ?? null)) !== null;
	}//end wantsArrayGinIndex()

	/**
	 * Create an index on the current property column, tolerating an index that
	 * already exists or a column type the index does not support.
	 *
	 * @param MagicTableIndexRun $run    The index-creation pass, positioned on the column.
	 * @param string             $suffix The index kind; a `gin` kind is created with USING GIN.
	 *
	 * @return bool Whether the index statement succeeded.
	 */
	private function tryCreateColumnIndex(MagicTableIndexRun $run, string $suffix): bool {
		$using = '';
		if (str_contains($suffix, 'gin') === true) {
			$using = 'USING GIN ';
		}

		try {
			$this->db->executeStatement(
				"CREATE INDEX IF NOT EXISTS {$run->columnIndexName(suffix: $suffix)} ON {$run->fullTableName} {$using}({$run->quotedColumn})"
			);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}//end tryCreateColumnIndex()

	/**
	 * Create the pg_trgm GIN index of a `searchable` property column.
	 *
	 * A column type gin_trgm_ops does not support (a non-string property marked
	 * searchable) or an existing index is logged as a warning; table creation
	 * and sync continue.
	 *
	 * @param MagicTableIndexRun $run The index-creation pass, positioned on the column.
	 *
	 * @return void
	 */
	private function createSearchableIndex(MagicTableIndexRun $run): void {
		try {
			$this->db->executeStatement(
				"CREATE INDEX IF NOT EXISTS {$run->columnIndexName(suffix: 'trgm_idx')} ON {$run->fullTableName} USING GIN ({$run->quotedColumn} gin_trgm_ops)"
			);
			$run->searchableIndexes[] = $run->column;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Skipped searchable trgm index (incompatible column or already present)',
				context: ['tableName' => $run->tableName, 'column' => $run->column, 'error' => $e->getMessage()]
			);
		}
	}//end createSearchableIndex()
}//end class
