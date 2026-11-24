<?php

/**
 * BackfillEmailLinkMessageIdJob: the one-shot run and its kill switch.
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
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BackfillEmailLinkMessageIdJob;
use OCA\OpenRegister\Notifications\Service\EmailLinkMessageIdBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\BackfillEmailLinkMessageIdJob
 */
class BackfillEmailLinkMessageIdJobTest extends TestCase {

	/** @var string[] The log lines, level first. */
	private array $log = [];

	private function runJob(string $flag, EmailLinkMessageIdBackfill&MockObject $backfill): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', BackfillEmailLinkMessageIdJob::CONFIG_FLAG, 'yes')->willReturn($flag);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = $level . ' ' . $message;
				}
			);
		}

		$job = new BackfillEmailLinkMessageIdJob($this->createMock(ITimeFactory::class), $backfill, $config, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testTheBackfillRunsAndItsTallyIsLogged(): void {
		$backfill = $this->createMock(EmailLinkMessageIdBackfill::class);
		$backfill->expects($this->once())->method('run')->willReturn(['read' => 5, 'recorded' => 3]);

		$this->runJob(flag: 'yes', backfill: $backfill);

		$this->assertSame(['info BackfillEmailLinkMessageIdJob finished. Read=5, recorded=3'], $this->log);
	}

	public function testTheKillSwitchSkipsTheRun(): void {
		$backfill = $this->createMock(EmailLinkMessageIdBackfill::class);
		$backfill->expects($this->never())->method('run');

		$this->runJob(flag: 'no', backfill: $backfill);

		$this->assertSame(['info BackfillEmailLinkMessageIdJob skipped (flag backfill_email_link_message_id = no)'], $this->log);
	}

	public function testAFailureIsLoggedAndEndsTheJob(): void {
		$backfill = $this->createMock(EmailLinkMessageIdBackfill::class);
		$backfill->method('run')->willThrowException(new RuntimeException('database gone'));

		$this->runJob(flag: 'yes', backfill: $backfill);

		$this->assertSame(['warning BackfillEmailLinkMessageIdJob failed: database gone'], $this->log);
	}
}
