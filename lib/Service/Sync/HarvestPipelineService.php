<?php

/**
 * OpenRegister Harvest Pipeline Service
 *
 * Orchestrates the CKAN-style three-stage harvest pipeline (gather, fetch,
 * import) for a single sync Source. Transport is delegated to an injected
 * SourceFetcherInterface so the orchestration is unit-testable without real
 * network or database I/O; mapping reuses MappingService, persistence reuses
 * ObjectService::saveObject (which validates against the target schema), and
 * conflict resolution reuses SyncConflictResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordInputs;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

/**
 * Runs the gather -> fetch -> import pipeline for one source.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HarvestPipelineService implements HarvestPipelineServiceInterface {

	/**
	 * Default batch size when a source does not configure one.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Items one run takes when a source sets no maxItemsPerRun.
	 */
	public const DEFAULT_MAX_ITEMS_PER_RUN = 1000;

	/**
	 * The most items one run may take, whatever the source sets.
	 */
	public const MAX_ITEMS_PER_RUN = 10000;

	/**
	 * The most per-item errors a run summary lists.
	 */
	public const MAX_SUMMARY_ERRORS = 100;

	/**
	 * The import-summary counter each settled status increments.
	 *
	 * @var array<string, string>
	 */
	private const SUMMARY_KEY_BY_STATUS = [
		SyncRecordStatus::UNCHANGED => 'unchanged',
		SyncRecordStatus::CONFLICT => 'conflicts',
		SyncRecordStatus::SHADOWED => 'shadowed',
		SyncRecordStatus::REJECTED => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param SyncRecordMapperInterface $syncRecordMapper Per-record tracking persistence
	 * @param HarvestImportServices $importServices Mapping, persistence and conflict resolution for the import stage
	 * @param LoggerInterface $logger Logger
	 * @param HarvestHttpClient|null $http The guarded client a batch fetcher reads through (null: none; a batch run then fails)
	 */
	public function __construct(
		private readonly SyncRecordMapperInterface $syncRecordMapper,
		private readonly HarvestImportServices $importServices,
		private readonly LoggerInterface $logger,
		private readonly ?HarvestHttpClient $http = null,
	) {
	}//end __construct()

	/**
	 * Run the full pipeline for a source.
	 *
	 * @param HarvestRun $harvest The source to harvest, its transport, the execution id and the
	 *                            incremental checkpoint (null = full)
	 *
	 * @return array<string, mixed> Execution summary (counts + status)
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function run(HarvestRun $harvest): array {
		if ($harvest->fetcher instanceof IBatchSourceFetcher) {
			return $this->runBatch(harvest: $harvest, fetcher: $harvest->fetcher);
		}

		$gathered = $this->gather(harvest: $harvest);
		$fetched  = $this->fetchAll(harvest: $harvest, records: $gathered);

		return $this->import(harvest: $harvest, records: $fetched);
	}//end run()

	/**
	 * Run a whole-document fetcher: one read, no per-item request.
	 *
	 * Items past the source's maxItemsPerRun are left for the next run and make
	 * the batch incomplete. Every id the batch names in its errors keeps its
	 * last good record data under status fetch_error. After a complete batch,
	 * every record of the source whose id was neither returned nor named in an
	 * error is tombstoned; after an incomplete one nothing is.
	 *
	 * @param HarvestRun          $harvest The run.
	 * @param IBatchSourceFetcher $fetcher The run's fetcher.
	 *
	 * @return array<string, mixed> The run summary: the import counters plus tombstoned, complete and errorList.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function runBatch(HarvestRun $harvest, IBatchSourceFetcher $fetcher): array {
		if ($this->http === null) {
			throw new \RuntimeException('A batch fetcher needs the harvest HTTP client; none is configured.');
		}

		$batch = $fetcher->gatherItems(source: $harvest->source, since: $harvest->since, http: $this->http)
			->capped(max: self::maxItemsPerRun(source: $harvest->source));

		$execution = SyncRecordInputs::execution(source: $harvest->source, executionId: $harvest->executionId);
		$fetched   = [];
		foreach ($batch->items as $externalId => $raw) {
			$record = $this->syncRecordMapper->createPending(execution: $execution, externalId: (string)$externalId);
			$record->setRawData($raw);
			$record->setAttempts(1);
			$this->syncRecordMapper->transitionStatus(record: $record, newStatus: SyncRecordStatus::FETCHED);
			$fetched[] = $record;
		}

		foreach ($batch->errorsById() as $externalId => $message) {
			$this->recordItemError(harvest: $harvest, externalId: $externalId, message: $message);
		}

		$summary = $this->import(harvest: $harvest, records: $fetched);
		$summary['errors'] = ((int)$summary['errors'] + count($batch->errors));
		$summary['gathered'] = (count($batch->items) + count($batch->errorsById()));

		$summary['tombstoned'] = 0;
		$summary['deleted']    = 0;
		if ($batch->complete === true) {
			$pass = new HarvestTombstonePass(
				records: $this->syncRecordMapper,
				objects: $this->importServices->objectService,
				logger: $this->logger
			);
			$summary = array_replace($summary, $pass->apply(source: $harvest->source, seenIds: $batch->seenIds()));
		}

		if ($batch->checkpoint !== null) {
			$harvest->source->setLastSyncToken($batch->checkpoint);
		}

		$summary['complete']  = $batch->complete;
		$summary['errorList'] = self::errorList(batch: $batch);
		$summary['status']    = $this->deriveStatus(summary: $summary);
		if ($batch->complete === false && $summary['status'] === 'success') {
			$summary['status'] = 'partial';
		}

		return $summary;
	}//end runBatch()

	/**
	 * The most items one run of a source takes: its maxItemsPerRun, within 1 and the ceiling.
	 *
	 * @param Source $source The source.
	 *
	 * @return int The cap.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public static function maxItemsPerRun(Source $source): int {
		$configured = ($source->getMaxItemsPerRun() ?? self::DEFAULT_MAX_ITEMS_PER_RUN);

		return max(1, min((int)$configured, self::MAX_ITEMS_PER_RUN));
	}//end maxItemsPerRun()

	/**
	 * Record a per-item error: the id keeps its last good data, under status fetch_error.
	 *
	 * The run's own record of the id copies rawData, contentHash, objectUuid and
	 * lastAppliedAt from the id's previous record, so the next run compares
	 * against the last good import and the linked object is left as it is. An id
	 * with no previous record gets a record with the error and no object.
	 *
	 * @param HarvestRun $harvest    The run.
	 * @param string     $externalId The errored id.
	 * @param string     $message    The fetcher's message.
	 *
	 * @return void
	 */
	private function recordItemError(HarvestRun $harvest, string $externalId, string $message): void {
		$previous = $this->syncRecordMapper->findByExternalId(sourceId: (int)$harvest->source->getId(), externalId: $externalId);
		$record   = $this->syncRecordMapper->createPending(
			execution: SyncRecordInputs::execution(source: $harvest->source, executionId: $harvest->executionId),
			externalId: $externalId
		);
		if ($previous !== null) {
			$record->setRawData($previous->getRawData());
			$record->setContentHash($previous->getContentHash());
			$record->setObjectUuid($previous->getObjectUuid());
			$record->setLastAppliedAt($previous->getLastAppliedAt());
		}

		$record->setAttempts(1);
		$this->syncRecordMapper->recordFailure(
			record: $record,
			failure: SyncRecordInputs::failure(status: SyncRecordStatus::FETCH_ERROR, message: $message)
		);
		$this->logger->warning(
			message: sprintf('[HarvestPipeline] Fetcher reported an error for %s: %s', $externalId, $message),
			context: ['source' => $harvest->source->getUuid(), 'execution' => $harvest->executionId]
		);
	}//end recordItemError()

	/**
	 * The batch's errors as the summary lists them, at most MAX_SUMMARY_ERRORS.
	 *
	 * @param HarvestBatch $batch The batch.
	 *
	 * @return list<array{externalId: string|null, message: string}> The errors.
	 */
	private static function errorList(HarvestBatch $batch): array {
		$list = [];
		foreach (array_slice($batch->errors, 0, self::MAX_SUMMARY_ERRORS) as $error) {
			$externalId = ($error['externalId'] ?? null);
			if ($externalId !== null) {
				$externalId = (string)$externalId;
			}

			$list[] = ['externalId' => $externalId, 'message' => (string)($error['message'] ?? '')];
		}

		return $list;
	}//end errorList()

	/**
	 * Gather stage: identify records and create pending tracking rows.
	 *
	 * @param HarvestRun $harvest The run: source, transport, execution id and checkpoint
	 *
	 * @return SyncRecord[] The created pending tracking rows
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function gather(HarvestRun $harvest): array {
		$externalIds = $harvest->fetcher->gather(source: $harvest->source, since: $harvest->since);

		$execution = SyncRecordInputs::execution(source: $harvest->source, executionId: $harvest->executionId);
		$records   = [];
		foreach ($externalIds as $externalId) {
			$records[] = $this->syncRecordMapper->createPending(execution: $execution, externalId: (string)$externalId);
		}

		$this->logger->info(
			message: sprintf('[HarvestPipeline] Gather complete: %d records identified', count($records)),
			context: ['source' => $harvest->source->getUuid(), 'execution' => $harvest->executionId]
		);

		return $records;
	}//end gather()

	/**
	 * Fetch stage: retrieve raw data for each pending record.
	 *
	 * Individual fetch failures are isolated: the record is marked
	 * fetch_error and the pipeline continues (partial-failure support).
	 *
	 * @param HarvestRun   $harvest The run: source and transport
	 * @param SyncRecord[] $records Pending records to fetch
	 *
	 * @return SyncRecord[] Records that fetched successfully
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function fetchAll(HarvestRun $harvest, array $records): array {
		$fetched = [];
		foreach ($records as $record) {
			try {
				$raw = $harvest->fetcher->fetch(source: $harvest->source, externalId: (string)$record->getExternalId());
				$record->setRawData($raw);
				$record->setAttempts((int)$record->getAttempts() + 1);
				$this->syncRecordMapper->transitionStatus(record: $record, newStatus: SyncRecordStatus::FETCHED);
				$fetched[] = $record;
			} catch (Throwable $e) {
				$this->markFetchFailed(record: $record, error: $e);
			}
		}//end foreach

		return $fetched;
	}//end fetchAll()

	/**
	 * Count a failed fetch as an attempt, record it as fetch_error, and say so.
	 *
	 * @param SyncRecord $record The record whose fetch failed.
	 * @param Throwable  $error  Why it failed.
	 *
	 * @return void
	 */
	private function markFetchFailed(SyncRecord $record, Throwable $error): void {
		$record->setAttempts((int)$record->getAttempts() + 1);
		$this->syncRecordMapper->recordFailure(
			record: $record,
			failure: SyncRecordInputs::failure(status: SyncRecordStatus::FETCH_ERROR, message: $error->getMessage())
		);
		$this->logger->warning(
			message: sprintf('[HarvestPipeline] Fetch failed for %s: %s', $record->getExternalId(), $error->getMessage())
		);
	}//end markFetchFailed()

	/**
	 * Import stage: map, validate, resolve conflicts and persist.
	 *
	 * @param HarvestRun   $harvest The run: source and execution id
	 * @param SyncRecord[] $records Fetched records to import
	 *
	 * @return array<string, int|string> Summary counts + overall status
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function import(HarvestRun $harvest, array $records): array {
		$summary = self::openSummary(executionId: $harvest->executionId, gathered: count($records));

		$target = new SourceMapping(source: $harvest->source, mapping: $this->resolveMapping(source: $harvest->source));

		foreach ($records as $record) {
			$summary[$this->outcomeOf(target: $target, record: $record)]++;
		}

		$summary['status'] = $this->deriveStatus(summary: $summary);

		$this->logCompletion(harvest: $harvest, summary: $summary);

		return $summary;
	}//end import()

	/**
	 * A run summary with every counter at zero.
	 *
	 * @param string $executionId The run's execution id
	 * @param int    $gathered    How many records were gathered
	 *
	 * @return array<string, int|string> The summary.
	 */
	private static function openSummary(string $executionId, int $gathered): array {
		return [
			'executionId' => $executionId,
			'gathered' => $gathered,
			'created' => 0,
			'updated' => 0,
			'unchanged' => 0,
			'conflicts' => 0,
			'shadowed' => 0,
			'rejected' => 0,
			'errors' => 0,
		];
	}//end openSummary()

	/**
	 * Import one record and name the counter it lands in; a failure is recorded and lands in 'errors'.
	 *
	 * @param SourceMapping $target The source and the mapping its records go through
	 * @param SyncRecord    $record The fetched record
	 *
	 * @return string The summary counter: created, updated, unchanged, conflicts or errors
	 */
	private function outcomeOf(SourceMapping $target, SyncRecord $record): string {
		try {
			return $this->importRecord(target: $target, record: $record);
		} catch (Throwable $e) {
			$this->syncRecordMapper->recordFailure(
				record: $record,
				failure: SyncRecordInputs::failure(status: SyncRecordStatus::IMPORT_ERROR, message: $e->getMessage())
			);
			$this->logger->warning(
				message: sprintf('[HarvestPipeline] Import failed for %s: %s', $record->getExternalId(), $e->getMessage())
			);
			return 'errors';
		}
	}//end outcomeOf()

	/**
	 * Log the counters of a finished import.
	 *
	 * @param HarvestRun                $harvest The run
	 * @param array<string, int|string> $summary The run summary
	 *
	 * @return void
	 */
	private function logCompletion(HarvestRun $harvest, array $summary): void {
		$this->logger->info(
			message: sprintf(
				'[HarvestPipeline] Import complete: %d created, %d updated, %d unchanged, %d conflicts, %d errors',
				$summary['created'],
				$summary['updated'],
				$summary['unchanged'],
				$summary['conflicts'],
				$summary['errors']
			),
			context: ['source' => $harvest->source->getUuid(), 'execution' => $harvest->executionId]
		);
	}//end logCompletion()

	/**
	 * Import a single fetched record and name the summary counter it increments.
	 *
	 * The D-7 decision: a record whose content is unchanged settles without a
	 * write; a first sight whose external id a local object already claims, an
	 * object edited locally since the harvest last wrote it, and an object
	 * deleted locally are collisions, settled by the source's strategy
	 * (SyncConflictResolver::strategyFor()); anything else is written.
	 *
	 * @param SourceMapping $target The source and the mapping its records go through
	 * @param SyncRecord    $record The fetched record
	 *
	 * @return string The summary counter: created, updated, unchanged, conflicts, shadowed or rejected
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-collisions-are-detected-by-edit-time-and-carry-a-reason-req-haf-006
	 */
	private function importRecord(SourceMapping $target, SyncRecord $record): string {
		$item = $this->harvested(target: $target, record: $record);

		$repeated = $this->repeatedStatus(item: $item);
		if ($repeated !== null) {
			$this->settleUnwritten(item: $item, status: $repeated, objectUuid: $item->trackedUuid());
			return self::SUMMARY_KEY_BY_STATUS[$repeated];
		}

		[$reason, $localUuid] = $this->collisionOf(source: $target->source, item: $item);
		if ($reason === null) {
			return $this->writeAndSettle(source: $target->source, item: $item, objectUuid: $localUuid);
		}

		$item->record->setConflictReason($reason);
		$strategy = $this->importServices->conflictResolver->strategyFor(
			source: $target->source,
			reason: $reason,
			externalId: (string)$record->getExternalId(),
			mapped: $item->mapped
		);
		$decision = $this->importServices->conflictResolver->resolve(
			strategy: $strategy,
			conflict: new SyncConflict(localChanged: true)
		);

		return $this->settleCollision(source: $target->source, item: $item, decision: $decision, objectUuid: $localUuid);
	}//end importRecord()

	/**
	 * The status a record keeps when its content equals the tracked record's, or null.
	 *
	 * Unchanged content is `unchanged`; a record still waiting on a person
	 * (`conflict`) or settled by a strategy (`shadowed`, `rejected`) keeps that
	 * status until the upstream content changes, which is evaluated afresh.
	 *
	 * @param HarvestedRecord $item The record being imported.
	 *
	 * @return string|null
	 */
	private function repeatedStatus(HarvestedRecord $item): ?string {
		$previous = $item->tracked;
		if ($previous === null || $previous->getContentHash() !== $item->hash) {
			return null;
		}

		$status = (string)$previous->getStatus();
		if (in_array($status, [SyncRecordStatus::CONFLICT, SyncRecordStatus::SHADOWED, SyncRecordStatus::REJECTED], true) === true) {
			$item->record->setConflictReason($previous->getConflictReason());
			return $status;
		}

		return SyncRecordStatus::UNCHANGED;
	}//end repeatedStatus()

	/**
	 * The collision reason of an item and the local object it concerns.
	 *
	 * @param Source          $source The source.
	 * @param HarvestedRecord $item   The record being imported.
	 *
	 * @return array{0: string|null, 1: string|null} The reason (null: no collision) and the local object uuid (null: create).
	 */
	private function collisionOf(Source $source, HarvestedRecord $item): array {
		$trackedUuid = $item->trackedUuid();
		if ($trackedUuid === null) {
			$claim = $this->claimingObject(source: $source, externalId: (string)$item->record->getExternalId());
			if ($claim !== null) {
				return [SyncConflictResolver::REASON_PRE_EXISTING_CLAIM, $claim];
			}

			return [null, null];
		}

		$local = $this->localObject(source: $source, uuid: $trackedUuid);
		if ($local === null) {
			return [SyncConflictResolver::REASON_LOCAL_DELETED, $trackedUuid];
		}

		if ($this->editedLocally(previous: $item->tracked, local: $local) === true) {
			return [SyncConflictResolver::REASON_LOCAL_EDITED, $trackedUuid];
		}

		return [null, $trackedUuid];
	}//end collisionOf()

	/**
	 * Whether the local object changed after the harvest last wrote it.
	 *
	 * The object's updated time against the record's lastAppliedAt; a record
	 * written before lastAppliedAt existed falls back to the content hash of
	 * the whole local object, once (the next write sets lastAppliedAt).
	 *
	 * @param SyncRecord|null                  $previous The tracked record.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $local    The local object.
	 *
	 * @return bool
	 */
	private function editedLocally(?SyncRecord $previous, mixed $local): bool {
		if ($previous === null) {
			return false;
		}

		$appliedAt = $previous->getLastAppliedAt();
		if ($appliedAt !== null) {
			$updated = $local->getUpdated();
			return ($updated instanceof \DateTimeInterface && $updated->getTimestamp() > $appliedAt->getTimestamp());
		}

		if ($previous->getContentHash() === null) {
			return false;
		}

		return $this->contentHash(data: $local->getObject()) !== $previous->getContentHash();
	}//end editedLocally()

	/**
	 * The uuid of a local object in the target schema that already holds this
	 * external id in the source's identityProperty, or null.
	 *
	 * @param Source $source     The source.
	 * @param string $externalId The item's external id.
	 *
	 * @return string|null
	 */
	private function claimingObject(Source $source, string $externalId): ?string {
		$property = $source->getIdentityProperty();
		if ($property === null || $property === '') {
			return null;
		}

		$found = $this->importServices->objectService->searchObjects(
			query: [
				'@self' => ['register' => self::idOrSlug(value: $source->getTargetRegister()), 'schema' => self::idOrSlug(value: $source->getTargetSchema())],
				$property => $externalId,
				'_limit' => 1,
			]
		);
		if (is_array($found) === false || $found === []) {
			return null;
		}

		$first = reset($found);
		if (is_object($first) === true && method_exists($first, 'getUuid') === true) {
			return (string)$first->getUuid();
		}

		if (is_array($first) === true && isset($first['@self']['id']) === true) {
			return (string)$first['@self']['id'];
		}

		return null;
	}//end claimingObject()

	/**
	 * A register or schema reference as a search filter: an int for a numeric id.
	 *
	 * @param string|null $value The reference.
	 *
	 * @return int|string|null
	 */
	private static function idOrSlug(?string $value): int|string|null {
		if ($value !== null && ctype_digit($value) === true) {
			return (int)$value;
		}

		return $value;
	}//end idOrSlug()

	/**
	 * Write the item and settle it imported.
	 *
	 * @param Source          $source     The source.
	 * @param HarvestedRecord $item       The record being imported.
	 * @param string|null     $objectUuid The local object to update (null: create).
	 *
	 * @return string created or updated.
	 */
	private function writeAndSettle(Source $source, HarvestedRecord $item, ?string $objectUuid): string {
		$savedUuid = $this->writeObject(source: $source, item: $item, objectUuid: $objectUuid);
		$this->settleImported(item: $item, objectUuid: $savedUuid);
		if ($objectUuid !== null) {
			return 'updated';
		}

		return 'created';
	}//end writeAndSettle()

	/**
	 * Settle a collision by the strategy's decision.
	 *
	 * Apply: the local object is updated (its previous state stays a version)
	 * and the record is imported. Keep local: shadowed, payload kept. Defer:
	 * conflict, payload kept for a person. Reject: rejected, payload cleared,
	 * never linked.
	 *
	 * @param Source          $source     The source.
	 * @param HarvestedRecord $item       The record being imported.
	 * @param string          $decision   The resolver's decision.
	 * @param string|null     $objectUuid The local object the collision concerns.
	 *
	 * @return string The summary counter.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-a-collision-follows-the-sources-strategy-or-a-decision-table-req-haf-007
	 */
	private function settleCollision(Source $source, HarvestedRecord $item, string $decision, ?string $objectUuid): string {
		switch ($decision) {
			case SyncConflictResolver::APPLY_SOURCE:
				return $this->writeAndSettle(source: $source, item: $item, objectUuid: $objectUuid);
			case SyncConflictResolver::KEEP_LOCAL:
				$this->settleUnwritten(item: $item, status: SyncRecordStatus::SHADOWED, objectUuid: $objectUuid);
				return 'shadowed';
			case SyncConflictResolver::REJECT_SOURCE:
				$item->record->setRawData(null);
				$this->settleUnwritten(item: $item, status: SyncRecordStatus::REJECTED, objectUuid: null);
				return 'rejected';
			default:
				$this->settleUnwritten(item: $item, status: SyncRecordStatus::CONFLICT, objectUuid: $objectUuid);
				return 'conflicts';
		}//end switch
	}//end settleCollision()

	/**
	 * Map a fetched record and find the earlier tracking row for its external id.
	 *
	 * @param SourceMapping $target The source and the mapping its records go through
	 * @param SyncRecord    $record The fetched record
	 *
	 * @return HarvestedRecord The record with its mapped content, hash and earlier row
	 */
	private function harvested(SourceMapping $target, SyncRecord $record): HarvestedRecord {
		$mapped = $this->writablePayload(
			source: $target->source,
			mapping: $target->mapping,
			raw: ($record->getRawData() ?? []),
			externalId: (string)$record->getExternalId()
		);

		// Change detection against the previous synced version.
		$previous = $this->syncRecordMapper->findByExternalId(
			sourceId: (int)$target->source->getId(),
			externalId: (string)$record->getExternalId()
		);

		return new HarvestedRecord(
			record: $record,
			mapped: $mapped,
			hash: $this->contentHash(data: $mapped),
			tracked: $this->trackedPrevious(previous: $previous, record: $record)
		);
	}//end harvested()

	/**
	 * The payload a harvest (or a use-harvested resolution) writes for a raw item:
	 * the raw item through the source's mapping, every protectedFields property
	 * removed, and the provenance properties set after mapping so the mapping
	 * cannot override them.
	 *
	 * @param Source               $source     The source.
	 * @param Mapping|null         $mapping    The source's mapping (null: pass-through).
	 * @param array<string, mixed> $raw        The raw item.
	 * @param string               $externalId The item's external id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-protected-fields-and-provenance-are-never-taken-from-the-source-req-haf-008
	 */
	public function writablePayload(Source $source, ?Mapping $mapping, array $raw, string $externalId): array {
		$mapped = $this->applyMapping(mapping: $mapping, raw: $raw);

		return self::guarded(source: $source, payload: $mapped, externalId: $externalId);
	}//end writablePayload()

	/**
	 * A payload with the source's protectedFields removed and its provenance set.
	 *
	 * @param Source               $source     The source.
	 * @param array<string, mixed> $payload    The mapped payload.
	 * @param string               $externalId The item's external id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-protected-fields-and-provenance-are-never-taken-from-the-source-req-haf-008
	 */
	public static function guarded(Source $source, array $payload, string $externalId): array {
		foreach (self::protectedFieldsOf(source: $source) as $field) {
			unset($payload[$field]);
		}

		$provenance = ($source->getProvenance() ?? []);
		$idProperty = ($provenance['externalIdProperty'] ?? null);
		if (is_string($idProperty) === true && $idProperty !== '') {
			$payload[$idProperty] = $externalId;
		}

		$sourceProperty = ($provenance['sourceProperty'] ?? null);
		if (is_string($sourceProperty) === true && $sourceProperty !== '') {
			$payload[$sourceProperty] = (string)$source->getUuid();
		}

		return $payload;
	}//end guarded()

	/**
	 * The property names a harvest never writes: protectedFields plus the provenance properties.
	 *
	 * @param Source $source The source.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md#requirement-protected-fields-and-provenance-are-never-taken-from-the-source-req-haf-008
	 */
	public static function protectedFieldsOf(Source $source): array {
		$fields = [];
		foreach (($source->getProtectedFields() ?? []) as $field) {
			if (is_string($field) === true && $field !== '') {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end protectedFieldsOf()

	/**
	 * The previous sync record when it is another row than this one, else null.
	 *
	 * @param SyncRecord|null $previous The previous record for the external id.
	 * @param SyncRecord      $record   The record being imported.
	 *
	 * @return SyncRecord|null
	 */
	private function trackedPrevious(?SyncRecord $previous, SyncRecord $record): ?SyncRecord {
		if ($previous !== null && $previous->getId() !== $record->getId()) {
			return $previous;
		}

		return null;
	}//end trackedPrevious()

	/**
	 * Settle a record that was not written.
	 *
	 * @param HarvestedRecord $item       The record being imported.
	 * @param string          $status     The new status.
	 * @param string|null     $objectUuid The local object the record names (null: none).
	 *
	 * @return void
	 */
	private function settleUnwritten(HarvestedRecord $item, string $status, ?string $objectUuid): void {
		$item->record->setContentHash($item->hash);
		$item->record->setObjectUuid($objectUuid);
		// No write: the last harvest write is still the one the previous record names.
		$item->record->setLastAppliedAt($item->tracked?->getLastAppliedAt());
		$this->syncRecordMapper->transitionStatus(record: $item->record, newStatus: $status);
	}//end settleUnwritten()

	/**
	 * Settle a record that was written: it carries the saved object uuid.
	 *
	 * @param HarvestedRecord $item       The record being imported.
	 * @param string|null     $objectUuid The saved local object uuid.
	 *
	 * @return void
	 */
	private function settleImported(HarvestedRecord $item, ?string $objectUuid): void {
		$item->record->setContentHash($item->hash);
		$item->record->setObjectUuid($objectUuid);
		$item->record->setConflictReason(null);
		// The harvest's own write: a later local edit is one after this moment.
		$item->record->setLastAppliedAt(new \DateTime());
		$this->syncRecordMapper->transitionStatus(record: $item->record, newStatus: SyncRecordStatus::IMPORTED);
	}//end settleImported()

	/**
	 * Save the mapped object and return its uuid, falling back to the existing one.
	 *
	 * @param Source          $source     The source (target register and schema).
	 * @param HarvestedRecord $item       The record: its mapped data.
	 * @param string|null     $objectUuid The local object to update (null to create).
	 *
	 * @return string|null
	 */
	private function writeObject(Source $source, HarvestedRecord $item, ?string $objectUuid): ?string {
		$existingUuid = $objectUuid;
		$saved        = $this->importServices->objectService->saveObject(
			object: $item->mapped,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $source->getTargetRegister(), schema: $source->getTargetSchema()),
				uuid: $existingUuid
			)
		);

		$savedUuid = $this->savedUuidOf(saved: $saved);
		if ($savedUuid === '') {
			return $existingUuid;
		}

		return $savedUuid;
	}//end writeObject()

	/**
	 * The uuid a saved entity reports, or '' when it reports none (or cannot).
	 *
	 * @param mixed $saved The saved entity.
	 *
	 * @return string
	 */
	private function savedUuidOf($saved): string {
		try {
			$candidate = $saved->getUuid();
		} catch (Throwable $e) {
			// Fall back to the existing uuid when the entity cannot report one.
			return '';
		}

		if (is_string($candidate) === true) {
			return $candidate;
		}

		return '';
	}//end savedUuidOf()

	/**
	 * Apply the configured mapping to a raw record (pass-through when none).
	 *
	 * @param Mapping|null $mapping The mapping entity
	 * @param array<string, mixed> $raw The raw record
	 *
	 * @return array<string, mixed> The mapped record
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function applyMapping(?Mapping $mapping, array $raw): array {
		if ($mapping === null) {
			return $raw;
		}

		return $this->importServices->mappingService->executeMapping(mapping: $mapping, input: $raw);
	}//end applyMapping()

	/**
	 * Deterministic content hash for change detection.
	 *
	 * @param array<string, mixed> $data The mapped record
	 *
	 * @return string A stable SHA-256 hash
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function contentHash(array $data): string {
		ksort($data);

		return hash('sha256', (string)json_encode($data));
	}//end contentHash()

	/**
	 * Derive the overall execution status from the summary counts.
	 *
	 * @param array<string, mixed> $summary The summary
	 *
	 * @return string success | partial | failed
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function deriveStatus(array $summary): string {
		$errors = (int)($summary['errors'] ?? 0);
		$created = (int)($summary['created'] ?? 0);
		$updated = (int)($summary['updated'] ?? 0);
		$unchanged = (int)($summary['unchanged'] ?? 0);
		$conflicts = (int)($summary['conflicts'] ?? 0);
		$settled = ((int)($summary['shadowed'] ?? 0) + (int)($summary['rejected'] ?? 0));
		$processed = ($created + $updated + $unchanged + $conflicts + $settled);

		if ($errors === 0) {
			return 'success';
		}

		if ($processed === 0) {
			return 'failed';
		}

		return 'partial';
	}//end deriveStatus()

	/**
	 * Resolve the configured Mapping entity for a source, if any.
	 *
	 * @param Source $source The source
	 *
	 * @return Mapping|null The mapping or null when none/unresolvable
	 */
	private function resolveMapping(Source $source): ?Mapping {
		$mappingId = $source->getMappingId();
		if ($mappingId === null) {
			return null;
		}

		try {
			return $this->importServices->mappingMapper->find($mappingId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[HarvestPipeline] Mapping %d not resolvable: %s', $mappingId, $e->getMessage())
			);
			return null;
		}
	}//end resolveMapping()

	/**
	 * The local object a sync record links, read without an audit entry, or
	 * null when it no longer exists.
	 *
	 * Any other failure to read it propagates, so the item is recorded as an
	 * import error rather than read as deleted.
	 *
	 * @param Source $source The source, for the target register and schema.
	 * @param string $uuid   The object uuid.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function localObject(Source $source, string $uuid): mixed {
		try {
			return WriteCause::asLookup(fn () => $this->importServices->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(
					register: $source->getTargetRegister(),
					schema: $source->getTargetSchema(),
					audit: false
				)
			));
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end localObject()
}//end class
