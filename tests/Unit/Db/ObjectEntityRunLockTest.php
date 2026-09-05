<?php

/**
 * Run-scoped object locking, at the entity.
 *
 * These tests exist because the case that matters most silently PASSED
 * before this change: two flow runs executing under the same `runAs` could
 * not conflict, because ownership was compared on the user alone. The second
 * run took the extend branch, pushed the first run's expiry out, and was
 * handed the object.
 *
 * Every assertion here goes through the production predicate
 * `ObjectEntity::isLockedBySomeoneElse()` or through `lock()`/`unlock()`
 * themselves. None of them restates the ownership table: a test that
 * reimplements its subject agrees with the subject's bugs, which is exactly
 * how the broken write guard survived (see SaveObjectTest, and
 * openregister#3428).
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

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
final class ObjectEntityRunLockTest extends TestCase {

	private const RUN_A = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const RUN_B = 'run-bbbbbbbb-0000-0000-0000-000000000002';

	private ObjectEntity $entity;

	/**
	 * Fresh entity per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->entity = new ObjectEntity();
	}//end setUp()

	/**
	 * A session for the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUserSession The session.
	 */
	private function session(string $uid): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	// ---------------------------------------------------------------
	// The case that silently passed before this change.
	// ---------------------------------------------------------------

	/**
	 * Two runs under ONE user must conflict.
	 *
	 * @return void
	 */
	public function testASecondRunUnderTheSameUserIsRefused(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage(self::RUN_A);
		$this->entity->lock($this->session('alice'), 'step-two', 3600, self::RUN_B);
	}//end testASecondRunUnderTheSameUserIsRefused()

	/**
	 * The refusal must not have quietly extended the first run's lock.
	 *
	 * Before this change the second lock() call landed in the extend branch
	 * and REWROTE the expiry and the process tag. Asserting only that the
	 * second call throws would not catch a guard that throws after mutating.
	 *
	 * @return void
	 */
	public function testARefusedRunLeavesTheFirstRunsLockUntouched(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);
		$before = $this->entity->getLocked();

		try {
			$this->entity->lock($this->session('alice'), 'step-two', 60, self::RUN_B);
			$this->fail('the second run was admitted');
		} catch (Exception $e) {
			// Expected.
		}

		$this->assertSame($before, $this->entity->getLocked(), 'the refused run rewrote the lock');
	}//end testARefusedRunLeavesTheFirstRunsLockUntouched()

	/**
	 * A run may extend its OWN lock.
	 *
	 * @return void
	 */
	public function testARunMayExtendItsOwnLock(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 60, self::RUN_A);
		$this->assertTrue($this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A));
		$this->assertSame(self::RUN_A, $this->entity->getLockedByRun());
	}//end testARunMayExtendItsOwnLock()

	/**
	 * A run lock refuses the run's OWN runAs user acting as a person.
	 *
	 * @return void
	 */
	public function testARunLockRefusesItsOwnRunAsUser(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);

		$this->assertTrue(
			$this->entity->isLockedBySomeoneElse(userId: 'alice', runUuid: null),
			'a person walked in behind the run identity'
		);
	}//end testARunLockRefusesItsOwnRunAsUser()

	// ---------------------------------------------------------------
	// The record shape, and the records that already exist.
	// ---------------------------------------------------------------

	/**
	 * A payload with no `kind` is a user lock, with no migration.
	 *
	 * @return void
	 */
	public function testALegacyPayloadWithNoKindIsAUserLock(): void {
		$expiration = (new DateTime())->add(new DateInterval('PT3600S'));
		$this->entity->hydrate(
			[
				'locked' => ['user' => 'bob', 'expiration' => $expiration->format('c')],
			]
		);

		$this->assertFalse($this->entity->isLockedBySomeoneElse(userId: 'bob'));
		$this->assertTrue($this->entity->isLockedBySomeoneElse(userId: 'carol'));
		$this->assertNull($this->entity->getLockedByRun());
	}//end testALegacyPayloadWithNoKindIsAUserLock()

	/**
	 * A run lock records the kind, the run and the runAs user.
	 *
	 * @return void
	 */
	public function testARunLockRecordsAllThreeIdentityFields(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);
		$payload = $this->entity->getLocked();

		$this->assertSame(ObjectEntity::LOCK_KIND_RUN, $payload['kind']);
		$this->assertSame(self::RUN_A, $payload['runUuid']);
		$this->assertSame('alice', $payload['user'], 'existing readers of `user` must keep working');
		$this->assertSame('alice', $this->entity->getLockedBy());
	}//end testARunLockRecordsAllThreeIdentityFields()

	/**
	 * The caller's process tag reaches the payload.
	 *
	 * @return void
	 */
	public function testTheProcessTagSurvives(): void {
		$this->entity->lock($this->session('alice'), 'buildiq.mcp-manifest-edit', 3600);
		$this->assertSame('buildiq.mcp-manifest-edit', $this->entity->getLocked()['process']);
	}//end testTheProcessTagSurvives()

	/**
	 * A malformed run lock admits nobody.
	 *
	 * @return void
	 */
	public function testAMalformedRunLockAdmitsNobody(): void {
		$expiration = (new DateTime())->add(new DateInterval('PT3600S'));
		$this->entity->hydrate(
			[
				'locked' => [
					'kind' => ObjectEntity::LOCK_KIND_RUN,
					'user' => 'alice',
					'expiration' => $expiration->format('c'),
				],
			]
		);

		$this->assertTrue($this->entity->isLockedBySomeoneElse(userId: 'alice', runUuid: self::RUN_A));
		$this->assertTrue($this->entity->isLockedBySomeoneElse(userId: 'alice'));
		$this->assertNull($this->entity->getLockedByRun());
	}//end testAMalformedRunLockAdmitsNobody()

	// ---------------------------------------------------------------
	// Layer 3: the TTL backstop.
	// ---------------------------------------------------------------

	/**
	 * An expired run lock blocks nobody, and a live one blocks everybody.
	 *
	 * This is the last release layer: a run that never released, whose
	 * terminal event never fired and which the sweep never reached, still
	 * stops blocking once its duration is up.
	 *
	 * @return void
	 */
	public function testAnExpiredRunLockBlocksNobody(): void {
		$live = (new DateTime())->add(new DateInterval('PT3600S'));
		$this->entity->hydrate(
			[
				'locked' => [
					'kind' => ObjectEntity::LOCK_KIND_RUN,
					'runUuid' => self::RUN_A,
					'user' => 'alice',
					'expiration' => $live->format('c'),
				],
			]
		);
		$this->assertTrue($this->entity->isLockedBySomeoneElse(userId: 'carol'), 'control: a live lock blocks');

		$expired = (new DateTime())->sub(new DateInterval('PT10S'));
		$this->entity->hydrate(
			[
				'locked' => [
					'kind' => ObjectEntity::LOCK_KIND_RUN,
					'runUuid' => self::RUN_A,
					'user' => 'alice',
					'expiration' => $expired->format('c'),
				],
			]
		);

		$this->assertFalse($this->entity->isLockedBySomeoneElse(userId: 'carol'));
		$this->assertNull($this->entity->getLockedByRun());
		$this->assertNull($this->entity->describeLockHolder());
	}//end testAnExpiredRunLockBlocksNobody()

	// ---------------------------------------------------------------
	// The refusal message.
	// ---------------------------------------------------------------

	/**
	 * The refusal names the run, so the reader has somewhere to go.
	 *
	 * @return void
	 */
	public function testTheHolderDescriptionNamesTheRun(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);
		$description = (string)$this->entity->describeLockHolder();

		$this->assertStringContainsString(self::RUN_A, $description);
		$this->assertStringContainsString('alice', $description);
	}//end testTheHolderDescriptionNamesTheRun()

	// ---------------------------------------------------------------
	// unlock()
	// ---------------------------------------------------------------

	/**
	 * A different run cannot release a run's lock.
	 *
	 * @return void
	 */
	public function testAnotherRunCannotUnlock(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);

		$this->expectException(Exception::class);
		$this->entity->unlock($this->session('alice'), self::RUN_B);
	}//end testAnotherRunCannotUnlock()

	/**
	 * The holding run releases its own lock.
	 *
	 * @return void
	 */
	public function testTheHoldingRunUnlocks(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);
		$this->assertTrue($this->entity->unlock($this->session('alice'), self::RUN_A));
		$this->assertFalse($this->entity->isLocked());
	}//end testTheHoldingRunUnlocks()

	/**
	 * The break flag releases a lock the caller does not hold.
	 *
	 * Authorization for the break lives at the LockHandler call site; this
	 * only pins that the entity honours it.
	 *
	 * @return void
	 */
	public function testTheBreakFlagReleasesAnotherHoldersLock(): void {
		$this->entity->lock($this->session('alice'), 'step-one', 3600, self::RUN_A);
		$this->assertTrue($this->entity->unlock($this->session('admin'), null, true));
		$this->assertFalse($this->entity->isLocked());
	}//end testTheBreakFlagReleasesAnotherHoldersLock()
}//end class
