<?php

/**
 * Unit tests for the hourly sweep of one-use job keys.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\BackgroundJob\OneUseJobKeySweepJob;
use OCA\OpenRegister\Service\Exchange\OneUseJobKey;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\OneUseJobKeySweepJob
 */
class OneUseJobKeySweepJobTest extends TestCase {

	public function testASweepRunsAtTheClocksTimeAndLogsTheCount(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));
		$keys = $this->createMock(OneUseJobKey::class);
		$keys->expects($this->once())->method('sweep')
			->with($this->callback(static fn (DateTimeInterface $now): bool => $now->format('c') === '2026-11-13T10:00:00+00:00'))
			->willReturn(3);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('[OneUseJobKeySweepJob] stale job keys deleted', ['count' => 3]);

		$job = new OneUseJobKeySweepJob($time, $keys, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testAnEmptySweepLogsNothing(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));
		$keys = $this->createMock(OneUseJobKey::class);
		$keys->method('sweep')->willReturn(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$job = new OneUseJobKeySweepJob($time, $keys, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testItRunsHourlyAndIsRegistered(): void {
		$job = new OneUseJobKeySweepJob($this->createMock(ITimeFactory::class), $this->createMock(OneUseJobKey::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(3600, $job->getInterval());
		$this->assertStringContainsString(
			'<job>OCA\OpenRegister\BackgroundJob\OneUseJobKeySweepJob</job>',
			(string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml')
		);
	}
}
