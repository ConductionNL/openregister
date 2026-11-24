<?php

/**
 * ObjectEntity::acquireLockAs(): the lock rules of acquireLock() for a named
 * owner, with no session.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectEntity::acquireLockAs().
 */
#[CoversClass(ObjectEntity::class)]
#[UsesClass(LockTerms::class)]
class ObjectEntityAcquireLockAsTest extends TestCase {

	public function testANamedOwnerTakesAndExtendsItsOwnLock(): void {
		$entity = new ObjectEntity();
		$entity->acquireLockAs(ownerId: 'job:sync', terms: new LockTerms(process: 'sync', duration: 60));
		$created = $entity->getLocked()['created'];

		$this->assertSame('job:sync', $entity->getLocked()['user']);
		$this->assertTrue($entity->isLocked());

		$entity->acquireLockAs(ownerId: 'job:sync', terms: new LockTerms(duration: 120));
		$this->assertSame($created, $entity->getLocked()['created']);
		$this->assertSame('sync', $entity->getLocked()['process'], 'an extension keeps the process');
	}//end testANamedOwnerTakesAndExtendsItsOwnLock()

	public function testAnotherOwnerIsRefusedWhileTheLockLives(): void {
		$entity = new ObjectEntity();
		$entity->acquireLockAs(ownerId: 'job:a', terms: new LockTerms(duration: 60));

		$this->expectExceptionMessage('Object is locked by');
		$entity->acquireLockAs(ownerId: 'job:b', terms: new LockTerms(duration: 60));
	}//end testAnotherOwnerIsRefusedWhileTheLockLives()

	public function testAnEmptyOwnerIsRefused(): void {
		$entity = new ObjectEntity();
		try {
			$entity->acquireLockAs(ownerId: ' ');
			$this->fail('A lock needs an owner.');
		} catch (Exception $e) {
			$this->assertSame('A lock needs an owner', $e->getMessage());
		}

		$this->assertNull($entity->getLocked());
	}//end testAnEmptyOwnerIsRefused()
}//end class
