<?php

/**
 * Characterisation of SlaDeclaration::validateSla(): its outcome, refusal
 * message and the order the checks fire in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 */
final class SlaDeclarationCharacterisationTest extends TestCase {

	/**
	 * Declared SLAs and what validateSla() makes of them today.
	 *
	 * @return array<string, array{0: mixed, 1: array|string}> The SLA and the normalised shape or refusal message.
	 */
	public static function declarations(): array {
		$range = ' is refused: it must be an integer from 1 to 10000.';

		return [
			'not an array' => ['nope', 'An SLA must have the shape {value, unit}.'],
			'no unit' => [['value' => 2], 'An SLA must have the shape {value, unit}.'],
			'no value' => [['unit' => 'hours'], 'An SLA must have the shape {value, unit}.'],
			'null value is present' => [['value' => null, 'unit' => 'hours'], "SLA value 'NULL'" . $range],
			'zero-padded digits' => [['value' => '05', 'unit' => 'hours'], ['value' => 5, 'unit' => 'hours', 'rollToWorkingDay' => 'none']],
			'negative digits stay a string' => [['value' => '-1', 'unit' => 'weeks'], "SLA value ''-1''" . $range],
			'padded digits stay a string' => [['value' => ' 5', 'unit' => 'hours'], "SLA value '' 5''" . $range],
			'boolean' => [['value' => true, 'unit' => 'hours'], "SLA value 'true'" . $range],
			'value before unit' => [['value' => 0, 'unit' => 'weeks'], "SLA value '0'" . $range],
			'unit before roll' => [
				['value' => 3, 'unit' => 'weeks', 'rollToWorkingDay' => 'bogus'],
				"Unit ''weeks'' is refused: use one of hours, businessDays, calendarDays.",
			],
			'unknown roll' => [
				['value' => 3, 'unit' => 'hours', 'rollToWorkingDay' => 'bogus'],
				"rollToWorkingDay ''bogus'' is refused: use one of none, next, previous.",
			],
			'upper bound as digits' => [
				['value' => '10000', 'unit' => 'hours', 'rollToWorkingDay' => 'previous'],
				['value' => 10000, 'unit' => 'hours', 'rollToWorkingDay' => 'previous'],
			],
		];
	}//end declarations()

	/**
	 * Each declaration gives exactly today's outcome.
	 *
	 * @param mixed        $sla      The declared SLA.
	 * @param array|string $expected The normalised shape, or the refusal message.
	 *
	 * @return void
	 */
	#[DataProvider('declarations')]
	public function testTheOutcomeIsUnchanged(mixed $sla, array|string $expected): void {
		try {
			$actual = (new SlaDeclaration())->validateSla($sla);
		} catch (FlowTimerValidationException $refused) {
			$actual = $refused->getMessage();
		}

		$this->assertSame($expected, $actual);
	}//end testTheOutcomeIsUnchanged()
}//end class
