<?php

/**
 * OpenRegister KanbanBoardRun
 *
 * The state of one kanban board build: grouping, column order and page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * One kanban board build: the grouping field, the configured column order and
 * the per-column page. Built by ViewPresentationService::getKanbanBoard and
 * never leaves it.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
 */
final class KanbanBoardRun {
	/**
	 * The property whose distinct values are the columns.
	 *
	 * @var string
	 */
	public readonly string $groupByField;

	/**
	 * The configured column order, or null when none is configured.
	 *
	 * @var array<int|string, mixed>|null
	 */
	public readonly ?array $columnOrder;

	/**
	 * Cards per column.
	 *
	 * @var integer
	 */
	public readonly int $limit;

	/**
	 * Cards to skip per column.
	 *
	 * @var integer
	 */
	public readonly int $offset;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $kanbanConfig  The view's kanban config, with a non-empty groupByField
	 * @param array<string, mixed> $requestParams Request params (_limit/_offset apply per column)
	 *
	 * @return void
	 */
	public function __construct(array $kanbanConfig, array $requestParams) {
		$this->groupByField = $kanbanConfig['groupByField'];
		$this->columnOrder = $this->arrayOrNull(value: ($kanbanConfig['columnOrder'] ?? null));
		$this->limit = (int) ($requestParams['_limit'] ?? 20);
		$this->offset = (int) ($requestParams['_offset'] ?? 0);
	}//end __construct()

	/**
	 * The paginated object query of each column, in column order.
	 *
	 * @param array<string, mixed> $baseQuery    Base object query (filters/sort)
	 * @param array<int, mixed>    $columnValues The column values
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function columnQueries(array $baseQuery, array $columnValues): array {
		$queries = [];
		foreach ($columnValues as $columnValue) {
			$columnQuery = $baseQuery;
			$columnQuery[$this->groupByField] = $columnValue;
			$columnQuery['_limit'] = $this->limit;
			$columnQuery['_offset'] = $this->offset;
			$queries[] = $columnQuery;
		}

		return $queries;
	}//end columnQueries()

	/**
	 * The value when it is an array, else null.
	 *
	 * @param mixed $value The configured column order.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end arrayOrNull()
}//end class
