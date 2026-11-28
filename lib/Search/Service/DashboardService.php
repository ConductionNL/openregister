<?php

/**
 * OpenRegister Dashboard Service
 *
 * This file contains the service class for handling dashboard related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Search\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DashboardService handles dashboard related operations
 *
 * Service for handling dashboard related operations including statistics,
 * and register/schema aggregation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
 */
class DashboardService {
	/**
	 * Object totals reported when the statistics cannot be read.
	 */
	private const ZERO_OBJECTS = ['total' => 0, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0];

	/**
	 * A count and size pair reported when nothing is counted.
	 */
	private const ZERO_TOTALS = ['total' => 0, 'size' => 0];

	/**
	 * Chart data reported when the chart cannot be read.
	 */
	private const EMPTY_CHART = ['labels' => [], 'series' => []];

	/**
	 * Whether one dashboard answer is being built, so the webhook log totals are read once for it.
	 *
	 * @var boolean
	 */
	private bool $buildingAnswer = false;

	/**
	 * The webhook log totals of the answer being built, once read.
	 *
	 * Every entry carries the same instance-wide count, which was read once per entry.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $webhookLogTotalsMemo = null;

	/**
	 * The audit totals of the answer being built, by stored register then schema
	 * value; null outside an answer or when the grouped read failed.
	 *
	 * @var array<int|string, array<int|string, array{total: int, size: int}>>|null
	 */
	private ?array $auditPairTotals = null;


	/**
	 * Constructor for DashboardService
	 *
	 * Initializes service with required mappers and logger for dashboard operations.
	 *
	 * @param DashboardMappers $mappers The magic table, audit trail, webhook log and register mappers
	 * @param DashboardSizes   $sizes   Size recalculation
	 * @param LoggerInterface  $logger  Logger instance for error tracking
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DashboardMappers $mappers,
		private readonly DashboardSizes $sizes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get statistics for a register/schema combination
	 *
	 * @param int|null $registerId The register ID (optional)
	 * @param int|null $schemaId The schema ID (optional)
	 *
	 * @return array Statistics with objects, logs, webhookLogs, and files totals and sizes.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	private function getStats(?int $registerId = null, ?int $schemaId = null): array {
		try {
			$scope = new StatisticsScope(registerId: $registerId, schemaId: $schemaId);
			$logScope = new AuditStatisticsScope(registerId: $registerId, schemaId: $schemaId);
			return [
				'objects' => $this->objectTotals(stats: $this->mappers->catalog->tables()->getStatistics(scope: $scope)),
				'logs' => $this->auditTotals(scope: $logScope),
				'webhookLogs' => $this->webhookLogTotals(),
				'files' => self::ZERO_TOTALS,
			];
		} catch (Exception $e) {
			$this->logFailure(what: 'statistics', error: $e);
			return ['objects' => self::ZERO_OBJECTS, 'logs' => self::ZERO_TOTALS, 'webhookLogs' => self::ZERO_TOTALS, 'files' => self::ZERO_TOTALS];
		}
	}//end getStats()

	/**
	 * The audit trail totals of a scope. While an answer is built, a register's
	 * and a register/schema pair's totals come from the one grouped read made for
	 * the answer (sum over the register's schemas, or the pair's entry), instead
	 * of one COUNT query per entry; elsewhere, and for the instance totals, the
	 * scope is counted on its own.
	 *
	 * @param AuditStatisticsScope $scope The register and schema to count.
	 *
	 * @return array{total: int, size: int}
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	private function auditTotals(AuditStatisticsScope $scope): array {
		if ($this->auditPairTotals === null || $scope->registerId === null) {
			return $this->sizeTotals(stats: $this->mappers->auditTrailMapper->statisticsTotals(scope: $scope));
		}

		$perSchema = ($this->auditPairTotals[(string) $scope->registerId] ?? []);
		if ($scope->schemaId !== null) {
			return ($perSchema[(string) $scope->schemaId] ?? self::ZERO_TOTALS);
		}

		$totals = self::ZERO_TOTALS;
		foreach ($perSchema as $pair) {
			$totals['total'] += $pair['total'];
			$totals['size'] += $pair['size'];
		}

		return $totals;
	}//end auditTotals()

	/**
	 * Read the audit totals of the answer's registers in one grouped query.
	 *
	 * A failed read leaves the memo unset, so each entry counts its own scope as before.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\Register> $registers The registers of the answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	private function readAuditPairTotals(array $registers): void {
		$ids = [];
		foreach ($registers as $register) {
			$ids[] = (int) $register->getId();
		}

		try {
			$this->auditPairTotals = $this->mappers->auditTrailMapper->statisticsTotalsPerPair(registerIds: $ids);
		} catch (Throwable $e) {
			// Not a failure of the answer: each entry counts its own scope instead.
			$this->auditPairTotals = null;
			$this->logger->debug(
				message: '[DashboardService] Audit totals per register and schema unavailable, counting per entry: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end readAuditPairTotals()

	/**
	 * The webhook log count across all webhooks; webhook log size is not tracked.
	 *
	 * @return array<string, mixed>
	 */
	private function webhookLogTotals(): array {
		if ($this->webhookLogTotalsMemo !== null) {
			return $this->webhookLogTotalsMemo;
		}

		$webhookLogStats = $this->mappers->webhookLogMapper->getStatistics(webhookId: 0);
		$totals = ['total' => $webhookLogStats['total'] ?? 0, 'size' => 0];
		if ($this->buildingAnswer === true) {
			$this->webhookLogTotalsMemo = $totals;
		}

		return $totals;
	}//end webhookLogTotals()

