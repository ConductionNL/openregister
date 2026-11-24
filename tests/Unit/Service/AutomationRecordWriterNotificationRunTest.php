<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The automation record writer answers the notification run port: one
 * evaluated rule becomes one notification record, stamped and stored, and a
 * store failure is logged, never thrown.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Notifications\NotificationRun;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\AutomationRecordWriter
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Notifications\NotificationRun
 */
final class AutomationRecordWriterNotificationRunTest extends TestCase {
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-00000000000a');
		$object->setSchema('12');
		$object->setRegister('3');

		return $object;
	}

	private function notificationRun(?string $message = null): NotificationRun {
		return new NotificationRun(
			key: ' rule-key ',
			name: 'case-assigned',
			object: $this->object(),
			eventId: 'evt-1',
			outcome: 'sent',
			reason: 'reached',
			message: $message,
			channels: '{"email":1}'
		);
	}

	public function testARunBecomesOneStoredNotificationRecord(): void {
		$stored = null;
		$mapper = $this->createMock(AutomationRecordMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (AutomationRecord $record) use (&$stored): AutomationRecord {
				$stored = $record;
				return $record;
			}
		);

		(new AutomationRecordWriter($mapper, $this->createMock(LoggerInterface::class)))->recordNotificationRun($this->notificationRun());

		$this->assertSame(AutomationRecord::KIND_NOTIFICATION, $stored->getKind());
		$this->assertSame('rule-key', $stored->getAutomationKey());
		$this->assertSame('case-assigned', $stored->getName());
		$this->assertSame('12', $stored->getSchemaId());
		$this->assertSame('3', $stored->getRegisterId());
		$this->assertSame('00000000-0000-0000-0000-00000000000a', $stored->getObjectUuid());
		$this->assertSame('evt-1', $stored->getEventId());
		$this->assertSame('sent', $stored->getOutcome());
		$this->assertSame('reached', $stored->getReason());
		$this->assertNull($stored->getMessage());
		$this->assertArrayNotHasKey('message', $stored->getUpdatedFields());
		$this->assertSame('{"email":1}', $stored->getChannels());
		$this->assertNotNull($stored->getRecordedAt());
	}

	public function testAFailureMessageIsStored(): void {
		$stored = null;
		$mapper = $this->createMock(AutomationRecordMapper::class);
		$mapper->method('insert')->willReturnCallback(
			static function (AutomationRecord $record) use (&$stored): AutomationRecord {
				$stored = $record;
				return $record;
			}
		);

		(new AutomationRecordWriter($mapper, $this->createMock(LoggerInterface::class)))->recordNotificationRun($this->notificationRun(message: 'boom'));

		$this->assertSame('boom', $stored->getMessage());
	}

	public function testAStoreFailureIsLoggedNotThrown(): void {
		$mapper = $this->createMock(AutomationRecordMapper::class);
		$mapper->method('insert')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new AutomationRecordWriter($mapper, $logger))->recordNotificationRun($this->notificationRun());
	}
}
