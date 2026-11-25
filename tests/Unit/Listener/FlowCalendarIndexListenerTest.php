<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\FlowCalendarIndexListener;
use OCA\OpenRegister\Service\Flow\FlowCalendarIndex;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Object writes reach the calendar-trigger index; a failing index write never breaks the save.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarIndexListenerTest extends TestCase {

	/**
	 * A record.
	 *
	 * @param string $uuid Its uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function record(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end record()

	public function testACreatedRecordIsIndexed(): void {
		$record = self::record(uuid: 'c-1');
		$index = $this->createMock(FlowCalendarIndex::class);
		$index->expects($this->once())->method('index')->with($record);
		$index->expects($this->never())->method('remove');

		(new FlowCalendarIndexListener(index: $index, logger: $this->createMock(LoggerInterface::class)))->handle(new ObjectCreatedEvent($record));
	}//end testACreatedRecordIsIndexed()

	public function testAnUpdatedRecordIsIndexedAsItIsNow(): void {
		$old = self::record(uuid: 'c-1');
		$new = self::record(uuid: 'c-1');
		$index = $this->createMock(FlowCalendarIndex::class);
		$index->expects($this->once())->method('index')->with($this->identicalTo($new));

		(new FlowCalendarIndexListener(index: $index, logger: $this->createMock(LoggerInterface::class)))->handle(new ObjectUpdatedEvent($new, $old));
	}//end testAnUpdatedRecordIsIndexedAsItIsNow()

	public function testADeletedRecordLosesItsMoments(): void {
		$index = $this->createMock(FlowCalendarIndex::class);
		$index->expects($this->once())->method('remove')->with('c-1');
		$index->expects($this->never())->method('index');

		(new FlowCalendarIndexListener(index: $index, logger: $this->createMock(LoggerInterface::class)))->handle(new ObjectDeletedEvent(self::record(uuid: 'c-1')));
	}//end testADeletedRecordLosesItsMoments()

	public function testAFailingIndexWriteIsLoggedNotThrown(): void {
		$index = $this->createMock(FlowCalendarIndex::class);
		$index->method('index')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('database gone'));

		(new FlowCalendarIndexListener(index: $index, logger: $logger))->handle(new ObjectCreatedEvent(self::record(uuid: 'c-1')));
	}//end testAFailingIndexWriteIsLoggedNotThrown()
}//end class
