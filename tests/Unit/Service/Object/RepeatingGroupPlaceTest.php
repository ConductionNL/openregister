<?php

/**
 * A repeating group's place: row and member positions, bounds, members and violation shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RepeatingGroupPlace;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\RepeatingGroupPlace
 */
class RepeatingGroupPlaceTest extends TestCase {

	/**
	 * A group definition with numeric and non-numeric bounds and an items schema.
	 *
	 * @return array<string, mixed>
	 */
	private function definition(): array {
		return [
			'minItems' => '2',
			'maxItems' => 5,
			'label' => 'not-a-number',
			'items' => [
				'properties' => ['street' => ['type' => 'string'], 'number' => ['type' => 'integer']],
				'required' => ['street'],
			],
		];
	}//end definition()

	public function testAtRowAndAtMemberReturnNewPlacesAndLeaveTheOriginalUntouched(): void {
		$place = new RepeatingGroupPlace(name: 'addresses', definition: $this->definition());

		$row    = $place->atRow(3);
		$member = $row->atMember('street');

		$this->assertNotSame($place, $row);
		$this->assertNotSame($row, $member);
		$this->assertNull($place->position());
		$this->assertSame(3, $row->position());
		$this->assertSame(3, $member->position());
		$this->assertSame(
			['property' => 'addresses', 'position' => 3, 'member' => null, 'code' => 'c', 'message' => 'm'],
			$row->violation('c', 'm')
		);
		$this->assertSame(
			['property' => 'addresses', 'position' => 3, 'member' => 'street', 'code' => 'required', 'message' => 'Street is required'],
			$member->violation('required', 'Street is required')
		);
	}//end testAtRowAndAtMemberReturnNewPlacesAndLeaveTheOriginalUntouched()

	public function testBoundCastsNumericValuesAndRejectsMissingOrNonNumeric(): void {
		$place = new RepeatingGroupPlace(name: 'addresses', definition: $this->definition());

		$this->assertSame(2, $place->bound('minItems'));
		$this->assertSame(5, $place->bound('maxItems'));
		$this->assertNull($place->bound('label'));
		$this->assertNull($place->bound('absent'));
	}//end testBoundCastsNumericValuesAndRejectsMissingOrNonNumeric()

	public function testMembersAndRequiredComeFromTheItemsSchema(): void {
		$place = new RepeatingGroupPlace(name: 'addresses', definition: $this->definition());

		$this->assertSame(['street' => ['type' => 'string'], 'number' => ['type' => 'integer']], $place->members());
		$this->assertSame(['street'], $place->required());
	}//end testMembersAndRequiredComeFromTheItemsSchema()

	public function testMembersAndRequiredAreEmptyWhenItemsAreMissingOrMalformed(): void {
		$noItems   = new RepeatingGroupPlace(name: 'g', definition: []);
		$malformed = new RepeatingGroupPlace(name: 'g', definition: ['items' => ['properties' => 'street', 'required' => true]]);

		$this->assertSame([], $noItems->members());
		$this->assertSame([], $noItems->required());
		$this->assertSame([], $malformed->members());
		$this->assertSame([], $malformed->required());
	}//end testMembersAndRequiredAreEmptyWhenItemsAreMissingOrMalformed()
}//end class
