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
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapperInterface;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;

/**
 * Declarative migration engine with rollback.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The thirteenth class is WriteCause, the ambient
 *                                                  audit-cause frame its object reads run in.
 */
class SchemaMigrationService implements SchemaBatchMigratorInterface {

	use SchemaRunPopulation;

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
	 * The report of a migration run that has processed nothing.
	 *
	 * @var array<string, int>
	 */
	private const EMPTY_REPORT = ['migrated' => 0, 'unchanged' => 0, 'failed' => 0];

	/**
	 * The type and state a new migration run starts with.
	 *
	 * @var array<string, string>
	 */
	private const RUNNING_MIGRATION = ['type' => SchemaRun::TYPE_MIGRATION, 'state' => SchemaRun::STATE_RUNNING];

	/**
	 * Constructor.
	 *
	 * @param SchemaMigrationPlanner $planner Pure transform engine.
	 * @param SchemaRunMapper $runMapper Run persistence.
	 * @param SchemaRunEntryMapperInterface $runEntryMapper Per-object run entries.
	 * @param SchemaMapperInterface $schemaMapper Schema lookup.
	 * @param ObjectOperationsInterface $objectService Object read/save pipeline.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaMigrationPlanner $planner,
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRunEntryMapperInterface $runEntryMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly ObjectOperationsInterface $objectService,
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

		return array_map(fn (ObjectEntity $object): array => $this->previewPair(object: $object, plan: $plan), $objects);
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
		$this->assertValidPlan(plan: $plan);
		$this->assertNoActiveRun(schemaId: $schemaId);
		$scope = ['schemaId' => $schemaId, 'registerId' => $registerId];

		return $this->runMapper->createFromArray(
			$scope + self::RUNNING_MIGRATION + [
				'plan' => $plan,
				'options' => $options,
				'total' => $this->countPopulation(schemaId: $schemaId, registerId: $registerId),
				'startedBy' => $startedBy,
				'report' => self::EMPTY_REPORT,
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
		$objects = $this->nextBatch(run: $run, batchSize: $batchSize);
		if ($objects === []) {
			return false;
		}

		$batch = new SchemaRunBatch(run: $run, schema: $schema, emptyReport: self::EMPTY_REPORT);
		foreach ($objects as $object) {
			if ($this->migrateOne(batch: $batch, object: $object) === false) {
				return $this->haltBatch(batch: $batch);
			}
		}

		return $this->saveBatch(batch: $batch);
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
		$this->assertRollbackable(run: $run, runId: $runId);

		$batch = new SchemaRunBatch(run: $run, schema: $this->schemaMapper->find($run->getSchemaId()), emptyReport: []);
		$entries = $this->runEntryMapper->findByRun(runId: $runId, filter: new OutcomeFilter(outcome: SchemaRunEntry::OUTCOME_MIGRATED));
		$tally = new SchemaRollbackTally();
		foreach ($entries as $entry) {
			$this->rollbackOne(batch: $batch, entry: $entry, tally: $tally);
		}

		$this->markRolledBack(run: $run, tally: $tally);

		return $run;
	}//end rollback()

	/**
	 * Add the rollback counts to the run's report and persist it as rolled back.
	 *
	 * @param SchemaRun           $run   The run.
	 * @param SchemaRollbackTally $tally The restored and conflict counts.
	 *
	 * @return void
	 */
	private function markRolledBack(SchemaRun $run, SchemaRollbackTally $tally): void {
		$report = ($run->getReport() ?? []);
		$report['rollback'] = $tally->toArray();
		$run->setReport($report);
		$run->setState(SchemaRun::STATE_ROLLED_BACK);
		$this->runMapper->save($run);

	}//end markRolledBack()

	/**
	 * Refuse an invalid plan.
	 *
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the plan is invalid.
	 */
	private function assertValidPlan(array $plan): void {
		$problems = $this->planner->validatePlan($plan);
		if (count($problems) > 0) {
			throw new InvalidArgumentException(implode(' ', $problems));
		}

	}//end assertValidPlan()

	/**
	 * One before/after pair of a preview.
	 *
	 * @param ObjectEntity                     $object The sampled object.
	 * @param array<int, array<string, mixed>> $plan   The transform chain.
	 *
	 * @return array<string, mixed> The pair.
	 */
	private function previewPair(ObjectEntity $object, array $plan): array {
		$before = $object->getObject();
		$result = $this->planner->apply($before, $plan);

		return [
			'uuid' => $object->getUuid(),
			'before' => $before,
			'after' => $result->getData(),
			'changed' => $result->isChanged(),
			'failed' => $result->isFailed(),
			'error' => $result->getFailure(),
		];
	}//end previewPair()

