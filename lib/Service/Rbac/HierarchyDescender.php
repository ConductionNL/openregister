<?php

/**
 * Reads one level of an object hierarchy (ledger row Q13.23).
 *
 * The queries {@see HierarchyGrantExpander} runs, kept apart from the decision
 * it makes with them. That split is not tidiness: the expander is the thing
 * with a verb rule, a cycle rule and a depth cap in it, and those are worth
 * testing against a table of rows rather than against a database.
 *
 * WHY THE PARENT AND THE CHILD ARE IN THE SAME TABLE. The declared edge is a
 * reference to the SAME schema, so a row and its parent are two rows of one
 * magic table, `openregister_table_<register>_<schema>`. That is what makes a
 * level a single `IN (...)` query rather than a join across anything.
 *
 * WHY THE PARENT COLUMN IS DERIVED AND THEN CHECKED AGAINST THE TABLE. The
 * magic tables are snake_case renderings of camelCase properties, and a column
 * that does not exist would make the query THROW rather than answer nothing, on
 * a code path where throwing is a 500 on every list. The column list is read
 * once per request and a declaration naming a column the table does not have is
 * skipped with a warning, which is the same fail-closed direction as everything
 * else on this path: no inheritance, and a sentence saying why.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the hierarchical schemas, and reads one level of children at a time.
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */
class HierarchyDescender {

	/**
	 * How many parent uuids may go into one level's `IN (...)`.
	 *
	 * A grant set is small, but a level of a wide tree is not, and some
	 * backends refuse a very long IN list outright. The level is read in
	 * chunks so a wide tree answers rather than erroring.
	 *
	 * @var integer
	 */
	private const CHUNK = 500;

	/**
	 * Resolved declarations, for the lifetime of ONE request.
	 *
	 * Per request and never longer, for the reason {@see ObjectGrantResolver}
	 * gives about its own memo: this feeds an authorization verdict, and a
	 * stale answer here is wrong in both directions.
	 *
	 * @var array<int, array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int}>|null
	 */
	private ?array $memoised = null;

