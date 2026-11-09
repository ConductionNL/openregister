<?php

/**
 * The frozen-object delete guard, every way the refusal reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The refusal names who froze the record and since when, with a fallback for every missing part.
 *
 * @covers \OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses \OCA\OpenRegister\Event\WriteVetoState
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class FrozenObjectDeleteGuardRefusalMessageTest extends TestCase {

	private IUserManager&MockObject $users;

	/**
	 * Fresh user manager per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->users = $this->createMock(IUserManager::class);
	}//end setUp()

	/**
	 * Delete an object frozen with the given marker.
	 *
	 * @param array|null $marker The freeze marker, null for unfrozen.
	 *
	 * @return ObjectDeletingEvent The handled event.
	 */
	private function handled(?array $marker): ObjectDeletingEvent {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setFrozen($marker);
		$event = new ObjectDeletingEvent(object: $object);
		(new FrozenObjectDeleteGuardListener(users: $this->users))->handle($event);
		return $event;
	}//end handled()

	/**
	 * A known user and an ISO timestamp give display name and calendar date, with a 409.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheDisplayNameAndDate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Anna Archivist');
		$this->users->expects($this->once())->method('get')->with('anna')->willReturn($user);

		$event = $this->handled(marker: ['by' => ' anna ', 'at' => '2026-03-04T10:00:00+00:00']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => FrozenObjectDeleteGuardListener::ERROR_CODE,
				'message' => 'This record is locked by Anna Archivist since 2026-03-04. Unlock it before deleting it.',
				'status' => 409,
				'frozenBy' => 'anna',
				'frozenAt' => '2026-03-04T10:00:00+00:00',
				'uuid' => 'obj-1',
			],
			$event->getErrors()
		);
	}//end testTheRefusalNamesTheDisplayNameAndDate()

	/**
	 * A user whose account is gone is named by id; an unparseable date is quoted raw.
	 *
	 * @return void
	 */
	public function testAGoneAccountIsNamedByIdAndABadDateIsQuoted(): void {
		$this->users->method('get')->willReturn(null);

		$event = $this->handled(marker: ['by' => 'bob', 'at' => 'not a date']);

		$this->assertSame(
			'This record is locked by bob since not a date. Unlock it before deleting it.',
			$event->getErrors()['message']
		);
	}//end testAGoneAccountIsNamedByIdAndABadDateIsQuoted()

	/**
	 * A marker without who or when uses the placeholders and never looks a user up.
	 *
	 * @return void
	 */
	public function testAMarkerWithoutWhoOrWhenUsesPlaceholders(): void {
		$this->users->expects($this->never())->method('get');

		$event = $this->handled(marker: ['by' => '  ', 'at' => 17, 'reason' => 'hold']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			'This record is locked by an unknown user since an unrecorded date. Unlock it before deleting it.',
			$event->getErrors()['message']
		);
		$this->assertNull($event->getErrors()['frozenBy']);
		$this->assertNull($event->getErrors()['frozenAt']);
	}//end testAMarkerWithoutWhoOrWhenUsesPlaceholders()

	/**
	 * An unfrozen object (null or empty marker) is deletable.
	 *
	 * @return void
	 */
	public function testAnUnfrozenObjectIsDeletable(): void {
		$this->users->expects($this->never())->method('get');

		$this->assertFalse($this->handled(marker: null)->isPropagationStopped());
		$this->assertFalse($this->handled(marker: [])->isPropagationStopped());
	}//end testAnUnfrozenObjectIsDeletable()

	/**
	 * Other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$event = new Event();

		(new FrozenObjectDeleteGuardListener(users: $this->users))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOtherEventsAreIgnored()
}//end class
