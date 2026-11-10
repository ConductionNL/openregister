<?php

/**
 * The hourly purge deletes expired upload tokens and idempotency answers, and says how many.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\FormUploadPurgeJob;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Hourly, counted, and a failure surfaces instead of reading as success.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\FormUploadPurgeJob
 */
class FormUploadPurgeJobTest extends TestCase {

	/**
	 * Run the protected run().
	 */
	private function runJob(FormUploadPurgeJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * Hourly, and both counts are logged, a zero included.
	 */
	public function testItRunsHourlyAndLogsBothCounts(): void {
		$uploads = $this->createMock(FormUploadStore::class);
		$uploads->expects($this->once())->method('purge')->willReturn(3);
		$keys = $this->createMock(FormIdempotencyStore::class);
		$keys->expects($this->once())->method('purge')->willReturn(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains('purged'),
			$this->equalTo(['uploadTokens' => 3, 'idempotencyKeys' => 0])
		);

		$job = new FormUploadPurgeJob(time: $this->createMock(ITimeFactory::class), uploads: $uploads, keys: $keys, logger: $logger);
		$this->assertSame(3600, $job->getInterval());
		$this->runJob(job: $job);
	}//end testItRunsHourlyAndLogsBothCounts()

	/**
	 * A failing purge is logged as an error and rethrown, so the job does not report success.
	 */
	public function testAFailingPurgeSurfaces(): void {
		$uploads = $this->createMock(FormUploadStore::class);
		$uploads->method('purge')->willThrowException(new RuntimeException('storage gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new FormUploadPurgeJob(time: $this->createMock(ITimeFactory::class), uploads: $uploads, keys: $this->createMock(FormIdempotencyStore::class), logger: $logger);

		$this->expectException(RuntimeException::class);
		$this->runJob(job: $job);
	}//end testAFailingPurgeSurfaces()
}//end class
