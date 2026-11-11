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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the hierarchical schemas, and reads one level of children at a time.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class answers the
 * declaration, the descent and the ascent of a hierarchy over the same memoised
 * tables, and each question is a short method; splitting them would put the
 * memo in two places, which is how a stale answer reaches an authorization verdict.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class HierarchyDescender {
	use MagicColumnNameTrait;

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
	 * Every table on the instance with its columns, for the lifetime of one
	 * request, or null before it has been read.
	 *
	 * @var array<string, string[]>|null
	 */
	private ?array $tables = null;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 * @param SchemaMapperInterface $schemaMapper Reads the schemas.
	 * @param RegisterMapperInterface $registerMapper Reads the registers a schema belongs to.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every (table, parent column) pair a hierarchy is declared over.
	 *
	 * @return array<int, array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int}>
	 *         One entry per register the hierarchical schema belongs to.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
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
			$resolved = array_merge(
				$resolved,
				$this->tablesFor(declaration: $this->declarationOf(reader: $reader, schema: $schema), registers: $registers)
			);
		}

		$this->memoised = $resolved;

		return $resolved;
	}//end hierarchicalTables()

	/**
	 * One schema's hierarchy declaration, with its schema id and parent column, or null.
	 *
	 * @param HierarchyGrantExpander $reader Reads the schema's declaration.
	 * @param object                 $schema The schema.
	 *
	 * @return array<string, mixed>|null The declaration, or null when the schema declares no hierarchy.
	 */
	private function declarationOf(HierarchyGrantExpander $reader, object $schema): ?array {
		$declaration = $reader->declarationFor(schema: $schema);
		if ($declaration === null) {
			return null;
		}

		$declaration['schemaId'] = (int)$schema->getId();
		$declaration['parentColumn'] = $this->columnFor(property: $declaration['parent']);

		return $declaration;
	}//end declarationOf()

	/**
	 * The tables one schema's declared hierarchy is descended in, register by register.
	 *
	 * @param array<string, mixed>|null $declaration The schema's declaration with its schema id and parent column.
	 * @param array                     $registers   Every register.
	 *
	 * @return array<int, array> Entries in register order; none when the schema declares no hierarchy.
	 */
	private function tablesFor(?array $declaration, array $registers): array {
		if ($declaration === null) {
			return [];
		}

		$resolved = [];
		foreach ($registers as $register) {
			$entry = $this->tableIn(register: $register, declaration: $declaration);
			if ($entry !== null) {
				$resolved[] = $entry;
			}
		}

		return $resolved;
	}//end tablesFor()

	/**
	 * The hierarchy entry for one register's table, or null when the table lacks the parent column.
	 *
	 * @param object               $register    The register.
	 * @param array<string, mixed> $declaration The schema's hierarchy declaration, with its schema id and parent column.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	private function tableIn(object $register, array $declaration): array|null {
			// 🔴 THE TABLE IS THE AUTHORITY, NOT THE REGISTER'S `schemas`
			// LIST. That list is only filled by the paths that attach a
			// schema to a register explicitly; a register created over the
			// API and written to directly keeps an EMPTY list while its
			// magic table fills with objects. Gating on the list therefore
			// answered "no register holds this schema" for every schema on
			// such a register, `hierarchicalTables()` returned nothing, and
			// inheritance was inert with no error and no warning — measured
			// on a live instance 2026-09-19, where register 34 held four
			// objects of schema 987 and its `schemas` column was empty.
			//
			// The existence of `openregister_table_<register>_<schema>`
			// carrying the parent column says the same thing the list was
			// being asked to say, and says it from the storage rather than
			// from bookkeeping beside it.
		$table = self::tableNameOf(register: $register, schemaId: $declaration['schemaId']);
		if ($this->tableHasColumn(table: $table, column: $declaration['parentColumn']) === false) {
			$this->warnMissingParentColumn(register: $register, declaration: $declaration);
			return null;
		}

		return [
			'table' => $table,
			'parentColumn' => $declaration['parentColumn'],
			'maxDepth' => $declaration['maxDepth'],
			'verbs' => $declaration['verbs'],
			'schemaId' => $declaration['schemaId'],
		];
	}//end tableIn()

	/**
	 * The magic table of one register for one schema.
	 *
	 * @param object $register The register.
	 * @param mixed  $schemaId The schema id.
	 *
	 * @return string The table name.
	 */
	private static function tableNameOf(object $register, mixed $schemaId): string {
		return MagicMapperInterface::TABLE_PREFIX . (int)$register->getId() . '_' . $schemaId;
	}//end tableNameOf()

	/**
	 * Say that a register's table lacks the declared parent column, when the register claims the schema.
	 *
	 * Only worth saying when the register DOES claim the schema: a table that
	 * is simply not there is the ordinary case for every other register on the
	 * instance.
	 *
	 * @param object               $register    The register.
	 * @param array<string, mixed> $declaration The schema's hierarchy declaration.
	 *
	 * @return void
	 */
	private function warnMissingParentColumn(object $register, array $declaration): void {
		if ($this->registerHolds(register: $register, schemaId: $declaration['schemaId']) === true) {
			$this->logger->warning(
				message: '[HierarchyDescender] A schema declares a parent property its table does not carry; nothing is inherited for it',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $declaration['schemaId'],
					'property' => $declaration['parent'],
					'column' => $declaration['parentColumn'],
					'table' => self::tableNameOf(register: $register, schemaId: $declaration['schemaId']),
				]
			);
		}
	}//end warnMissingParentColumn()

	/**
	 * The children of a set of parents, as child uuid => parent uuid.
	 *
	 * With a limit the read itself is bounded: at most that many rows leave
	 * the database, so a caller that stops at a node cap does not first fetch
	 * a level of a hundred thousand rows to find out it is over it. A row
	 * naming itself as its parent is then left out in SQL, so it does not use
	 * up the limit.
	 *
	 * @param HierarchyLevel $level       The magic table, the column naming the parent and the most rows to read
	 *                                    (null for the whole level).
	 * @param string[]       $parentUuids The parents to read children of.
	 *
	 * @return array<string, string> Child UUID => parent UUID.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function childrenOf(HierarchyLevel $level, array $parentUuids): array {
		return $this->readChildren(
			read: new ChildrenRead(table: $level->table, parentColumn: $level->parentColumn, limit: $level->limit),
			parentUuids: $parentUuids
		);
	}//end childrenOf()

	/**
	 * Read the children of the given parents chunk by chunk, until the read's limit is met.
	 *
	 * @param ChildrenRead $read        The table, the parent column and the limit; collects the children.
	 * @param string[]     $parentUuids The parents to read children of.
	 *
	 * @return array<string, string> Child UUID => parent UUID.
	 */
	private function readChildren(ChildrenRead $read, array $parentUuids): array {
		if ($this->nothingToRead(parentUuids: $parentUuids, limit: $read->limit) === true) {
			return [];
		}

		foreach (array_chunk($parentUuids, self::CHUNK) as $chunk) {
			$read->children = array_replace($read->children, $this->childrenInChunk(read: $read, parents: $chunk));

			if ($read->limitMet() === true) {
				break;
			}
		}

		return $read->children;
	}//end readChildren()

	/**
	 * Whether a children read can be answered without a query: no parents, or a limit below one.
	 *
	 * @param array        $parentUuids The parents.
	 * @param integer|null $limit       The limit, or null for none.
	 *
	 * @return bool True when the answer is no children.
	 */
	private function nothingToRead(array $parentUuids, int|null $limit): bool {
		return (empty($parentUuids) === true || ($limit !== null && $limit < 1));
	}//end nothingToRead()

	/**
	 * The children of one chunk of parents, as child uuid => parent uuid.
	 *
	 * @param ChildrenRead $read    The table, the parent column, and how many rows the read still takes.
	 * @param string[]     $parents The parents of this chunk.
	 *
	 * @return array<string, string> Child UUID => parent UUID.
	 */
	private function childrenInChunk(ChildrenRead $read, array $parents): array {
		$parentColumn = $read->parentColumn;
		$limit = $read->remaining();
		$qb = $this->db->getQueryBuilder();
		$qb->select('_uuid', $parentColumn)
			->from($read->table)
			->where(
				$qb->expr()->in(
					$parentColumn,
					$qb->createNamedParameter($parents, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		if ($limit !== null) {
			$qb->andWhere($qb->expr()->neq('_uuid', $parentColumn));
			$qb->setMaxResults($limit);
		}

		$result = $qb->executeQuery();
		$children = $this->childrenFromRows(rows: $result->fetchAll(), parentColumn: $parentColumn);

		$result->closeCursor();

		return $children;
	}//end childrenInChunk()

	/**
	 * Child uuid => parent uuid from fetched rows, dropping blanks and self-parents.
	 *
	 * @param array<int, array<string, mixed>> $rows         The rows.
	 * @param string                           $parentColumn The parent column.
	 *
	 * @return array<string, string> Child uuid => parent uuid.
	 */
	private function childrenFromRows(array $rows, string $parentColumn): array {
		$children = [];
		foreach ($rows as $row) {
			$childUuid = (string)($row['_uuid'] ?? '');
			$parentUuid = (string)($row[$parentColumn] ?? '');
			if ($this->isChildRow(childUuid: $childUuid, parentUuid: $parentUuid) === false) {
				// A row naming ITSELF as its parent is the shortest cycle
				// there is, and it is the one an import writes. Dropping it
				// here means the expander never has to treat it specially.
				continue;
			}

			$children[$childUuid] = $parentUuid;
		}

		return $children;
	}//end childrenFromRows()

	/**
	 * Whether a row names a child and a different parent.
	 *
	 * @param string $childUuid  The child's uuid.
	 * @param string $parentUuid The parent's uuid.
	 *
	 * @return bool True when both are set and differ.
	 */
	private function isChildRow(string $childUuid, string $parentUuid): bool {
		return ($childUuid !== '' && $parentUuid !== '' && $childUuid !== $parentUuid);
	}//end isChildRow()

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
	 * @param ObjectRef $ref The object to walk up from, by register id, schema id and uuid.
	 *
	 * @return string[] The ancestor UUIDs, nearest first, empty when the schema declares no hierarchy.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function ancestorsOf(ObjectRef $ref): array {
		if ($ref->id === '') {
			return [];
		}

		$hierarchy = $this->hierarchyFor(registerId: (int)$ref->register, schemaId: (int)$ref->schema);
		if ($hierarchy === null) {
			return [];
		}

		return $this->walkUp(hierarchy: $hierarchy, objectUuid: $ref->id);
	}//end ancestorsOf()

	/**
	 * Follow the parent column up from one object, bounded by depth and stopped by a cycle.
	 *
	 * @param array<string, mixed> $hierarchy  The object's hierarchy entry.
	 * @param string               $objectUuid The object to start from.
	 *
	 * @return array<int, string> Ancestor uuids, nearest first.
	 */
	private function walkUp(array $hierarchy, string $objectUuid): array {
		$ancestors = [];
		$seen = [$objectUuid => true];
		$current = $objectUuid;

		for ($depth = 0; $depth < $hierarchy['maxDepth']; $depth++) {
			$parent = $this->parentOf(hierarchy: $hierarchy, uuid: $current);

			if ($parent === null || $parent === '' || isset($seen[$parent]) === true) {
				break;
			}

			$ancestors[] = $parent;
			$seen[$parent] = true;
			$current = $parent;
		}

		return $ancestors;
	}//end walkUp()

	/**
	 * The hierarchy declaration for one register and schema, or null.
	 *
	 * Matched on BOTH the schema id and the table name. A schema id alone would
	 * match the same schema in another register, whose rows are a different
	 * tenant's, which is the widening direction.
	 *
	 * @param integer $registerId The register.
	 * @param integer $schemaId   The schema.
	 *
	 * @return array{table: string, parentColumn: string, maxDepth: int, verbs: string[], schemaId: int}|null The declaration, or null.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function hierarchyFor(int $registerId, int $schemaId): ?array {
		$table = (MagicMapperInterface::TABLE_PREFIX . $registerId . '_' . $schemaId);

		foreach ($this->hierarchicalTables() as $candidate) {
			if ($candidate['schemaId'] === $schemaId && $candidate['table'] === $table) {
				return $candidate;
			}
		}

		return null;
	}//end hierarchyFor()

	/**
	 * The uuid one row names as its parent, or null.
	 *
	 * @param array<string, mixed> $hierarchy The row's hierarchy entry: its table and parent column.
	 * @param string               $uuid      The row.
	 *
	 * @return string|null The parent UUID, or null.
	 */
	private function parentOf(array $hierarchy, string $uuid): ?string {
		try {
			return $this->readParent(hierarchy: $hierarchy, uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HierarchyDescender] Could not read an object\'s parent; the walk up stops here',
				context: self::failureContext(where: ['table' => $hierarchy['table'], 'object' => $uuid], error: $e)
			);
			return null;
		}
	}//end parentOf()

	/**
	 * Read the uuid one row names as its parent.
	 *
	 * @param array<string, mixed> $hierarchy The row's hierarchy entry: its table and parent column.
	 * @param string               $uuid      The row.
	 *
	 * @return string|null The parent UUID, '' when the row names none, null when there is no such row.
	 *
	 * @throws Throwable When the table cannot be read.
	 */
	private function readParent(array $hierarchy, string $uuid): ?string {
		$parentColumn = $hierarchy['parentColumn'];
		$qb = $this->db->getQueryBuilder();
		$qb->select($parentColumn)
			->from($hierarchy['table'])
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($uuid)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return null;
		}

		return (string)($row[$parentColumn] ?? '');
	}//end readParent()

	/**
	 * The context of a warning about a failed read: where it was called, what it read, and why it failed.
	 *
	 * @param array<string, string> $where What was being read.
	 * @param Throwable             $error Why it failed.
	 *
	 * @return array<string, mixed> The context.
	 */
	private static function failureContext(array $where, Throwable $error): array {
		return array_merge(['file' => __FILE__, 'line' => __LINE__], $where, ['exception' => $error->getMessage()]);
	}//end failureContext()

	/**
	 * The column a property is stored in, by the rule the table writer uses.
	 *
	 * @param string $property The declared property name.
	 *
	 * @return string The column name.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function columnFor(string $property): string {
		return $this->sanitizeColumnName(name: $property);
	}//end columnFor()

	/**
	 * Forget what was resolved, for tests and for a schema saved mid-request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
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
			if ((string)$this->schemaIdOf(entry: $entry) === (string)$schemaId) {
				return true;
			}
		}

		return false;
	}//end registerHolds()

	/**
	 * The schema id one entry of a register's schemas list names.
	 *
	 * @param mixed $entry A bare id, or an object-shaped entry carrying `id` or `schema`.
	 *
	 * @return mixed The id as written.
	 */
	private function schemaIdOf(mixed $entry): mixed {
		if (is_array($entry) === true) {
			return ($entry['id'] ?? ($entry['schema'] ?? ''));
		}

		return $entry;
	}//end schemaIdOf()

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
			$this->columns[$table] = [];
			foreach ($this->everyTable() as $name => $columns) {
				// 🔴 NOT `IDBConnection::getPrefix()`. OCP exposes no such
				// method: calling it is a runtime Error, and because the catch
				// in everyTable() takes every Throwable, this whole lookup
				// answered "the table carries no columns" on every call. A
				// hierarchy grant then silently stopped descending, with a
				// warning in the log and nothing on screen. The prefix is
				// discovered from the schema instead, by matching the table's
				// own name.
				if ($name !== $table && str_ends_with($name, '_' . $table) === false) {
					continue;
				}

				$this->columns[$table] = $columns;
				break;
			}
		}

		return in_array(strtolower($column), $this->columns[$table], true);
	}//end tableHasColumn()

	/**
	 * Every table on the instance, with its column names, read ONCE.
	 *
	 * `IDBConnection::createSchema()` introspects the whole database, which on
	 * an instance carrying a magic table per register-and-schema pair is
	 * hundreds of tables. Calling it per candidate table, as this class did
	 * while the register list was pre-filtering the candidates down to one or
	 * two, is affordable only for as long as that pre-filter holds — and the
	 * pre-filter was the bug. One call per request is what makes asking the
	 * database directly cheap enough to be the authority.
	 *
	 * @return array<string, string[]> Table name => lower-cased column names.
	 */
	private function everyTable(): array {
		if ($this->tables !== null) {
			return $this->tables;
		}

		try {
			$this->tables = $this->readTables();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HierarchyDescender] Could not read the instance\'s tables; nothing is inherited this request',
				context: self::failureContext(where: [], error: $e)
			);
			$this->tables = [];
		}

		return $this->tables;
	}//end everyTable()

	/**
	 * Every table on the instance with its lower-cased column names, from one schema introspection.
	 *
	 * @return array<string, string[]> Table name => lower-cased column names.
	 *
	 * @throws Throwable When the database cannot be introspected.
	 */
	private function readTables(): array {
		$tables = [];
		foreach ($this->db->createSchema()->getTables() as $candidate) {
			$tables[(string)$candidate->getName()] = array_map(
				static fn (object $c): string => strtolower((string)$c->getName()),
				$candidate->getColumns()
			);
		}

		return $tables;
	}//end readTables()
}//end class
