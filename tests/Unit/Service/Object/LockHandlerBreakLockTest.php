<?php

/**
 * Unit tests for LockHandler::breakLock (run-scoped-object-locking task 7.5).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockStores;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Breaking a lock is the administrator escape hatch for a wedged run: a run
 * lock refuses everyone by design, so only `mayBreak()` — an administrator —
 * passes, the break displaces whatever holds the lock, and it is audited under
 * its own `lock.broken` action so an override is never invisible.
 *
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 * @uses \OCA\OpenRegister\Service\Object\LockAuthorization
 * @uses \OCA\OpenRegister\Service\Object\LockStores
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses \OCA\OpenRegister\Db\LockTerms
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-an-administrator-may-break-a-lock-and-the-break-is-recorded
 */
final class LockHandlerBreakLockTest extends TestCase {

	private const RUN = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	/** @var MagicMapper&MockObject */
	private MagicMapper $magic;

	/** @var AuditTrailMapper&MockObject */
	private AuditTrailMapper $audit;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(MagicMapper::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
	}//end setUp()

	private function handler(): LockHandler {
		return new LockHandler(
			new LockStores(
				magicMapper: $this->magic,
				auditTrailMapper: $this->audit,
				advisory: $this->createMock(AdvisoryLockStore::class),
				runLocks: $this->createMock(RunLockRegistry::class)
			),
			$this->createMock(LoggerInterface::class),
			new LockAuthorization($this->session, $this->groups, $this->createMock(SchemaMapper::class), $this->createMock(PermissionHandlerInterface::class))
		);
	}//end handler()

	private function callerIsAdmin(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ops');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with('ops')->willReturn($admin);
	}//end callerIsAdmin()

	private function runLockedObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('owner-uid');

		$holder = $this->createMock(IUser::class);
		$holder->method('getUID')->willReturn('run-user');
		$holderSession = $this->createMock(IUserSession::class);
		$holderSession->method('getUser')->willReturn($holder);
		$object->lock($holderSession, 'step-one', 3600, self::RUN);

		return $object;
	}//end runLockedObject()

	private function unlockedObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');

		return $object;
	}//end unlockedObject()

	private function resolvesTo(ObjectEntity $object): void {
		$this->magic->method('findAcrossAllSources')->willReturn(
			[
				'object' => $object,
				'register' => $this->createMock(Register::class),
				'schema' => $this->createMock(Schema::class),
			]
		);
	}//end resolvesTo()

	public function testANonAdminCannotBreakALock(): void {
		$this->callerIsAdmin(false);
		$this->magic->expects($this->never())->method('unlockObjectEntity');
		$this->audit->expects($this->never())->method('recordObjectEvent');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Only an administrator may break a lock');

		$this->handler()->breakLock(self::OBJ);
	}//end testANonAdminCannotBreakALock()

	public function testAnAdminBreaksARunHeldLockAndItIsAudited(): void {
		$this->callerIsAdmin(true);
		$this->resolvesTo($this->runLockedObject());
		$this->magic->expects($this->once())
			->method('unlockObjectEntity')
			->with($this->anything(), $this->anything(), $this->anything(), null, true)
			->willReturn($this->unlockedObject());

		$recorded = [];
		$this->audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use (&$recorded): AuditTrail {
				$recorded[] = $event;
				return new AuditTrail();
			}
		);

		$broke = $this->handler()->breakLock(self::OBJ);

		$this->assertTrue($broke);
		$actions = array_map(static fn (ObjectAuditEvent $e): string => $e->action, $recorded);
		$this->assertContains('lock.broken', $actions, 'the break was not audited under lock.broken');

		$broken = null;
		foreach ($recorded as $event) {
			if ($event->action === 'lock.broken') {
				$broken = $event;
			}
		}

		$this->assertSame(self::RUN, $broken->context['displacedRun'] ?? null, 'the audit does not name the displaced run');
	}//end testAnAdminBreaksARunHeldLockAndItIsAudited()

	public function testBreakingWhenThereIsNoLockReturnsFalseAndDoesNotAudit(): void {
		$this->callerIsAdmin(true);
		$this->resolvesTo($this->unlockedObject());
		$this->magic->expects($this->never())->method('unlockObjectEntity');
		$this->audit->expects($this->never())->method('recordObjectEvent');

		$this->assertFalse($this->handler()->breakLock(self::OBJ));
	}//end testBreakingWhenThereIsNoLockReturnsFalseAndDoesNotAudit()
}//end class
