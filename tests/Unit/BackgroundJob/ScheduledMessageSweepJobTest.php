<?php

/**
 * Unit tests for ScheduledMessageSweepJob: one pass every five minutes, its counts logged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\ScheduledMessageSweepJob;
use OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\ScheduledMessageSweepJob
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt
 */
class ScheduledMessageSweepJobTest extends TestCase {

	public function testAPassRunsAtTheClocksTimeAndLogsItsCounts(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-16T08:00:00+00:00'));
		$seen  = null;
		$sweep = $this->createMock(ScheduledMessageSweep::class);
		$sweep->expects($this->once())->method('run')->willReturnCallback(static function (ClaimAttempt $attempt) use (&$seen): array {
			$seen = $attempt;
			return ['sent' => 2, 'failed' => 1, 'skipped' => 0];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('[ScheduledMessageSweepJob] scheduled messages swept', ['sent' => 2, 'failed' => 1, 'skipped' => 0]);

		$job = new ScheduledMessageSweepJob($time, $sweep, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame('2026-11-16T08:00:00+00:00', $seen->at->format('c'));
		$this->assertStringStartsWith('sweep-', $seen->worker);
	}

	public function testItRunsEveryFiveMinutesAndIsRegistered(): void {
		$job = new ScheduledMessageSweepJob($this->createMock(ITimeFactory::class), $this->createMock(ScheduledMessageSweep::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(300, $job->getInterval());
		$this->assertStringContainsString(
			'<job>OCA\OpenRegister\BackgroundJob\ScheduledMessageSweepJob</job>',
			(string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml')
		);
	}
}
