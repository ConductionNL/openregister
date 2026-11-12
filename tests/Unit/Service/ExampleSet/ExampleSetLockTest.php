<?php

/**
 * The lock that keeps two installs of one example set apart.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetLock;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * One exclusive lock per set path; a held lock refuses the second run without throwing.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 */
final class ExampleSetLockTest extends TestCase {

	/**
	 * Holding takes the set's exclusive lock and release frees that same lock.
	 *
	 * @return void
	 */
	public function testHoldAndReleaseUseTheSetsExclusiveLock(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')->with('openregister/example-set/demo', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock')->with('openregister/example-set/demo', ILockingProvider::LOCK_EXCLUSIVE);
		$lock = new ExampleSetLock(locks: $locks);

		$this->assertTrue($lock->hold(set: 'demo'));
		$lock->release(set: 'demo');
	}//end testHoldAndReleaseUseTheSetsExclusiveLock()

	/**
	 * A set already locked is refused, and the refusal names the set.
	 *
	 * @return void
	 */
	public function testALockedSetIsRefused(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('openregister/example-set/demo'));
		$lock = new ExampleSetLock(locks: $locks);

		$this->assertFalse($lock->hold(set: 'demo'));
		$this->assertStringStartsWith('another install or removal of set "demo" is running', $lock->busy(set: 'demo'));
	}//end testALockedSetIsRefused()
}//end class
