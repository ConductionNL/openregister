<?php

/**
 * Run-held locks at the handler: authorization and the administrator break.
 *
 * The release and sweep layers live in RunLockRegistry and are covered by
 * RunLockRegistryTest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 */
final class LockHandlerRunLockTest extends TestCase {

	private const RUN_A = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const RUN_B = 'run-bbbbbbbb-0000-0000-0000-000000000002';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private MagicMapper $magic;

	private AuditTrailMapper $audit;

	private IUserSession $session;

	private IGroupManager $groups;

	private RunLockRegistry $registry;

	/**
	 * Wire a handler over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(originalClassName: MagicMapper::class);
		$this->audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->groups = $this->createMock(originalClassName: IGroupManager::class);
		$this->registry = $this->createMock(originalClassName: RunLockRegistry::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(): LockHandler {
		return new LockHandler(
			$this->magic,
			$this->audit,
			$this->createMock(LoggerInterface::class),
			$this->session,
			$this->groups,
			$this->createMock(SchemaMapper::class),
			$this->createMock(AdvisoryLockStore::class),
			$this->registry
		);
	}//end handler()

	/**
	 * An object carrying a live lock written by the PRODUCTION writer.
	 *
	 * @param string $holderUid The runAs identity.
	 * @param string|null $runUuid The holding run, for a run lock.
	 *
	 * @return ObjectEntity The locked object.
	 */
	private function lockedObject(string $holderUid, ?string $runUuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('owner-uid');

		$holder = $this->createMock(IUser::class);
		$holder->method('getUID')->willReturn($holderUid);
		$holderSession = $this->createMock(IUserSession::class);
		$holderSession->method('getUser')->willReturn($holder);
		$object->lock($holderSession, 'step-one', 3600, $runUuid);

		return $object;
	}//end lockedObject()

	/**
	 * Make findObjectWithContext resolve to this object.
	 *
	 * @param ObjectEntity $object The object to return.
	 *
	 * @return void
	 */
	private function resolvesTo(ObjectEntity $object): void {
		$this->magic->method('findAcrossAllSources')->willReturn(
			[
				'object' => $object,
				'register' => $this->createMock(Register::class),
				'schema' => $this->createMock(Schema::class),
			]
		);
	}//end resolvesTo()

	/**
	 * A registry row.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return RunObjectLock The row.
	 */
	private function row(string $runUuid): RunObjectLock {
		$row = new RunObjectLock();
		$row->setRunUuid($runUuid);
		$row->setObjectUuid(self::OBJ);
		$row->setLockedAt(new DateTime());
		$row->setExpiresAt((new DateTime())->add(new DateInterval('PT3600S')));

		return $row;
	}//end row()

	// ---------------------------------------------------------------
	// The administrator break.
	// ---------------------------------------------------------------

	/**
	 * A non-administrator cannot break a lock.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotBreakALock(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Only an administrator may break a lock');
		$this->handler()->breakLock(identifier: self::OBJ);
	}//end testANonAdministratorCannotBreakALock()

	/**
	 * An administrator breaks a run's lock, and the break is AUDITED naming
	 * the run it displaced.
	 *
	 * An override nobody can see afterwards is indistinguishable from the
	 * lock never having worked, so the audit entry is part of the feature and
	 * not decoration.
	 *
	 * @return void
	 */
	public function testAnAdministratorBreaksARunLockAndItIsAudited(): void {
		$this->resolvesTo($this->lockedObject('alice', self::RUN_A));

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($admin);
		$this->groups->method('isAdmin')->willReturn(true);
		$this->magic->method('unlockObjectEntity')->willReturn(new ObjectEntity());

		$recorded = [];
		$this->audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context) use (&$recorded) {
				$recorded = ['action' => $action, 'context' => $context];
				return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
			}
		);

		$this->assertTrue($this->handler()->breakLock(identifier: self::OBJ));
		$this->assertSame('lock.broken', $recorded['action']);
		$this->assertSame(self::RUN_A, $recorded['context']['displacedRun']);
		$this->assertStringContainsString(self::RUN_A, (string)$recorded['context']['displacedHolder']);
	}//end testAnAdministratorBreaksARunLockAndItIsAudited()

	/**
	 * The object's OWNER cannot unlock a run's lock.
	 *
	 * The owner and schema-manage routes exist for user locks. Extending them
	 * to run locks would let any case owner quietly defeat the engine's
	 * mutual exclusion, which is the entire point of a run lock. Their escape
	 * hatch is the administrator break above.
	 *
	 * @return void
	 */
	public function testTheObjectOwnerCannotUnlockARunsLock(): void {
		$this->resolvesTo($this->lockedObject('alice', self::RUN_A));

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-uid');
		$this->session->method('getUser')->willReturn($owner);
		$this->groups->method('isAdmin')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('does not have permission to unlock');
		$this->handler()->unlock(identifier: self::OBJ);
	}//end testTheObjectOwnerCannotUnlockARunsLock()

	/**
	 * The object's owner CAN still unlock a plain user lock: the historical
	 * behaviour is untouched for the case it was written for.
	 *
	 * @return void
	 */
	public function testTheObjectOwnerCanStillUnlockAUserLock(): void {
		$this->resolvesTo($this->lockedObject('alice', null));

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-uid');
		$this->session->method('getUser')->willReturn($owner);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->magic->method('unlockObjectEntity')->willReturn(new ObjectEntity());

		$this->assertTrue($this->handler()->unlock(identifier: self::OBJ));
	}//end testTheObjectOwnerCanStillUnlockAUserLock()
}//end class
