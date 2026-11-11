<?php

/**
 * The stored shape of an object lock.
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
 * Pins the exact keys, their order and the refusals of `ObjectEntity::lock()`
 * so the payload builder behind it can be restructured without a change in
 * what is stored or who is refused.
 */
class ObjectEntityLockPayloadShapeTest extends TestCase {

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
	 * A lock without a duration stores no expiration key at all.
	 *
	 * @return void
	 */
	public function testALockWithoutDurationHasNoExpirationKey(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), 'import', null);

		$lock = $entity->getLocked();
		$this->assertSame(['kind', 'runUuid', 'user', 'process', 'created', 'duration'], array_keys($lock));
		$this->assertSame(ObjectEntity::LOCK_KIND_USER, $lock['kind']);
		$this->assertNull($lock['runUuid']);
		$this->assertNull($lock['duration']);
		$this->assertTrue($entity->isLocked());
	}//end testALockWithoutDurationHasNoExpirationKey()

	/**
	 * A run lock with a duration stores the trimmed run and an expiration.
	 *
	 * @return void
	 */
	public function testARunLockStoresTheTrimmedRunAndExpiration(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), null, 60, '  run-1  ');

		$lock = $entity->getLocked();
		$this->assertSame(
			['kind', 'runUuid', 'user', 'process', 'created', 'duration', 'expiration'],
			array_keys($lock)
		);
		$this->assertSame(ObjectEntity::LOCK_KIND_RUN, $lock['kind']);
		$this->assertSame('run-1', $lock['runUuid']);
		$this->assertSame('alice', $lock['user']);
		$this->assertNull($lock['process']);
		$this->assertSame(60, $lock['duration']);
		$this->assertSame(
			60,
			(new DateTime($lock['expiration']))->getTimestamp() - (new DateTime($lock['created']))->getTimestamp()
		);
	}//end testARunLockStoresTheTrimmedRunAndExpiration()

	/**
	 * Extending to "until released" drops the expiration and keeps created.
	 *
	 * @return void
	 */
	public function testExtendingWithoutDurationDropsExpiration(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), 'import', 60);
		$created = $entity->getLocked()['created'];

		$entity->lock($this->sessionFor('alice'), null, null);

		$lock = $entity->getLocked();
		$this->assertSame(['kind', 'runUuid', 'user', 'process', 'created', 'duration'], array_keys($lock));
		$this->assertSame($created, $lock['created']);
		$this->assertSame('import', $lock['process']);
	}//end testExtendingWithoutDurationDropsExpiration()

	/**
	 * A second run under the same user is refused and the lock is unchanged.
	 *
	 * @return void
	 */
	public function testASecondRunIsRefusedAndTheLockIsKept(): void {
		$entity = new ObjectEntity();
		$entity->lock($this->sessionFor('alice'), 'p', 60, 'run-1');
		$before = $entity->getLocked();

		try {
			$entity->lock($this->sessionFor('alice'), 'p', 60, 'run-2');
			$this->fail('A second run must be refused.');
		} catch (Exception $e) {
			$this->assertStringContainsString('Object is locked by', $e->getMessage());
		}

		$this->assertSame($before, $entity->getLocked());
	}//end testASecondRunIsRefusedAndTheLockIsKept()
}//end class
