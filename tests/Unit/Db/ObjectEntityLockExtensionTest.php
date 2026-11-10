<?php

/**
 * Re-locking an object that is already locked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `ObjectEntity::lock()` has three paths and only one of them was tested.
 *
 * Taking a lock on an UNLOCKED object was covered. What was not: what happens
 * when the object is ALREADY locked — the branch that decides whether a second
 * caller extends the existing lock or is refused. That is the half of a lock
 * that actually protects anything, and it was reached by no test at all.
 *
 * Found by reading the uncovered lines out of the CI coverage artifact rather
 * than by guessing: lines 1132-1144 of lib/Db/ObjectEntity.php were a
 * contiguous block of twelve unexecuted statements.
 */
class ObjectEntityLockExtensionTest extends TestCase {

	/**
	 * Build a session that reports the given user id.
	 *
	 * @param string $uid The user id the session should return.
	 *
	 * @return IUserSession The session double.
	 */
	private function sessionFor(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end sessionFor()

	/**
	 * A lock is created on an object that is not yet locked.
	 *
	 * @return void
	 */
	public function testLockingAnUnlockedObjectCreatesTheLock(): void {
		$entity = new ObjectEntity();

		$this->assertTrue($entity->lock($this->sessionFor('alice'), 'import', 600));
		$this->assertTrue($entity->isLocked());

		$lock = $entity->getLocked();
		$this->assertSame('alice', $lock['user']);
		$this->assertSame('import', $lock['process']);
		$this->assertSame(600, $lock['duration']);
	}//end testLockingAnUnlockedObjectCreatesTheLock()

	/**
	 * The SAME user re-locking extends their own lock rather than being refused.
	 *
	 * The extension keeps the original `created` stamp — the lock is the same
	 * lock, held longer — while `expiration` and `duration` move. Asserting
	 * `created` is what distinguishes an extension from a silently replaced
	 * lock, which would look identical from the outside.
	 *
	 * @return void
	 */
	public function testTheSameUserExtendsTheirOwnLock(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), 'import', 600);

		$created = $entity->getLocked()['created'];
		$firstExpiry = $entity->getLocked()['expiration'];

		$this->assertTrue($entity->lock($this->sessionFor('alice'), null, 7200));

		$lock = $entity->getLocked();
		$this->assertSame('alice', $lock['user']);
		$this->assertSame($created, $lock['created'], 'An extension keeps the original created stamp.');
		$this->assertSame(7200, $lock['duration']);
		$this->assertSame(
			'import',
			$lock['process'],
			'A null process on re-lock inherits the existing one rather than clearing it.'
		);
		$this->assertGreaterThan(
			new DateTime($firstExpiry),
			new DateTime($lock['expiration']),
			'Extending must push the expiry out, or the lock is not actually extended.'
		);
	}//end testTheSameUserExtendsTheirOwnLock()

	/**
	 * A DIFFERENT user is refused.
	 *
	 * This is the assertion the whole lock exists for, and nothing was making it.
	 *
	 * @return void
	 */
	public function testADifferentUserIsRefused(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), 'import', 600);

		$this->expectException(Exception::class);
		// The refusal now NAMES the holder: a refusal that does not say who
		// holds the lock leaves the reader with nowhere to go.
		$this->expectExceptionMessage('Object is locked by alice');

		$entity->lock($this->sessionFor('bob'), 'export', 600);
	}//end testADifferentUserIsRefused()

	/**
	 * Locking without a session user is refused.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		(new ObjectEntity())->lock($session);
	}//end testAnAnonymousCallerIsRefused()
}//end class
