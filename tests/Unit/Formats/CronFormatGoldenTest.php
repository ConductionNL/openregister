<?php

/**
 * Golden pin for CronFormat::validate().
 *
 * Records the verdict the validator gave for a broad table of accepted and
 * refused expressions before its methods were split, so the refactor can be
 * shown to leave every verdict unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\CronFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every CronFormat verdict recorded on the pre-refactor code.
 */
class CronFormatGoldenTest extends TestCase {
	/**
	 * The recorded verdicts.
	 *
	 * @return array<string, array{mixed, bool}> The cases.
	 */
	public static function recordedVerdicts(): array {
		return [
			'expression 01' => ['* * * * *', true],
			'expression 02' => ['0 9 * * 1', true],
			'expression 03' => ['*/15 * * * *', true],
			'expression 04' => ['0 0,12 * * *', true],
			'expression 05' => ['0 9-17 * * 1-5', true],
			'expression 06' => ['0-30/5 * * * *', true],
			'expression 07' => ['  0 9 * * 1  ', true],
			'expression 08' => ["0\t9\n* * 1", true],
			'expression 09' => ['', false],
			'expression 10' => ['* * * *', false],
			'expression 11' => ['* * * * * *', false],
			'expression 12' => ['60 * * * *', false],
			'expression 13' => ['59 23 31 12 7', true],
			'expression 14' => ['0 24 * * *', false],
			'expression 15' => ['0 0 0 * *', false],
			'expression 16' => ['0 0 32 * *', false],
			'expression 17' => ['0 0 * 0 *', false],
			'expression 18' => ['0 0 * 13 *', false],
			'expression 19' => ['0 0 * * 8', false],
			'expression 20' => ['*/0 * * * *', false],
			'expression 21' => ['*/ * * * *', false],
			'expression 22' => ['/5 * * * *', false],
			'expression 23' => ['*/5/2 * * * *', false],
			'expression 24' => ['1,,2 * * * *', false],
			'expression 25' => ['1, * * * *', false],
			'expression 26' => [',1 * * * *', false],
			'expression 27' => ['1,99 * * * *', false],
			'expression 28' => ['5-3 * * * *', false],
			'expression 29' => ['3-3 * * * *', true],
			'expression 30' => ['1-2-3 * * * *', false],
			'expression 31' => ['-1 * * * *', false],
			'expression 32' => ['1- * * * *', false],
			'expression 33' => ['a * * * *', false],
			'expression 34' => ['@daily', false],
			'expression 35' => ['*/x * * * *', false],
			'expression 36' => ['1-5/2 * * * *', true],
			'expression 37' => ['*-5 * * * *', false],
			'expression 38' => ['01 * * * *', true],
			'expression 39' => ['0x1 * * * *', false],
			'expression 40' => ['+1 * * * *', false],
			'expression 41' => ['1.5 * * * *', false],
			'expression 42' => ['*/007 * * * *', true],
			'expression 43' => ['0-59 0-23 1-31 1-12 0-7', true],
			'expression 44' => ['** * * * *', false],
			'expression 45' => ['*,1 * * * *', true],
			'expression 46' => ['1,* * * * *', true],
			'expression 47' => ['0 0 1-0 * *', false],
			'non-string 0' => [null, false],
			'non-string 1' => [5, false],
			'non-string 2' => [1.5, false],
			'non-string 3' => [true, false],
			'non-string 4' => [['* * * * *'], false],
		];
	}//end recordedVerdicts()

	/**
	 * Each input still gets the verdict recorded before the refactor.
	 *
	 * @param mixed $input    The value to validate.
	 * @param bool  $expected The recorded verdict.
	 *
	 * @return void
	 */
	#[DataProvider('recordedVerdicts')]
	public function testVerdictMatchesRecording(mixed $input, bool $expected): void {
		$this->assertSame($expected, (new CronFormat())->validate($input));
	}//end testVerdictMatchesRecording()
}//end class
