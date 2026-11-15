<?php

/**
 * Unit tests for ArchivalContainment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalContainment;
use PHPUnit\Framework\TestCase;

/**
 * The parts a schema's declared containment relation holds, read off one row.
 *
 * @covers \OCA\OpenRegister\Service\Archival\ArchivalContainment
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
 */
class ArchivalContainmentTest extends TestCase {

	/**
	 * An id, an object with an id (and a name when it has one) and a list are all parts.
	 *
	 * @return void
	 */
	public function testEveryShapeOfAPart(): void {
		$row = [
			'onderdelen' => [
				' child-1 ',
				['uuid' => 'child-2', 'naam' => 'Bijlage B'],
				['id' => 3, 'title' => 'Bijlage C'],
				['zonder' => 'id'],
				['genest'],
				'',
				42,
			],
		];

		$this->assertSame(
			[
				'property' => 'onderdelen',
				'parts' => [
					['id' => 'child-1'],
					['id' => 'child-2', 'name' => 'Bijlage B'],
					['id' => '3', 'name' => 'Bijlage C'],
				],
			],
			ArchivalContainment::forRow(declared: ' onderdelen ', row: $row)
		);
	}//end testEveryShapeOfAPart()

	/**
	 * A single reference, string or object, is one part; a repeated one is listed once.
	 *
	 * @return void
	 */
	public function testASingleReferenceIsOnePartAndRepeatsAreListedOnce(): void {
		$this->assertSame(['property' => 'deel', 'parts' => [['id' => 'child-1']]], ArchivalContainment::forRow(declared: 'deel', row: ['deel' => 'child-1']));
		$this->assertSame(['property' => 'deel', 'parts' => [['id' => 'child-1', 'name' => 'Een']]], ArchivalContainment::forRow(declared: 'deel', row: ['deel' => ['id' => 'child-1', 'name' => 'Een']]));
		$this->assertSame(
			['property' => 'deel', 'parts' => [['id' => 'child-1']]],
			ArchivalContainment::forRow(declared: 'deel', row: ['deel' => ['child-1', 'child-1']])
		);
	}//end testASingleReferenceIsOnePartAndRepeatsAreListedOnce()

	/**
	 * A declared relation the row leaves empty is a fact with no parts.
	 *
	 * @return void
	 */
	public function testADeclaredRelationWithoutPartsHasNoParts(): void {
		$this->assertSame(['property' => 'onderdelen', 'parts' => []], ArchivalContainment::forRow(declared: 'onderdelen', row: []));
		$this->assertSame(['property' => 'onderdelen', 'parts' => []], ArchivalContainment::forRow(declared: 'onderdelen', row: ['onderdelen' => ['id' => '']]));
	}//end testADeclaredRelationWithoutPartsHasNoParts()

	/**
	 * Nothing declared, or a declaration that is not a property name, is no fact at all.
	 *
	 * @return void
	 */
	public function testNoDeclarationIsNoFact(): void {
		$row = ['onderdelen' => ['child-1']];

		$this->assertNull(ArchivalContainment::forRow(declared: null, row: $row));
		$this->assertNull(ArchivalContainment::forRow(declared: '  ', row: $row));
		$this->assertNull(ArchivalContainment::forRow(declared: ['onderdelen'], row: $row));
	}//end testNoDeclarationIsNoFact()
}//end class
