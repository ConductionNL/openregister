<?php

/**
 * AuditTrailMapper answers Core's object-audit port through recordObjectEvent().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectAuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectAuditRecorderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An entry written through the port is the event recordObjectEvent() always wrote.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper::writeObjectEvent
 * @uses   \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Core\ObjectAuditEntry
 */
final class AuditTrailMapperObjectAuditEntryTest extends TestCase {

	public function testTheMapperInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(AuditTrailMapperInterface::class, ObjectAuditRecorderInterface::class));
		$this->assertTrue(is_subclass_of(AuditTrailMapper::class, ObjectAuditRecorderInterface::class));
	}

	public function testANamedActorIsPassedOnWithItsName(): void {
		$object = new ObjectEntity();
		$events = $this->recordedEvents(entry: new ObjectAuditEntry(object: $object, action: 'draft.removed', context: ['job' => 'x'], actorId: 'job:x', actorName: 'x'));

		$this->assertCount(1, $events);
		$this->assertSame($object, $events[0]->object);
		$this->assertSame('draft.removed', $events[0]->action);
		$this->assertSame(['job' => 'x'], $events[0]->context);
		$this->assertSame('job:x', $events[0]->options->actor->id);
		$this->assertSame('x', $events[0]->options->actor->name);
		$this->assertNull($events[0]->options->ipAddress);
	}

	public function testAnEntryWithoutAnActorIsTheEventWithDefaultOptions(): void {
		$object = new ObjectEntity();
		$events = $this->recordedEvents(entry: new ObjectAuditEntry(object: $object, action: 'lock.broken', context: ['displacedRun' => 'r']));

		$this->assertEquals(new ObjectAuditEvent(object: $object, action: 'lock.broken', context: ['displacedRun' => 'r']), $events[0]);
	}

	public function testAFailedWriteIsThrownToTheCaller(): void {
		$mapper = $this->getMockBuilder(AuditTrailMapper::class)->disableOriginalConstructor()->onlyMethods(['recordObjectEvent'])->getMock();
		$mapper->method('recordObjectEvent')->willThrowException(new RuntimeException('db down'));

		$this->expectException(RuntimeException::class);
		$mapper->writeObjectEvent(entry: new ObjectAuditEntry(object: new ObjectEntity(), action: 'moved'));
	}

	/**
	 * Write one entry through the port and return what recordObjectEvent() received.
	 *
	 * @param ObjectAuditEntry $entry The entry.
	 *
	 * @return array<int, ObjectAuditEvent> The recorded events.
	 */
	private function recordedEvents(ObjectAuditEntry $entry): array {
		$events = [];
		$mapper = $this->getMockBuilder(AuditTrailMapper::class)->disableOriginalConstructor()->onlyMethods(['recordObjectEvent'])->getMock();
		$mapper->method('recordObjectEvent')->willReturnCallback(
			static function (ObjectAuditEvent $event) use (&$events): AuditTrail {
				$events[] = $event;
				return new AuditTrail();
			}
		);

		$mapper->writeObjectEvent(entry: $entry);

		return $events;
	}
}//end class
