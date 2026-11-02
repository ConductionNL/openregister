<?php

/**
 * The frozen-object delete guard, through a real ObjectDeletingEvent.
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
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener
 */
class FrozenObjectDeleteGuardListenerTest extends TestCase {

	/**
	 * Resolves the display name of whoever froze the object.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $users;

	/**
	 * The listener under test.
	 *
	 * @var FrozenObjectDeleteGuardListener
	 */
	private FrozenObjectDeleteGuardListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->users = $this->createMock(IUserManager::class);
		$this->listener = new FrozenObjectDeleteGuardListener($this->users);
	}//end setUp()

	/**
	 * An object frozen by the given user through the entity's own freeze().
	 *
	 * @param string $userId The user who freezes it.
	 *
	 * @return ObjectEntity The frozen object.
	 */
	private function frozenObject(string $userId): ObjectEntity {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$object = new ObjectEntity();
		$object->setUuid('11111111-2222-3333-4444-555555555555');
		$object->freeze(userSession: $session, reason: 'Account manager lock');

		return $object;
	}//end frozenObject()

	/**
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function testAFrozenObjectRefusesItsDeleteNamingWhoAndWhen(): void {
		$manager = $this->createMock(IUser::class);
		$manager->method('getDisplayName')->willReturn('Anna de Vries');
		$this->users->method('get')->with('anna')->willReturn($manager);

		$object = $this->frozenObject(userId: 'anna');
		$event = new ObjectDeletingEvent(object: $object);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(FrozenObjectDeleteGuardListener::ERROR_CODE, $errors['code']);
		$this->assertSame(409, $errors['status']);
		$this->assertSame('anna', $errors['frozenBy']);
		$this->assertStringContainsString('Anna de Vries', $errors['message']);
		$this->assertStringContainsString(date('Y-m-d'), $errors['message']);
		$this->assertStringContainsString('Unlock it before deleting it.', $errors['message']);
	}//end testAFrozenObjectRefusesItsDeleteNamingWhoAndWhen()

	/**
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function testAnUnfrozenObjectIsDeletable(): void {
		$object = $this->frozenObject(userId: 'anna');
		$object->unfreeze();
		$event = new ObjectDeletingEvent(object: $object);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnUnfrozenObjectIsDeletable()

	/**
	 * A cascade dispatches the deleting event per child, so a frozen child
	 * stops the cascade while its unfrozen siblings pass.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function testACascadeMeetingAFrozenChildIsStoppedAtThatChild(): void {
		$this->users->method('get')->willReturn(null);

		$sibling = new ObjectEntity();
		$sibling->setUuid('aaaaaaaa-0000-0000-0000-000000000001');
		$siblingEvent = new ObjectDeletingEvent(object: $sibling);

		$child = $this->frozenObject(userId: 'gone-user');
		$childEvent = new ObjectDeletingEvent(object: $child);

		$this->listener->handle($siblingEvent);
		$this->listener->handle($childEvent);

		$this->assertFalse($siblingEvent->isPropagationStopped());
		$this->assertTrue($childEvent->isPropagationStopped());
		// The account is gone, so the message falls back to the user id.
		$this->assertStringContainsString('locked by gone-user since', $childEvent->getErrors()['message']);
		$this->assertSame($child->getUuid(), $childEvent->getErrors()['uuid']);
	}//end testACascadeMeetingAFrozenChildIsStoppedAtThatChild()

	public function testAnotherEventIsIgnored(): void {
		$event = $this->createMock(Event::class);
		$this->users->expects($this->never())->method('get');

		$this->listener->handle($event);

		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()
}//end class
