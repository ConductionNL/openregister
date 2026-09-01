<?php

/**
 * The worker rides the existing 300s cadence, delegates one bounded pass,
 * logs work performed (not rows read) and never lets a failure escape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\FlowTimerWorker;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\FlowTimerWorker
 */
class FlowTimerWorkerTest extends TestCase {

	private FlowTimerSweep&MockObject $sweep;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	private FlowTimerWorker $worker;

	protected function setUp(): void {
		parent::setUp();
		$this->sweep = $this->createMock(FlowTimerSweep::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-01 10:00:00'));
		$this->worker = new FlowTimerWorker(time: $time, sweep: $this->sweep, appConfig: $this->appConfig, logger: $this->logger);
	}//end setUp()

	private function tick(): void {
		$method = new ReflectionMethod(FlowTimerWorker::class, 'run');
		$method->invoke($this->worker, null);
	}//end tick()

	public function testIntervalMatchesTheScheduleWorker(): void {
		self::assertSame(300, FlowTimerWorker::INTERVAL_SECONDS);
		self::assertSame(300, $this->worker->getInterval());
	}//end testIntervalMatchesTheScheduleWorker()

	public function testRunsOnePassWithTheConfiguredBatchAndLogsWorkPerformed(): void {
		$this->appConfig->method('getValueString')->with('openregister', FlowTimerWorker::CONFIG_BATCH, '200')->willReturn('50');
		$this->sweep->expects(self::once())->method('run')
			->with(self::callback(static fn (DateTimeImmutable $now): bool => $now->format('Y-m-d H:i') === '2026-09-01 10:00'), 50)
			->willReturn(['expiriesFired' => 3, 'rungsFired' => 2, 'truncated' => true, 'errors' => 0]);
		$this->logger->expects(self::once())->method('info')
			->with(self::stringContains('Fired 3 expiry timer(s) and 2 escalation rung(s); truncated: true'), self::anything());
		$this->tick();
	}//end testRunsOnePassWithTheConfiguredBatchAndLogsWorkPerformed()

	public function testAQuietPassLogsNothingAndABadBatchIsFlooredAtOne(): void {
		$this->appConfig->method('getValueString')->willReturn('-5');
		$this->sweep->expects(self::once())->method('run')->with(self::anything(), 1)
			->willReturn(['expiriesFired' => 0, 'rungsFired' => 0, 'truncated' => false, 'errors' => 0]);
		$this->logger->expects(self::never())->method('info');
		$this->tick();
	}//end testAQuietPassLogsNothingAndABadBatchIsFlooredAtOne()

	public function testAFailingPassIsLoggedNotThrown(): void {
		$this->appConfig->method('getValueString')->willReturn('200');
		$this->sweep->method('run')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('db gone'), self::anything());
		$this->tick();
	}//end testAFailingPassIsLoggedNotThrown()
}//end class
