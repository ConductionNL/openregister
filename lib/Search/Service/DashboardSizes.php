<?php

/**
 * Recalculates the stored sizes of objects and audit logs.
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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use Psr\Log\LoggerInterface;

/**
 * Re-saves objects and logs so their sizes are recalculated, and reports the outcome.
 */
class DashboardSizes {
	use DashboardErrorLog;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface      $objectMapper     Object mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper.
	 * @param RegisterSchemaLookup      $lookup           Resolves registers and schemas.
	 * @param LoggerInterface           $logger           Logger.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly RegisterSchemaLookup $lookup,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recalculate sizes for objects in specified registers and/or schemas
	 *
	 * @param int|null $registerId The register ID to filter by (optional)
	 * @param int|null $schemaId The schema ID to filter by (optional)
	 *
	 * @return int[] Array containing counts of processed and failed objects
	 *
	 * @psalm-return array{processed: int, failed: int}
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	public function recalculateSizes(?int $registerId = null, ?int $schemaId = null): array {
		$tally = new SizeTally();

		try {
			$this->recalculatePairs(scope: new DashboardScope(registerId: $registerId, schemaId: $schemaId), tally: $tally);

			return $tally->toArray();
		} catch (Exception $e) {
			$this->logError(message: 'Failed to recalculate sizes: ' . $e->getMessage());
			throw new Exception('Failed to recalculate sizes: ' . $e->getMessage());
		}
	}//end recalculateSizes()

	/**
	 * Re-save the objects of every register+schema pair in scope.
	 *
	 * A magic table is one table per register+schema pair, so there is no
	 * single object table to filter. MagicMapper::findAll() says so by
	 * requiring the register and schema ENTITIES and answering [] (with a
	 * warning) when it does not get them, so the pairs are walked instead,
	 * narrowed by whichever id the caller named.
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 * @param SizeTally      $tally The running outcome.
	 *
	 * @return void
	 */
	private function recalculatePairs(DashboardScope $scope, SizeTally $tally): void {
		foreach ($this->objectMapper->getAllRegisterSchemaPairs() as $pair) {
			if ($scope->contains(registerId: (int)$pair['registerId'], schemaId: (int)$pair['schemaId']) === true) {
				$this->recalculateSizesForPair(pair: $pair, tally: $tally);
			}
		}
	}//end recalculatePairs()

	/**
	 * Re-save every object of one register+schema pair, counting the outcome.
	 *
	 * An unresolvable pair is counted as one failure and logged rather than
	 * taking the whole run down: a stale magic table whose register or schema
	 * row is gone must not stop the rest from being recalculated.
	 *
	 * @param array<string, mixed> $pair  The pair, with registerId and schemaId.
	 * @param SizeTally            $tally The running outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	private function recalculateSizesForPair(array $pair, SizeTally $tally): void {
		$registerId = (int)$pair['registerId'];
		$schemaId = (int)$pair['schemaId'];

		try {
			$resolved = $this->lookup->pair(registerId: $registerId, schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logError(message: 'Skipping pair ' . $registerId . '/' . $schemaId . ': ' . $e->getMessage());
			$tally->addFailed();
			return;
		}

		$this->resaveObjects(pair: $resolved, tally: $tally);
	}//end recalculateSizesForPair()

	/**
	 * Re-save every object of a resolved pair, counting each outcome.
	 *
	 * @param RegisterSchemaPair $pair  The register and schema.
	 * @param SizeTally          $tally The running outcome.
	 *
	 * @return void
	 */
	private function resaveObjects(RegisterSchemaPair $pair, SizeTally $tally): void {
		$objects = $this->objectMapper->findAllInRegisterSchemaTable(table: new RegisterSchema(register: $pair->register, schema: $pair->schema));

		foreach ($objects as $object) {
			try {
				$this->objectMapper->update($object, $pair->register, $pair->schema);
				$tally->addProcessed();
			} catch (Exception $e) {
				$this->logError(message: 'Failed to update object ' . $object->getId() . ': ' . $e->getMessage());
				$tally->addFailed();
			}
		}
	}//end resaveObjects()

	/**
	 * Recalculate sizes for audit trail logs in specified registers and/or schemas
	 *
	 * @param int|null $registerId The register ID to filter by (optional)
	 * @param int|null $schemaId The schema ID to filter by (optional)
	 *
	 * @return int[] Array containing counts of processed and failed logs
	 *
	 * @psalm-return array{processed: int, failed: int}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function recalculateLogSizes(?int $registerId = null, ?int $schemaId = null): array {
		$tally = new SizeTally();
		$scope = new DashboardScope(registerId: $registerId, schemaId: $schemaId);

		try {
			foreach ($this->auditTrailMapper->findAll(filters: $scope->logFilters()) as $log) {
				$this->resaveLog(log: $log, tally: $tally);
			}

			return $tally->toArray();
		} catch (Exception $e) {
			$this->logError(message: 'Failed to recalculate log sizes: ' . $e->getMessage());
			throw new Exception('Failed to recalculate log sizes: ' . $e->getMessage());
		}
	}//end recalculateLogSizes()

	/**
	 * Update one log to trigger its size recalculation, counting the outcome.
	 *
	 * @param \OCA\OpenRegister\Db\AuditTrail $log   The log.
	 * @param SizeTally                        $tally The running outcome.
	 *
	 * @return void
	 */
	private function resaveLog(\OCA\OpenRegister\Db\AuditTrail $log, SizeTally $tally): void {
		try {
			$this->auditTrailMapper->update($log);
			$tally->addProcessed();
		} catch (Exception $e) {
			$this->logError(message: 'Failed to update log ' . $log->getId() . ': ' . $e->getMessage());
			$tally->addFailed();
		}
	}//end resaveLog()

