<?php

/**
 * SchemaMigrationService — declarative, auditable, rollback-capable object
 * migration over a schema's population.
 *
 * Wraps the pure {@see SchemaMigrationPlanner} with persistence and the
 * standard object save pipeline:
 *
 *  - preview: apply a plan to a bounded sample, returning before/after
 *    pairs WITHOUT persisting (the planner never mutates its input);
 *  - execute (batched, driven by {@see \OCA\OpenRegister\BackgroundJob\SchemaRunJob}):
 *    for each object, apply the transform chain in memory; an object whose
 *    chain fails is recorded as a failure and left untouched (no partial
 *    write — the no-data-loss guard); a changed object is persisted through
 *    {@see \OCA\OpenRegister\Service\ObjectService::saveObject} so audit
 *    trail, content versions, events (under bulk-suppression) and
 *    system-context attribution all apply, capturing its pre/post version
 *    and a pre-migration data snapshot for rollback;
 *  - rollback: restore each touched object's pre-migration snapshot forward
 *    through the save pipeline, skipping (and reporting as a conflict) any
 *    object whose current version no longer matches the recorded
 *    post-migration version.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Declarative migration engine with rollback.
 */
class SchemaMigrationService {

	/**
	 * Default preview sample size.
	 *
	 * @var int
	 */
	public const DEFAULT_PREVIEW_SAMPLE = 10;