	/**
	 * Get statistics for orphaned items
	 *
	 * Orphaned rows are those outside every register/schema combination that exists.
	 *
	 * @return array Statistics for orphaned objects, logs, and files.
	 */
	private function getOrphanedStats(): array {
		try {
			$valid = $this->validCombinations();
			$logScope = new AuditStatisticsScope();
			$logScope->exclude = $valid;
			return [
				'objects' => $this->objectTotals(stats: $this->mappers->catalog->tables()->getStatistics(scope: new StatisticsScope(exclude: $valid))),
				'logs' => $this->sizeTotals(stats: $this->mappers->auditTrailMapper->statisticsTotals(scope: $logScope)),
				'files' => self::ZERO_TOTALS,
			];
		} catch (Throwable $e) {
			$this->logFailure(what: 'orphaned statistics', error: $e);
			return ['objects' => self::ZERO_OBJECTS, 'logs' => self::ZERO_TOTALS, 'files' => self::ZERO_TOTALS];
		}
	}//end getOrphanedStats()

	/**
	 * Every register/schema combination that exists.
	 *
	 * @return array<int, array{register: int, schema: int}>
	 */
	private function validCombinations(): array {
		$combinations = [];
		foreach ($this->mappers->catalog->registers()->findAll() as $register) {
			foreach ($this->mappers->catalog->registers()->getSchemasOfRegister(register: $register) as $schema) {
				$combinations[] = ['register' => $register->getId(), 'schema' => $schema->getId()];
			}
		}

		return $combinations;
	}//end validCombinations()

	/**
	 * Get all registers with their schemas and statistics
	 *
	 * @param int|null $registerId The register ID to filter by
	 * @param int|null $schemaId The schema ID to filter by
	 *
	 * @throws \Exception If there is an error getting the registers with schemas
	 *
	 * @return array Registers with their schemas and statistics for dashboard display.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function getRegistersWithSchemas(?int $registerId = null, ?int $schemaId = null): array {
		try {
			// One count per register/schema pair for the whole answer: the totals,
			// register and schema entries walk the same pairs.
			$this->buildingAnswer = true;
			return $this->mappers->catalog->tables()->rememberPairCounts(
				work: fn (): array => $this->dashboardEntries(scope: new DashboardScope(registerId: $registerId, schemaId: $schemaId))
			);
		} catch (Exception $e) {
			$this->logFailure(what: 'registers with schemas', error: $e);
			throw new Exception('Failed to get registers with schemas: ' . $e->getMessage());
		} finally {
			$this->buildingAnswer = false;
			$this->webhookLogTotalsMemo = null;
			$this->auditPairTotals = null;
		}
	}//end getRegistersWithSchemas()

	/**
	 * Run read-only dashboard work with each register/schema pair counted once.
	 *
	 * @param callable(): T $work Read-only work; it must not write objects.
	 *
	 * @return T What $work returns.
	 *
	 * @template T
	 *
	 * @spec openspec/changes/dashboard-chart-counts-once/specs/built-in-dashboards/spec.md
	 */
	public function countingPairsOnce(callable $work): mixed {
		return $this->mappers->catalog->tables()->rememberPairCounts(work: $work);
	}//end countingPairsOnce()

