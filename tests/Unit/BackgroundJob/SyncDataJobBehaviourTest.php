<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\SyncDataServices;
use OCA\OpenRegister\BackgroundJob\SyncDataJob;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SyncScheduleInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The `since` fallbacks and the job-level failure of SyncDataJob.
 */
class SyncDataJobBehaviourTest extends TestCase {

	private function runJob(SourceMapperInterface $sources, HarvestPipelineServiceInterface $pipeline, LoggerInterface $logger): void {
		$schedule = $this->createMock(SyncScheduleInterface::class);
		$schedule->method('selectDueSources')->willReturnCallback(static fn (array $sources): array => $sources);
		$fetchers = $this->createMock(SourceFetcherLookupInterface::class);
		$fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$job = new SyncDataJob(
			$this->createMock(ITimeFactory::class),
			new SyncDataServices($sources, $schedule, $fetchers, $pipeline),
			$logger
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private function source(): Source {
		$source = new Source();
		$source->setUuid('s-1');
		$source->setType('rest');
		return $source;
	}

	public function testWithoutTokenTheLastSyncDateIsTheSince(): void {
		$source = $this->source();
		$source->setLastSyncDate(new DateTime('2026-03-04T05:06:07+00:00'));
		$sources = $this->createMock(SourceMapperInterface::class);
		$sources->method('findBySyncEnabled')->willReturn([$source]);
		$pipeline = $this->createMock(HarvestPipelineServiceInterface::class);
		$pipeline->expects($this->once())->method('run')
			->with($this->callback(fn (HarvestRun $h): bool => $h->source === $source && is_string($h->executionId) && $h->since === '2026-03-04T05:06:07+00:00'))
			->willReturn([]);

		$this->runJob($sources, $pipeline, $this->createMock(LoggerInterface::class));

		$this->assertSame('success', $source->getLastSyncStatus());
	}

	public function testANeverSyncedSourceIsHarvestedFromTheStart(): void {
		$source = $this->source();
		$sources = $this->createMock(SourceMapperInterface::class);
		$sources->method('findBySyncEnabled')->willReturn([$source]);
		$pipeline = $this->createMock(HarvestPipelineServiceInterface::class);
		$pipeline->expects($this->once())->method('run')
			->with($this->callback(fn (HarvestRun $h): bool => $h->source === $source && is_string($h->executionId) && $h->since === null))
			->willReturn(['status' => 'success']);

		$this->runJob($sources, $pipeline, $this->createMock(LoggerInterface::class));
	}

	public function testSourceLookupFailureIsLoggedAndHarvestsNothing(): void {
		$sources = $this->createMock(SourceMapperInterface::class);
		$sources->method('findBySyncEnabled')->willThrowException(new \RuntimeException('no table'));
		$sources->expects($this->never())->method('update');
		$pipeline = $this->createMock(HarvestPipelineServiceInterface::class);
		$pipeline->expects($this->never())->method('run');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[SyncDataJob] Data sync job failed: no table');

		$this->runJob($sources, $pipeline, $logger);
	}
}
