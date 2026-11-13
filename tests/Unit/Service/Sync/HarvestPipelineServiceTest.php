<?php

/**
 * Unit tests for HarvestPipelineService (gather/fetch/import orchestration).
 *
 * Uses an in-memory SourceFetcherInterface double plus mocked persistence so
 * the three-stage pipeline is exercised without any real network or DBAL.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Sync;

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncExecution;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use GuzzleHttp\Client;
use OCA\OpenRegister\Service\Sync\HarvestBatch;
use OCA\OpenRegister\Service\Sync\HarvestHttpClient;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\IBatchSourceFetcher;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Source
 * @uses \OCA\OpenRegister\Db\SyncExecution
 * @uses \OCA\OpenRegister\Db\SyncFailure
 * @uses \OCA\OpenRegister\Db\SyncRecord
 * @uses \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Db\SyncRecordInputs
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\Sync\HarvestImportServices
 * @uses \OCA\OpenRegister\Service\Sync\HarvestRun
 * @uses \OCA\OpenRegister\Service\Sync\HarvestedRecord
 * @uses \OCA\OpenRegister\Service\Sync\SourceMapping
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\Sync\SyncConflict
 * @uses \OCA\OpenRegister\Service\Sync\HarvestBatch
 * @uses \OCA\OpenRegister\Service\Sync\HarvestHttpClient
 * @uses \OCA\OpenRegister\Service\Http\OutboundUrlGuard
 */
class HarvestPipelineServiceTest extends TestCase {

	private SyncRecordMapper&MockObject $syncRecordMapper;

	private MappingMapper&MockObject $mappingMapper;

	private MappingService&MockObject $mappingService;

	private ObjectService&MockObject $objectService;

	private HarvestPipelineService $pipeline;

