<?php

/**
 * Unit test for ExampleSetFieldRules: the field checks the rows of an example set share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRowShape;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules
 */
class ExampleSetFieldRulesTest extends TestCase {
	/**
	 * The first check that refuses wins, and later checks do not run.
	 *
	 * @return void
	 */
	public function testFirstRefusalStopsAtTheFirstThatRefuses(): void {
		$ran    = [];
		$checks = [
			function () use (&$ran): string {
				$ran[] = 'a';
				return '';
			},
			function () use (&$ran): string {
				$ran[] = 'b';
				return 'b refuses';
			},
			function () use (&$ran): string {
				$ran[] = 'c';
				return 'c refuses';
			},
		];
		$this->assertSame('b refuses', (new ExampleSetFieldRules())->firstRefusal(checks: $checks));
		$this->assertSame(['a', 'b'], $ran);
		$this->assertSame('', (new ExampleSetFieldRules())->firstRefusal(checks: []));
	}//end testFirstRefusalStopsAtTheFirstThatRefuses()

	/**
	 * Unknown fields are named, in the row's order.
	 *
	 * @return void
	 */
	public function testUnknownRefusal(): void {
		$rules = new ExampleSetFieldRules();
		$this->assertSame(
			'field "b", "d" is not a widget field a set may write',
			$rules->unknownRefusal(data: ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], shape: new ExampleSetRowShape(row: 'a widget', fields: ['a', 'c']))
		);
		$this->assertSame('', $rules->unknownRefusal(data: ['a' => 1], shape: new ExampleSetRowShape(row: 'a widget', fields: ['a', 'c'])));
	}//end testUnknownRefusal()

	/**
	 * The first needed field that is missing, blank or not text is named.
	 *
	 * @return void
	 */
	public function testMissingRefusal(): void {
		$rules    = new ExampleSetFieldRules();
		$required = ['name' => 'a name', 'owner' => 'an owner'];
		$this->assertSame('a widget needs a name (name)', $rules->missingRefusal(data: [], shape: new ExampleSetRowShape(row: 'a widget', required: $required)));
		$this->assertSame('a widget needs a name (name)', $rules->missingRefusal(data: ['name' => 3], shape: new ExampleSetRowShape(row: 'a widget', required: $required)));
		$blank = $rules->missingRefusal(data: ['name' => 'n', 'owner' => ' '], shape: new ExampleSetRowShape(row: 'a widget', required: $required));
		$this->assertSame('a widget needs an owner (owner)', $blank);
		$this->assertSame('', $rules->missingRefusal(data: ['name' => 'n', 'owner' => 'o'], shape: new ExampleSetRowShape(row: 'a widget', required: $required)));
	}//end testMissingRefusal()

	/**
	 * The first account the set neither creates nor adopts is named.
	 *
	 * @return void
	 */
	public function testStrangerRefusal(): void {
		$rules = new ExampleSetFieldRules();
		$this->assertSame(
			'"eve" is not an account the set creates or adopts',
			$rules->strangerRefusal(people: ['alice', 'eve', 'mallory'], users: ['alice', 'bob'])
		);
		$this->assertSame('', $rules->strangerRefusal(people: ['bob'], users: ['alice', 'bob']));
	}//end testStrangerRefusal()

	/**
	 * An optional date field set to something that is not a date is named; absent and null are fine.
	 *
	 * @return void
	 */
	public function testDatesRefusal(): void {
		$rules = new ExampleSetFieldRules();
		$this->assertSame('due "soon" is not a date', $rules->datesRefusal(data: ['due' => 'soon'], fields: ['start', 'due']));
		$this->assertSame('', $rules->datesRefusal(data: ['start' => '2026-01-01', 'due' => null], fields: ['start', 'due']));
	}//end testDatesRefusal()
}//end class
