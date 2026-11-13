<?php

/**
 * A soft-delete stamp writes deletion metadata on every target with a uuid and keeps the pre-stamp copies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SoftDeleteStamp;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SoftDeleteStamp
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class SoftDeleteStampTest extends TestCase {

	/**
	 * An object with a uuid.
	 *
	 * @param string|null $uuid The uuid.
	 */
	private function entity(?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		return $entity;
	}//end entity()

	public function testStampsEachTargetAndReturnsHandledAndOldCopies(): void {
		$first  = $this->entity('u-1');
		$second = $this->entity('u-2');
		$stamp  = new SoftDeleteStamp(deletedBy: 'alice', organisation: 'org-9');

		$before = new DateTime();
		[$handled, $old] = $stamp->apply([$first, $second]);

		$this->assertSame(['u-1' => true, 'u-2' => true], $handled);
		$this->assertSame(['u-1', 'u-2'], array_keys($old));
		$this->assertNotSame($first, $old['u-1']);
		$this->assertSame([], $old['u-1']->getDeleted());

		$deleted = $first->getDeleted();
		$this->assertSame('alice', $deleted['deletedBy']);
		$this->assertSame('u-1', $deleted['objectId']);
		$this->assertSame('org-9', $deleted['organisation']);
		$at = DateTime::createFromFormat(DateTime::ATOM, $deleted['deletedAt']);
		$this->assertInstanceOf(DateTime::class, $at);
		$this->assertGreaterThanOrEqual($before->getTimestamp() - 1, $at->getTimestamp());
		$this->assertSame($deleted['deletedAt'], $second->getDeleted()['deletedAt']);
		$this->assertSame('u-2', $second->getDeleted()['objectId']);
	}//end testStampsEachTargetAndReturnsHandledAndOldCopies()

	public function testTargetsWithoutUuidAreSkipped(): void {
		$noUuid    = $this->entity(null);
		$emptyUuid = $this->entity('');
		$stamp     = new SoftDeleteStamp(deletedBy: 'bob', organisation: null);

		[$handled, $old] = $stamp->apply([$noUuid, $emptyUuid]);

		$this->assertSame([], $handled);
		$this->assertSame([], $old);
		$this->assertSame([], $noUuid->getDeleted());
		$this->assertSame([], $emptyUuid->getDeleted());
	}//end testTargetsWithoutUuidAreSkipped()
}//end class
