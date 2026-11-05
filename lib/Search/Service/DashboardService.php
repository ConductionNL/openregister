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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\WebhookLogMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DashboardService handles dashboard related operations
 *
 * Service for handling dashboard related operations including statistics,
 * register/schema aggregation, and data size calculations.
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
	 * Object entity mapper
	 *
	 * Handles database operations for object entities.
	 *
	 * @var MagicMapperInterface Object entity mapper instance
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * Audit trail mapper
	 *
	 * Handles database operations for audit trail entries.
	 *
	 * @var AuditTrailMapperInterface Audit trail mapper instance
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Webhook log mapper
	 *
	 * Handles database operations for webhook log entries.
	 *
	 * @var WebhookLogMapper Webhook log mapper instance
	 */
	private readonly WebhookLogMapper $webhookLogMapper;

	/**
	 * Register mapper
	 *
	 * Handles database operations for register entities.
	 *
	 * @var RegisterMapperInterface Register mapper instance
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Logger
	 *
	 * Used for logging dashboard operations and errors.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Schema mapper
	 *
	 * Handles database operations for schema entities.
	 *
	 * @var SchemaMapperInterface Schema mapper instance
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Constructor for DashboardService
	 *
	 * Initializes service with required mappers and logger for dashboard operations.
	 *
	 * @param MagicMapperInterface $objectMapper Object entity mapper for object statistics
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for log statistics
	 * @param WebhookLogMapper $webhookLogMapper Webhook log mapper for webhook statistics
	 * @param RegisterMapperInterface $registerMapper Register mapper for register operations
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for schema operations
	 * @param LoggerInterface $logger Logger instance for error tracking
	 *
	 * @return void
	 */
	public function __construct(
		MagicMapperInterface $objectMapper,
		AuditTrailMapperInterface $auditTrailMapper,
		WebhookLogMapper $webhookLogMapper,
		RegisterMapperInterface $registerMapper,
		SchemaMapperInterface $schemaMapper,
		LoggerInterface $logger,
	) {
		// Store dependencies for use in service methods.
		$this->objectMapper = $objectMapper;
		$this->auditTrailMapper = $auditTrailMapper;
		$this->webhookLogMapper = $webhookLogMapper;
		$this->registerMapper = $registerMapper;
		$this->schemaMapper = $schemaMapper;
		$this->logger = $logger;
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
			return [
				'objects' => $this->objectTotals(stats: $this->objectMapper->getStatistics(registerId: $registerId, schemaId: $schemaId)),
				'logs' => $this->sizeTotals(stats: $this->auditTrailMapper->getStatistics(registerId: $registerId, schemaId: $schemaId)),
				'webhookLogs' => $this->webhookLogTotals(),
				'files' => self::ZERO_TOTALS,
			];
		} catch (Exception $e) {
			$this->logFailure(what: 'statistics', error: $e);
			return ['objects' => self::ZERO_OBJECTS, 'logs' => self::ZERO_TOTALS, 'webhookLogs' => self::ZERO_TOTALS, 'files' => self::ZERO_TOTALS];
		}
	}//end getStats()

	/**
	 * The webhook log count across all webhooks; webhook log size is not tracked.
	 *
	 * @return array<string, mixed>
	 */
	private function webhookLogTotals(): array {
		$webhookLogStats = $this->webhookLogMapper->getStatistics(webhookId: 0);
		return ['total' => $webhookLogStats['total'] ?? 0, 'size' => 0];
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
			return [
				'objects' => $this->objectTotals(stats: $this->objectMapper->getStatistics(registerId: null, schemaId: null, exclude: $valid)),
				'logs' => $this->sizeTotals(stats: $this->auditTrailMapper->getStatistics(registerId: null, schemaId: null, exclude: $valid)),
				'files' => self::ZERO_TOTALS,
			];
		} catch (Exception $e) {
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
		foreach ($this->registerMapper->findAll() as $register) {
			foreach ($this->registerMapper->getSchemasByRegisterId($register->getId()) as $schema) {
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
			return $this->dashboardEntries(scope: new DashboardScope(registerId: $registerId, schemaId: $schemaId));
		} catch (Exception $e) {
			$this->logFailure(what: 'registers with schemas', error: $e);
			throw new Exception('Failed to get registers with schemas: ' . $e->getMessage());
		}
	}//end getRegistersWithSchemas()

	/**
	 * The system totals, each register in scope, then the orphaned items.
	 *
	 * @param DashboardScope $scope The register and schema to narrow to.
	 *
	 * @return array<int, array<string, mixed>> The dashboard entries.
	 */
	private function dashboardEntries(DashboardScope $scope): array {
		$registers = $this->registerMapper->findAll(filters: $scope->registerFilters());

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
		$schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());

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
			$resolved = new RegisterSchemaPair(
				register: $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false),
				schema: $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false)
			);
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
		$objects = $this->objectMapper->findAllInRegisterSchemaTable(register: $pair->register, schema: $pair->schema);

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
	 * @psalm-return array{processed: 0|1|2, failed: 0|1|2}
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
			$register = $this->fetchRegister(registerId: $registerId);
			$schema = $this->fetchOptionalSchema(schemaId: $schemaId, register: $register);
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
	 * Fetch register by ID with validation
	 *
	 * @param int|null $registerId Register ID to fetch.
	 *
	 * @return \OCA\OpenRegister\Db\Register|null Register entity or null if not provided.
	 *
	 * @throws \Exception If register is not found.
	 */
	private function fetchRegister(?int $registerId): ?\OCA\OpenRegister\Db\Register {
		if ($registerId === null) {
			return null;
		}

		try {
			return $this->registerMapper->find($registerId);
		} catch (Exception $e) {
			throw new Exception('Register not found: ' . $e->getMessage());
		}
	}//end fetchRegister()

	/**
	 * Fetch schema by ID with validation, when one was named
	 *
	 * @param int|null $schemaId Schema ID to fetch.
	 * @param \OCA\OpenRegister\Db\Register|null $register Register to validate against.
	 *
	 * @return \OCA\OpenRegister\Db\Schema|null Schema entity or null if not provided.
	 *
	 * @throws \Exception If schema is not found or doesn't belong to register.
	 */
	private function fetchOptionalSchema(?int $schemaId, ?\OCA\OpenRegister\Db\Register $register): ?\OCA\OpenRegister\Db\Schema {
		if ($schemaId === null) {
			return null;
		}

		return $this->fetchSchema(schemaId: $schemaId, register: $register);
	}//end fetchOptionalSchema()

	/**
	 * Fetch schema by ID with validation
	 *
	 * @param int $schemaId Schema ID to fetch.
	 * @param \OCA\OpenRegister\Db\Register|null $register Register to validate against.
	 *
	 * @return \OCA\OpenRegister\Db\Schema Schema entity.
	 *
	 * @throws \Exception If schema is not found or doesn't belong to register.
	 */
	private function fetchSchema(int $schemaId, ?\OCA\OpenRegister\Db\Register $register): \OCA\OpenRegister\Db\Schema {
		try {
			$schema = $this->schemaMapper->find($schemaId);

			// Verify schema belongs to register if both are provided.
			if ($register !== null && in_array($schema->getId(), $register->getSchemas()) === false) {
				throw new Exception('Schema does not belong to the specified register');
			}

			return $schema;
		} catch (Exception $e) {
			throw new Exception('Schema not found or invalid: ' . $e->getMessage());
		}
	}//end fetchSchema()

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

	/**
	 * Get chart data for audit trail actions over time
	 *
	 * @param DashboardPeriod $period The start and end of the chart data
	 * @param DashboardScope  $scope  The register and schema to filter by
	 *
	 * @return ((int[]|string)[]|(int|string))[][]
	 *
	 * @psalm-return array{labels: list<array-key>, series: list<array{data: list<int>, name: string}>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function getAuditTrailActionChartData(DashboardPeriod $period, DashboardScope $scope): array {
		try {
			return $this->auditTrailMapper->getActionChartData(
				from: $period->from,
				till: $period->till,
				registerId: $scope->registerId,
				schemaId: $scope->schemaId
			);
		} catch (Exception $e) {
			$this->logFailure(what: 'audit trail action chart data', error: $e);
			return self::EMPTY_CHART;
		}
	}//end getAuditTrailActionChartData()

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
			return $this->objectMapper->getRegisterChartData(registerId: $registerId, schemaId: $schemaId);
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
			return $this->objectMapper->getSchemaChartData(registerId: $registerId, schemaId: $schemaId);
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
			return $this->objectMapper->getSizeDistributionChartData(registerId: $registerId, schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logFailure(what: 'objects by size chart data', error: $e);
			return self::EMPTY_CHART;
		}
	}//end getObjectsBySizeChartData()

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
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::getDetailedStatistics with degraded-empty fallback.
	 */
	public function getAuditTrailStatistics(DashboardScope $scope, ?int $hours = 24): array {
		try {
			return $this->auditTrailMapper->getDetailedStatistics(registerId: $scope->registerId, schemaId: $scope->schemaId, hours: $hours);
		} catch (Exception $e) {
			$this->logFailure(what: 'audit trail statistics', error: $e);
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
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::getActionDistribution with degraded-empty fallback.
	 */
	public function getAuditTrailActionDistribution(DashboardScope $scope, ?int $hours = 24): array {
		try {
			return $this->auditTrailMapper->getActionDistribution(
				registerId: $scope->registerId,
				schemaId: $scope->schemaId,
				hours: $hours
			);
		} catch (Exception $e) {
			$this->logFailure(what: 'audit trail action distribution', error: $e);
			return [
				'actions' => [],
			];
		}
	}//end getAuditTrailActionDistribution()

	/**
	 * Get most active objects based on audit trail activity
	 *
	 * @param DashboardScope $scope  The register and schema to filter by
	 * @param ActivityWindow $window The number of results and hours to look back
	 *
	 * @return (int|mixed|string)[][][]
	 *
	 * @psalm-return array{objects: list<array{count: int, id: mixed, name: string}>}
	 *
	 * @spec exclude Thin try/catch delegation to AuditTrailMapper::getMostActiveObjects with degraded-empty fallback.
	 */
	public function getMostActiveObjects(DashboardScope $scope, ActivityWindow $window): array {
		try {
			return $this->auditTrailMapper->getMostActiveObjects(
				registerId: $scope->registerId,
				schemaId: $scope->schemaId,
				limit: $window->limit,
				hours: $window->hours
			);
		} catch (Exception $e) {
			$this->logFailure(what: 'most active objects', error: $e);
			return [
				'objects' => [],
			];
		}
	}//end getMostActiveObjects()

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
	 * @param Exception $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(string $what, Exception $error): void {
		$this->logError(message: 'Failed to get ' . $what . ': ' . $error->getMessage());
	}//end logFailure()

	/**
	 * Log one error under this service's prefix.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function logError(string $message): void {
		$this->logger->error(
			message: '[DashboardService] ' . $message,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logError()
}//end class
