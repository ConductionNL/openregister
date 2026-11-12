<?php

/**
 * ObjectEntity::unlock() releases a lock for its holder or on a break, and refuses everyone else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of unlocking an object.
 */
class ObjectEntityUnlockTest extends TestCase {

	/**
	 * A session for a user, or an anonymous one.
	 *
	 * @param string|null $uid The user id, or null for no user.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/**
	 * An object locked by alice for an hour.
	 *
	 * @return ObjectEntity The object.
	 */
	private function lockedByAlice(): ObjectEntity {
		$object = new ObjectEntity();
		$object->lock($this->session(uid: 'alice'), 'edit', 3600);
		return $object;
	}

	/**
	 * Run an unlock and say how it ended.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string|null  $uid    The caller, or null for no user.
	 * @param bool         $break  Whether this is a break.
	 *
	 * @return string `released`, or the refusal message.
	 */
	private function outcome(ObjectEntity $object, ?string $uid, bool $break): string {
		try {
			$object->unlock($this->session(uid: $uid), null, $break);
			return 'released';
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}

	public function testAnUnlockedObjectUnlocksForAnyoneEvenWithoutAUser(): void {
		$this->assertSame('released', $this->outcome(object: new ObjectEntity(), uid: null, break: false));
	}

	public function testTheHolderReleasesTheLock(): void {
		$object = $this->lockedByAlice();

		$this->assertSame('released', $this->outcome(object: $object, uid: 'alice', break: false));
		$this->assertFalse($object->isLocked());
	}

	public function testAnotherUserIsRefusedAndTheLockStays(): void {
		$object = $this->lockedByAlice();

		$this->assertStringStartsWith('Object is locked by', $this->outcome(object: $object, uid: 'bob', break: false));
		$this->assertTrue($object->isLocked());
	}

	public function testNoUserIsRefusedAndTheLockStays(): void {
		$object = $this->lockedByAlice();

		$this->assertSame('No user logged in', $this->outcome(object: $object, uid: null, break: false));
		$this->assertTrue($object->isLocked());
	}

	public function testABreakReleasesAnotherHoldersLock(): void {
		$object = $this->lockedByAlice();

		$this->assertSame('released', $this->outcome(object: $object, uid: 'bob', break: true));
		$this->assertFalse($object->isLocked());
	}

	public function testABreakNeedsNoUser(): void {
		$object = $this->lockedByAlice();

		$this->assertSame('released', $this->outcome(object: $object, uid: null, break: true));
		$this->assertFalse($object->isLocked());
	}
}