	/**
	 * Migrate one object of a batch.
	 *
	 * @param SchemaRunBatch $batch  The batch.
	 * @param ObjectEntity   $object The object.
	 *
	 * @return bool False when the run must halt.
	 */
	private function migrateOne(SchemaRunBatch $batch, ObjectEntity $object): bool {
		$batch->reach(object: $object);
		$continue = $this->applyPlan(batch: $batch, object: $object);
		$batch->markProcessed();

		return $continue;
	}//end migrateOne()

	/**
	 * Apply the run's plan to one object in memory and persist a change.
	 *
	 * An object whose chain fails is recorded and left untouched: no partial write.
	 *
	 * @param SchemaRunBatch $batch  The batch.
	 * @param ObjectEntity   $object The object.
	 *
	 * @return bool False when the run must halt.
	 */
	private function applyPlan(SchemaRunBatch $batch, ObjectEntity $object): bool {
		$result = $this->planner->apply($object->getObject(), ($batch->run->getPlan() ?? []));
		if ($result->isFailed() === true) {
			return $this->recordFailure(batch: $batch, object: $object, message: $result->getFailure());
		}

		if ($result->isChanged() === false) {
			$batch->tally(key: 'unchanged');
			return true;
		}

		return $this->persist(batch: $batch, object: $object, data: $result->getData());
	}//end applyPlan()

	/**
	 * Persist a changed object through the standard save pipeline.
	 *
	 * The entry keeps the pre-migration version and data for a rollback.
	 *
	 * @param SchemaRunBatch       $batch  The batch.
	 * @param ObjectEntity         $object The object as it was read.
	 * @param array<string, mixed> $data   The migrated data.
	 *
	 * @return bool False when the run must halt.
	 */
	private function persist(SchemaRunBatch $batch, ObjectEntity $object, array $data): bool {
		try {
			$saved = $this->saveAs(batch: $batch, data: $data, uuid: $object->getUuid());
			$batch->tally(key: 'migrated');
			$this->recordMigrated(run: $batch->run, object: $object, saved: $saved);
		} catch (\Throwable $e) {
			return $this->recordFailure(batch: $batch, object: $object, message: $e->getMessage());
		}

		return true;
	}//end persist()

	/**
	 * Record a migrated object with its pre/post version and pre-migration data.
	 *
	 * @param SchemaRun    $run    The run.
	 * @param ObjectEntity $object The object as it was read.
	 * @param ObjectEntity $saved  The object as it was saved.
	 *
	 * @return void
	 */
	private function recordMigrated(SchemaRun $run, ObjectEntity $object, ObjectEntity $saved): void {
		$this->recordEntry(
			run: $run,
			entry: [
				'objectUuid' => $object->getUuid(),
				'outcome' => SchemaRunEntry::OUTCOME_MIGRATED,
				'preVersion' => $object->getVersion(),
				'postVersion' => $saved->getVersion(),
				'preData' => $object->getObject(),
			]
		);

	}//end recordMigrated()

	/**
	 * Record a failed object; the run halts when it stops on error.
	 *
	 * @param SchemaRunBatch $batch   The batch.
	 * @param ObjectEntity   $object  The object.
	 * @param string|null    $message Why it failed.
	 *
	 * @return bool False when the run must halt.
	 */
	private function recordFailure(SchemaRunBatch $batch, ObjectEntity $object, ?string $message): bool {
		$batch->tally(key: 'failed');
		$this->recordEntry(
			run: $batch->run,
			entry: ['objectUuid' => $object->getUuid(), 'outcome' => SchemaRunEntry::OUTCOME_FAILED, 'message' => $message]
		);

		return ((bool)(($batch->run->getOptions() ?? [])['stopOnError'] ?? false)) === false;
	}//end recordFailure()

	/**
	 * Save object data into the run's register and schema, without RBAC or multitenancy.
	 *
	 * @param SchemaRunBatch       $batch The batch.
	 * @param array<string, mixed> $data  The object data.
	 * @param string|null          $uuid  The object uuid.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function saveAs(SchemaRunBatch $batch, array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: $batch->run->getRegisterId(),
			schema: $batch->schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end saveAs()

	/**
	 * Refuse to roll back a run that is not a migration or was rolled back already.
	 *
	 * @param SchemaRun $run   The run.
	 * @param int       $runId The run id asked for.
	 *
	 * @return void
	 *
	 * @throws SchemaRunConcurrencyException When the run was already rolled back.
	 * @throws InvalidArgumentException When the run is not a migration.
	 */
	private function assertRollbackable(SchemaRun $run, int $runId): void {
		if ($run->getType() !== SchemaRun::TYPE_MIGRATION) {
			throw new InvalidArgumentException('Only migration runs can be rolled back.');
		}

		if ($run->getState() === SchemaRun::STATE_ROLLED_BACK) {
			throw new SchemaRunConcurrencyException(message: sprintf('Run #%d has already been rolled back.', $runId));
		}

	}//end assertRollbackable()