	/**
	 * The size recalculation of the dashboard.
	 *
	 * @return DashboardSizes
	 */
	public function sizes(): DashboardSizes {
		return $this->sizes;
	}//end sizes()

	/**
	 * The audit trail reads of the dashboard.
	 *
	 * @return DashboardAuditReads
	 */
	public function auditReads(): DashboardAuditReads {
		return new DashboardAuditReads(auditTrailMapper: $this->mappers->auditTrailMapper, logger: $this->logger);
	}//end auditReads()

	/**
	 * The system totals, each register in scope, then the orphaned items.
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 *
	 * @return array<int, array<string, mixed>> The dashboard entries.
	 */
	private function dashboardEntries(DashboardScope $scope): array {
		$registers = $this->mappers->catalog->registers()->findAll(filters: $scope->registerFilters());

		$this->readAuditPairTotals(registers: $registers);

		$result = [$this->totalsEntry(scope: $scope)];
		foreach ($registers as $register) {
			$result[] = $this->registerEntryOrFlag(register: $register, schemaId: $scope->schemaId);
		}

		$result[] = $this->orphanedEntry();

		return $result;
	}//end dashboardEntries()

	/**
	 * The system totals, shaped as the first "register".
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 *
	 * @return array<string, mixed>
	 */
	private function totalsEntry(DashboardScope $scope): array {
		return [
			'id' => 'totals',
			'title' => 'System Totals',
			'description' => 'Total statistics across all registers and schemas',
			'stats' => $this->getStats(registerId: $scope->registerId, schemaId: $scope->schemaId),
			'schemas' => [],
		];
	}//end totalsEntry()

	/**
	 * The orphaned items statistics, shaped as a special "register".
	 *
	 * @return array<string, mixed>
	 */
	private function orphanedEntry(): array {
		return [
			'id' => 'orphaned',
			'title' => 'Orphaned Items',
			'description' => 'Items referencing non-existent registers/schemas or invalid combinations',
			'stats' => $this->getOrphanedStats(),
			'schemas' => [],
		];
	}//end orphanedEntry()

