<?php

/**
 * Release layers 1 and 2: the run-lock registry.
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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\RunLockRegistry
 */
final class RunLockRegistryTest extends TestCase {

	private const RUN_A = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const RUN_B = 'run-bbbbbbbb-0000-0000-0000-000000000002';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private MagicMapper $magic;

	private AuditTrailMapper $audit;

	private RunObjectLockMapper $rows;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(originalClassName: MagicMapper::class);
		$this->audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$this->rows = $this->createMock(originalClassName: RunObjectLockMapper::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RunLockRegistry The service.
	 */
	private function registry(): RunLockRegistry {
		return new RunLockRegistry(
			$this->rows,
			$this->magic,
			$this->audit,
			$this->createMock(LoggerInterface::class)
		);
	}//end registry()

	/**
	 * An object carrying a live lock written by the PRODUCTION writer.
	 *
	 * @param string|null $runUuid The holding run, or null for a user lock.
	 *
	 * @return ObjectEntity The locked object.
	 */
	private function lockedObject(?string $runUuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);

		$holder = $this->createMock(IUser::class);
		$holder->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($holder);
		$object->lock($session, 'step-one', 3600, $runUuid);

		return $object;
	}//end lockedObject()

	/**
	 * Resolve every lookup to this object.
	 *
	 * @param ObjectEntity $object The object.
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
	// Layer 1.
	// ---------------------------------------------------------------

	/**
	 * A terminal run's lock is released and its rows forgotten.
	 *
	 * @return void
	 */
	public function testReleaseRunLocksReleasesWhatTheRunHolds(): void {
		$this->resolvesTo($this->lockedObject(self::RUN_A));
		$this->rows->method('findByRun')->with(self::RUN_A)->willReturn([$this->row(self::RUN_A)]);
		$this->rows->expects($this->once())->method('forgetRun')->with(self::RUN_A);
		$this->magic->expects($this->once())->method('unlockObjectEntity')->willReturn(new ObjectEntity());

		$this->assertSame(1, $this->registry()->releaseRunLocks(runUuid: self::RUN_A));
	}//end testReleaseRunLocksReleasesWhatTheRunHolds()

	/**
	 * A stale row MUST NOT strip a lock a different run has since taken.
	 *
	 * The registry is bookkeeping, so it is checked against the object rather
	 * than trusted. Without that check the release would be a licence to
	 * unlock anything whose uuid appears in a stale row.
	 *
	 * @return void
	 */
	public function testAStaleRowDoesNotStripAnotherRunsLock(): void {
		$this->resolvesTo($this->lockedObject(self::RUN_B));
		$this->rows->method('findByRun')->willReturn([$this->row(self::RUN_A)]);
		$this->magic->expects($this->never())->method('unlockObjectEntity');

		$this->assertSame(0, $this->registry()->releaseRunLocks(runUuid: self::RUN_A));
	}//end testAStaleRowDoesNotStripAnotherRunsLock()

	/**
	 * A row pointing at a USER lock is not released either.
	 *
	 * @return void
	 */
	public function testAUserLockIsNeverReleasedByTheRunPath(): void {
		$this->resolvesTo($this->lockedObject(null));
		$this->rows->method('findByRun')->willReturn([$this->row(self::RUN_A)]);
		$this->magic->expects($this->never())->method('unlockObjectEntity');

		$this->assertSame(0, $this->registry()->releaseRunLocks(runUuid: self::RUN_A));
	}//end testAUserLockIsNeverReleasedByTheRunPath()

	/**
	 * A release that fails MUST NOT propagate.
	 *
	 * Layer 1 runs inside the run's own terminal write transaction, so a
	 * throw would unwind the run's status change.
	 *
	 * @return void
	 */
	public function testAFailedReleaseIsSwallowed(): void {
		$this->magic->method('findAcrossAllSources')->willThrowException(new RuntimeException('gone'));
		$this->rows->method('findByRun')->willReturn([$this->row(self::RUN_A)]);

		$this->assertSame(0, $this->registry()->releaseRunLocks(runUuid: self::RUN_A));
	}//end testAFailedReleaseIsSwallowed()

	// ---------------------------------------------------------------
	// Layer 2: the sweep. The killed-worker case.
	// ---------------------------------------------------------------

	/**
	 * The holder is gone, so the sweep releases the lock.
	 *
	 * This is the worker-killed-mid-flight case: nothing ran a release, and
	 * `findOrphaned()` is what notices. It matches a run that is not ACTIVE,
	 * which covers both "terminal" and "the row no longer exists".
	 *
	 * @return void
	 */
	public function testTheSweepReleasesALockWhoseHolderIsGone(): void {
		$this->resolvesTo($this->lockedObject(self::RUN_A));
		$this->rows->method('findOrphaned')->willReturn([$this->row(self::RUN_A)]);
		$this->rows->expects($this->once())->method('forget')->with(self::RUN_A, self::OBJ);
		$this->magic->expects($this->once())->method('unlockObjectEntity')->willReturn(new ObjectEntity());

		$this->assertSame(1, $this->registry()->sweepOrphaned(now: new DateTime()));
	}//end testTheSweepReleasesALockWhoseHolderIsGone()

	/**
	 * A live run's lock is never offered to the sweep, and survives.
	 *
	 * @return void
	 */
	public function testTheSweepLeavesALiveRunsLockAlone(): void {
		$this->rows->method('findOrphaned')->willReturn([]);
		$this->magic->expects($this->never())->method('unlockObjectEntity');

		$this->assertSame(0, $this->registry()->sweepOrphaned(now: new DateTime()));
	}//end testTheSweepLeavesALiveRunsLockAlone()

	// ---------------------------------------------------------------
	// Recording.
	// ---------------------------------------------------------------

	/**
	 * A user lock is not recorded: the registry is for run locks.
	 *
	 * @return void
	 */
	public function testAUserLockIsNotRecorded(): void {
		$this->rows->expects($this->never())->method('record');
		$this->registry()->record($this->lockedObject(null), null, 'lock-1');
	}//end testAUserLockIsNotRecorded()

	/**
	 * A run lock is recorded with the object's identity and its expiry.
	 *
	 * @return void
	 */
	public function testARunLockIsRecordedWithItsExpiry(): void {
		$captured = null;
		$this->rows->method('record')->willReturnCallback(
			function (RunObjectLock $row) use (&$captured): void {
				$captured = $row;
			}
		);

		$this->registry()->record($this->lockedObject(self::RUN_A), self::RUN_A, 'lock-1');

		$this->assertSame(self::RUN_A, $captured->getRunUuid());
		$this->assertSame(self::OBJ, $captured->getObjectUuid());
		$this->assertSame('lock-1', $captured->getNodeId());
		$this->assertGreaterThan(new DateTime(), $captured->getExpiresAt());
	}//end testARunLockIsRecordedWithItsExpiry()

	/**
	 * A registry write that fails MUST NOT undo a lock that was taken.
	 *
	 * Failing the lock because its index entry did not land would trade a
	 * working lock for no lock at all.
	 *
	 * @return void
	 */
	public function testAFailedRecordDoesNotUndoTheLock(): void {
		$this->rows->method('record')->willThrowException(new RuntimeException('table missing'));

		$this->registry()->record($this->lockedObject(self::RUN_A), self::RUN_A, 'lock-1');
		$this->addToAssertionCount(1);
	}//end testAFailedRecordDoesNotUndoTheLock()
}//end class
