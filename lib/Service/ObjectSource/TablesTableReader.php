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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every Tables-entity getter is individually guarded (soft dependency).
 */
class TablesTableReader {

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
		$service = $this->resolveService(class: self::TABLE_SERVICE);
		if ($service === null) {
			return [];
		}

		try {
			// Fourth argument (createTutorial) MUST stay false: the default of
			// TableService::findAll() creates a tutorial table for first-time
			// users, which a read-only reconcile pass must never do.
			$tables = $service->findAll($userId, false, false, false);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not list tables: ' . $e->getMessage());
			return [];
		}

		$result = [];
		foreach ((array)$tables as $table) {
			if (is_object($table) === false) {
				continue;
			}

			$id = $this->intGetter(entity: $table, getter: 'getId');
			if ($id === null) {
				continue;
			}

			$result[] = [
				'id' => $id,
				'title' => $this->stringGetter(entity: $table, getter: 'getTitle'),
			];
		}//end foreach

		return $result;
	}//end listTables()

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
			foreach ($this->listTables(userId: $userId) as $table) {
				$tableId = (int)$table['id'];
				if (isset($byId[$tableId]) === true) {
					continue;
				}

				$byId[$tableId] = [
					'id' => $tableId,
					'title' => (string)$table['title'],
					'columns' => $this->listColumns(tableId: $tableId, userId: $userId),
				];
			}
		}

		return array_values($byId);
	}//end collectTableDescriptors()

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

		$result = [];
		foreach ((array)$columns as $column) {
			if (is_object($column) === false) {
				continue;
			}

			$result[] = $this->columnDescriptor(column: $column);
		}

		return $result;
	}//end listColumns()

	/**
	 * Fetch a page of rows for a table, as plain descriptors.
	 *
	 * @param int $tableId The table id.
	 * @param string $userId The acting user id.
	 * @param int|null $limit Optional native row limit.
	 * @param int|null $offset Optional native row offset.
	 *
	 * @return array<int, array<string, mixed>> The row descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findRowsByTable(int $tableId, string $userId, ?int $limit = null, ?int $offset = null): array {
		return $this->rows(getter: 'findAllByTable', id: $tableId, userId: $userId, limit: $limit, offset: $offset);
	}//end findRowsByTable()

	/**
	 * Fetch a page of rows for a View, as plain descriptors.
	 *
	 * @param int $viewId The view id.
	 * @param string $userId The acting user id.
	 * @param int|null $limit Optional native row limit.
	 * @param int|null $offset Optional native row offset.
	 *
	 * @return array<int, array<string, mixed>> The row descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findRowsByView(int $viewId, string $userId, ?int $limit = null, ?int $offset = null): array {
		return $this->rows(getter: 'findAllByView', id: $viewId, userId: $userId, limit: $limit, offset: $offset);
	}//end findRowsByView()

	/**
	 * Fetch a single row by id, as a plain descriptor.
	 *
	 * @param int $rowId The Tables row id.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string, mixed>|null The row descriptor, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is kept for reader-contract parity
	 * with the View/table paths; RowService::find() resolves the acting user from the session.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function findRow(int $rowId, string $userId): ?array {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return null;
		}

		try {
			// RowService::find() takes only the row id; Tables resolves the
			// acting user from the session internally (session-scoped RBAC).
			// The $userId parameter is kept in this reader's contract for the
			// View/table paths, which do take an explicit user id.
			$row = $service->find($rowId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not read row ' . $rowId . ': ' . $e->getMessage());
			return null;
		}

		if (is_object($row) === false) {
			return null;
		}

		return $this->rowDescriptor(row: $row);
	}//end findRow()

	/**
	 * Count the rows of a table (or View) the acting user may read.
	 *
	 * @param int $id The table id (or the view id when $isView).
	 * @param string $userId The acting user id.
	 * @param bool $isView Whether $id is a view id.
	 *
	 * @return int The row count (0 when unavailable/denied).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the table-vs-View binding in the schema config.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function countRows(int $id, string $userId, bool $isView = false): int {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return 0;
		}

		try {
			if ($isView === true) {
				// The getViewRowsCount() call needs the View entity, not the view id.
				$viewService = $this->resolveService(class: self::VIEW_SERVICE);
				if ($viewService === null) {
					return 0;
				}

				$view = $viewService->find($id, true, $userId);
				return (int)$service->getViewRowsCount($view, $userId);
			}

			// The getRowsCount() call takes only the table id; Tables checks read
			// access against the session user internally.
			return (int)$service->getRowsCount($id);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not count rows for ' . $id . ': ' . $e->getMessage());
			return 0;
		}
	}//end countRows()

	/**
	 * Shared row-fetch for table/view getters.
	 *
	 * @param string $getter The RowService method name.
	 * @param int $id The table or view id.
	 * @param string $userId The acting user id.
	 * @param int|null $limit Optional native row limit.
	 * @param int|null $offset Optional native row offset.
	 *
	 * @return array<int, array<string, mixed>> The row descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rows(string $getter, int $id, string $userId, ?int $limit, ?int $offset): array {
		$service = $this->resolveService(class: self::ROW_SERVICE);
		if ($service === null) {
			return [];
		}

		try {
			$rows = $service->$getter($id, $userId, $limit, $offset);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not read rows for ' . $id . ': ' . $e->getMessage());
			return [];
		}

		$result = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === false) {
				continue;
			}

			$result[] = $this->rowDescriptor(row: $row);
		}

		return $result;
	}//end rows()

	/**
	 * Extract a plain descriptor from a Tables Row2 entity.
	 *
	 * @param object $row The Tables row entity.
	 *
	 * @return array<string, mixed> The row descriptor.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rowDescriptor(object $row): array {
		$cells = [];
		foreach ($this->arrayGetter(entity: $row, getter: 'getData') as $cell) {
			$cellArray = $this->cellToArray(cell: $cell);
			if ($cellArray !== null) {
				$cells[] = $cellArray;
			}
		}

		return [
			'id' => ($this->intGetter(entity: $row, getter: 'getId') ?? 0),
			'createdBy' => $this->nullableStringGetter(entity: $row, getter: 'getCreatedBy'),
			'createdAt' => $this->dateGetter(entity: $row, getter: 'getCreatedAt'),
			'lastEditBy' => $this->nullableStringGetter(entity: $row, getter: 'getLastEditBy'),
			'lastEditAt' => $this->dateGetter(entity: $row, getter: 'getLastEditAt'),
			'cells' => $cells,
		];
	}//end rowDescriptor()

	/**
	 * Normalise a single Tables cell (array or object) to `{columnId, value}`.
	 *
	 * @param mixed $cell The raw cell (array or object).
	 *
	 * @return array{columnId: int, value: mixed}|null The normalised cell, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function cellToArray(mixed $cell): ?array {
		if (is_array($cell) === true) {
			if (isset($cell['columnId']) === false) {
				return null;
			}

			return ['columnId' => (int)$cell['columnId'], 'value' => ($cell['value'] ?? null)];
		}

		if (is_object($cell) === true) {
			$columnId = $this->intGetter(entity: $cell, getter: 'getColumnId');
			if ($columnId === null) {
				return null;
			}

			return ['columnId' => $columnId, 'value' => $this->rawGetter(entity: $cell, getter: 'getValue')];
		}

		return null;
	}//end cellToArray()

	/**
	 * Extract a plain descriptor from a Tables Column entity.
	 *
	 * @param object $column The Tables column entity.
	 *
	 * @return array<string, mixed> The column descriptor.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function columnDescriptor(object $column): array {
		return [
			'id' => ($this->intGetter(entity: $column, getter: 'getId') ?? 0),
			'title' => $this->stringGetter(entity: $column, getter: 'getTitle'),
			'technicalName' => $this->nullableStringGetter(entity: $column, getter: 'getTechnicalName'),
			'type' => $this->stringGetter(entity: $column, getter: 'getType'),
			'subtype' => $this->nullableStringGetter(entity: $column, getter: 'getSubtype'),
			'mandatory' => ($this->boolGetter(entity: $column, getter: 'getMandatory') === true),
			'numberMin' => $this->floatGetter(entity: $column, getter: 'getNumberMin'),
			'numberMax' => $this->floatGetter(entity: $column, getter: 'getNumberMax'),
			'numberDecimals' => $this->intGetter(entity: $column, getter: 'getNumberDecimals'),
			'selectionOptions' => $this->selectionOptions(column: $column),
			'relationTargetTableId' => $this->intGetter(entity: $column, getter: 'getRelationTableId'),
		];
	}//end columnDescriptor()

	/**
	 * Extract selection-option labels from a Tables column, tolerant of shapes.
	 *
	 * @param object $column The Tables column entity.
	 *
	 * @return array<int, string> The option labels.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function selectionOptions(object $column): array {
		$options = [];
		foreach ($this->arrayGetter(entity: $column, getter: 'getSelectionOptions') as $option) {
			if (is_array($option) === true) {
				$options[] = (string)($option['label'] ?? $option['value'] ?? '');
				continue;
			}

			$options[] = (string)$option;
		}

		return array_values(array_filter($options, static fn (string $label) => $label !== ''));
	}//end selectionOptions()

	/**
	 * Resolve one of Tables' service classes from the container, or null.
	 *
	 * @param string $class The fully-qualified Tables service class name.
	 *
	 * @return object|null The resolved service, or null when Tables is absent.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function resolveService(string $class): ?object {
		if (class_exists($class) === false) {
			return null;
		}

		try {
			$service = $this->container->get($class);
			if (is_object($service) === true) {
				return $service;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:tables] could not resolve ' . $class . ': ' . $e->getMessage());
		}

		return null;
	}//end resolveService()

	/**
	 * Read an integer getter from a Tables entity, guarded (magic getters).
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return int|null The integer value, or null when unavailable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function intGetter(object $entity, string $getter): ?int {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intGetter()

	/**
	 * Read a float getter from a Tables entity, guarded.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return float|null The float value, or null when unavailable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function floatGetter(object $entity, string $getter): ?float {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (float)$value;
	}//end floatGetter()

	/**
	 * Read a boolean getter from a Tables entity, guarded.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return bool The boolean value (false when unavailable).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function boolGetter(object $entity, string $getter): bool {
		return (bool)$this->rawGetter(entity: $entity, getter: $getter);
	}//end boolGetter()

	/**
	 * Read a string getter from a Tables entity, defaulting to '' on failure.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string The string value, or ''.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function stringGetter(object $entity, string $getter): string {
		return (string)($this->nullableStringGetter(entity: $entity, getter: $getter) ?? '');
	}//end stringGetter()

	/**
	 * Read a string getter from a Tables entity, returning null when absent.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string|null The string value, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function nullableStringGetter(object $entity, string $getter): ?string {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end nullableStringGetter()

	/**
	 * Read an ISO-8601 date getter from a Tables entity, or null.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string|null The ISO-8601 date string, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function dateGetter(object $entity, string $getter): ?string {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTime::ATOM);
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end dateGetter()

	/**
	 * Read an array getter from a Tables entity, defaulting to [] on failure.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return array<int|string, mixed> The array value, or [].
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function arrayGetter(object $entity, string $getter): array {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayGetter()

	/**
	 * Invoke a getter on a Tables entity, guarded (OCP Entity magic getters make
	 * method_exists unreliable, so each call is wrapped).
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return mixed The raw value, or null on failure.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rawGetter(object $entity, string $getter): mixed {
		try {
			return $entity->$getter();
		} catch (Throwable $e) {
			return null;
		}
	}//end rawGetter()
}//end class
