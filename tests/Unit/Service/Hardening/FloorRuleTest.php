<?php

/**
 * A hardening floor rule admits values at or above its floor, or at or below it for atMost.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\FloorRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\FloorRule
 */
class FloorRuleTest extends TestCase {

	/**
	 * Comparator, floor, value, expected.
	 *
	 * @return array<string, array{string, int, int, bool}>
	 */
	public static function cases(): array {
		return [
			'atLeast below' => ['atLeast', 10, 9, false],
			'atLeast equal' => ['atLeast', 10, 10, true],
			'atLeast above' => ['atLeast', 10, 11, true],
			'atMost below' => ['atMost', 10, 9, true],
			'atMost equal' => ['atMost', 10, 10, true],
			'atMost above' => ['atMost', 10, 11, false],
			'unknown comparator acts as atLeast' => ['sideways', 10, 9, false],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testAdmits(string $comparator, int $floor, int $value, bool $expected): void {
		$this->assertSame($expected, (new FloorRule(comparator: $comparator, floor: $floor))->admits($value));
	}//end testAdmits()
}//end class
