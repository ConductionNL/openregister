<?php

/**
 * The receipt event carries the activated object and its moment of receipt.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Event\ObjectActivatedEvent
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectActivatedEventTest extends TestCase {

	/**
	 * What the submit put in is what a receipt listener reads.
	 */
	public function testItCarriesTheObjectAndTheMoment(): void {
		$object = new ObjectEntity();
		$object->setUuid('u');
		$object->setStatus(ObjectEntity::STATUS_ACTIVE);
		$moment = new DateTimeImmutable('2026-10-12T09:15:00+02:00');

		$event = new ObjectActivatedEvent(object: $object, receivedAt: $moment);

		$this->assertSame($object, $event->getObject());
		$this->assertSame($moment, $event->getReceivedAt());
		$this->assertFalse($event->getObject()->isDraft());
	}//end testItCarriesTheObjectAndTheMoment()
}//end class
