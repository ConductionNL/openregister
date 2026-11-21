<?php

/**
 * The daily job that refreshes the example sets and logs each set's outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ExampleSetDailyRefreshJob;
use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetBackgroundRun;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefreshInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ExampleSetDailyRefreshJob::class)]
#[UsesClass(JobRuntime::class)]
#[UsesClass(ExampleSetBackgroundRun::class)]
final class ExampleSetDailyRefreshJobTest extends TestCase {
	private ExampleSetDailyRefreshInterface&MockObject $daily;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->daily = $this->createMock(ExampleSetDailyRefreshInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testEachSetIsLoggedAtTheLevelOfItsOutcome(): void {
		$this->daily->expects(self::once())->method('run')->willReturn([
			'gemeente' => ['ok' => true, 'created' => 3],
			'provincie' => ['ok' => false, 'error' => 'schema missing'],
		]);
		$this->logger->expects(self::once())->method('info')
			->with('[ExampleSetDailyRefreshJob] Example set refreshed for today', ['set' => 'gemeente', 'ok' => true, 'created' => 3]);
		$this->logger->expects(self::once())->method('warning')
			->with('[ExampleSetDailyRefreshJob] Example set refreshed for today', ['set' => 'provincie', 'ok' => false, 'error' => 'schema missing']);
		$this->logger->expects(self::never())->method('error');

		$this->runJob();
	}

	public function testTheRefreshRunsInsideABackgroundRun(): void {
		$inside = null;
		$this->daily->method('run')->willReturnCallback(static function () use (&$inside): array {
			$inside = ExampleSetBackgroundRun::isOpen();

			return [];
		});

		$this->runJob();

		self::assertTrue($inside);
		self::assertFalse(ExampleSetBackgroundRun::isOpen());
	}

	public function testARefreshThatFailsIsLoggedOnceAndNothingElse(): void {
		$this->daily->method('run')->willThrowException(new RuntimeException('register unavailable'));
		$this->logger->expects(self::once())->method('error')
			->with('[ExampleSetDailyRefreshJob] The daily refresh failed', ['exception' => 'register unavailable']);
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::never())->method('warning');

		$this->runJob();
	}

	public function testTheJobRunsDaily(): void {
		self::assertSame(86400, $this->job()->getInterval());
	}

	private function job(): ExampleSetDailyRefreshJob {
		return new ExampleSetDailyRefreshJob(new JobRuntime($this->createMock(ITimeFactory::class), $this->logger), $this->daily);
	}

	private function runJob(): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