	protected function setUp(): void {
		$this->syncRecordMapper = $this->createMock(SyncRecordMapper::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		// createPending returns a real entity carrying the external id.
		$this->syncRecordMapper->method('createPending')->willReturnCallback(
			function (SyncExecution $execution, string $externalId): SyncRecord {
				$rec = new SyncRecord();
				$rec->setSourceId((int) $execution->source->getId());
				$rec->setExecutionId($execution->executionId);
				$rec->setExternalId($externalId);
				$rec->setStatus(SyncRecordStatus::PENDING);
				$rec->setAttempts(0);
				return $rec;
			}
		);

		// transitionStatus applies the status to the entity and returns it.
		$this->syncRecordMapper->method('transitionStatus')->willReturnCallback(
			function (SyncRecord $rec, string $status): SyncRecord {
				$rec->setStatus($status);
				return $rec;
			}
		);

		// recordFailure applies the error status and message to the entity and returns it.
		$this->syncRecordMapper->method('recordFailure')->willReturnCallback(
			function (SyncRecord $rec, SyncFailure $failure): SyncRecord {
				$rec->setStatus($failure->status);
				$rec->setErrorMessage($failure->message);
				return $rec;
			}
		);

		$this->pipeline = new HarvestPipelineService(
			$this->syncRecordMapper,
			new HarvestImportServices(
				mappingMapper: $this->mappingMapper,
				mappingService: $this->mappingService,
				objectService: $this->objectService,
				conflictResolver: new SyncConflictResolver()
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	private function makeSource(): Source {
		$source = new Source();
		$source->setId(1);
		$source->setUuid('source-uuid');
		$source->setType('rest-api');
		$source->setTargetRegister('reg');
		$source->setTargetSchema('schema');
		$source->setConflictStrategy(SyncConflictResolver::SOURCE_WINS);
		return $source;
	}//end makeSource()

	private function fakeFetcher(array $ids, array $payloads, array $failOn = []): SourceFetcherInterface {
		return new class($ids, $payloads, $failOn) implements SourceFetcherInterface {
			public function __construct(
				private array $ids,
				private array $payloads,
				private array $failOn,
			) {
			}

			public function getType(): string {
				return 'test';
			}

			public function getDisplayName(): string {
				return 'Test';
			}

			public function getConfigSchema(): array {
				return [];
			}

			public function supports(string $type): bool {
				return true;
			}

			public function gather(Source $source, ?string $since = null): array {
				return $this->ids;
			}

			public function fetch(Source $source, string $externalId): array {
				if (in_array($externalId, $this->failOn, true) === true) {
					throw new RuntimeException('boom ' . $externalId);
				}
				return ($this->payloads[$externalId] ?? []);
			}
		};
	}//end fakeFetcher()

	public function testGatherCreatesPendingRecords(): void {
		$source = $this->makeSource();
		$fetcher = $this->fakeFetcher(['a', 'b', 'c'], []);

		$records = $this->pipeline->gather(new HarvestRun($source, $fetcher, 'exec-1'));

		$this->assertCount(3, $records);
		$this->assertSame('a', $records[0]->getExternalId());
		$this->assertSame(SyncRecordStatus::PENDING, $records[0]->getStatus());
	}//end testGatherCreatesPendingRecords()

	public function testFetchAllMarksFetchedAndIsolatesFailures(): void {
		$source = $this->makeSource();
		$fetcher = $this->fakeFetcher(['a', 'b'], ['a' => ['x' => 1], 'b' => ['x' => 2]], failOn: ['b']);

		$records = $this->pipeline->gather(new HarvestRun($source, $fetcher, 'exec-1'));
		$fetched = $this->pipeline->fetchAll(new HarvestRun($source, $fetcher, 'exec-1'), $records);

		// Only 'a' fetched; 'b' failed but did not abort.
		$this->assertCount(1, $fetched);
		$this->assertSame(SyncRecordStatus::FETCHED, $fetched[0]->getStatus());
		$this->assertSame(SyncRecordStatus::FETCH_ERROR, $records[1]->getStatus());
		$this->assertSame('boom b', $records[1]->getErrorMessage());
	}//end testFetchAllMarksFetchedAndIsolatesFailures()

	public function testFullRunCreatesNewObjects(): void {
		$source = $this->makeSource();
		$fetcher = $this->fakeFetcher(
			['a', 'b'],
			['a' => ['title' => 'A'], 'b' => ['title' => 'B']]
		);

		// No previous tracking → everything is new.
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$obj = new ObjectEntity();
				$obj->setUuid('new-uuid');
				return $obj;
			}
		);

		$summary = $this->pipeline->run(new HarvestRun($source, $fetcher, 'exec-1'));

		$this->assertSame(2, $summary['created']);
		$this->assertSame(0, $summary['updated']);
		$this->assertSame(0, $summary['errors']);
		$this->assertSame('success', $summary['status']);
	}//end testFullRunCreatesNewObjects()

	public function testUnchangedRecordIsSkipped(): void {
		$source = $this->makeSource();
		$payload = ['title' => 'Same'];
		$fetcher = $this->fakeFetcher(['a'], ['a' => $payload]);

		// Compute the same hash the pipeline will produce for the mapped data.
		$hash = $this->pipeline->contentHash($payload);

		$previous = new SyncRecord();
		$previous->setId(99);
		$previous->setExternalId('a');
		$previous->setObjectUuid('existing-uuid');
		$previous->setContentHash($hash);
		$this->syncRecordMapper->method('findByExternalId')->willReturn($previous);

		// saveObject must NOT be called for an unchanged record.
		$this->objectService->expects($this->never())->method('saveObject');

		$summary = $this->pipeline->run(new HarvestRun($source, $fetcher, 'exec-2'));

		$this->assertSame(1, $summary['unchanged']);
		$this->assertSame(0, $summary['created']);
		$this->assertSame(0, $summary['updated']);
		$this->assertSame('success', $summary['status']);
	}//end testUnchangedRecordIsSkipped()

	public function testManualStrategyDefersConflict(): void {
		$source = $this->makeSource();
		$source->setConflictStrategy(SyncConflictResolver::MANUAL);

		$payload = ['title' => 'Changed at source'];
		$fetcher = $this->fakeFetcher(['a'], ['a' => $payload]);

		// Previous record exists with a DIFFERENT hash (source changed) and an objectUuid.
		$previous = new SyncRecord();
		$previous->setId(99);
		$previous->setExternalId('a');
		$previous->setObjectUuid('existing-uuid');
		$previous->setContentHash('old-hash-different');
		$this->syncRecordMapper->method('findByExternalId')->willReturn($previous);

		// Local object also changed since last sync → local hash differs from previous->contentHash.
		$local = new ObjectEntity();
		$local->setUuid('existing-uuid');
		$local->setObject(['title' => 'Locally edited']);
		$this->objectService->method('find')->willReturn($local);

		// Manual + both-changed → defer (conflict), no save.
		$this->objectService->expects($this->never())->method('saveObject');

		$summary = $this->pipeline->run(new HarvestRun($source, $fetcher, 'exec-3'));

		$this->assertSame(1, $summary['conflicts']);
		$this->assertSame(0, $summary['updated']);
	}//end testManualStrategyDefersConflict()

	public function testImportErrorCountedAsPartial(): void {
		$source = $this->makeSource();
		$fetcher = $this->fakeFetcher(
			['a', 'b'],
			['a' => ['title' => 'A'], 'b' => ['title' => 'B']]
		);

		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);

		// First save succeeds, second throws (e.g. schema validation failure).
		$calls = 0;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use (&$calls): ObjectEntity {
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('validation failed');
				}
				$obj = new ObjectEntity();
				$obj->setUuid('ok-uuid');
				return $obj;
			}
		);

