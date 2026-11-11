<?php

/**
 * ObjectEntity's terms-based lock and soft delete store what the per-argument methods store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SoftDeletion;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @covers \OCA\OpenRegister\Db\LockTerms
 * @covers \OCA\OpenRegister\Db\SoftDeletion
 */
final class ObjectEntityLockTermsTest extends TestCase {

	/**
	 * A session for a user, or an anonymous one for null.
	 *
	 * @param string|null $uid The user id.
	 *
	 * @return IUserSession
	 */
	private function sessionFor(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end sessionFor()

	/**
	 * The stored lock without its clock-dependent fields.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return array
	 */
	private static function lockShape(ObjectEntity $entity): array {
		$lock = (array) $entity->getLocked();
		unset($lock['created'], $lock['expiration']);

		return ['keys' => array_keys((array) $entity->getLocked()), 'lock' => $lock];
	}//end lockShape()

	/**
	 * acquireLock stores what lock stores, for user, run and blank-run terms.
	 *
	 * @return void
	 */
	public function testAcquireLockMatchesLock(): void {
		foreach ([['import', 60, null], [null, null, '  run-1  '], ['p', 3600, '   ']] as [$process, $duration, $run]) {
			$old = new ObjectEntity();
			$old->lock($this->sessionFor('alice'), $process, $duration, $run);
			$new = new ObjectEntity();
			$new->acquireLock($this->sessionFor('alice'), new LockTerms($process, $duration, $run));
			$this->assertSame(self::lockShape($old), self::lockShape($new));
		}
	}//end testAcquireLockMatchesLock()

	/**
	 * The holder extends its lock and keeps the process; another user is refused.
	 *
	 * @return void
	 */
	public function testAcquireLockExtendsForTheHolderAndRefusesAnother(): void {
		$entity = new ObjectEntity();
		$entity->acquireLock($this->sessionFor('alice'), new LockTerms('import'));
		$created = $entity->getLocked()['created'];
		$entity->acquireLock($this->sessionFor('alice'), new LockTerms(null, 120));
		$this->assertSame('import', $entity->getLocked()['process']);
		$this->assertSame($created, $entity->getLocked()['created']);
		$this->assertSame(120, $entity->getLocked()['duration']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Object is locked by');
		$entity->acquireLock($this->sessionFor('bob'), new LockTerms());
	}//end testAcquireLockExtendsForTheHolderAndRefusesAnother()

	/**
	 * Locking and deleting both refuse an anonymous session.
	 *
	 * @return void
	 */
	public function testAnonymousSessionIsRefused(): void {
		foreach ([fn (ObjectEntity $e) => $e->acquireLock($this->sessionFor(null)), fn (ObjectEntity $e) => $e->softDelete($this->sessionFor(null))] as $call) {
			$entity = new ObjectEntity();
			try {
				$call($entity);
				$this->fail('An anonymous session was accepted');
			} catch (Exception $e) {
				$this->assertSame('No user logged in', $e->getMessage());
			}

			$this->assertNull($entity->getLocked());
		}
	}//end testAnonymousSessionIsRefused()

	/**
	 * softDelete stores what delete stores, the default window included.
	 *
	 * @return void
	 */
	public function testSoftDeleteMatchesDelete(): void {
		foreach ([['gone', 365], [null, 0], [null, null], ['x', 30]] as [$reason, $days]) {
			$old = (new ObjectEntity())->delete($this->sessionFor('alice'), $reason, $days)->getDeleted();
			$new = (new ObjectEntity())->softDelete($this->sessionFor('alice'), new SoftDeletion($reason, $days))->getDeleted();
			$this->assertSame(array_keys($old), array_keys($new));
			$this->assertSame([$old['deletedBy'], $old['deletedReason'], $old['retentionPeriod']], [$new['deletedBy'], $new['deletedReason'], $new['retentionPeriod']]);
		}

		$this->assertSame(DeletionWindowService::DEFAULT_RETENTION_DAYS, (new SoftDeletion(null, -4))->days());
		$this->assertSame(365, (new SoftDeletion(null, 365))->days());
	}//end testSoftDeleteMatchesDelete()
}//end class
