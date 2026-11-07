<?php

/**
 * TablesTableReader — the single guarded gateway to the Nextcloud Tables app's
 * internal services.
 *
 * Nextcloud Tables is a SOFT dependency: it is not installed in the dev env,
 * exposes no stable public OCP API, and its `OCA\Tables\Service\*` classes may be
 * entirely absent. This class is therefore the ONLY place in the provider that
 * names those classes — always by FQCN string constant, always behind
 * `class_exists` + a guarded container lookup (mirroring
 * {@see DeckObjectSourceProvider::resolveService()}). It returns plain-array
 * descriptors so every consumer (the provider, the seeder, the sync command, the
 * listener) stays free of Tables types and unit-testable with this reader mocked.
 *
 * When Tables is missing/disabled every method fails closed — `isAvailable()`
 * false, empty lists, null rows — with a single logged warning and never a fatal.
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

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guarded plain-array reader over the Nextcloud Tables internal services.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TablesTableReader {

	use ContainerServiceLookup;
	use TablesEntityDescriptors;

	/**
	 * Label the warnings of this reader carry.
	 *
	 * @var string
	 */
	private const SOURCE_LABEL = 'tables';

	/**
	 * NC app id whose install-state gates the reader.
	 *
	 * @var string
	 */
	public const REQUIRED_APP = 'tables';

	/**
	 * Tables' table service (resolved dynamically — Tables' namespace).
	 *
	 * @var string
	 */
	private const TABLE_SERVICE = 'OCA\\Tables\\Service\\TableService';

	/**
	 * Tables' column service (resolved dynamically — Tables' namespace).
	 *
	 * @var string
	 */
	private const COLUMN_SERVICE = 'OCA\\Tables\\Service\\ColumnService';

	/**
	 * Tables' row service (resolved dynamically — Tables' namespace).
	 *
	 * @var string
	 */
	private const ROW_SERVICE = 'OCA\\Tables\\Service\\RowService';

	/**
	 * Tables' view service (resolved dynamically — Tables' namespace).
	 *
	 * @var string
	 */
	private const VIEW_SERVICE = 'OCA\\Tables\\Service\\ViewService';

	/**
	 * Tables' permission service (resolved dynamically — Tables' namespace).
	 *
	 * @var string
	 */
	private const PERMISSIONS_SERVICE = 'OCA\\Tables\\Service\\PermissionsService';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App availability checks.
	 * @param ContainerInterface $container Server container (lazy Tables-service lookup).
	 * @param LoggerInterface $logger Logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the Tables app is enabled for the acting (session) user AND its
	 * service classes are loadable on this instance.
	 *
	 * @return bool True when Tables reads can be served.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function isAvailable(): bool {
		try {
			$enabled = $this->appManager->isEnabledForUser(self::REQUIRED_APP);
		} catch (Throwable $e) {
			return false;
		}

		return $enabled === true && class_exists(self::ROW_SERVICE) === true;
	}//end isAvailable()

	/**
	 * List every table the acting user may read, as `{id, title}` descriptors.
	 *
	 * @param string $userId The acting user id.
	 *
	 * @return array<int, array{id: int, title: string}> The table descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function listTables(string $userId): array {
		$result = [];
		foreach ($this->tableEntities(userId: $userId) as $table) {
			$id = $this->intGetter(entity: $table, getter: 'getId');
			if ($id !== null) {
				$result[] = ['id' => $id, 'title' => $this->stringGetter(entity: $table, getter: 'getTitle')];
			}
		}

		return $result;
	}//end listTables()

	/**
	 * Ask Tables for the table entities the acting user may read.
	 *
	 * @param string $userId The acting user id.
	 *
	 * @return array<int, object> The table entities, empty on failure.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function tableEntities(string $userId): array {
		$service = $this->resolveService(class: self::TABLE_SERVICE);
		if ($service === null) {
			return [];
		}

		try {
			// Fourth argument (createTutorial) MUST stay false: the default of
			// TableService::findAll() creates a tutorial table for first-time
			// users, which a read-only reconcile pass must never do.
			return $this->entities(list: $service->findAll($userId, false, false, false));
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not list tables: ' . $e->getMessage());
			return [];
		}
	}//end tableEntities()

	/**
	 * Collect full table descriptors (`{id, title, columns}`) visible to any of
	 * the given users, deduplicated by table id.
	 *
	 * Tables scopes enumeration per user (no un-scoped public API), so seeding
	 * enumerates the tables visible to the supplied users (typically admins);
	 * this is best-effort and documented — a table visible to none of them is not
	 * seeded until a sync runs for a user who can see it.
	 *
	 * @param array<int, string> $userIds The acting user ids to enumerate for.
	 *
	 * @return array<int, array{id: int, title: string, columns: array<int, array<string, mixed>>}> The descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function collectTableDescriptors(array $userIds): array {
		$byId = [];
		foreach ($userIds as $userId) {
			$byId = $this->withTablesOf(byId: $byId, userId: $userId);
		}

		return array_values($byId);
	}//end collectTableDescriptors()

	/**
	 * Add the descriptors of the tables one user sees that are not collected yet.
	 *
	 * @param array<int, array<string, mixed>> $byId The descriptors so far, by table id.
	 * @param string $userId The acting user id.
	 *
	 * @return array<int, array<string, mixed>> The descriptors, by table id.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function withTablesOf(array $byId, string $userId): array {
		foreach ($this->listTables(userId: $userId) as $table) {
			$tableId = $table['id'];
			if (isset($byId[$tableId]) === false) {
				$byId[$tableId] = $table + ['columns' => $this->listColumns(tableId: $tableId, userId: $userId)];
			}
		}

		return $byId;
	}//end withTablesOf()

	/**
	 * List a table's columns as plain descriptors.
	 *
	 * @param int $tableId The table id.
	 * @param string $userId The acting user id.
	 *
	 * @return array<int, array<string, mixed>> The column descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function listColumns(int $tableId, string $userId): array {
		$service = $this->resolveService(class: self::COLUMN_SERVICE);
		if ($service === null) {
			return [];
		}

		try {
			$columns = $service->findAllByTable($tableId, $userId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not list columns for table ' . $tableId . ': ' . $e->getMessage());
			return [];
		}

		return array_map(fn (object $column): array => $this->columnDescriptor(column: $column), $this->entities(list: $columns));
	}//end listColumns()

	/**
	 * Fetch the rows of a scope, through its View when one is bound, as plain descriptors.
	 *
	 * @param TablesReadScope $scope The table or View, the acting user and the page.
	 *
	 * @return array<int, array<string, mixed>> The row descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findRows(TablesReadScope $scope): array {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return [];
		}

		try {
			$rows = $this->readRows(service: $service, scope: $scope);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not read rows for ' . $scope->sourceId() . ': ' . $e->getMessage());
			return [];
		}

		return array_map(fn (object $row): array => $this->rowDescriptor(row: $row), $this->entities(list: $rows));
	}//end findRows()

	/**
	 * Fetch a single row of the bound table by id, as a plain descriptor.
	 *
	 * RowService::find() takes only the row id and checks read access for
	 * the user Tables captured from the session, and it serves a row of ANY
	 * table that user can read. So this method asks Tables, before reading,
	 * whether the scope's user may read rows of the bound table, and refuses a
	 * row that belongs to another table. A row the asking user may not read, and
	 * a row of a table this schema is not bound to, both come back as null.
	 *
	 * @param int $rowId The Tables row id.
	 * @param TablesReadScope $scope The bound table and the acting user.
	 *
	 * @return array<string, mixed>|null The row descriptor, or null when absent/denied.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findRow(int $rowId, TablesReadScope $scope): ?array {
		$row = $this->readableRow(rowId: $rowId, scope: $scope);
		if (is_object($row) === false) {
			return null;
		}

		if ($this->intGetter(entity: $row, getter: 'getTableId') !== $scope->tableId) {
			return null;
		}

		return $this->rowDescriptor(row: $row);
	}//end findRow()

	/**
	 * Read a row once Tables confirms the scope's user may read the bound table.
	 *
	 * @param int $rowId The Tables row id.
	 * @param TablesReadScope $scope The bound table and the acting user.
	 *
	 * @return mixed The Tables reply, or null when refused or failed.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function readableRow(int $rowId, TablesReadScope $scope): mixed {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return null;
		}

		try {
			if ($this->mayReadRows(scope: $scope) === false) {
				return null;
			}

			return $service->find($rowId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not read row ' . $rowId . ': ' . $e->getMessage());
			return null;
		}
	}//end readableRow()

	/**
	 * Whether Tables says the scope's user may read rows of the bound table.
	 *
	 * @param TablesReadScope $scope The bound table and the acting user.
	 *
	 * @return bool True only on an explicit yes; false without the permission service.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function mayReadRows(TablesReadScope $scope): bool {
		$permissions = $this->resolveService(class: self::PERMISSIONS_SERVICE);
		if ($permissions === null) {
			return false;
		}

		return $permissions->canReadRowsByElementId($scope->tableId, 'table', $scope->userId) === true;
	}//end mayReadRows()

	/**
	 * Count the rows of a scope the acting user may read, through its View when one is bound.
	 *
	 * @param TablesReadScope $scope The table or View and the acting user.
	 *
	 * @return int The row count (0 when unavailable/denied).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function countRows(TablesReadScope $scope): int {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return 0;
		}

		try {
			return $this->readCount(service: $service, scope: $scope);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not count rows for ' . $scope->sourceId() . ': ' . $e->getMessage());
			return 0;
		}
	}//end countRows()

	/**
	 * Ask the row service for a page of rows.
	 *
	 * @param object $service The Tables row service.
	 * @param TablesReadScope $scope The table or View, the acting user and the page.
	 *
	 * @return mixed The Tables reply.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function readRows(object $service, TablesReadScope $scope): mixed {
		$getter = 'findAllByTable';
		if ($scope->isView() === true) {
			$getter = 'findAllByView';
		}

		return $service->$getter($scope->sourceId(), $scope->userId, $scope->limit(), $scope->offset());
	}//end readRows()

	/**
	 * Ask the row service for a row count.
	 *
	 * @param object $service The Tables row service.
	 * @param TablesReadScope $scope The table or View and the acting user.
	 *
	 * @return int The row count.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function readCount(object $service, TablesReadScope $scope): int {
		if ($scope->isView() === false) {
			// The getRowsCount() call takes only the table id; Tables checks read
			// access against the session user internally.
			return (int)$service->getRowsCount($scope->tableId);
		}

		// The getViewRowsCount() call needs the View entity, not the view id.
		$viewService = $this->resolveService(class: self::VIEW_SERVICE);
		if ($viewService === null) {
			return 0;
		}

		$view = $viewService->find($scope->sourceId(), true, $scope->userId);
		return (int)$service->getViewRowsCount($view, $scope->userId);
	}//end readCount()

	/**
	 * The entities in a Tables reply, skipping anything that is not an object.
	 *
	 * @param mixed $list The reply.
	 *
	 * @return array<int, object> The entities.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function entities(mixed $list): array {
		return array_values(array_filter((array)$list, 'is_object'));
	}//end entities()
}//end class