		$summary = $this->pipeline->run(new HarvestRun($source, $fetcher, 'exec-4'));

		$this->assertSame(1, $summary['created']);
		$this->assertSame(1, $summary['errors']);
		$this->assertSame('partial', $summary['status']);
	}//end testImportErrorCountedAsPartial()

	public function testDeriveStatus(): void {
		$this->assertSame('success', $this->pipeline->deriveStatus(['created' => 5, 'errors' => 0]));
		$this->assertSame('partial', $this->pipeline->deriveStatus(['created' => 5, 'errors' => 2]));
		$this->assertSame('failed', $this->pipeline->deriveStatus(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'errors' => 3]));
	}//end testDeriveStatus()

	// ── Whole-document fetchers (app-harvest-fetchers-and-flow-node) ──

	/**
	 * A pipeline that holds a harvest HTTP client, as the container builds it.
	 */
	private function batchPipeline(): HarvestPipelineService {
		return new HarvestPipelineService(
			$this->syncRecordMapper,
			new HarvestImportServices(
				mappingMapper: $this->mappingMapper,
				mappingService: $this->mappingService,
				objectService: $this->objectService,
				conflictResolver: new SyncConflictResolver()
			),
			$this->createMock(LoggerInterface::class),
			new HarvestHttpClient($this->createMock(Client::class))
		);
	}

	/**
	 * A batch fetcher that returns one fixed batch and fails if asked for a single item.
	 */
	private function batchFetcher(HarvestBatch $batch): IBatchSourceFetcher {
		return new class($batch) implements IBatchSourceFetcher {
			public int $gatherItemsCalls = 0;

			public function __construct(private HarvestBatch $batch) {
			}

			public function getType(): string {
				return 'test.batch';
			}

			public function getDisplayName(): string {
				return 'Test batch';
			}

			public function getConfigSchema(): array {
				return [];
			}

			public function supports(string $type): bool {
				return $type === 'test.batch';
			}

			public function gather(Source $source, ?string $since = null): array {
				throw new RuntimeException('a batch fetcher is never asked for an id list');
			}

			public function fetch(Source $source, string $externalId): array {
				throw new RuntimeException('a batch fetcher is never asked for one item: ' . $externalId);
			}

			public function gatherItems(Source $source, ?string $since, HarvestHttpClient $http): HarvestBatch {
				$this->gatherItemsCalls++;
				return $this->batch;
			}
		};
	}

	private function savesNewObjects(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$obj = new ObjectEntity();
				$obj->setUuid('new-' . ($object['id'] ?? 'x'));
				return $obj;
			}
		);
	}

	public function testABatchIsImportedWithoutAPerItemRequest(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: ['ds-1' => ['id' => 'ds-1'], 'ds-2' => ['id' => 'ds-2'], 'ds-3' => ['id' => 'ds-3']],
			complete: true,
			checkpoint: 'cursor-9'
		));
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		$this->syncRecordMapper->method('markTombstones')->willReturn(0);
		$written = [];
		$this->syncRecordMapper->expects($this->exactly(3))->method('createPending')->willReturnCallback(
			function (SyncExecution $execution, string $externalId) use (&$written): SyncRecord {
				$written[] = $externalId;
				$rec = new SyncRecord();
				$rec->setExternalId($externalId);
				$rec->setStatus(SyncRecordStatus::PENDING);
				return $rec;
			}
		);
		$this->savesNewObjects();
		$source = $this->makeSource();

		// fetch() throws: any per-item request would surface as an error.
		$summary = $this->batchPipeline()->run(new HarvestRun($source, $fetcher, 'exec-b1'));

		$this->assertSame(1, $fetcher->gatherItemsCalls);
		$this->assertSame(['ds-1', 'ds-2', 'ds-3'], $written);
		$this->assertSame(3, $summary['created']);
		$this->assertSame(0, $summary['errors']);
		$this->assertTrue($summary['complete']);
		$this->assertSame('success', $summary['status']);
		$this->assertSame('cursor-9', $source->getLastSyncToken());
	}

	public function testItemsPastTheCapAreLeftAndMakeTheBatchIncomplete(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: ['a' => ['id' => 'a'], 'b' => ['id' => 'b'], 'c' => ['id' => 'c']],
			complete: true
		));
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		// An incomplete run tombstones nothing.
		$this->syncRecordMapper->expects($this->never())->method('markTombstones');
		$this->savesNewObjects();
		$source = $this->makeSource();
		$source->setMaxItemsPerRun(2);

		$summary = $this->batchPipeline()->run(new HarvestRun($source, $fetcher, 'exec-b2'));

		$this->assertSame(2, $summary['created']);
		$this->assertFalse($summary['complete']);
		$this->assertSame('partial', $summary['status']);
	}

	public function testMaxItemsPerRunDefaultsAndIsBounded(): void {
		$source = $this->makeSource();
		$this->assertSame(1000, HarvestPipelineService::maxItemsPerRun($source));
		$source->setMaxItemsPerRun(50000);
		$this->assertSame(10000, HarvestPipelineService::maxItemsPerRun($source));
		$source->setMaxItemsPerRun(0);
		$this->assertSame(1, HarvestPipelineService::maxItemsPerRun($source));
	}

	public function testAnIdNamedInErrorsIsSeenAndTheRunStaysComplete(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: ['ds-1' => ['id' => 'ds-1'], 'ds-2' => ['id' => 'ds-2']],
			complete: true,
			errors: [['externalId' => 'ds-3', 'message' => 'SHACL: dct:title missing']]
		));
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		$seen = null;
		$this->syncRecordMapper->expects($this->once())->method('markTombstones')->willReturnCallback(
			function (int $sourceId, array $seenIds, \DateTime $at) use (&$seen): int {
				$seen = $seenIds;
				// ds-4 was neither returned nor named: the mapper tombstones it.
				return 1;
			}
		);
		$this->savesNewObjects();

		$summary = $this->batchPipeline()->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-b3'));

		$this->assertSame(['ds-1' => true, 'ds-2' => true, 'ds-3' => true], $seen);
		$this->assertTrue($summary['complete']);
		$this->assertSame(1, $summary['errors']);
		$this->assertSame([['externalId' => 'ds-3', 'message' => 'SHACL: dct:title missing']], $summary['errorList']);
		$this->assertSame(1, $summary['tombstoned']);
		$this->assertSame('partial', $summary['status']);
	}

	public function testAnErrorWithoutAnIdMarksNothingSeen(): void {
		$batch = new HarvestBatch(
			items: ['ds-1' => ['id' => 'ds-1']],
			complete: true,
			errors: [['externalId' => null, 'message' => 'page 2 timed out'], ['externalId' => 'ds-2', 'message' => 'bad']]
		);

		$this->assertSame(['ds-1' => true, 'ds-2' => true], $batch->seenIds());
		$this->assertSame(['ds-2' => 'bad'], $batch->errorsById());
	}

	public function testAnErroredIdKeepsItsLastGoodDataUnderFetchError(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: [],
			complete: true,
			errors: [['externalId' => 'ds-3', 'message' => 'SHACL: dct:title missing']]
		));
		$applied = new \DateTime('2026-10-01T10:00:00Z');
		$previous = new SyncRecord();
		$previous->setId(7);
		$previous->setExternalId('ds-3');
		$previous->setStatus(SyncRecordStatus::IMPORTED);
		$previous->setObjectUuid('obj-3');
		$previous->setContentHash('hash-3');
		$previous->setRawData(['id' => 'ds-3', 'title' => 'Good']);
		$previous->setLastAppliedAt($applied);
		$this->syncRecordMapper->method('findByExternalId')->willReturn($previous);
		$this->syncRecordMapper->method('markTombstones')->willReturn(0);
		$failed = null;
		$this->syncRecordMapper->expects($this->once())->method('recordFailure')->willReturnCallback(
			function (SyncRecord $rec, SyncFailure $failure) use (&$failed): SyncRecord {
				$rec->setStatus($failure->status);
				$rec->setErrorMessage($failure->message);
				$failed = $rec;
				return $rec;
			}
		);
		// The linked object is neither updated nor deleted.
		$this->objectService->expects($this->never())->method('saveObject');

		$this->batchPipeline()->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-b4'));

		$this->assertInstanceOf(SyncRecord::class, $failed);
		$this->assertSame(SyncRecordStatus::FETCH_ERROR, $failed->getStatus());
		$this->assertSame('SHACL: dct:title missing', $failed->getErrorMessage());
		$this->assertSame(['id' => 'ds-3', 'title' => 'Good'], $failed->getRawData());
		$this->assertSame('hash-3', $failed->getContentHash());
		$this->assertSame('obj-3', $failed->getObjectUuid());
		$this->assertEquals($applied, $failed->getLastAppliedAt());
	}

	public function testAnErroredIdWithoutARecordGetsOneWithNoObject(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: [],
			complete: true,
			errors: [['externalId' => 'ds-9', 'message' => 'refused']]
		));
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		$this->syncRecordMapper->method('markTombstones')->willReturn(0);
		$failed = null;
		$this->syncRecordMapper->method('recordFailure')->willReturnCallback(
			function (SyncRecord $rec, SyncFailure $failure) use (&$failed): SyncRecord {
				$rec->setStatus($failure->status);
				$rec->setErrorMessage($failure->message);
				$failed = $rec;
				return $rec;
			}
		);

		$this->batchPipeline()->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-b5'));

		$this->assertSame('ds-9', $failed->getExternalId());
		$this->assertSame(SyncRecordStatus::FETCH_ERROR, $failed->getStatus());
		$this->assertNull($failed->getObjectUuid());
	}

	public function testAnIncompleteBatchTombstonesNothing(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(
			items: ['ds-1' => ['id' => 'ds-1']],
			complete: false,
			errors: [['externalId' => null, 'message' => 'page 2 failed']]
		));
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		$this->syncRecordMapper->expects($this->never())->method('markTombstones');
		$this->savesNewObjects();

		$summary = $this->batchPipeline()->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-b6'));

		$this->assertFalse($summary['complete']);
		$this->assertSame(0, $summary['tombstoned']);
		$this->assertSame('partial', $summary['status']);
	}

	public function testABatchFetcherWithoutAnHttpClientIsRefused(): void {
		$fetcher = $this->batchFetcher(new HarvestBatch(items: [], complete: true));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs the harvest HTTP client');
		$this->pipeline->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-b7'));
	}

	public function testAWriteSetsLastAppliedAt(): void {
		$fetcher = $this->fakeFetcher(['a'], ['a' => ['title' => 'A']]);
		$this->syncRecordMapper->method('findByExternalId')->willReturn(null);
		$settled = [];
		$this->syncRecordMapper->method('transitionStatus')->willReturnCallback(
			function (SyncRecord $rec, string $status) use (&$settled): SyncRecord {
				$rec->setStatus($status);
				$settled[$status] = $rec;
				return $rec;
			}
		);
		$this->savesNewObjects();

		$this->pipeline->run(new HarvestRun($this->makeSource(), $fetcher, 'exec-la'));

		$this->assertInstanceOf(\DateTime::class, $settled[SyncRecordStatus::IMPORTED]->getLastAppliedAt());
	}
}//end class
