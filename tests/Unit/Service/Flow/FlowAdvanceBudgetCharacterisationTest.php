<?php

/**
 * Characterisation of FlowAdvanceBudget::fromValue() across value shapes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 */
final class FlowAdvanceBudgetCharacterisationTest extends TestCase {

	/**
	 * Values and the stored budget they read as, or the refusal message.
	 *
	 * @return array<string, array{0: mixed, 1: int|string}> The value and the outcome.
	 */
	public static function values(): array {
		$refused = static fn (string $shown): string => 'REFUSED:advance ' . $shown . ' is not a budget. '
			. 'Use 0 to park the run for the worker, a positive number of transitions, or "all".';

		return [
			'zero' => [0, 0],
			'int' => [3, 3],
			'digit string' => ['3', 3],
			'whole float' => [3.0, 3],
			'whole float string' => ['3.0', 3],
			'exponent string' => ['1e2', 100],
			'negative zero string' => ['-0', 0],
			'leading space' => [' 3', 3],
			'trailing space' => ['3 ', 3],
			'all' => ['all', 'all'],
			'all padded upper' => [' ALL ', 'all'],
			'all mixed case' => ['All', 'all'],
			'fraction' => [3.5, $refused('3.5')],
			'negative' => [-1, $refused('-1')],
			'true' => [true, $refused('true')],
			'false' => [false, $refused('false')],
			'hex string' => ['0x1A', $refused("'0x1A'")],
			'word' => ['none', $refused("'none'")],
			'null' => [
				null,
				'REFUSED:advance is null. A null budget reads as 0 at every PHP and JSON coercion, so it is refused: '
				. 'unlimited is spelled "all", the default is 0, and any other value is a positive number of transitions.',
			],
		];
	}//end values()

	/**
	 * Each value reads exactly as it does today.
	 *
	 * @param mixed      $value    The configured value.
	 * @param int|string $expected The stored budget, or 'REFUSED:' and the message.
	 *
	 * @return void
	 */
	#[DataProvider('values')]
	public function testTheReadingIsUnchanged(mixed $value, int|string $expected): void {
		try {
			$actual = FlowAdvanceBudget::fromValue(value: $value)->toStored();
		} catch (UnexpectedValueException $refused) {
			$actual = 'REFUSED:' . $refused->getMessage();
		}

		$this->assertSame($expected, $actual);
	}//end testTheReadingIsUnchanged()
}//end class
