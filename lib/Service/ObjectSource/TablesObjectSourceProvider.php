<?php

/**
 * TablesObjectSourceProvider — serves a virtual schema's objects live (read-only)
 * from a single Nextcloud Tables table (or Tables View).
 *
 * The authoritative record is the Tables row held by the Tables app; this
 * provider projects each row the acting user may read as a non-persisted
 * ObjectEntity and never writes back (writes to a bound schema are rejected by the
 * existing object-source write-guard). It is modelled on
 * {@see GroupObjectSourceProvider} (shape, no-persist `toObjectEntity`) and
 * {@see DeckObjectSourceProvider} (another-app integration): all Tables access
 * goes through {@see TablesTableReader}, which guards every `OCA\Tables\Service\*`
 * reference with `class_exists` + the app-enabled gate, so an instance without
 * Tables installed degrades to an empty result plus a logged warning — never a
 * fatal, never a DB fallback.
 *
 * Every Tables call is scoped to the acting `$userId`, so Tables enforces
 * ownership/shares/contexts; a denied table/row surfaces as null/empty (denied ==
 * absent, no enumeration oracle).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Service\LinkSessionRuntime;
use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Tables app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TablesObjectSourceProvider implements ObjectSourceProvider {

	/**
	 * Default page size when a query carries no explicit limit.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 200;

	/**
	 * Row cap for the O(n) scans of a UUID lookup and of a query applied in PHP (bounded + logged).
	 *
	 * @var int
	 */
	private const UUID_SCAN_CAP = 1000;

	/**
	 * The acting-user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param TablesProviderParts $parts   The reader, row mapper, uuid derivation and managed-schema lookup.
	 * @param LinkSessionRuntime  $session The acting-user session and the logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TablesProviderParts $parts,
		LinkSessionRuntime $session,
	) {
		$this->userSession = $session->userSession;
		$this->logger = $session->logger;
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function getId(): string {
		return 'tables';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Gated on the Tables app (enabled for the acting user) and the loadability of
	 * its service classes; otherwise a bound schema degrades to an empty list.
	 *
	 * @return bool True when Tables can serve reads on this instance.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function isEnabled(): bool {
		return $this->parts->reader->isAvailable();
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * Accepts both a numeric Tables rowId and a derived UUID (resolved by a
	 * bounded, logged scan of the bound table). Returns null when the row is
	 * absent OR the acting user may not read it (denied == absent).
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The rowId or derived UUID.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		return $this->findTargeted(target: $this->target(table: $source->table, config: $source->config), id: $id);
	}//end find()

	/**
	 * Read one object of a target that names a table.
	 *
	 * @param TablesObjectTarget|null $target The target, or null when no user is logged in.
	 * @param string                  $id     The object uuid or numeric row id.
	 *
	 * @return ObjectEntity|null The object, or null when absent or the target names no table.
	 */
	private function findTargeted(?TablesObjectTarget $target, string $id): ?ObjectEntity {
		if ($target === null || $target->scope->tableId === 0) {
			return null;
		}

		return $this->findById(target: $target, id: $id);
	}//end findTargeted()

	/**
	 * {@inheritDoc}
	 *
	 * Pushes `limit`/`offset` natively to Tables. A query with filters, search, ids
	 * or a sort is applied in PHP over a bounded scan, and paged after that. A
	 * `config.viewId` binds a Tables View instead of the raw `config.tableId`.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/sort/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		return $this->listTargeted(target: $this->target(table: $source->table, config: $source->config), query: $query);
	}//end findAll()

	/**
	 * Read one page of a target that names a table.
	 *
	 * @param TablesObjectTarget|null $target The target, or null when no user is logged in.
	 * @param array<string, mixed>    $query  Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The page, or an empty list when the target names no table.
	 */
	private function listTargeted(?TablesObjectTarget $target, array $query): array {
		if ($target === null || $target->scope->tableId === 0) {
			return [];
		}

		$scope       = $target->scope;
		$objectQuery = new TablesObjectQuery(query: $query);
		if ($objectQuery->isNarrowing() === true) {
			return $objectQuery->page(objects: $this->matching(target: $target, query: $objectQuery), defaultLimit: self::DEFAULT_LIMIT);
		}

		$page = $scope->withPage(limit: (int)($query['limit'] ?? self::DEFAULT_LIMIT), offset: (int)($query['offset'] ?? 0));

		return $this->toObjects(target: $target, rows: $this->parts->reader->findRows(scope: $page));
	}//end listTargeted()

	/**
	 * {@inheritDoc}
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/search).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function count(SourcedTable $source, array $query = []): int {
		return $this->countTargeted(target: $this->target(table: $source->table, config: $source->config), query: $query);
	}//end count()

	/**
	 * Count the objects of a target that names a table or a view.
	 *
	 * @param TablesObjectTarget|null $target The target, or null when no user is logged in.
	 * @param array<string, mixed>    $query  Query (filters/search).
	 *
	 * @return int The count, or 0 when the target names no table or view.
	 */
	private function countTargeted(?TablesObjectTarget $target, array $query): int {
		if ($target === null || $target->scope->sourceId() === 0) {
			return 0;
		}

		return $this->countIn(target: $target, query: new TablesObjectQuery(query: $query));
	}//end countTargeted()

	/**
	 * Count a table's or view's objects: by matching when a narrowing query hits a table, else by row count.
	 *
	 * @param TablesObjectTarget $target The target, naming a table or a view.
	 * @param TablesObjectQuery  $query  The parsed query.
	 *
	 * @return int The count.
	 */
	private function countIn(TablesObjectTarget $target, TablesObjectQuery $query): int {
		if ($query->isNarrowing() === true && $target->scope->tableId !== 0) {
			return count($this->matching(target: $target, query: $query));
		}

		return $this->parts->reader->countRows(scope: $target->scope);
	}//end countIn()

	/**
	 * Resolve a virtual object by a numeric row id or a derived UUID.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param string $id The rowId or derived UUID.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function findById(TablesObjectTarget $target, string $id): ?ObjectEntity {
		if (ctype_digit($id) === true) {
			return $this->findByRowId(target: $target, rowId: (int)$id);
		}

		if ($this->parts->uuidDeriver->looksLikeUuid($id) === true) {
			return $this->findByUuid(target: $target, uuid: $id);
		}

		return null;
	}//end findById()

	/**
	 * Resolve a virtual object by its Tables row id.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param int $rowId The Tables row id.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function findByRowId(TablesObjectTarget $target, int $rowId): ?ObjectEntity {
		$row = $this->parts->reader->findRow(rowId: $rowId, scope: $target->scope);
		if ($row === null) {
			return null;
		}

		return $this->toObjectEntity(target: $target, row: $row);
	}//end findByRowId()

	/**
	 * Resolve a virtual object by its derived UUID via a bounded, logged scan.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param string $uuid The derived object UUID.
	 *
	 * @return ObjectEntity|null The virtual object, or null when not found.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function findByUuid(TablesObjectTarget $target, string $uuid): ?ObjectEntity {
		$tableId = $target->scope->tableId;
		$this->logger->warning(
			sprintf('[ObjectSource:tables] resolving UUID %s by scanning table %d (bounded to %d rows)', $uuid, $tableId, self::UUID_SCAN_CAP)
		);

		foreach ($this->parts->reader->findRows(scope: $target->scope->withPage(limit: self::UUID_SCAN_CAP, offset: 0)) as $row) {
			$rowId = (int)($row['id'] ?? 0);
			if ($this->parts->uuidDeriver->deriveObjectUuid(tableId: $tableId, rowId: $rowId) === $uuid) {
				return $this->toObjectEntity(target: $target, row: $row);
			}
		}

		return null;
	}//end findByUuid()

	/**
	 * The objects of a bounded scan that a query matches. A scan that reaches the cap
	 * logs a warning, because rows past it were not considered.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param TablesObjectQuery $query The query.
	 *
	 * @return array<int, ObjectEntity> The matching objects.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function matching(TablesObjectTarget $target, TablesObjectQuery $query): array {
		$rows = $this->parts->reader->findRows(scope: $target->scope->withPage(limit: self::UUID_SCAN_CAP, offset: 0));
		if (count($rows) >= self::UUID_SCAN_CAP) {
			$this->logger->warning(
				sprintf('[ObjectSource:tables] query applied to the first %d rows of table %d only', self::UUID_SCAN_CAP, $target->scope->tableId)
			);
		}

		return $query->matching(objects: $this->toObjects(target: $target, rows: $rows));
	}//end matching()

	/**
	 * Map row descriptors onto virtual objects.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param array<int, array<string, mixed>> $rows The row descriptors.
	 *
	 * @return array<int, ObjectEntity> The virtual objects.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function toObjects(TablesObjectTarget $target, array $rows): array {
		return array_map(fn (array $row): ObjectEntity => $this->toObjectEntity(target: $target, row: $row), $rows);
	}//end toObjects()

	/**
	 * Map a Tables row descriptor onto a non-persisted virtual ObjectEntity.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param array<string, mixed> $row The row descriptor.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function toObjectEntity(TablesObjectTarget $target, array $row): ObjectEntity {
		$rowId = (int)($row['id'] ?? 0);

		$entity = new ObjectEntity();
		$entity->setUuid($this->parts->uuidDeriver->deriveObjectUuid(tableId: $target->scope->tableId, rowId: $rowId));
		$entity->setRegister((string)$target->register->getId());
		$entity->setSchema((string)$target->schema->getId());
		$entity->setObject(array_merge(['id' => (string)$rowId], $this->projectedData(target: $target, row: $row)));
		$this->applyOwner(entity: $entity, row: $row);
		$this->applyDates(entity: $entity, row: $row);

		return $entity;
	}//end toObjectEntity()

	/**
	 * Project a row onto the schema's properties through the table's columns.
	 *
	 * @param TablesObjectTarget $target The register, schema and read scope.
	 * @param array<string, mixed> $row The row descriptor.
	 *
	 * @return array<string, mixed> The projected properties.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function projectedData(TablesObjectTarget $target, array $row): array {
		$scope = $target->scope;
		$columns = $target->columns(load: fn (): array => $this->parts->reader->listColumns(tableId: $scope->tableId, userId: $scope->userId));

		$projection = new TablesRowProjection(
			columns: $columns,
			columnMap: $this->parts->columnMapper->buildColumnMap(columns: $columns),
			targetSchemaExists: $this->targetSchemaResolver()
		);

		return $this->parts->columnMapper->projectRow(row: $row, projection: $projection);
	}//end projectedData()

	/**
	 * Apply the row's creator as the entity's owner.
	 *
	 * @param ObjectEntity $entity The virtual object.
	 * @param array<string, mixed> $row The row descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function applyOwner(ObjectEntity $entity, array $row): void {
		$owner = ($row['createdBy'] ?? null);
		if (is_string($owner) === true && $owner !== '') {
			$entity->setOwner($owner);
		}
	}//end applyOwner()

	/**
	 * Apply the row's timestamps to the entity; one that cannot be parsed stays unset.
	 *
	 * @param ObjectEntity $entity The virtual object.
	 * @param array<string, mixed> $row The row descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function applyDates(ObjectEntity $entity, array $row): void {
		$created = $this->parsedDate(value: ($row['createdAt'] ?? null));
		if ($created !== null) {
			$entity->setCreated($created);
		}

		$updated = $this->parsedDate(value: ($row['lastEditAt'] ?? null));
		if ($updated !== null) {
			$entity->setUpdated($updated);
		}
	}//end applyDates()

	/**
	 * Parse an ISO-8601 string.
	 *
	 * @param mixed $value The ISO-8601 string, or null.
	 *
	 * @return DateTime|null The date, or null when absent or unparseable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function parsedDate(mixed $value): ?DateTime {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parsedDate()

	/**
	 * Build a memoised predicate: does a target table have a seeded schema?
	 *
	 * @return callable(int): bool The relation-target predicate.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function targetSchemaResolver(): callable {
		$cache = [];

		return function (int $targetTableId) use (&$cache): bool {
			if (array_key_exists($targetTableId, $cache) === false) {
				$cache[$targetTableId] = $this->parts->syncService->hasManagedSchemaForTableId(tableId: $targetTableId);
			}

			return $cache[$targetTableId];
		};
	}//end targetSchemaResolver()

	/**
	 * The target a schema's config names for the acting user.
	 *
	 * @param RegisterSchema       $table  The register and schema the objects belong to.
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return TablesObjectTarget|null The target, or null when no user is logged in.
	 */
	private function target(RegisterSchema $table, array $config): ?TablesObjectTarget {
		$scope = $this->scope(config: $config);
		if ($scope === null) {
			return null;
		}

		return new TablesObjectTarget(register: $table->register, schema: $table->schema, scope: $scope);
	}//end target()

	/**
	 * The read scope of a schema's config for the acting user.
	 *
	 * @param array<string, mixed> $config The object-source config block.
	 *
	 * @return TablesReadScope|null The scope, or null when no user is logged in.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function scope(array $config): ?TablesReadScope {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$scope = new TablesReadScope(tableId: (int)($config['tableId'] ?? 0), userId: $user->getUID());

		return $scope->withView(viewId: (int)($config['viewId'] ?? 0));
	}//end scope()
}//end class
