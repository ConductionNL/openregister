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

use OCA\OpenRegister\BackgroundJob\SyncDataJob;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SyncScheduleInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which due sources the hourly sync harvests and what status each ends with.
 */
class SyncDataJobTest extends TestCase {

	/**
	 * The source store.
	 *
	 * @var SourceMapperInterface&MockObject
	 */
	private SourceMapperInterface&MockObject $sources;

	/**
	 * The fetcher lookup.
	 *
	 * @var SourceFetcherLookupInterface&MockObject
	 */
	private SourceFetcherLookupInterface&MockObject $fetchers;

	/**
	 * The harvest pipeline.
	 *
	 * @var HarvestPipelineServiceInterface&MockObject
	 */
	private HarvestPipelineServiceInterface&MockObject $pipeline;

	/**
	 * The one due source.
	 *
	 * @var Source
	 */
	private Source $source;

	/**
	 * Build the doubles around one enabled, due source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->source = new Source();
		$this->source->setUuid('s-1');
		$this->source->setType('rest');
		$this->source->setLastSyncToken('tok-9');

		$this->sources = $this->createMock(SourceMapperInterface::class);
		$this->sources->method('findBySyncEnabled')->willReturn([$this->source]);
		$this->fetchers = $this->createMock(SourceFetcherLookupInterface::class);
		$this->pipeline = $this->createMock(HarvestPipelineServiceInterface::class);
	}//end setUp()

	/**
	 * Build and run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$schedule = $this->createMock(SyncScheduleInterface::class);
		$schedule->method('selectDueSources')->willReturnCallback(
			function (array $sources): array {
				return $sources;
			}
		);
		$job = new SyncDataJob(
			$this->createMock(ITimeFactory::class),
			$this->sources,
			$schedule,
			$this->fetchers,
			$this->pipeline,
			$this->createMock(LoggerInterface::class)
		);
		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * A source type without a fetcher is skipped untouched.
	 *
	 * @return void
	 */
	public function testSourceWithoutFetcherIsSkipped(): void {
		$this->fetchers->method('get')->with('rest')->willReturn(null);
		$this->sources->expects($this->never())->method('update');
		$this->pipeline->expects($this->never())->method('run');
		$this->runJob();
		$this->assertNull($this->source->getLastSyncStatus());
	}//end testSourceWithoutFetcherIsSkipped()

	/**
	 * A due source is harvested since its token and takes the pipeline status.
	 *
	 * @return void
	 */
	public function testDueSourceIsHarvestedSinceItsToken(): void {
		$fetcher = $this->createMock(SourceFetcherInterface::class);
		$this->fetchers->method('get')->willReturn($fetcher);
		$this->sources->expects($this->exactly(2))->method('update');
		$this->pipeline->expects($this->once())->method('run')
			->with($this->source, $fetcher, $this->isType('string'), 'tok-9')
			->willReturn(['status' => 'partial']);
		$this->runJob();
		$this->assertSame('partial', $this->source->getLastSyncStatus());
	}//end testDueSourceIsHarvestedSinceItsToken()

	/**
	 * A pipeline failure marks the source failed.
	 *
	 * @return void
	 */
	public function testPipelineFailureMarksTheSourceFailed(): void {
		$this->fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->pipeline->method('run')->willThrowException(new \RuntimeException('down'));
		$this->runJob();
		$this->assertSame('failed', $this->source->getLastSyncStatus());
	}//end testPipelineFailureMarksTheSourceFailed()
}//end class
