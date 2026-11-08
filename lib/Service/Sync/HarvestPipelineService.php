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
use OCA\OpenRegister\Db\SyncExecution;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecord;
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
	 * Resolves the configured Mapping entity.
	 *
	 * @var MappingMapperInterface
	 */
	private readonly MappingMapperInterface $mappingMapper;

	/**
	 * Applies Twig field transformation.
	 *
	 * @var MappingService
	 */
	private readonly MappingService $mappingService;

	/**
	 * Validates + persists target objects.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Decides source/local conflicts.
	 *
	 * @var SyncConflictResolver
	 */
	private readonly SyncConflictResolver $conflictResolver;

	/**
	 * Constructor.
	 *
	 * @param SyncRecordMapperInterface $syncRecordMapper Per-record tracking persistence
	 * @param HarvestImportServices $importServices Mapping, persistence and conflict resolution for the import stage
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SyncRecordMapperInterface $syncRecordMapper,
		HarvestImportServices $importServices,
		private readonly LoggerInterface $logger,
	) {
		$this->mappingMapper    = $importServices->mappingMapper;
		$this->mappingService   = $importServices->mappingService;
		$this->objectService    = $importServices->objectService;
		$this->conflictResolver = $importServices->conflictResolver;
	}//end __construct()

	/**
	 * Run the full pipeline for a source.
	 *
	 * @param Source $source The source to harvest
	 * @param SourceFetcherInterface $fetcher Transport for gather/fetch
	 * @param string $executionId Unique id for this run
	 * @param string|null $since Incremental checkpoint (null = full)
	 *
	 * @return array<string, int|string> Execution summary (counts + status)
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function run(
		Source $source,
		SourceFetcherInterface $fetcher,
		string $executionId,
		?string $since = null,
	): array {
		$gathered = $this->gather(source: $source, fetcher: $fetcher, executionId: $executionId, since: $since);
		$fetched = $this->fetchAll(source: $source, fetcher: $fetcher, records: $gathered);

		return $this->import(source: $source, records: $fetched, executionId: $executionId);
	}//end run()

	/**
	 * Gather stage: identify records and create pending tracking rows.
	 *
	 * @param Source $source The source
	 * @param SourceFetcherInterface $fetcher Transport
	 * @param string $executionId Execution id
	 * @param string|null $since Incremental checkpoint
	 *
	 * @return SyncRecord[] The created pending tracking rows
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function gather(
		Source $source,
		SourceFetcherInterface $fetcher,
		string $executionId,
		?string $since = null,
	): array {
		$externalIds = $fetcher->gather(source: $source, since: $since);

		$execution = new SyncExecution(source: $source, executionId: $executionId);
		$records   = [];
		foreach ($externalIds as $externalId) {
			$records[] = $this->syncRecordMapper->createPending(execution: $execution, externalId: (string)$externalId);
		}

		$this->logger->info(
			message: sprintf('[HarvestPipeline] Gather complete: %d records identified', count($records)),
			context: ['source' => $source->getUuid(), 'execution' => $executionId]
		);

		return $records;
	}//end gather()

	/**
	 * Fetch stage: retrieve raw data for each pending record.
	 *
	 * Individual fetch failures are isolated: the record is marked
	 * fetch_error and the pipeline continues (partial-failure support).
	 *
	 * @param Source $source The source
	 * @param SourceFetcherInterface $fetcher Transport
	 * @param SyncRecord[] $records Pending records to fetch
	 *
	 * @return SyncRecord[] Records that fetched successfully
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function fetchAll(Source $source, SourceFetcherInterface $fetcher, array $records): array {
		$fetched = [];
		foreach ($records as $record) {
			try {
				$raw = $fetcher->fetch(source: $source, externalId: (string)$record->getExternalId());
				$record->setRawData($raw);
				$record->setAttempts((int)$record->getAttempts() + 1);
				$this->syncRecordMapper->transitionStatus(record: $record, newStatus: SyncRecordStatus::FETCHED);
				$fetched[] = $record;
			} catch (Throwable $e) {
				$record->setAttempts((int)$record->getAttempts() + 1);
				$this->syncRecordMapper->recordFailure(
					record: $record,
					failure: new SyncFailure(status: SyncRecordStatus::FETCH_ERROR, message: $e->getMessage())
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
	 * @param Source $source The source
	 * @param SyncRecord[] $records Fetched records to import
	 * @param string $executionId Execution id
	 *
	 * @return array<string, int|string> Summary counts + overall status
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function import(Source $source, array $records, string $executionId): array {
		$summary = [
			'executionId' => $executionId,
			'gathered' => count($records),
			'created' => 0,
			'updated' => 0,
			'unchanged' => 0,
			'conflicts' => 0,
			'errors' => 0,
		];

		$mapping = $this->resolveMapping(source: $source);

		foreach ($records as $record) {
			try {
				$this->importRecord(source: $source, record: $record, mapping: $mapping, summary: $summary);
			} catch (Throwable $e) {
				$summary['errors']++;
				$this->syncRecordMapper->recordFailure(
					record: $record,
					failure: new SyncFailure(status: SyncRecordStatus::IMPORT_ERROR, message: $e->getMessage())
				);
				$this->logger->warning(
					message: sprintf('[HarvestPipeline] Import failed for %s: %s', $record->getExternalId(), $e->getMessage())
				);
			}
		}

		$summary['status'] = $this->deriveStatus(summary: $summary);

		$this->logger->info(
			message: sprintf(
				'[HarvestPipeline] Import complete: %d created, %d updated, %d unchanged, %d conflicts, %d errors',
				$summary['created'],
				$summary['updated'],
				$summary['unchanged'],
				$summary['conflicts'],
				$summary['errors']
			),
			context: ['source' => $source->getUuid(), 'execution' => $executionId]
		);

		return $summary;
	}//end import()

	/**
	 * Import a single fetched record, updating the summary in place.
	 *
	 * @param Source $source The source
	 * @param SyncRecord $record The fetched record
	 * @param Mapping|null $mapping The configured mapping (or null for pass-through)
	 * @param array $summary Running summary (by reference)
	 *
	 * The precise shape lives in @phpstan-param below rather than inline: every
	 * counter is an int, which is what stops `$summary['created']++` reading as
	 * a string increment. It does not fit on one @param line under the 150-char
	 * limit, and wrapping a @param breaks the parameter-name sniff.
	 *
	 * @phpstan-param array{executionId: string, gathered: int, created: int,
	 *     updated: int, unchanged: int, conflicts: int, errors: int} $summary
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	private function importRecord(Source $source, SyncRecord $record, ?Mapping $mapping, array &$summary): void {
		$raw = ($record->getRawData() ?? []);
		$mapped = $this->applyMapping(mapping: $mapping, raw: $raw);
		$hash = $this->contentHash(data: $mapped);

		// Change detection against the previous synced version.
		$previous = $this->syncRecordMapper->findByExternalId(
			sourceId: (int)$source->getId(),
			externalId: (string)$record->getExternalId()
		);

		// Find an existing local object via prior tracking (carries objectUuid).
		$tracked = $this->trackedPrevious(previous: $previous, record: $record);
		$existingUuid = null;
		$settled = null;
		if ($tracked !== null) {
			$existingUuid = $tracked->getObjectUuid();
			$settled = $this->settledStatus(source: $source, previous: $tracked, hash: $hash);
		}

		// Unchanged, kept local or deferred: no write.
		if ($settled !== null) {
			$this->settle(record: $record, hash: $hash, objectUuid: $existingUuid, status: $settled);
			$summary[self::SUMMARY_KEY_BY_STATUS[$settled]]++;
			return;
		}

		// Persist via ObjectService (validates against the target schema).
		$isUpdate = ($existingUuid !== null);
		$savedUuid = $this->writeObject(source: $source, mapped: $mapped, existingUuid: $existingUuid);

		$this->settle(record: $record, hash: $hash, objectUuid: $savedUuid, status: SyncRecordStatus::IMPORTED);
		$counter = 'created';
		if ($isUpdate === true) {
			$counter = 'updated';
		}

		$summary[$counter]++;
	}//end importRecord()

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
	 * @param Source     $source   The source.
	 * @param SyncRecord $previous The tracked previous record.
	 * @param string     $hash     The content hash of the mapped record.
	 *
	 * @return string|null
	 */
	private function settledStatus(Source $source, SyncRecord $previous, string $hash): ?string {
		if ($previous->getContentHash() === $hash) {
			return SyncRecordStatus::UNCHANGED;
		}

		$existingUuid = $previous->getObjectUuid();
		if ($existingUuid === null) {
			return null;
		}

		// Conflict resolution when an existing local object changed too.
		$decision = $this->conflictResolver->resolve(
			strategy: (string)($source->getConflictStrategy() ?? SyncConflictResolver::SOURCE_WINS),
			localChanged: $this->localChangedSinceSync(source: $source, objectUuid: $existingUuid, previous: $previous)
		);

		return (self::STATUS_BY_DECISION[$decision] ?? null);
	}//end settledStatus()

	/**
	 * Record the content hash and object uuid on a sync record and move it to a status.
	 *
	 * @param SyncRecord  $record     The sync record.
	 * @param string      $hash       The content hash.
	 * @param string|null $objectUuid The local object uuid.
	 * @param string      $status     The new status.
	 *
	 * @return void
	 */
	private function settle(SyncRecord $record, string $hash, ?string $objectUuid, string $status): void {
		$record->setContentHash($hash);
		$record->setObjectUuid($objectUuid);
		$this->syncRecordMapper->transitionStatus(record: $record, newStatus: $status);
	}//end settle()

	/**
	 * Save the mapped object and return its uuid, falling back to the existing one.
	 *
	 * @param Source      $source       The source (target register and schema).
	 * @param array       $mapped       The mapped object data.
	 * @param string|null $existingUuid The tracked local object uuid, or null to create.
	 *
	 * @return string|null
	 */
	private function writeObject(Source $source, array $mapped, ?string $existingUuid): ?string {
		$saved = $this->objectService->saveObject(
			object: $mapped,
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

		return $this->mappingService->executeMapping(mapping: $mapping, input: $raw);
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
			return $this->mappingMapper->find($mappingId);
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
	 * @param Source $source The source
	 * @param string $objectUuid The local object uuid
	 * @param SyncRecord $previous The previous tracking record
	 *
	 * @return bool True when the local object diverged from the last sync
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function localChangedSinceSync(Source $source, string $objectUuid, SyncRecord $previous): bool {
		if ($previous->getContentHash() === null) {
			return false;
		}

		try {
			$local = WriteCause::asLookup(fn () => $this->objectService->find(
				id: $objectUuid,
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
