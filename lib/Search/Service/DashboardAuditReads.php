<?php

/**
 * The audit trail reads of the dashboard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Audit trail statistics and charts, answering with empty results when a read fails.
 */
class DashboardAuditReads {
	use DashboardErrorLog;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper.
	 * @param LoggerInterface           $logger           Logger.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get chart data for audit trail actions over time
	 *
	 * @param DashboardPeriod $period The start, end and scope of the chart data
	 *
	 * @return ((int[]|string)[]|(int|string))[][]
	 *
	 * @psalm-return array{labels: list<array-key>, series: list<array{data: list<int>, name: string}>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function getAuditTrailActionChartData(DashboardPeriod $period): array {
		try {
			return $this->auditTrailMapper->getActionChartData(
				from: $period->from,
				till: $period->till,
				registerId: $period->scope->registerId,
				schemaId: $period->scope->schemaId
			);
		} catch (Exception $e) {
			$this->logError(message: 'Failed to get audit trail action chart data: ' . $e->getMessage());
			return ['labels' => [], 'series' => []];
		}
	}//end getAuditTrailActionChartData()

	/**
	 * Get audit trail statistics including total counts and recent activity
	 *
	 * @param DashboardScope $scope The register and schema to filter by
	 * @param int|null $hours Optional number of hours to look back for recent activity (default: 24)
	 *
	 * @return int[]
	 *
	 * @psalm-return array{total: int, creates: int, updates: int, deletes: int, reads: int}
	 *
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::detailedStatistics with degraded-empty fallback.
	 */
	public function getAuditTrailStatistics(DashboardScope $scope, ?int $hours = 24): array {
		try {
			$window = new AuditStatisticsScope(registerId: $scope->registerId, schemaId: $scope->schemaId);
			$window->hours = $hours;

			return $this->auditTrailMapper->detailedStatistics(scope: $window);
		} catch (Exception $e) {
			$this->logError(message: 'Failed to get audit trail statistics: ' . $e->getMessage());
			return ['total' => 0, 'creates' => 0, 'updates' => 0, 'deletes' => 0, 'reads' => 0];
		}
	}//end getAuditTrailStatistics()

	/**
	 * Get action distribution data for audit trails with percentages
	 *
	 * @param DashboardScope $scope The register and schema to filter by
	 * @param int|null $hours Optional number of hours to look back (default: 24)
	 *
	 * @return (int|mixed)[][][]
	 *
	 * @psalm-return array{actions: list<array{count: int, name: mixed}>}
	 *
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::actionDistribution with degraded-empty fallback.
	 */
	public function getAuditTrailActionDistribution(DashboardScope $scope, ?int $hours = 24): array {
		try {
			$window = new AuditStatisticsScope(registerId: $scope->registerId, schemaId: $scope->schemaId);
			$window->hours = $hours;

			return $this->auditTrailMapper->actionDistribution(scope: $window);
		} catch (Exception $e) {
			$this->logError(message: 'Failed to get audit trail action distribution: ' . $e->getMessage());
			return [
				'actions' => [],
			];
		}
	}//end getAuditTrailActionDistribution()

	/**
	 * Get most active objects based on audit trail activity
	 *
	 * @param ActivityWindow $window The scope, number of results and hours to look back
	 *
	 * @return (int|mixed|string)[][][]
	 *
	 * @psalm-return array{objects: list<array{count: int, id: mixed, name: string}>}
	 *
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::mostActiveObjects with degraded-empty fallback.
	 */
	public function getMostActiveObjects(ActivityWindow $window): array {
		try {
			$audit = new AuditStatisticsScope(registerId: $window->scope->registerId, schemaId: $window->scope->schemaId);
			$audit->limit = $window->limit;
			$audit->hours = $window->hours;

			return $this->auditTrailMapper->mostActiveObjects(scope: $audit);
		} catch (Exception $e) {
			$this->logError(message: 'Failed to get most active objects: ' . $e->getMessage());
			return [
				'objects' => [],
			];
		}
	}//end getMostActiveObjects()
}//end class
