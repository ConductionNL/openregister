<?php

/**
 * Characterisation test pinning what a failed harvest fetch leaves behind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\Sync;

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failed fetch counts as an attempt, is recorded as fetch_error with the cause, and logs one warning.
 */
class HarvestPipelineFetchFailureGoldenTest extends TestCase {

	/**
	 * Both records are attempted once; the failure is recorded and logged, the success is returned.
	 *
	 * @return void
	 */
	public function testAFailedFetchIsCountedRecordedAndLogged(): void {
		$events = [];
		$mapper = $this->createMock(SyncRecordMapper::class);
		$mapper->method('transitionStatus')->willReturnCallback(
			function (SyncRecord $record, string $newStatus) use (&$events): SyncRecord {
				$events[] = ['transition', $record->getExternalId(), $newStatus, $record->getAttempts()];
				return $record;
			}
		);
		$mapper->method('recordFailure')->willReturnCallback(
			function (SyncRecord $record, SyncFailure $failure) use (&$events): SyncRecord {
				$events[] = ['failure', $record->getExternalId(), $failure->status, $failure->message, $record->getAttempts()];
				return $record;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$events): void {
				$events[] = ['warning', $message];
			}
		);

		$pipeline = new HarvestPipelineService(
			$mapper,
			new HarvestImportServices(
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class),
				objectService: $this->createMock(ObjectService::class),
				conflictResolver: new SyncConflictResolver()
			),
			$logger
		);

		$fetcher = new class() implements SourceFetcherInterface {
			public function supports(string $type): bool {
				return true;
			}

			public function gather(Source $source, ?string $since = null): array {
				return [];
			}

			public function fetch(Source $source, string $externalId): array {
				if ($externalId === 'bad') {
					throw new RuntimeException('timeout');
				}

				return ['id' => $externalId];
			}
		};

		$records = [];
		foreach (['bad', 'good'] as $id) {
			$record = new SyncRecord();
			$record->setExternalId($id);
			$record->setAttempts(2);
			$records[] = $record;
		}

		$fetched = $pipeline->fetchAll(new HarvestRun(new Source(), $fetcher, 'exec'), $records);

		$this->assertSame([$records[1]], $fetched);
		$this->assertSame(
			[
				['failure', 'bad', SyncRecordStatus::FETCH_ERROR, 'timeout', 3],
				['warning', '[HarvestPipeline] Fetch failed for bad: timeout'],
				['transition', 'good', SyncRecordStatus::FETCHED, 3],
			],
			$events
		);
		$this->assertNull($records[0]->getRawData());
	}//end testAFailedFetchIsCountedRecordedAndLogged()
}//end class