	/**
	 * Column names per table, for the lifetime of one request.
	 *
	 * @var array<string, string[]>
	 */
	private array $columns = [];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 * @param SchemaMapper $schemaMapper Reads the schemas.
	 * @param RegisterMapper $registerMapper Reads the registers a schema belongs to.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every (table, parent column) pair a hierarchy is declared over.
	 *
	 * @return array<int, array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int}>
	 *         One entry per register the hierarchical schema belongs to.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function hierarchicalTables(): array {
		if ($this->memoised !== null) {
			return $this->memoised;
		}

		$reader = new HierarchyGrantExpander(descender: $this, logger: $this->logger);
		$resolved = [];

		// `_rbac: false`: this reads the SCHEMA definitions to find out how
		// authorization works, and running it through the authorization it is
		// about is how a resolver ends up depending on itself.
		$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

		foreach ($schemas as $schema) {
			$declaration = $reader->declarationFor(schema: $schema);
			if ($declaration === null) {
				continue;
			}

			$schemaId = (int)$schema->getId();
			$parentColumn = $this->columnFor(property: $declaration['parent']);

			foreach ($registers as $register) {
				if ($this->registerHolds(register: $register, schemaId: $schemaId) === false) {
					continue;
				}

				$table = MagicMapper::TABLE_PREFIX . (int)$register->getId() . '_' . $schemaId;
				if ($this->tableHasColumn(table: $table, column: $parentColumn) === false) {
					$this->logger->warning(
						message: '[HierarchyDescender] A schema declares a parent property its table does not carry; nothing is inherited for it',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaId' => $schemaId,
							'property' => $declaration['parent'],
							'column' => $parentColumn,
							'table' => $table,
						]
					);
					continue;
				}

				$resolved[] = [
					'table' => $table,
					'parentColumn' => $parentColumn,
					'maxDepth' => $declaration['maxDepth'],
					'verbs' => $declaration['verbs'],
					'schemaId' => $schemaId,
				];
			}//end foreach
		}//end foreach

		$this->memoised = $resolved;

		return $resolved;
	}//end hierarchicalTables()

	/**
	 * The children of a set of parents, as child uuid => parent uuid.
	 *
	 * @param string $table The magic table.
	 * @param string $parentColumn The column naming the parent.
	 * @param string[] $parentUuids The parents to read children of.
	 *
	 * @return array<string, string> Child UUID => parent UUID.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function childrenOf(string $table, string $parentColumn, array $parentUuids): array {
		if (empty($parentUuids) === true) {
			return [];
		}

		$children = [];
		foreach (array_chunk($parentUuids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('_uuid', $parentColumn)
				->from($table)
				->where(
					$qb->expr()->in(
						$parentColumn,
						$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)
					)
				);

			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				$childUuid = (string)($row['_uuid'] ?? '');
				$parentUuid = (string)($row[$parentColumn] ?? '');
				if ($childUuid === '' || $parentUuid === '' || $childUuid === $parentUuid) {
					// A row naming ITSELF as its parent is the shortest cycle
					// there is, and it is the one an import writes. Dropping it
					// here means the expander never has to treat it specially.
					continue;
				}

				$children[$childUuid] = $parentUuid;
			}

			$result->closeCursor();
		}//end foreach

		return $children;
	}//end childrenOf()

	/**
	 * The ancestors of one object, nearest first.
	 *
	 * The walk UP, which the descent has no use for and the audit cannot do
	 * without: an inherited grant is written on an ancestor's folder, so
	 * answering "why can this person see this object" means naming the
	 * ancestors and asking each of them.
	 *
	 * Bounded by the same `maxDepth` the descent honours and by the same
	 * seen-set, for the same reason: a parent chain that returns to itself is
	 * something an import writes, and a walk that does not expect one never
	 * returns.
	 *
	 * @param integer $registerId The register the object is in.
	 * @param integer $schemaId The object's schema.
	 * @param string $objectUuid The object to walk up from.
	 *
	 * @return string[] The ancestor UUIDs, nearest first, empty when the schema declares no hierarchy.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function ancestorsOf(int $registerId, int $schemaId, string $objectUuid): array {
		if ($objectUuid === '') {
			return [];
		}

		$hierarchy = null;
		foreach ($this->hierarchicalTables() as $candidate) {
			if ($candidate['schemaId'] === $schemaId
				&& $candidate['table'] === (MagicMapper::TABLE_PREFIX . $registerId . '_' . $schemaId)
			) {
				$hierarchy = $candidate;
				break;
			}
		}

		if ($hierarchy === null) {
			return [];
		}

		$ancestors = [];
		$seen = [$objectUuid => true];
		$current = $objectUuid;

		for ($depth = 0; $depth < $hierarchy['maxDepth']; $depth++) {
			$parent = $this->parentOf(
				table: $hierarchy['table'],
				parentColumn: $hierarchy['parentColumn'],
				uuid: $current
			);

			if ($parent === null || $parent === '' || isset($seen[$parent]) === true) {
				break;
			}

			$ancestors[] = $parent;
			$seen[$parent] = true;
			$current = $parent;
		}

		return $ancestors;
	}//end ancestorsOf()

	/**
	 * The uuid one row names as its parent, or null.
	 *
	 * @param string $table The magic table.
	 * @param string $parentColumn The parent column.
	 * @param string $uuid The row.
	 *
	 * @return string|null The parent UUID, or null.
	 */
	private function parentOf(string $table, string $parentColumn, string $uuid): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($parentColumn)
				->from($table)
				->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($uuid)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if (is_array($row) === false) {
				return null;
			}

			return (string)($row[$parentColumn] ?? '');
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HierarchyDescender] Could not read an object\'s parent; the walk up stops here',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $table,
					'object' => $uuid,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end parentOf()

	/**
	 * The column a property is stored in.
	 *
	 * The same camelCase to snake_case rendering
	 * {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::propertyToColumnName()}
	 * uses. Two renderings of one rule drift, so if that one ever changes this
	 * one has to move with it, which is what the shared test pins.
	 *
	 * @param string $property The declared property name.
	 *
	 * @return string The column name.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function columnFor(string $property): string {
		return strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $property));
	}//end columnFor()

	/**
	 * Forget what was resolved, for tests and for a schema saved mid-request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function forget(): void {
		$this->memoised = null;
		$this->columns = [];
	}//end forget()

	/**
	 * Whether a register holds this schema.
	 *
	 * A register's `schemas` list carries ids, and has carried them as ints and
	 * as numeric strings over the years, so the comparison is on the string
	 * rendering rather than strict: a strict compare against the wrong one of
	 * those reads as "no register holds this schema", which is an absent
	 * feature rather than an error.
	 *
	 * @param object $register The register.
	 * @param integer $schemaId The schema id.
	 *
	 * @return bool True when the register holds it.
	 */
	private function registerHolds(object $register, int $schemaId): bool {
		try {
			$schemas = $register->getSchemas();
		} catch (Throwable $e) {
			return false;
		}

		if (is_array($schemas) === false) {
			return false;
		}

		foreach ($schemas as $entry) {
			if (is_array($entry) === true) {
				$entry = ($entry['id'] ?? ($entry['schema'] ?? ''));
			}

			if ((string)$entry === (string)$schemaId) {
				return true;
			}
		}

		return false;
	}//end registerHolds()

	/**
	 * Whether a table carries a column.
	 *
	 * @param string $table The table, without the instance prefix.
	 * @param string $column The column.
	 *
	 * @return bool True when the column is there.
	 */
	private function tableHasColumn(string $table, string $column): bool {
		if (array_key_exists($table, $this->columns) === false) {
			try {
				// 🔴 NOT `IDBConnection::getPrefix()`. OCP exposes no such
				// method: calling it is a runtime Error, and because the catch
				// below takes every Throwable, this whole lookup answered "the
				// table carries no columns" on every call. A hierarchy grant
				// then silently stopped descending, with a warning in the log
				// and nothing on screen. The prefix is discovered from the
				// schema instead, by matching the table's own name.
				$schemaManager = $this->db->createSchema();
				$this->columns[$table] = [];
				foreach ($schemaManager->getTables() as $candidate) {
					$name = (string)$candidate->getName();
					if ($name !== $table && str_ends_with($name, '_' . $table) === false) {
						continue;
					}

					$this->columns[$table] = array_map(
						static fn (object $c): string => strtolower((string)$c->getName()),
						$candidate->getColumns()
					);
					break;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[HierarchyDescender] Could not read a table\'s columns; treating it as carrying none',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $table,
						'exception' => $e->getMessage(),
					]
				);
				$this->columns[$table] = [];
			}
		}

		return in_array(strtolower($column), $this->columns[$table], true);
	}//end tableHasColumn()
}//end class
