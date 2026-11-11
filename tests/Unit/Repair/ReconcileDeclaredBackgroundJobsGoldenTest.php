<?php

/**
 * Characterisation (golden) test for ReconcileDeclaredBackgroundJobs::run().
 *
 * Reads the real appinfo/info.xml, so expectations are built from the classes
 * the step asks about, in the order it asks: nothing missing, and a run where
 * the first declared job is added, the second is not an IJob and the third
 * cannot be built. Written against the code before run() was split; it must
 * keep passing unchanged afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\ReconcileDeclaredBackgroundJobs;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the declared background-job reconciliation.
 */
class ReconcileDeclaredBackgroundJobsGoldenTest extends TestCase {

	/**
	 * Ordered call log.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Classes the step asked the job list about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Run the step; the first $missing declared classes are not registered.
	 *
	 * @param int $missing How many leading declared classes are missing.
	 *
	 * @return array<int, string> The call log.
	 */
	private function runStep(int $missing): array {
		$this->calls = [];
		$this->asked = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturnCallback(
			function (string $class, mixed $argument) use ($missing): bool {
				$this->asked[] = $class;
				$this->calls[] = 'has '.(count($this->asked) - 1).' '.var_export($argument, true);
				return (count($this->asked) > $missing);
			}
		);
		$jobList->method('add')->willReturnCallback(
			function (string $class): void {
				$this->calls[] = 'add '.array_search($class, $this->asked, true);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): mixed {
				$index = array_search($class, $this->asked, true);
				$this->calls[] = 'get '.$index;
				if ($index === 1) {
					return new \stdClass();
				}

				if ($index === 2) {
					throw new RuntimeException('cannot build');
				}

				return $this->createMock(IJob::class);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = 'log '.str_replace($this->asked, array_keys($this->asked), $message).' '.get_class($context['exception']);
			}
		);

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = $level.' '.str_replace($this->asked, array_keys($this->asked), $message);
				}
			);
		}

		(new ReconcileDeclaredBackgroundJobs($jobList, $container, $logger))->run($output);

		return $this->calls;
	}//end runStep()

	/**
	 * Every declared job is already registered.
	 *
	 * @return void
	 */
	public function testNothingMissing(): void {
		$calls = $this->runStep(0);
		$count = count($this->asked);

		self::assertGreaterThan(3, $count);
		$expected = [];
		for ($i = 0; $i < $count; $i++) {
			$expected[] = 'has '.$i.' NULL';
		}

		$expected[] = 'info [OpenRegister] All '.$count.' declared background jobs are already registered.';
		self::assertSame($expected, $calls);
	}//end testNothingMissing()

	/**
	 * Added, not an IJob, and unbuildable, then the rest present.
	 *
	 * @return void
	 */
	public function testMixedRun(): void {
		$calls = $this->runStep(3);
		$count = count($this->asked);

		$expected = [
			'has 0 NULL',
			'get 0',
			'add 0',
			'info [OpenRegister] Registered missing background job: 0',
			'has 1 NULL',
			'get 1',
			'warning [OpenRegister] 1 is declared as a job but is not an IJob — skipped.',
			'has 2 NULL',
			'get 2',
			'warning [OpenRegister] Could not register 2: cannot build',
			'log [OpenRegister] Background-job reconciliation skipped 2 '.RuntimeException::class,
		];
		for ($i = 3; $i < $count; $i++) {
			$expected[] = 'has '.$i.' NULL';
		}

		$expected[] = 'info [OpenRegister] Background jobs reconciled: 1 added, 2 skipped, '.$count.' declared.';
		self::assertSame($expected, $calls);
	}//end testMixedRun()
}//end class
