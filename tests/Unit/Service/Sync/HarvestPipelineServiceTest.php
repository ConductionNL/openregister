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
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 */
class HarvestPipelineServiceTest extends TestCase
{

    private SyncRecordMapper&MockObject $syncRecordMapper;

    private MappingMapper&MockObject $mappingMapper;

    private MappingService&MockObject $mappingService;

    private ObjectService&MockObject $objectService;

    private HarvestPipelineService $pipeline;

    protected function setUp(): void
    {
        $this->syncRecordMapper = $this->createMock(SyncRecordMapper::class);
        $this->mappingMapper    = $this->createMock(MappingMapper::class);
        $this->mappingService   = $this->createMock(MappingService::class);
        $this->objectService    = $this->createMock(ObjectService::class);

        // createPending returns a real entity carrying the external id.
        $this->syncRecordMapper->method('createPending')->willReturnCallback(
            function (int $sourceId, string $exec, string $externalId): SyncRecord {
                $rec = new SyncRecord();
                $rec->setSourceId($sourceId);
                $rec->setExecutionId($exec);
                $rec->setExternalId($externalId);
                $rec->setStatus(SyncRecordStatus::PENDING);
                $rec->setAttempts(0);
                return $rec;
            }
        );

        // transitionStatus applies the status to the entity and returns it.
        $this->syncRecordMapper->method('transitionStatus')->willReturnCallback(
            function (SyncRecord $rec, string $status, ?string $err=null): SyncRecord {
                $rec->setStatus($status);
                if ($err !== null) {
                    $rec->setErrorMessage($err);
                }
                return $rec;
            }
        );

        $this->pipeline = new HarvestPipelineService(
            $this->syncRecordMapper,
            $this->mappingMapper,
            $this->mappingService,
            $this->objectService,
            new SyncConflictResolver(),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    private function makeSource(): Source
    {
        $source = new Source();
        $source->setId(1);
        $source->setUuid('source-uuid');
        $source->setType('rest-api');
        $source->setTargetRegister('reg');
        $source->setTargetSchema('schema');
        $source->setConflictStrategy(SyncConflictResolver::SOURCE_WINS);
        return $source;
    }//end makeSource()

    private function fakeFetcher(array $ids, array $payloads, array $failOn=[]): SourceFetcherInterface
    {
        return new class($ids, $payloads, $failOn) implements SourceFetcherInterface {
            public function __construct(
                private array $ids,
                private array $payloads,
                private array $failOn
            ) {
            }

            public function supports(string $type): bool
            {
                return true;
            }

            public function gather(Source $source, ?string $since=null): array
            {
                return $this->ids;
            }

            public function fetch(Source $source, string $externalId): array
            {
                if (in_array($externalId, $this->failOn, true) === true) {
                    throw new RuntimeException('boom '.$externalId);
                }
                return ($this->payloads[$externalId] ?? []);
            }
        };
    }//end fakeFetcher()

    public function testGatherCreatesPendingRecords(): void
    {
        $source  = $this->makeSource();
        $fetcher = $this->fakeFetcher(['a', 'b', 'c'], []);

        $records = $this->pipeline->gather($source, $fetcher, 'exec-1');

        $this->assertCount(3, $records);
        $this->assertSame('a', $records[0]->getExternalId());
        $this->assertSame(SyncRecordStatus::PENDING, $records[0]->getStatus());
    }//end testGatherCreatesPendingRecords()

    public function testFetchAllMarksFetchedAndIsolatesFailures(): void
    {
        $source  = $this->makeSource();
        $fetcher = $this->fakeFetcher(['a', 'b'], ['a' => ['x' => 1], 'b' => ['x' => 2]], failOn: ['b']);

        $records = $this->pipeline->gather($source, $fetcher, 'exec-1');
        $fetched = $this->pipeline->fetchAll($source, $fetcher, $records);

        // Only 'a' fetched; 'b' failed but did not abort.
        $this->assertCount(1, $fetched);
        $this->assertSame(SyncRecordStatus::FETCHED, $fetched[0]->getStatus());
        $this->assertSame(SyncRecordStatus::FETCH_ERROR, $records[1]->getStatus());
        $this->assertSame('boom b', $records[1]->getErrorMessage());
    }//end testFetchAllMarksFetchedAndIsolatesFailures()

    public function testFullRunCreatesNewObjects(): void
    {
        $source  = $this->makeSource();
        $fetcher = $this->fakeFetcher(
            ['a', 'b'],
            ['a' => ['title' => 'A'], 'b' => ['title' => 'B']]
        );

        // No previous tracking → everything is new.
        $this->syncRecordMapper->method('findByExternalId')->willReturn(null);

        $this->objectService->method('saveObject')->willReturnCallback(
            function (): ObjectEntity {
                $obj = new ObjectEntity();
                $obj->setUuid('new-uuid');
                return $obj;
            }
        );

        $summary = $this->pipeline->run($source, $fetcher, 'exec-1');

        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(0, $summary['errors']);
        $this->assertSame('success', $summary['status']);
    }//end testFullRunCreatesNewObjects()

    public function testUnchangedRecordIsSkipped(): void
    {
        $source  = $this->makeSource();
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

        $summary = $this->pipeline->run($source, $fetcher, 'exec-2');

        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame('success', $summary['status']);
    }//end testUnchangedRecordIsSkipped()

    public function testManualStrategyDefersConflict(): void
    {
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

        $summary = $this->pipeline->run($source, $fetcher, 'exec-3');

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame(0, $summary['updated']);
    }//end testManualStrategyDefersConflict()

    public function testImportErrorCountedAsPartial(): void
    {
        $source  = $this->makeSource();
        $fetcher = $this->fakeFetcher(
            ['a', 'b'],
            ['a' => ['title' => 'A'], 'b' => ['title' => 'B']]
        );

        $this->syncRecordMapper->method('findByExternalId')->willReturn(null);

        // First save succeeds, second throws (e.g. schema validation failure).
        $calls = 0;
        $this->objectService->method('saveObject')->willReturnCallback(
            function () use (&$calls): ObjectEntity {
                $calls++;
                if ($calls === 2) {
                    throw new RuntimeException('validation failed');
                }
                $obj = new ObjectEntity();
                $obj->setUuid('ok-uuid');
                return $obj;
            }
        );

        $summary = $this->pipeline->run($source, $fetcher, 'exec-4');

        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['errors']);
        $this->assertSame('partial', $summary['status']);
    }//end testImportErrorCountedAsPartial()

    public function testDeriveStatus(): void
    {
        $this->assertSame('success', $this->pipeline->deriveStatus(['created' => 5, 'errors' => 0]));
        $this->assertSame('partial', $this->pipeline->deriveStatus(['created' => 5, 'errors' => 2]));
        $this->assertSame('failed', $this->pipeline->deriveStatus(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'errors' => 3]));
    }//end testDeriveStatus()
}//end class
