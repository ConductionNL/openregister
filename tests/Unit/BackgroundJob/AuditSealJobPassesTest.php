<?php

declare(strict_types=1);

/**
 * Characterisation of the seal passes of one AuditSealJob run: how many
 * passes run, where they stop, and what the run then logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\AuditSealJob;
use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Pins the seal-pass loop and the log trail of an AuditSealJob run.
 */
class AuditSealJobPassesTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	public function testPassesStopAtTheFirstThatSealsNothing(): void {
		$this->runTick(passes: [5, 3, 0, 7], remaining: 2);

		$this->assertSame(
			[
				'seal', 'seal', 'seal', 'count',
				'info [AuditSealJob] Sealed 8 audit row(s); 2 still awaiting a seal.',
			],
			$this->trail
		);
	}//end testPassesStopAtTheFirstThatSealsNothing()

	public function testPassesAreCappedPerRun(): void {
		$this->runTick(passes: array_fill(0, 12, 1), remaining: 0);

		$this->assertSame(
			array_merge(
				array_fill(0, 10, 'seal'),
				['count', 'info [AuditSealJob] Sealed 10 audit row(s); 0 still awaiting a seal.']
			),
			$this->trail
		);
	}//end testPassesAreCappedPerRun()

	public function testABacklogWithNothingSealedWarns(): void {
		$this->runTick(passes: [0], remaining: 4);

		$this->assertSame(
			[
				'seal', 'count',
				'warning [AuditSealJob] 4 audit row(s) remain unsealed and this pass sealed none. The hash chain has gaps that are not closing.',
			],
			$this->trail
		);
	}//end testABacklogWithNothingSealedWarns()

	public function testNothingToSealLogsNothing(): void {
		$this->runTick(passes: [0], remaining: 0);

		$this->assertSame(['seal', 'count'], $this->trail);
	}//end testNothingToSealLogsNothing()

	public function testAFailingCountIsAnError(): void {
		$this->runTick(passes: [2], remaining: -1);

		$this->assertSame(
			['seal', 'seal', 'count', 'error [AuditSealJob] Seal sweep failed: count failed'],
			$this->trail
		);
	}//end testAFailingCountIsAnError()

	/**
	 * Run one tick over a recording service and logger.
	 *
	 * @param array<int, int> $passes    What each sealUnsealed() call returns, in order.
	 * @param int             $remaining What countUnsealed() reports; -1 throws.
	 *
	 * @return void
	 */
	private function runTick(array $passes, int $remaining): void {
		$this->trail = [];
		$hashes      = $this->createMock(AuditHashService::class);
		$hashes->method('sealUnsealed')->willReturnCallback(
			function () use (&$passes): int {
				$this->trail[] = 'seal';
				return (array_shift($passes) ?? 0);
			}
		);
		$hashes->method('countUnsealed')->willReturnCallback(
			function () use ($remaining): int {
				$this->trail[] = 'count';
				if ($remaining < 0) {
					throw new RuntimeException('count failed');
				}

				return $remaining;
			}
		);

		$trail  = &$this->trail;
		$logger = new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trail[] = $level . ' ' . $message;
			}
		};

		$job = new AuditSealJob(new JobRuntime($this->createMock(ITimeFactory::class), $logger), $hashes);
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runTick()
}//end class
