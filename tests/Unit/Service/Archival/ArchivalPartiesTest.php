<?php

/**
 * Unit tests for ArchivalParties.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalParties;
use PHPUnit\Framework\TestCase;

/**
 * The parties a schema's archival block names, read off one row.
 *
 * @covers \OCA\OpenRegister\Service\Archival\ArchivalParties
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
 */
class ArchivalPartiesTest extends TestCase {

	/**
	 * A name, an object with a name and an id, and a list are all parties.
	 *
	 * @return void
	 */
	public function testEveryShapeOfAParty(): void {
		$row = [
			'aanvrager' => ['naam' => 'Gemeente Zuiddrecht', 'uuid' => 'org-1'],
			'gemachtigde' => '  J. de Vries ',
			'belanghebbenden' => [['name' => 'A. Bakker', 'id' => 7], 'B. Smit', ['zonder' => 'naam'], ['genest']],
		];

		$this->assertSame(
			[
				['role' => 'aanvrager', 'name' => 'Gemeente Zuiddrecht', 'id' => 'org-1'],
				['role' => 'gemachtigde', 'name' => 'J. de Vries'],
				['role' => 'belanghebbenden', 'name' => 'A. Bakker', 'id' => '7'],
				['role' => 'belanghebbenden', 'name' => 'B. Smit'],
			],
			ArchivalParties::forRow(['aanvrager', 'gemachtigde', 'belanghebbenden', 'aanvrager'], $row)
		);
	}//end testEveryShapeOfAParty()

	/**
	 * One property name is a declaration too.
	 *
	 * @return void
	 */
	public function testOnePropertyName(): void {
		$this->assertSame([['role' => 'aanvrager', 'name' => 'X']], ArchivalParties::forRow('aanvrager', ['aanvrager' => 'X']));
	}//end testOnePropertyName()

	/**
	 * Nothing declared, an empty or missing value, or no name is no party.
	 *
	 * @return void
	 */
	public function testNoPartyIsNull(): void {
		$this->assertNull(ArchivalParties::forRow(null, ['aanvrager' => 'X']));
		$this->assertNull(ArchivalParties::forRow(42, ['aanvrager' => 'X']));
		$this->assertNull(ArchivalParties::forRow(['', 7], ['aanvrager' => 'X']));
		$this->assertNull(ArchivalParties::forRow('aanvrager', ['aanvrager' => '   ']));
		$this->assertNull(ArchivalParties::forRow('aanvrager', []));
		$this->assertNull(ArchivalParties::forRow('aanvrager', ['aanvrager' => ['id' => 'org-1', 'naam' => '']]));
		$this->assertNull(ArchivalParties::forRow('aanvrager', ['aanvrager' => 12]));
	}//end testNoPartyIsNull()
}//end class
