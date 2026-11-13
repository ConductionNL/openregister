<?php

/**
 * An ownership handover carries only to unvisited children the previous owner held.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\OwnershipHandover;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\OwnershipHandover
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class OwnershipHandoverTest extends TestCase {

	private function child(?string $uuid, ?string $owner): ObjectEntity {
		$child = new ObjectEntity();
		if ($uuid !== null) {
			$child->setUuid($uuid);
		}

		$child->setOwner($owner);

		return $child;
	}

	public function testAChildOfThePreviousOwnerMoves(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: true);

		$this->assertTrue($handover->carries($this->child('c-1', 'alice'), 'alice'));
		$this->assertSame('bob', $handover->newOwner);
		$this->assertTrue($handover->cascade);
	}

	public function testAChildSomeoneElseOwnsStays(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: true);

		$this->assertFalse($handover->carries($this->child('c-1', 'carol'), 'alice'));
	}

	public function testAnUnownedChildMovesOnlyFromAnUnownedParent(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: true);

		$this->assertTrue($handover->carries($this->child('c-1', null), null));
		$this->assertFalse($handover->carries($this->child('c-2', null), 'alice'));
	}

	public function testAVisitedChildIsNotCarriedTwice(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: true);
		$handover->visit('c-1');

		$this->assertFalse($handover->carries($this->child('c-1', 'alice'), 'alice'));
		$this->assertTrue($handover->carries($this->child('c-2', 'alice'), 'alice'));
	}

	public function testAChildWithoutAUuidIsNeverCarried(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: false);

		$this->assertFalse($handover->carries($this->child(null, 'alice'), 'alice'));
	}
}
