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

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageAudit;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageAudit
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 */
class ScheduledMessageAuditTest extends TestCase {

	private const MESSAGE = ['id' => '7', 'channel' => 'email', 'objectUuid' => 'case-1', 'attempts' => 2, 'messageId' => '<a@b>'];

	private MagicObjectFinderInterface&MockObject $objects;

	private AuditTrailMapperInterface&MockObject $auditTrail;

	private LoggerInterface&MockObject $logger;

	/** @var ObjectAuditEvent[] */
	private array $events = [];

	private ObjectEntity $case;

	protected function setUp(): void {
		$this->case = new ObjectEntity();
		$this->case->setUuid('case-1');
		$this->objects    = $this->createMock(MagicObjectFinderInterface::class);
		$this->auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->auditTrail->method('recordObjectEvent')->willReturnCallback(function (ObjectAuditEvent $event) {
			$this->events[] = $event;
			return null;
		});
	}

	private function audit(): ScheduledMessageAudit {
		return new ScheduledMessageAudit($this->objects, $this->auditTrail, $this->logger);
	}

	public function testASentMessageIsRecordedOnItsObjectReadWithoutAUser(): void {
		$this->objects->expects($this->once())->method('find')
			->with('case-1', null, null, false, false, false)
			->willReturn($this->case);

		$this->audit()->sent(message: self::MESSAGE);

		$this->assertCount(1, $this->events);
		$this->assertSame($this->case, $this->events[0]->object);
		$this->assertSame('message.sent', $this->events[0]->action);
		$this->assertSame(['message' => '7', 'channel' => 'email', 'messageId' => '<a@b>'], $this->events[0]->context);
	}

	public function testAFailureRecordsTheCauseTheAttemptAndWhetherItIsParked(): void {
		$this->objects->method('find')->willReturn($this->case);

		$this->audit()->failed(message: self::MESSAGE, outcome: ['state' => 'pending', 'lastError' => 'upstream_service_down']);
		$this->audit()->failed(message: ['attempts' => 5] + self::MESSAGE, outcome: ['state' => 'parked', 'lastError' => 'upstream_service_down']);

		$this->assertSame('message.failed', $this->events[0]->action);
		$this->assertSame(['message' => '7', 'channel' => 'email', 'error' => 'upstream_service_down', 'attempt' => 2, 'parked' => false], $this->events[0]->context);
		$this->assertSame(['message' => '7', 'channel' => 'email', 'error' => 'upstream_service_down', 'attempt' => 5, 'parked' => true], $this->events[1]->context);
	}

	public function testAnUnboundMessageWritesNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->auditTrail->expects($this->never())->method('recordObjectEvent');

		$this->audit()->sent(message: ['objectUuid' => null] + self::MESSAGE);
		$this->audit()->failed(message: ['objectUuid' => ''] + self::MESSAGE, outcome: ['state' => 'pending']);
	}

	public function testAnObjectThatIsGoneIsLoggedAndNeverFailsTheSweep(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->auditTrail->expects($this->never())->method('recordObjectEvent');
		$this->logger->expects($this->once())->method('warning')
			->with('[ScheduledMessageAudit] audit entry failed', ['action' => 'message.sent', 'message' => '7', 'error' => 'gone']);

		$this->audit()->sent(message: self::MESSAGE);
	}

	public function testAnAuditWriteThatFailsIsLogged(): void {
		$this->objects->method('find')->willReturn($this->case);
		$auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrail->method('recordObjectEvent')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ScheduledMessageAudit] audit entry failed', ['action' => 'message.failed', 'message' => '7', 'error' => 'db down']);

		(new ScheduledMessageAudit($this->objects, $auditTrail, $this->logger))->failed(message: self::MESSAGE, outcome: ['state' => 'pending']);
	}
}//end class
