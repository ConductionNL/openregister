<?php

/**
 * Tests for the veto state of the object creating, updating and deleting events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Event
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

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Each pre-write event starts unvetoed, and keeps a stop, the errors and the modified data a listener sets.
 *
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @covers \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @covers \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @covers \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @covers \OCA\OpenRegister\Event\WriteVetoState
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectWriteVetoEventsTest extends TestCase {

	/**
	 * One event of each kind around the same object.
	 *
	 * @return array<string, array{0: callable(ObjectEntity): object}>
	 */
	public static function events(): array {
		return [
			'creating' => [static fn (ObjectEntity $o): object => new ObjectCreatingEvent($o)],
			'updating' => [static fn (ObjectEntity $o): object => new ObjectUpdatingEvent($o, null)],
			'deleting' => [static fn (ObjectEntity $o): object => new ObjectDeletingEvent($o)],
		];
	}

	/**
	 * A fresh event is not stopped and holds no errors or modified data; what a listener sets is kept.
	 *
	 * @dataProvider events
	 *
	 * @param callable $make Builds the event.
	 *
	 * @return void
	 */
	public function testTheVetoStateIsKept(callable $make): void {
		$event = $make(new ObjectEntity());
		$this->assertInstanceOf(Event::class, $event);
		$this->assertInstanceOf(StoppableEventInterface::class, $event);
		$this->assertSame([false, [], []], [$event->isPropagationStopped(), $event->getErrors(), $event->getModifiedData()]);

		$event->setErrors(['code' => 'no']);
		$event->setModifiedData(['a' => 1]);
		$event->stopPropagation();
		$this->assertSame([true, ['code' => 'no'], ['a' => 1]], [$event->isPropagationStopped(), $event->getErrors(), $event->getModifiedData()]);
	}

	/**
	 * The events hand back the objects they were built with.
	 *
	 * @return void
	 */
	public function testTheEventsHandBackTheirObjects(): void {
		$new = new ObjectEntity();
		$old = new ObjectEntity();
		$this->assertSame($new, (new ObjectCreatingEvent($new))->getObject());
		$this->assertSame($new, (new ObjectDeletingEvent($new))->getObject());
		$updating = new ObjectUpdatingEvent($new, $old);
		$this->assertSame([$new, $old], [$updating->getNewObject(), $updating->getOldObject()]);
		$this->assertNull((new ObjectUpdatingEvent($new))->getOldObject());
	}
}
