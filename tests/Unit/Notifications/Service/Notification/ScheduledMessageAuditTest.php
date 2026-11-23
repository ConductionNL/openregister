<?php

/**
 * The send and fail entries the sweep writes on a scheduled message's bound object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageAudit;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageAudit
 */
class ScheduledMessageAuditTest extends TestCase {

	private const MESSAGE = ['id' => '7', 'channel' => 'email', 'objectUuid' => 'case-1', 'attempts' => 2, 'messageId' => '<a@b>'];

	private ObjectActivityAuditInterface&MockObject $activity;

	private LoggerInterface&MockObject $logger;

	/** @var array<int, array{string, string, array<string, mixed>}> */
	private array $entries = [];

	protected function setUp(): void {
		$this->activity = $this->createMock(ObjectActivityAuditInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->activity->method('record')->willReturnCallback(function (string $objectUuid, string $action, array $context = []): void {
			$this->entries[] = [$objectUuid, $action, $context];
		});
	}

	private function audit(): ScheduledMessageAudit {
		return new ScheduledMessageAudit($this->activity, $this->logger);
	}

	public function testASentMessageIsRecordedOnItsObject(): void {
		$this->audit()->sent(message: self::MESSAGE);

		$this->assertSame([['case-1', 'message.sent', ['message' => '7', 'channel' => 'email', 'messageId' => '<a@b>']]], $this->entries);
	}

	public function testAFailureRecordsTheCauseTheAttemptAndWhetherItIsParked(): void {
		$this->audit()->failed(message: self::MESSAGE, outcome: ['state' => 'pending', 'lastError' => 'upstream_service_down']);
		$this->audit()->failed(message: ['attempts' => 5] + self::MESSAGE, outcome: ['state' => 'parked', 'lastError' => 'upstream_service_down']);

		$this->assertSame('message.failed', $this->entries[0][1]);
		$this->assertSame(['message' => '7', 'channel' => 'email', 'error' => 'upstream_service_down', 'attempt' => 2, 'parked' => false], $this->entries[0][2]);
		$this->assertSame(['message' => '7', 'channel' => 'email', 'error' => 'upstream_service_down', 'attempt' => 5, 'parked' => true], $this->entries[1][2]);
	}

	public function testAnUnboundMessageWritesNothing(): void {
		$this->activity->expects($this->never())->method('record');

		$this->audit()->sent(message: ['objectUuid' => null] + self::MESSAGE);
		$this->audit()->failed(message: ['objectUuid' => ''] + self::MESSAGE, outcome: ['state' => 'pending']);
	}

	public function testAnObjectThatIsGoneIsLoggedAndNeverFailsTheSweep(): void {
		$activity = $this->createMock(ObjectActivityAuditInterface::class);
		$activity->method('record')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ScheduledMessageAudit] audit entry failed', ['action' => 'message.sent', 'message' => '7', 'error' => 'gone']);

		(new ScheduledMessageAudit($activity, $this->logger))->sent(message: self::MESSAGE);
	}

	public function testAnAuditWriteThatFailsIsLogged(): void {
		$activity = $this->createMock(ObjectActivityAuditInterface::class);
		$activity->method('record')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ScheduledMessageAudit] audit entry failed', ['action' => 'message.failed', 'message' => '7', 'error' => 'db down']);

		(new ScheduledMessageAudit($activity, $this->logger))->failed(message: self::MESSAGE, outcome: ['state' => 'pending']);
	}
}//end class
