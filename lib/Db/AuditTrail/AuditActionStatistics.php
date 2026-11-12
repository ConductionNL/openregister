<?php

/**
 * AuditActionStatistics: how often each audit action occurs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Counts per audit action, over a lifetime or a window. Every read but the
 * prefix count answers zeros or empty lists when the database fails.
 *
 * @spec exclude audit statistics plumbing moved out of AuditTrailMapper, not a capability of its own
 */
final class AuditActionStatistics {

	private const CHART_ACTIONS = ['create', 'update', 'delete', 'read'];

	private const WINDOWED_KEYS = ['create' => 'creates', 'update' => 'updates', 'delete' => 'deletes', 'read' => 'reads'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db        The database.
	 * @param string          $tableName The audit trail table.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly string $tableName,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Actions per day, as labels and one series per action.
	 *
	 * @param AuditStatisticsScope $scope The scope, with its date range.
	 *
	 * @return array{labels: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
	 */
	public function actionChart(AuditStatisticsScope $scope): array {
		try {
			return self::chartOf(rows: $this->chartRows(scope: $scope));
		} catch (\Exception $e) {
			return ['labels' => [], 'series' => []];
		}
	}//end actionChart()

	/**
	 * A lifetime total with the counts of the known actions in the window.
	 *
	 * @param AuditStatisticsScope $scope The scope, with its hours.
	 * @param int                  $total The lifetime total.
	 *
	 * @return array{total: int, creates: int, updates: int, deletes: int, reads: int}
	 */
	public function windowedActions(AuditStatisticsScope $scope, int $total): array {
		$counts = ['total' => $total, 'creates' => 0, 'updates' => 0, 'deletes' => 0, 'reads' => 0];
		try {
			$rows = $this->recentActionRows(scope: $scope);
		} catch (\Exception $e) {
			return array_fill_keys(array_keys($counts), 0);
		}

		foreach ($rows as $row) {
			$key = (self::WINDOWED_KEYS[(string) $row['action']] ?? null);
			if ($key !== null) {
				$counts[$key] = (int) $row['count'];
			}
		}

		return $counts;
	}//end windowedActions()

	/**
	 * Lifetime counts per action. The total counts every row, including rows
	 * whose action is outside the four known ones.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return array{total: int, create: int, update: int, delete: int, read: int}
	 */
	public function lifetimeActions(AuditStatisticsScope $scope): array {
		try {
			return self::lifetimeCounts(rows: $this->lifetimeActionRows(scope: $scope));
		} catch (\Exception $e) {
			// Zeros are indistinguishable from an empty system in the UI, so the
			// failure has to be traceable in the log.
			$this->logger->error(
				'Failed to count audit trails by action: ' . $e->getMessage(),
				['exception' => $e, 'registerId' => $scope->registerId, 'schemaId' => $scope->schemaId]
			);

			return self::lifetimeCounts(rows: []);
		}
	}//end lifetimeActions()

	/**
	 * The total and known-action counts of the lifetime rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array{total: int, create: int, update: int, delete: int, read: int}
	 */
	private static function lifetimeCounts(array $rows): array {
		$counts = array_fill_keys(self::CHART_ACTIONS, 0);
		$total = 0;
		foreach ($rows as $row) {
			$total += (int) $row['count'];
			$action = (string) ($row['action'] ?? '');
			if (array_key_exists($action, $counts) === true) {
				$counts[$action] = (int) $row['count'];
			}
		}

		return ['total' => $total] + $counts;
	}//end lifetimeCounts()

	/**
	 * Row counts per action that starts with a prefix, matched literally.
	 *
	 * @param string $prefix The action prefix.
	 *
	 * @return array<string, int> Keyed by the full action.
	 */
	public function countByActionPrefix(string $prefix): array {
		$qb = $this->actionCountQuery();
		$qb->where(
			$qb->expr()->like('action', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix).'%'))
		)
			->groupBy('action');

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[(string) $row['action']] = (int) $row['count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countByActionPrefix()

	/**
	 * The actions in the window, each with its count and share of the rows.
	 *
	 * @param AuditStatisticsScope $scope The scope, with its hours.
	 *
	 * @return array{actions: array<int, array<string, mixed>>}
	 */
	public function actionDistribution(AuditStatisticsScope $scope): array {
		try {
			$rows = $this->recentActionRows(scope: $scope);
		} catch (\Exception $e) {
			return ['actions' => []];
		}

		$total = array_sum(array_map('intval', array_column($rows, 'count')));
		$actions = [];
		foreach ($rows as $row) {
			$count = (int) $row['count'];
			$actions[] = ['name' => $row['action'], 'count' => $count, 'percentage' => self::percentage(part: $count, total: $total)];
		}

		return ['actions' => $actions];
	}//end actionDistribution()

	/**
	 * A part of a total as a percentage with two decimals.
	 *
	 * @param int $part  The part.
	 * @param int $total The total.
	 *
	 * @return float|int
	 */
	private static function percentage(int $part, int $total): float|int {
		if ($total > 0) {
			return round(($part / $total) * 100, 2);
		}

		return 0;
	}//end percentage()

	/**
	 * The rows of the action chart.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function chartRows(AuditStatisticsScope $scope): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('DATE(created) as date'), 'action', $qb->createFunction('COUNT(*) as count'))
			->from($this->tableName)
			->groupBy('date', 'action')
			->orderBy('date', 'ASC');

		self::onOrAfter(qb: $qb, day: $scope->from);
		self::onOrBefore(qb: $qb, day: $scope->till);
		$scope->narrow(qb: $qb);

		return $qb->executeQuery()->fetchAll();
	}//end chartRows()

	/**
	 * Keep rows created from a day on, when one is given.
	 *
	 * @param IQueryBuilder $qb  The query.
	 * @param DateTime|null $day The day.
	 *
	 * @return void
	 */
	private static function onOrAfter(IQueryBuilder $qb, ?DateTime $day): void {
		if ($day !== null) {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($day->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
		}
	}//end onOrAfter()

	/**
	 * Keep rows created up to a day, when one is given.
	 *
	 * @param IQueryBuilder $qb  The query.
	 * @param DateTime|null $day The day.
	 *
	 * @return void
	 */
	private static function onOrBefore(IQueryBuilder $qb, ?DateTime $day): void {
		if ($day !== null) {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($day->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
		}
	}//end onOrBefore()

	/**
	 * Chart rows as labels and series, dates in order.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array{labels: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
	 */
	private static function chartOf(array $rows): array {
		$days = [];
		foreach ($rows as $row) {
			$days[$row['date']] = ($days[$row['date']] ?? array_fill_keys(self::CHART_ACTIONS, 0));
			$days[$row['date']][$row['action']] = (int) $row['count'];
		}

		ksort($days);

		$series = [];
		foreach (self::CHART_ACTIONS as $action) {
			$series[] = ['name' => ucfirst($action), 'data' => array_column($days, $action)];
		}

		return ['labels' => array_keys($days), 'series' => $series];
	}//end chartOf()

	/**
	 * The count per action in the window.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recentActionRows(AuditStatisticsScope $scope): array {
		$qb = $this->actionCountQuery();
		$qb->where($scope->createdInWindow(qb: $qb))
			->groupBy('action');

		$scope->narrow(qb: $qb);

		return $qb->executeQuery()->fetchAll();
	}//end recentActionRows()

	/**
	 * The lifetime count per action.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lifetimeActionRows(AuditStatisticsScope $scope): array {
		$qb = $this->actionCountQuery();
		$qb->groupBy('action');

		$scope->narrow(qb: $qb);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end lifetimeActionRows()

	/**
	 * A query counting rows per action, still to be grouped.
	 *
	 * @return IQueryBuilder
	 */
	private function actionCountQuery(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('action', $qb->createFunction('COUNT(*) as count'))
			->from($this->tableName);

		return $qb;
	}//end actionCountQuery()
}//end class
