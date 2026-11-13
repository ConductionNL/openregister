<?php

/**
 * Failure and guard tests for RunLockRegistry.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRunStep;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bookkeeping failures never surface, and blank identifiers never reach the registry.
 *
 * @covers \OCA\OpenRegister\Service\Object\RunLockRegistry
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\RunObjectLock
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\LockRunStep
 * @uses \OCA\OpenRegister\Service\Object\ObjectChangeRecording
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class RunLockRegistryFailureTest extends TestCase {

	/**
	 * @var RunObjectLockMapperInterface&MockObject
	 */
	private RunObjectLockMapperInterface $registry;

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $magic;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registry = $this->createMock(RunObjectLockMapperInterface::class);
		$this->magic    = $this->createMock(MagicMapperInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The subject.
	 *
	 * @return RunLockRegistry
	 */
	private function locks(): RunLockRegistry {
		return new RunLockRegistry(registry: $this->registry, recording: new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->magic, $this->createMock(AuditTrailMapperInterface::class), $this->logger, $this->createStub(\OCP\EventDispatcher\IEventDispatcher::class)));
	}//end locks()

	/**
	 * A row for a run and object.
	 *
	 * @param string|null $run    The run uuid.
	 * @param string|null $object The object uuid.
	 *
	 * @return RunObjectLock
	 */
	private function row(?string $run, ?string $object): RunObjectLock {
		$row = new RunObjectLock();
		$row->setRunUuid($run);
		$row->setObjectUuid($object);
		return $row;
	}//end row()

	/**
	 * A lock without a run is not recorded.
	 *
	 * @return void
	 */
	public function testBlankRunIsNotRecorded(): void {
		$this->registry->expects($this->never())->method('record');

		$this->locks()->record(object: new ObjectEntity(), step: new LockRunStep('  ', 'n'));
		$this->locks()->record(object: new ObjectEntity(), step: new LockRunStep(null, 'n'));
	}//end testBlankRunIsNotRecorded()

	/**
	 * The recorded row carries the trimmed run, the object's pairing and the lock's expiry;
	 * a registry failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testRecordFailureIsLoggedNotThrown(): void {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setLocked(['expiration' => '2999-01-01T00:00:00+00:00']);
		$recorded = null;
		$this->registry->expects($this->once())->method('record')->willReturnCallback(
			static function (RunObjectLock $lock) use (&$recorded): void {
				$recorded = $lock;
				throw new RuntimeException('table missing');
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[RunLockRegistry] Could not record a run lock; the lock itself stands');

		$this->locks()->record(object: $object, step: new LockRunStep(' r-1 ', 'node-2'));

		$this->assertSame('r-1', $recorded->getRunUuid());
		$this->assertSame('o-1', $recorded->getObjectUuid());
		$this->assertSame('3', $recorded->getRegisterId());
		$this->assertSame('7', $recorded->getSchemaId());
		$this->assertSame('node-2', $recorded->getNodeId());
		$this->assertSame('2999-01-01', $recorded->getExpiresAt()->format('Y-m-d'));
	}//end testRecordFailureIsLoggedNotThrown()

	/**
	 * A lock without an expiration records no expiry.
	 *
	 * @return void
	 */
	public function testLockWithoutExpirationRecordsNoExpiry(): void {
		$recorded = null;
		$this->registry->method('record')->willReturnCallback(
			static function (RunObjectLock $lock) use (&$recorded): void {
				$recorded = $lock;
			}
		);

		$this->locks()->record(object: new ObjectEntity(), step: new LockRunStep('r-1', null));

		$this->assertNull($recorded->getExpiresAt());
	}//end testLockWithoutExpirationRecordsNoExpiry()

	/**
	 * A failing forget is logged at debug level only.
	 *
	 * @return void
	 */
	public function testForgetFailureIsSwallowed(): void {
		$this->registry->method('forget')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('debug')
			->with('[RunLockRegistry] Could not forget a run lock row', $this->callback(static fn (array $c): bool => $c['error'] === 'gone'));

		$this->locks()->forget(runUuid: 'r-1', objectUuid: 'o-1');
	}//end testForgetFailureIsSwallowed()

	/**
	 * Releasing a blank run touches nothing and releases nothing.
	 *
	 * @return void
	 */
	public function testBlankRunReleasesNothing(): void {
		$this->registry->expects($this->never())->method('findByRun');
		$this->registry->expects($this->never())->method('forgetRun');

		$this->assertSame(0, $this->locks()->releaseRunLocks(runUuid: ' '));
	}//end testBlankRunReleasesNothing()

	/**
	 * Rows with a blank run or object are not read, not released, but still forgotten by the sweep.
	 *
	 * @return void
	 */
	public function testSweepSkipsBlankRowsButForgetsThem(): void {
		$now = new DateTime('2026-01-01T00:00:00+00:00');
		$this->registry->method('findOrphaned')->with($now, 5)->willReturn([$this->row(run: '', object: 'o-1'), $this->row(run: 'r-1', object: null)]);
		$this->magic->expects($this->never())->method('findAcrossAllSources');
		$forgotten = [];
		$this->registry->method('forget')->willReturnCallback(
			static function (string $runUuid, string $objectUuid) use (&$forgotten): void {
				$forgotten[] = $runUuid . '|' . $objectUuid;
			}
		);

		$this->assertSame(0, $this->locks()->sweepOrphaned(now: $now, limit: 5));
		$this->assertSame(['|o-1', 'r-1|'], $forgotten);
	}//end testSweepSkipsBlankRowsButForgetsThem()

	/**
	 * An object that cannot be read is not released and the failure is logged.
	 *
	 * @return void
	 */
	public function testUnreadableObjectIsNotReleased(): void {
		$this->registry->method('findByRun')->with('r-1')->willReturn([$this->row(run: 'r-1', object: 'o-1')]);
		$this->registry->expects($this->once())->method('forgetRun')->with('r-1');
		$this->magic->method('findAcrossAllSources')->willThrowException(new RuntimeException('no table'));
		$this->magic->expects($this->never())->method('unlockObjectEntity');
		$this->logger->expects($this->once())->method('warning')->with('[RunLockRegistry] Could not release a run lock');

		$this->assertSame(0, $this->locks()->releaseRunLocks(runUuid: 'r-1'));
	}//end testUnreadableObjectIsNotReleased()
}//end class
