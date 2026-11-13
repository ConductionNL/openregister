<?php

/**
 * Unit tests for the ValidatesPreWriteObject trait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ValidatesPreWriteObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins which object a pre-write validator is handed for each event, and how
 * the written data is read off it.
 *
 * @covers \OCA\OpenRegister\Listener\ValidatesPreWriteObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 */
final class ValidatesPreWriteObjectTest extends TestCase {

	/**
	 * A listener using the trait that records every validate() call.
	 *
	 * @return object The recording listener
	 */
	private function listener(): object {
		return new class {
			use ValidatesPreWriteObject;

			/**
			 * The calls made to validate().
			 *
			 * @var list<array{event: object, object: ObjectEntity, data: array}>
			 */
			public array $calls = [];

			/**
			 * Record the call, with the data the trait reads off the object.
			 *
			 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event
			 * @param ObjectEntity                            $object The object
			 *
			 * @return void
			 */
			protected function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
				$this->calls[] = [
					'event'  => $event,
					'object' => $object,
					'data'   => $this->writtenData(object: $object),
				];
			}//end validate()
		};
	}//end listener()

	/**
	 * An object carrying the given data.
	 *
	 * @param array $data The object data
	 *
	 * @return ObjectEntity The object
	 */
	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setObject($data);

		return $object;
	}//end objectWith()

	/**
	 * A create event validates the object being created.
	 *
	 * @return void
	 */
	public function testCreatingEventValidatesTheCreatedObject(): void {
		$listener = $this->listener();
		$object   = $this->objectWith(data: ['name' => 'Amsterdam']);
		$event    = new ObjectCreatingEvent(object: $object);

		$listener->handle($event);

		$this->assertCount(expectedCount: 1, haystack: $listener->calls);
		$this->assertSame(expected: $event, actual: $listener->calls[0]['event']);
		$this->assertSame(expected: $object, actual: $listener->calls[0]['object']);
		$this->assertSame(expected: ['id' => 'uuid-1', 'name' => 'Amsterdam'], actual: $listener->calls[0]['data']);
	}//end testCreatingEventValidatesTheCreatedObject()

	/**
	 * An update event validates the new object, not the old one.
	 *
	 * @return void
	 */
	public function testUpdatingEventValidatesTheNewObject(): void {
		$listener = $this->listener();
		$old      = $this->objectWith(data: ['name' => 'old']);
		$new      = $this->objectWith(data: ['name' => 'new']);
		$event    = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);

		$listener->handle($event);

		$this->assertCount(expectedCount: 1, haystack: $listener->calls);
		$this->assertSame(expected: $event, actual: $listener->calls[0]['event']);
		$this->assertSame(expected: $new, actual: $listener->calls[0]['object']);
		$this->assertSame(expected: ['id' => 'uuid-1', 'name' => 'new'], actual: $listener->calls[0]['data']);
	}//end testUpdatingEventValidatesTheNewObject()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$listener = $this->listener();

		$listener->handle(new ObjectDeletingEvent(object: $this->objectWith(data: ['name' => 'x'])));

		$this->assertSame(expected: [], actual: $listener->calls);
	}//end testOtherEventsAreIgnored()

	/**
	 * An object without data is read as its identifier alone.
	 *
	 * @return void
	 */
	public function testObjectWithoutDataReadsAsItsIdentifier(): void {
		$listener = $this->listener();

		$object   = new ObjectEntity();
		$object->setUuid('uuid-2');

		$listener->handle(new ObjectCreatingEvent(object: $object));

		$this->assertCount(expectedCount: 1, haystack: $listener->calls);
		$this->assertSame(expected: ['id' => 'uuid-2'], actual: $listener->calls[0]['data']);
	}//end testObjectWithoutDataReadsAsItsIdentifier()
}//end class
