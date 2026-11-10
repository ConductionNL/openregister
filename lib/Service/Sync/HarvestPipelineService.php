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
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * The record status each no-write conflict decision settles on.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_BY_DECISION = [
		SyncConflictResolver::KEEP_LOCAL => SyncRecordStatus::UNCHANGED,
		SyncConflictResolver::DEFER => SyncRecordStatus::CONFLICT,
	];

	/**
	 * The import-summary counter each settled status increments.
	 *
	 * @var array<string, string>
	 */
	private const SUMMARY_KEY_BY_STATUS = [
		SyncRecordStatus::UNCHANGED => 'unchanged',
		SyncRecordStatus::CONFLICT => 'conflicts',
	];

	/**
	 * Constructor.
	 *
	 * @param SyncRecordMapperInterface $syncRecordMapper Per-record tracking persistence
	 * @param HarvestImportServices $importServices Mapping, persistence and conflict resolution for the import stage
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SyncRecordMapperInterface $syncRecordMapper,
		private readonly HarvestImportServices $importServices,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the full pipeline for a source.
	 *
	 * @param HarvestRun $harvest The source to harvest, its transport, the execution id and the
	 *                            incremental checkpoint (null = full)
	 *
	 * @return array<string, int|string> Execution summary (counts + status)
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function run(HarvestRun $harvest): array {
		$gathered = $this->gather(harvest: $harvest);
		$fetched  = $this->fetchAll(harvest: $harvest, records: $gathered);

		return $this->import(harvest: $harvest, records: $fetched);
	}//end run()

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
				$record->setAttempts((int)$record->getAttempts() + 1);
				$this->syncRecordMapper->recordFailure(
					record: $record,
					failure: SyncRecordInputs::failure(status: SyncRecordStatus::FETCH_ERROR, message: $e->getMessage())
				);
				$this->logger->warning(
					message: sprintf('[HarvestPipeline] Fetch failed for %s: %s', $record->getExternalId(), $e->getMessage())
				);
			}//end try
		}//end foreach

		return $fetched;
	}//end fetchAll()

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
	 * @param SourceMapping $target The source and the mapping its records go through
	 * @param SyncRecord    $record The fetched record
	 *
	 * @return string The summary counter: created, updated, unchanged or conflicts
	 */
	private function importRecord(SourceMapping $target, SyncRecord $record): string {
		$item = $this->harvested(target: $target, record: $record);

		// Unchanged, kept local or deferred: no write.
		$settled = $this->settledStatus(source: $target->source, item: $item);
		if ($settled !== null) {
			$this->settleUnwritten(item: $item, status: $settled);
			return self::SUMMARY_KEY_BY_STATUS[$settled];
		}

		// Persist via ObjectService (validates against the target schema).
		$savedUuid = $this->writeObject(source: $target->source, item: $item);
		$this->settleImported(item: $item, objectUuid: $savedUuid);
		if ($item->trackedUuid() !== null) {
			return 'updated';
		}

		return 'created';
	}//end importRecord()

	/**
	 * Map a fetched record and find the earlier tracking row for its external id.
	 *
	 * @param SourceMapping $target The source and the mapping its records go through
	 * @param SyncRecord    $record The fetched record
	 *
	 * @return HarvestedRecord The record with its mapped content, hash and earlier row
	 */
	private function harvested(SourceMapping $target, SyncRecord $record): HarvestedRecord {
		$mapped = $this->applyMapping(mapping: $target->mapping, raw: ($record->getRawData() ?? []));

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
	 * The status a tracked record settles on without a write, or null when the
	 * source version must be written.
	 *
	 * Unchanged content settles as UNCHANGED; otherwise, when a local object is
	 * tracked, the conflict decision may keep it (UNCHANGED) or defer (CONFLICT).
	 *
	 * @param Source          $source The source.
	 * @param HarvestedRecord $item   The record being imported.
	 *
	 * @return string|null
	 */
	private function settledStatus(Source $source, HarvestedRecord $item): ?string {
		$previous = $item->tracked;
		if ($previous === null) {
			return null;
		}

		if ($previous->getContentHash() === $item->hash) {
			return SyncRecordStatus::UNCHANGED;
		}

		if ($previous->getObjectUuid() === null) {
			return null;
		}

		// Conflict resolution when an existing local object changed too.
		$decision = $this->importServices->conflictResolver->resolve(
			strategy: (string)($source->getConflictStrategy() ?? SyncConflictResolver::SOURCE_WINS),
			conflict: new SyncConflict(localChanged: $this->localChangedSinceSync(source: $source, previous: $previous))
		);

		return (self::STATUS_BY_DECISION[$decision] ?? null);
	}//end settledStatus()

	/**
	 * Settle a record that was not written: it keeps the tracked object uuid.
	 *
	 * @param HarvestedRecord $item   The record being imported.
	 * @param string          $status The new status.
	 *
	 * @return void
	 */
	private function settleUnwritten(HarvestedRecord $item, string $status): void {
		$item->record->setContentHash($item->hash);
		$item->record->setObjectUuid($item->trackedUuid());
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
		$this->syncRecordMapper->transitionStatus(record: $item->record, newStatus: SyncRecordStatus::IMPORTED);
	}//end settleImported()

	/**
	 * Save the mapped object and return its uuid, falling back to the existing one.
	 *
	 * @param Source          $source The source (target register and schema).
	 * @param HarvestedRecord $item   The record: its mapped data and tracked object uuid (null to create).
	 *
	 * @return string|null
	 */
	private function writeObject(Source $source, HarvestedRecord $item): ?string {
		$existingUuid = $item->trackedUuid();
		$saved        = $this->importServices->objectService->saveObject(
			object: $item->mapped,
			register: $source->getTargetRegister(),
			schema: $source->getTargetSchema(),
			uuid: $existingUuid
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
	 * @param array<string, int|string> $summary The summary
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
		$processed = ($created + $updated + $unchanged + $conflicts);

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
	 * Whether the local object changed since the last sync wrote it.
	 *
	 * Compares the current local object's content hash to the hash stored
	 * on the previous sync record. Absence of a previous hash is treated as
	 * "not changed" so a first-time match does not spuriously conflict.
	 *
	 * @param Source     $source   The source
	 * @param SyncRecord $previous The previous tracking record (carries the local object uuid)
	 *
	 * @return bool True when the local object diverged from the last sync
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function localChangedSinceSync(Source $source, SyncRecord $previous): bool {
		if ($previous->getContentHash() === null) {
			return false;
		}

		try {
			$local = WriteCause::asLookup(fn () => $this->importServices->objectService->find(
				id: (string)$previous->getObjectUuid(),
				register: $source->getTargetRegister(),
				schema: $source->getTargetSchema()
			));
		} catch (Throwable $e) {
			return false;
		}

		if ($local === null) {
			return false;
		}

		$localHash = $this->contentHash(data: $local->getObject());

		return $localHash !== $previous->getContentHash();
	}//end localChangedSinceSync()
}//end class