	/**
	 * Default per-batch object count.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH = 100;

	/**
	 * Constructor.
	 *
	 * @param SchemaMigrationPlanner $planner Pure transform engine.
	 * @param SchemaRunMapper $runMapper Run persistence.
	 * @param SchemaRunEntryMapper $runEntryMapper Per-object run entries.
	 * @param SchemaMapper $schemaMapper Schema lookup.
	 * @param ObjectService $objectService Object read/save pipeline.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaMigrationPlanner $planner,
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRunEntryMapper $runEntryMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Validate a migration plan structurally.
	 *
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 *
	 * @return array<int, string> Problems (empty when valid).
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function validatePlan(array $plan): array {
		return $this->planner->validatePlan($plan);
	}//end validatePlan()

	/**
	 * Preview a plan against a bounded sample without persisting.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 * @param int $sampleSize The sample size.
	 *
	 * @return array<int, array<string, mixed>> Before/after pairs.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function preview(int $schemaId, int $registerId, array $plan, int $sampleSize = self::DEFAULT_PREVIEW_SAMPLE): array {
		$objects = $this->loadSample(schemaId: $schemaId, registerId: $registerId, sampleSize: $sampleSize);
		$pairs = [];

		foreach ($objects as $object) {
			$before = $object->getObject();
			$result = $this->planner->apply($before, $plan);

			$pairs[] = [
				'uuid' => $object->getUuid(),
				'before' => $before,
				'after' => $result->getData(),
				'changed' => $result->isChanged(),
				'failed' => $result->isFailed(),
				'error' => $result->getFailure(),
			];
		}

		return $pairs;
	}//end preview()

	/**
	 * Start a migration run.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 * @param array<string, mixed> $options Options (stopOnError).
	 * @param string|null $startedBy The starting user.
	 *
	 * @return SchemaRun The created run.
	 *
	 * @throws SchemaRunConcurrencyException When an active run exists.
	 * @throws \InvalidArgumentException When the plan is invalid.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function start(int $schemaId, int $registerId, array $plan, array $options = [], ?string $startedBy = null): SchemaRun {
		$problems = $this->planner->validatePlan($plan);
		if (count($problems) > 0) {
			throw new InvalidArgumentException(implode(' ', $problems));
		}

		$this->assertNoActiveRun(schemaId: $schemaId);

		$total = $this->countPopulation(schemaId: $schemaId, registerId: $registerId);

		return $this->runMapper->createFromArray(
			[
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'type' => SchemaRun::TYPE_MIGRATION,
				'state' => SchemaRun::STATE_RUNNING,
				'plan' => $plan,
				'options' => $options,
				'total' => $total,
				'startedBy' => $startedBy,
				'report' => ['migrated' => 0, 'unchanged' => 0, 'failed' => 0],
			]
		);

	}//end start()

	/**
	 * Process one batch of a migration run.
	 *
	 * @param SchemaRun $run The run.
	 * @param int $batchSize The batch size.
	 *
	 * @return bool True when more work remains.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function processBatch(SchemaRun $run, int $batchSize = self::DEFAULT_BATCH): bool {
		$schema = $this->schemaMapper->find($run->getSchemaId());
		$plan = ($run->getPlan() ?? []);
		$stopOnError = (bool)(($run->getOptions() ?? [])['stopOnError'] ?? false);

		$objects = $this->loadBatch(run: $run, batchSize: $batchSize);
		if (count($objects) === 0) {
			$this->finish(run: $run, state: SchemaRun::STATE_COMPLETED);
			return false;
		}

		$report = ($run->getReport() ?? ['migrated' => 0, 'unchanged' => 0, 'failed' => 0]);
		$maxId = $run->getCursor();

		foreach ($objects as $object) {
			$maxId = max($maxId, (int)$object->getId());
			$before = $object->getObject();
			$result = $this->planner->apply($before, $plan);

			if ($result->isFailed() === true) {
				$report['failed'] = (($report['failed'] ?? 0) + 1);
				$this->runEntryMapper->createFromArray(
					[
						'runId' => $run->getId(),
						'objectUuid' => $object->getUuid(),
						'outcome' => SchemaRunEntry::OUTCOME_FAILED,
						'message' => $result->getFailure(),
					]
				);

				$run->setProcessed(($run->getProcessed() + 1));

				if ($stopOnError === true) {
					$run->setCursor($maxId);
					$run->setReport($report);
					$this->finish(run: $run, state: SchemaRun::STATE_FAILED);
					return false;
				}

				continue;
			}//end if

			if ($result->isChanged() === false) {
				$report['unchanged'] = (($report['unchanged'] ?? 0) + 1);
				$run->setProcessed(($run->getProcessed() + 1));
				continue;
			}

			// Persist the changed object through the standard save pipeline.
			$preVersion = $object->getVersion();
			try {
				$saved = $this->objectService->saveObject(
					object: $result->getData(),
					register: $run->getRegisterId(),
					schema: $schema,
					uuid: $object->getUuid(),
					_rbac: false,
					_multitenancy: false
				);

				$report['migrated'] = (($report['migrated'] ?? 0) + 1);
				$this->runEntryMapper->createFromArray(
					[
						'runId' => $run->getId(),
						'objectUuid' => $object->getUuid(),
						'outcome' => SchemaRunEntry::OUTCOME_MIGRATED,
						'preVersion' => $preVersion,
						'postVersion' => $saved->getVersion(),
						'preData' => $before,
					]
				);
			} catch (\Throwable $e) {
				$report['failed'] = (($report['failed'] ?? 0) + 1);
				$this->runEntryMapper->createFromArray(
					[
						'runId' => $run->getId(),
						'objectUuid' => $object->getUuid(),
						'outcome' => SchemaRunEntry::OUTCOME_FAILED,
						'message' => $e->getMessage(),
					]
				);

				if ($stopOnError === true) {
					$run->setProcessed(($run->getProcessed() + 1));
					$run->setCursor($maxId);
					$run->setReport($report);
					$this->finish(run: $run, state: SchemaRun::STATE_FAILED);
					return false;
				}
			}//end try

			$run->setProcessed(($run->getProcessed() + 1));
		}//end foreach

		$run->setCursor($maxId);
		$run->setReport($report);
		$this->runMapper->save($run);

		return true;
	}//end processBatch()

	/**
	 * Roll a completed or failed migration run back.
	 *
	 * Restores each migrated object's pre-migration snapshot forward through
	 * the save pipeline, but only when the object's current version still
	 * equals the recorded post-migration version; otherwise the object is
	 * conflict-skipped and reported. A run can be rolled back only once.
	 *
	 * @param int $runId The migration run id.
	 * @param string|null $startedBy The user performing the rollback.
	 *
	 * @return SchemaRun The rollback result on the original run.
	 *
	 * @throws SchemaRunConcurrencyException When the run was already rolled back.
	 * @throws \InvalidArgumentException When the run is not a migration.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function rollback(int $runId, ?string $startedBy = null): SchemaRun {
		$run = $this->runMapper->find($runId);

		if ($run->getType() !== SchemaRun::TYPE_MIGRATION) {
			throw new InvalidArgumentException('Only migration runs can be rolled back.');
		}

		if ($run->getState() === SchemaRun::STATE_ROLLED_BACK) {
			throw new SchemaRunConcurrencyException(message: sprintf('Run #%d has already been rolled back.', $runId));
		}

		$schema = $this->schemaMapper->find($run->getSchemaId());
		$entries = $this->runEntryMapper->findByRun($runId, SchemaRunEntry::OUTCOME_MIGRATED);

		$restored = 0;
		$conflicts = 0;

		foreach ($entries as $entry) {
			$object = $this->objectService->find(
				id: $entry->getObjectUuid(),
				register: $run->getRegisterId(),
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);

			if ($object === null) {
				$conflicts++;
				$this->recordRollbackEntry(
					run: $run,
					objectUuid: $entry->getObjectUuid(),
					outcome: SchemaRunEntry::OUTCOME_CONFLICT,
					message: 'Object no longer exists.'
				);
				continue;
			}

			// Conflict guard: skip objects edited by other writers since the migration.
			if ((string)$object->getVersion() !== (string)$entry->getPostVersion()) {
				$conflicts++;
				$this->recordRollbackEntry(
					run: $run,
					objectUuid: $entry->getObjectUuid(),
					outcome: SchemaRunEntry::OUTCOME_CONFLICT,
					message: sprintf('Object modified after migration (current version %s).', (string)$object->getVersion())
				);
				continue;
			}

			try {
				$this->objectService->saveObject(
					object: ($entry->getPreData() ?? []),
					register: $run->getRegisterId(),
					schema: $schema,
					uuid: $entry->getObjectUuid(),
					_rbac: false,
					_multitenancy: false
				);
				$restored++;
				$this->recordRollbackEntry(
					run: $run,
					objectUuid: $entry->getObjectUuid(),
					outcome: SchemaRunEntry::OUTCOME_RESTORED,
					message: null
				);
			} catch (\Throwable $e) {
				$conflicts++;
				$this->recordRollbackEntry(
					run: $run,
					objectUuid: $entry->getObjectUuid(),
					outcome: SchemaRunEntry::OUTCOME_CONFLICT,
					message: $e->getMessage()
				);
			}//end try
		}//end foreach

		$report = ($run->getReport() ?? []);
		$report['rollback'] = ['restored' => $restored, 'conflicts' => $conflicts];
		$run->setReport($report);
		$run->setState(SchemaRun::STATE_ROLLED_BACK);
		$this->runMapper->save($run);

		return $run;
	}//end rollback()

	/**
	 * Finish a run, persisting its terminal state.
	 *
	 * @param SchemaRun $run The run.
	 * @param string $state The terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function finish(SchemaRun $run, string $state): void {
		$run->setState($state);
		$this->runMapper->save($run);

	}//end finish()

	/**
	 * Refuse a second concurrent run on the same schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 *
	 * @throws SchemaRunConcurrencyException When an active run exists.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function assertNoActiveRun(int $schemaId): void {
		$active = $this->runMapper->findActiveForSchema($schemaId);
		if ($active !== null) {
			throw new SchemaRunConcurrencyException(
				message: sprintf('An active run (#%d) already exists for schema %d.', $active->getId(), $schemaId)
			);
		}

	}//end assertNoActiveRun()

	/**
	 * Record a rollback per-object entry.
	 *
	 * @param SchemaRun $run The run.
	 * @param string|null $objectUuid The object UUID.
	 * @param string $outcome The outcome.
	 * @param string|null $message The message.
	 *
	 * @return void
	 */
	private function recordRollbackEntry(SchemaRun $run, ?string $objectUuid, string $outcome, ?string $message): void {
		$this->runEntryMapper->createFromArray(
			[
				'runId' => $run->getId(),
				'objectUuid' => $objectUuid,
				'outcome' => $outcome,
				'message' => $message,
			]
		);

	}//end recordRollbackEntry()

	/**
	 * Load a bounded sample of objects.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 * @param int $sampleSize The sample size.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function loadSample(int $schemaId, int $registerId, int $sampleSize): array {
		$result = $this->objectService->findAll(
			config: [
				'limit' => $sampleSize,
				'filters' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($result, static fn ($o) => $o instanceof ObjectEntity));
	}//end loadSample()

	/**
	 * Load the next batch of non-deleted objects.
	 *
	 * @param SchemaRun $run The run.
	 * @param int $batchSize The batch size.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function loadBatch(SchemaRun $run, int $batchSize): array {
		$result = $this->objectService->findAll(
			config: [
				'limit' => $batchSize,
				'offset' => $run->getProcessed(),
				'sort' => ['id' => 'ASC'],
				'filters' => [
					'register' => $run->getRegisterId(),
					'schema' => $run->getSchemaId(),
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($result, static fn ($o) => $o instanceof ObjectEntity));
	}//end loadBatch()

	/**
	 * Count the non-deleted population for a schema in a register.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 *
	 * @return int The count.
	 */
	private function countPopulation(int $schemaId, int $registerId): int {
		try {
			return $this->objectService->count(
				config: [
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

	}//end countPopulation()
}//end class
