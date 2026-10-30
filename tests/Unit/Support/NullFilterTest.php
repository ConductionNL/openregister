<?php

/**
 * The list endpoint's "empty" and "filled" spellings, read once for every path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Support;

use OCA\OpenRegister\Support\NullFilter;
use PHPUnit\Framework\TestCase;

class NullFilterTest extends TestCase {

	public function testTheScalarSpellingsAskForEmptyAndFilled(): void {
		$this->assertTrue(NullFilter::nullCheck(criterion: 'IS NULL'));
		$this->assertFalse(NullFilter::nullCheck(criterion: 'IS NOT NULL'));
	}

	public function testTheOperatorReadsItsValueAsAQueryStringBoolean(): void {
		$this->assertTrue(NullFilter::nullCheck(criterion: ['isnull' => 'true']));
		$this->assertTrue(NullFilter::nullCheck(criterion: ['isnull' => '1']));
		$this->assertFalse(NullFilter::nullCheck(criterion: ['isnull' => 'false']));
		$this->assertFalse(NullFilter::nullCheck(criterion: ['isnull' => '']));
	}

	public function testAnyOtherCriterionSaysNothingAboutEmptiness(): void {
		$this->assertNull(NullFilter::nullCheck(criterion: 'open'));
		$this->assertNull(NullFilter::nullCheck(criterion: ['in' => ['a']]));
		$this->assertNull(NullFilter::nullCheck(criterion: ['isnull' => 'true', 'ne' => 'x']));
	}

	public function testOrNullIsTakenOffTheBagAndReadAsAQueryStringBoolean(): void {
		$this->assertSame([['ne' => 'true'], true], NullFilter::splitOrNull(criterion: ['ne' => 'true', 'orNull' => 'true']));
		$this->assertSame([['ne' => 'true'], true], NullFilter::splitOrNull(criterion: ['ne' => 'true', 'orNull' => true]));
		$this->assertSame([['ne' => 'true'], false], NullFilter::splitOrNull(criterion: ['ne' => 'true', 'orNull' => 'false']));
	}

	public function testACriterionWithoutOrNullComesBackUnchanged(): void {
		$this->assertSame(['open', false], NullFilter::splitOrNull(criterion: 'open'));
		$this->assertSame([['ne' => 'x'], false], NullFilter::splitOrNull(criterion: ['ne' => 'x']));
	}

	public function testAdmitNullOrsTheAndedConditionsWithIsNull(): void {
		$this->assertSame(
			['(((a > 1) AND (a < 5)) OR a IS NULL)'],
			NullFilter::admitNull(conditions: ['a > 1', 'a < 5'], column: 'a', orNull: true)
		);
	}

	public function testAdmitNullLeavesTheConditionsAloneWhenNotAsked(): void {
		$this->assertSame(['a <> 1'], NullFilter::admitNull(conditions: ['a <> 1'], column: 'a', orNull: false));
		$this->assertSame([], NullFilter::admitNull(conditions: [], column: 'a', orNull: true));
	}
}