	/**
	 * One register's entry, or a flagged entry when it cannot be built.
	 *
	 * A single register whose schemas fail to hydrate (e.g. a cross-app
	 * schema-format conflict, openregister#2087) must degrade to a flagged
	 * entry, not blank the whole dashboard for every register and every user.
	 *
	 * @param \OCA\OpenRegister\Db\Register $register The register.
	 * @param int|null $schemaId Restrict to one schema, or null for all.
	 *
	 * @return array<string, mixed> The register entry.
	 */
	private function registerEntryOrFlag(\OCA\OpenRegister\Db\Register $register, ?int $schemaId): array {
		try {
			return $this->buildRegisterEntry(register: $register, schemaId: $schemaId);
		} catch (Throwable $registerError) {
			$this->logger->warning(
				message: '[DashboardService] Skipping a register whose schemas could not be loaded: ' . $registerError->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $register->getId()]
			);

			$registerArray = $register->jsonSerialize();
			$registerArray['stats'] = [];
			$registerArray['schemas'] = [];
			$registerArray['error'] = $registerError->getMessage();
			return $registerArray;
		}
	}//end registerEntryOrFlag()

	/**
	 * Build one register's dashboard entry: its stats plus each schema's stats.
	 *
	 * Split out of {@see self::getRegistersWithSchemas()} so a failure here is
	 * caught per register rather than aborting the whole dashboard. Any throw
	 * (a schema-hydration conflict, a stats query error) is the caller's to
	 * turn into a flagged entry.
	 *
	 * @param \OCA\OpenRegister\Db\Register $register The register.
	 * @param int|null $schemaId Restrict to one schema, or null for all.
	 *
	 * @return array<string, mixed> The register entry, with `stats` and `schemas`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	private function buildRegisterEntry(\OCA\OpenRegister\Db\Register $register, ?int $schemaId): array {
		$schemas = $this->mappers->catalog->registers()->getSchemasOfRegister(register: $register);

		$registerArray = $register->jsonSerialize();
		$registerArray['stats'] = $this->getStats(registerId: $register->getId());
		$registerArray['schemas'] = [];
		foreach ($schemas as $schema) {
			if ($schemaId === null || $schema->getId() === $schemaId) {
				$registerArray['schemas'][] = $this->schemaEntry(schema: $schema, registerId: $register->getId());
			}
		}

		return $registerArray;
	}//end buildRegisterEntry()

	/**
	 * One schema's dashboard entry within a register.
	 *
	 * @param \OCA\OpenRegister\Db\Schema $schema The schema.
	 * @param int $registerId The register it is listed under.
	 *
	 * @return array<string, mixed> The schema entry, with `stats`.
	 */
	private function schemaEntry(\OCA\OpenRegister\Db\Schema $schema, int $registerId): array {
		$schemaArray = $schema->jsonSerialize();
		$schemaArray['stats'] = $this->getStats(registerId: $registerId, schemaId: $schema->getId());

		return $schemaArray;
	}//end schemaEntry()

	/**
	 * Get chart data for objects by register
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return (int|mixed|string)[][] Array containing chart data for objects by register
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 *
	 * @spec exclude Thin try/catch delegation to ObjectMapper::getRegisterChartData with degraded-empty fallback; chart shaping owned by the mapper.
	 */
	public function getObjectsByRegisterChartData(?int $registerId = null, ?int $schemaId = null): array {
		try {
			return $this->mappers->catalog->tables()->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logFailure(what: 'objects by register chart data', error: $e);
			return self::EMPTY_CHART;
		}
	}//end getObjectsByRegisterChartData()

	/**
	 * Get chart data for objects by schema
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return (int|mixed|string)[][] Array containing chart data for objects by schema
	 *
	 * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
	 *
	 * @spec exclude Thin try/catch delegation to ObjectMapper::getSchemaChartData with degraded-empty fallback; chart shaping owned by the mapper.
	 */
	public function getObjectsBySchemaChartData(?int $registerId = null, ?int $schemaId = null): array {
		try {
			return $this->mappers->catalog->tables()->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logFailure(what: 'objects by schema chart data', error: $e);
			return self::EMPTY_CHART;
		}
	}//end getObjectsBySchemaChartData()

	/**
	 * Get chart data for objects by size distribution
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return (int|string)[][] Array containing chart data for objects by size
	 *
	 * @psalm-return array{labels: list<'0-1 KB'|'1-10 KB'|'10-100 KB'|'100 KB-1 MB'|'> 1 MB'>, series: list<int>}
	 *
	 * @spec exclude Thin try/catch delegation to ObjectMapper::getSizeDistributionChartData with degraded-empty
	 *              fallback; chart shaping owned by the mapper.
	 */
	public function getObjectsBySizeChartData(?int $registerId = null, ?int $schemaId = null): array {
		try {
			return $this->mappers->catalog->tables()->getSizeDistributionChartData(registerId: $registerId, schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logFailure(what: 'objects by size chart data', error: $e);
			return self::EMPTY_CHART;
		}
	}//end getObjectsBySizeChartData()

	/**
	 * The object totals of a mapper statistics row.
	 *
	 * @param array<string, mixed> $stats Row with total, size, invalid, deleted and locked.
	 *
	 * @return array<string, mixed>
	 */
	private function objectTotals(array $stats): array {
		return [
			'total' => $stats['total'],
			'size' => $stats['size'],
			'invalid' => $stats['invalid'],
			'deleted' => $stats['deleted'],
			'locked' => $stats['locked'],
		];
	}//end objectTotals()

	/**
	 * The count and size of a mapper statistics row.
	 *
	 * @param array<string, mixed> $stats Row with total and size.
	 *
	 * @return array<string, mixed>
	 */
	private function sizeTotals(array $stats): array {
		return [
			'total' => $stats['total'],
			'size' => $stats['size'],
		];
	}//end sizeTotals()

	/**
	 * Log that one dashboard read failed.
	 *
	 * @param string $what What could not be read.
	 * @param Throwable $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(string $what, Throwable $error): void {
		$this->logger->error(
			message: '[DashboardService] Failed to get ' . $what . ': ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logFailure()
}//end class