	/**
	 * Recalculate sizes for both objects and logs in specified registers and/or schemas
	 *
	 * @param int|null $registerId The register ID to filter by (optional)
	 * @param int|null $schemaId The schema ID to filter by (optional)
	 *
	 * @return array Results with objects, logs, and total processed and failed counts.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function recalculateAllSizes(?int $registerId = null, ?int $schemaId = null): array {
		try {
			$objectResults = $this->recalculateSizes(registerId: $registerId, schemaId: $schemaId);
			$logResults = $this->recalculateLogSizes(registerId: $registerId, schemaId: $schemaId);

			return $this->combinedSizes(objects: $objectResults, logs: $logResults);
		} catch (Exception $e) {
			$this->logError(message: 'Failed to recalculate all sizes: ' . $e->getMessage());
			throw new Exception('Failed to recalculate all sizes: ' . $e->getMessage());
		}
	}//end recalculateAllSizes()

	/**
	 * The object and log results with their combined totals.
	 *
	 * @param array{processed: int, failed: int} $objects The object results.
	 * @param array{processed: int, failed: int} $logs    The log results.
	 *
	 * @return array<string, array{processed: int, failed: int}>
	 */
	private function combinedSizes(array $objects, array $logs): array {
		return [
			'objects' => $objects,
			'logs' => $logs,
			'total' => [
				'processed' => $objects['processed'] + $logs['processed'],
				'failed' => $objects['failed'] + $logs['failed'],
			],
		];
	}//end combinedSizes()

	/**
	 * Calculate sizes for all entities (objects and logs) in the system
	 * Optionally filtered by register and/or schema
	 *
	 * @param int|null $registerId The register ID to filter by (optional)
	 * @param int|null $schemaId The schema ID to filter by (optional)
	 *
	 * @return ((array|float|mixed|null)[]|string)[]
	 *
	 * @psalm-return array{status: 'success', timestamp: string,
	 *     scope: array{register: array{id: int, title: null|string}|null,
	 *     schema: array{id: int, title: null|string}|null},
	 *     results: array{objects: array, logs: array,
	 *     total: array{processed: mixed, failed: mixed}},
	 *     summary: array{total_processed: mixed, total_failed: mixed,
	 *     success_rate: float}}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function calculate(?int $registerId = null, ?int $schemaId = null): array {
		try {
			$register = $this->lookup->fetchRegister(registerId: $registerId);
			$schema = $this->lookup->fetchOptionalSchema(schemaId: $schemaId, register: $register);
			$results = $this->recalculateAllSizes(registerId: $registerId, schemaId: $schemaId);

			return $this->sizeResponse(
				scope: ['register' => $this->scopeEntry(entity: $register), 'schema' => $this->scopeEntry(entity: $schema)],
				results: $results
			);
		} catch (Exception $e) {
			$this->logError(message: 'Size calculation failed: ' . $e->getMessage());
			throw new Exception('Size calculation failed: ' . $e->getMessage());
		}
	}//end calculate()

	/**
	 * The response of a size calculation.
	 *
	 * @param array<string, array{id: int, title: string|null}|null> $scope   The register and schema it covered.
	 * @param array<string, mixed>                                   $results The recalculation results.
	 *
	 * @return array<string, mixed>
	 */
	private function sizeResponse(array $scope, array $results): array {
		return [
			'status' => 'success',
			'timestamp' => (new DateTime('now'))->format(format: 'c'),
			'scope' => $scope,
			'results' => $results,
			'summary' => [
				'total_processed' => $results['total']['processed'],
				'total_failed' => $results['total']['failed'],
				'success_rate' => $this->calculateSuccessRate(results: $results),
			],
		];
	}//end sizeResponse()

	/**
	 * The id and title of a register or schema a calculation covered
	 *
	 * @param \OCA\OpenRegister\Db\Register|\OCA\OpenRegister\Db\Schema|null $entity The entity.
	 *
	 * @return array{id: int, title: string|null}|null The scope entry, or null when not narrowed.
	 */
	private function scopeEntry(\OCA\OpenRegister\Db\Register|\OCA\OpenRegister\Db\Schema|null $entity): ?array {
		if ($entity === null) {
			return null;
		}

		return [
			'id' => $entity->getId(),
			'title' => $entity->getTitle(),
		];
	}//end scopeEntry()

	/**
	 * Calculate success rate from results
	 *
	 * @param array<string, mixed> $results Results array with total processed and failed counts.
	 *
	 * @return float Success rate percentage.
	 */
	private function calculateSuccessRate(array $results): float {
		if ($results['total']['processed'] > 0) {
			$processed = $results['total']['processed'];
			$failed = $results['total']['failed'];
			return round(($processed - $failed) / $processed * 100, 2);
		}

		return 0.0;
	}//end calculateSuccessRate()
}//end class