	/**
	 * Restore one migrated object, unless it is gone or was edited since.
	 *
	 * @param SchemaRunBatch      $batch The rollback, as a batch over the run's schema.
	 * @param SchemaRunEntry      $entry The migrated entry.
	 * @param SchemaRollbackTally $tally The restored and conflict counts.
	 *
	 * @return void
	 */
	private function rollbackOne(SchemaRunBatch $batch, SchemaRunEntry $entry, SchemaRollbackTally $tally): void {
		$conflict = $this->rollbackConflict(current: $this->findCurrent(batch: $batch, uuid: $entry->getObjectUuid()), entry: $entry);
		if ($conflict !== null) {
			$tally->conflict();
			$this->recordOutcome(batch: $batch, entry: $entry, outcome: [SchemaRunEntry::OUTCOME_CONFLICT, $conflict]);
			return;
		}

		$this->restore(batch: $batch, entry: $entry, tally: $tally);

	}//end rollbackOne()

	/**
	 * Save an object's pre-migration snapshot; a refused save is a conflict.
	 *
	 * @param SchemaRunBatch      $batch The rollback batch.
	 * @param SchemaRunEntry      $entry The migrated entry.
	 * @param SchemaRollbackTally $tally The restored and conflict counts.
	 *
	 * @return void
	 */
	private function restore(SchemaRunBatch $batch, SchemaRunEntry $entry, SchemaRollbackTally $tally): void {
		try {
			$this->saveAs(batch: $batch, data: ($entry->getPreData() ?? []), uuid: $entry->getObjectUuid());
			$tally->restore();
			$this->recordOutcome(batch: $batch, entry: $entry, outcome: [SchemaRunEntry::OUTCOME_RESTORED, null]);
		} catch (\Throwable $e) {
			$tally->conflict();
			$this->recordOutcome(batch: $batch, entry: $entry, outcome: [SchemaRunEntry::OUTCOME_CONFLICT, $e->getMessage()]);
		}

	}//end restore()

	/**
	 * Why an object may not be restored, or null when it may.
	 *
	 * Conflict guard: an object gone, or edited by another writer since the migration, is skipped.
	 *
	 * @param ObjectEntity|null $current The object as it is now.
	 * @param SchemaRunEntry    $entry   The migrated entry.
	 *
	 * @return string|null The conflict message.
	 */
	private function rollbackConflict(?ObjectEntity $current, SchemaRunEntry $entry): ?string {
		if ($current === null) {
			return 'Object no longer exists.';
		}

		if ((string)$current->getVersion() !== (string)$entry->getPostVersion()) {
			return sprintf('Object modified after migration (current version %s).', (string)$current->getVersion());
		}

		return null;
	}//end rollbackConflict()

	/**
	 * Read an object as it is now, without RBAC or multitenancy.
	 *
	 * @param SchemaRunBatch $batch The rollback batch.
	 * @param string|null    $uuid  The object uuid.
	 *
	 * @return ObjectEntity|null The object, or null when it is gone.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function findCurrent(SchemaRunBatch $batch, ?string $uuid): ?ObjectEntity {
		return WriteCause::asLookup(fn () => $this->objectService->find(
			id: $uuid,
			register: $batch->run->getRegisterId(),
			schema: $batch->schema,
			_rbac: false,
			_multitenancy: false
		));
	}//end findCurrent()

	/**
	 * Record a rollback per-object entry.
	 *
	 * @param SchemaRunBatch                   $batch   The rollback batch.
	 * @param SchemaRunEntry                   $entry   The migrated entry.
	 * @param array{0: string, 1: string|null} $outcome The outcome and its message.
	 *
	 * @return void
	 */
	private function recordOutcome(SchemaRunBatch $batch, SchemaRunEntry $entry, array $outcome): void {
		$this->recordEntry(
			run: $batch->run,
			entry: ['objectUuid' => $entry->getObjectUuid(), 'outcome' => $outcome[0], 'message' => $outcome[1]]
		);

	}//end recordOutcome()

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
}//end class
