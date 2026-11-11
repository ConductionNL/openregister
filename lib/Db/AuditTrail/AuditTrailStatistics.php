<?php

/**
 * AuditTrailStatistics: aggregate reads over the audit trail.
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Row counts and sizes of the audit trail. Every read answers zeros or empty
 * lists when the database fails.
 *
 * @spec exclude audit statistics plumbing moved out of AuditTrailMapper, not a capability of its own
 */
final class AuditTrailStatistics {

	private const EMPTY_TOTALS = ['total' => 0, 'size' => 0];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db        The database.
	 * @param string        $tableName The audit trail table.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly string $tableName,
	) {
	}//end __construct()

	/**
	 * The number of rows and their total size.
	 *
	 * @param AuditStatisticsScope $scope The scope, with its exclusions.
	 *
	 * @return array{total: int, size: int}
	 */
	public function totals(AuditStatisticsScope $scope): array {
		try {
			$result = $this->totalsQuery(scope: $scope)->executeQuery()->fetch();

			return ['total' => (int) ($result['total'] ?? 0), 'size' => (int) ($result['size'] ?? 0)];
		} catch (\Exception $e) {
			return self::EMPTY_TOTALS;
		}
	}//end totals()

	/**
	 * The query behind the totals.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return IQueryBuilder
	 */
	private function totalsQuery(AuditStatisticsScope $scope): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->createFunction('COUNT(id) as total'),
			$qb->createFunction('COALESCE(SUM(size), 0) as size')
		)
			->from($this->tableName);

		$scope->narrow(qb: $qb);
		foreach ($scope->exclude as $combination) {
			self::exclude(qb: $qb, combination: $combination);
		}

		return $qb;
	}//end totalsQuery()

	/**
	 * The objects with the most rows in the window.
	 *
	 * @param AuditStatisticsScope $scope The scope, with its hours and limit.
	 *
	 * @return array{objects: array<int, array<string, mixed>>}
	 */
	public function mostActiveObjects(AuditStatisticsScope $scope): array {
		try {
			$rows = $this->mostActiveRows(scope: $scope);
		} catch (\Exception $e) {
			return ['objects' => []];
		}

		$objects = [];
		foreach ($rows as $row) {
			$objects[] = ['id' => $row['object'], 'name' => 'Object ' . $row['object'], 'count' => (int) $row['count']];
		}

		return ['objects' => $objects];
	}//end mostActiveObjects()

	/**
	 * Totals per schema in one query, with empty totals for a schema without rows.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 *
	 * @return array<int, array{total: int, size: int}>
	 */
	public function totalsPerSchema(array $schemaIds): array {
		if (empty($schemaIds) === true) {
			return [];
		}

		$empty = array_fill_keys($schemaIds, self::EMPTY_TOTALS);
		try {
			return ($this->schemaTotals(schemaIds: $schemaIds) + $empty);
		} catch (\Exception $e) {
			return $empty;
		}
	}//end totalsPerSchema()

	/**
	 * The totals of the schemas that have rows.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 *
	 * @return array<int, array{total: int, size: int}>
	 */
	private function schemaTotals(array $schemaIds): array {
		$qb = $this->db->getQueryBuilder();
		$stringIds = array_map('strval', $schemaIds);
		$qb->select('schema', $qb->createFunction('COUNT(id) as total'), $qb->createFunction('COALESCE(SUM(size), 0) as size'))
			->from($this->tableName)
			->where($qb->expr()->in('schema', $qb->createNamedParameter($stringIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('schema');

		$result = $qb->executeQuery();
		$totals = [];
		while (($row = $result->fetch()) !== false) {
			$totals[(int) $row['schema']] = ['total' => (int) ($row['total'] ?? 0), 'size' => (int) ($row['size'] ?? 0)];
		}

		$result->closeCursor();

		return $totals;
	}//end schemaTotals()

	/**
	 * The count per object in the window, highest first.
	 *
	 * @param AuditStatisticsScope $scope The scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mostActiveRows(AuditStatisticsScope $scope): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object', $qb->createFunction('COUNT(*) as count'))
			->from($this->tableName)
			->where($scope->createdInWindow(qb: $qb))
			->groupBy('object')
			->orderBy('count', 'DESC');

		$scope->narrow(qb: $qb);

		if ($scope->limit !== null) {
			$qb->setMaxResults($scope->limit);
		}

		return $qb->executeQuery()->fetchAll();
	}//end mostActiveRows()

	/**
	 * Leave out one register/schema combination.
	 *
	 * @param IQueryBuilder      $qb          The query.
	 * @param array<string, int> $combination The combination.
	 *
	 * @return void
	 */
	private static function exclude(IQueryBuilder $qb, array $combination): void {
		$conditions = $qb->expr()->orX();
		foreach (['register', 'schema'] as $column) {
			if (($combination[$column] ?? null) !== null) {
				$conditions->add($qb->expr()->isNull($column));
				$conditions->add($qb->expr()->neq($column, $qb->createNamedParameter($combination[$column], IQueryBuilder::PARAM_INT)));
			}
		}

		if ($conditions->count() > 0) {
			$qb->andWhere($conditions);
		}
	}//end exclude()
}//end class
