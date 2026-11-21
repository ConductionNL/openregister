<?php

/**
 * ExpiredObjectSweepJob: daily, switchable, sweeping at the job's clock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\ExpiredObjectSweepJob;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStoreInterface;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\ExpiredObjectSweepJob
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper
 */
final class ExpiredObjectSweepJobTest extends TestCase {

	/**
	 * The job over a real sweeper whose store is $store, with the sweep switched on or off.
	 *
	 * @param bool                        $enabled The switch.
	 * @param ExpiredObjectStoreInterface $store   The store mock.
	 *
	 * @return ExpiredObjectSweepJob
	 */
	private function job(bool $enabled, ExpiredObjectStoreInterface $store): ExpiredObjectSweepJob {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->with('openregister', 'expiry_sweep_enabled', true)->willReturn($enabled);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794528000);

		return new ExpiredObjectSweepJob(
			time: $time,
			sweeper: new ExpiredObjectSweeper(
				store: $store,
				retention: $this->createMock(RetentionServiceInterface::class),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end job()

	/**
	 * Invoke the protected run().
	 *
	 * @param ExpiredObjectSweepJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(ExpiredObjectSweepJob $job): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * It runs daily, one at a time.
	 *
	 * @return void
	 */
	public function testItRunsDaily(): void {
		$job = $this->job(true, $this->createMock(ExpiredObjectStoreInterface::class));
		$this->assertSame(86400, (new \ReflectionProperty($job, 'interval'))->getValue($job));
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testItRunsDaily()

	/**
	 * Switched on, it sweeps.
	 *
	 * @return void
	 */
	public function testSwitchedOnItSweeps(): void {
		$store = $this->createMock(ExpiredObjectStoreInterface::class);
		$store->expects($this->once())->method('optedInTables')->willReturn([]);
		self::runJob($this->job(true, $store));
	}//end testSwitchedOnItSweeps()

	/**
	 * Switched off, it touches nothing.
	 *
	 * @return void
	 */
	public function testSwitchedOffItDoesNothing(): void {
		$store = $this->createMock(ExpiredObjectStoreInterface::class);
		$store->expects($this->never())->method('optedInTables');
		self::runJob($this->job(false, $store));
	}//end testSwitchedOffItDoesNothing()
}//end class
