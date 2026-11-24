<?php

/**
 * A CSV field that would start a spreadsheet formula gets a leading apostrophe; everything else passes unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\CsvCell;
use OCA\OpenRegister\Service\Export\SpreadsheetCell;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\CsvCell
 * @uses   \OCA\OpenRegister\Service\Export\SpreadsheetCell
 */
class CsvCellTest extends TestCase {

	/**
	 * Every formula lead is neutralised; numbers, plain text, empty strings and non-strings are not.
	 *
	 * @return void
	 */
	public function testAFormulaLeadGetsAnApostrophe(): void {
		$this->assertSame(
			expected: ["'=1", "'+a b", "'-x", "'@SUM(A1)", "'\t=1", "'\r=1", '-3', 'Jansen', '', 7, null, true],
			actual: CsvCell::row(row: ['=1', '+a b', '-x', '@SUM(A1)', "\t=1", "\r=1", '-3', 'Jansen', '', 7, null, true])
		);
	}//end testAFormulaLeadGetsAnApostrophe()

	/**
	 * The row keeps its keys.
	 *
	 * @return void
	 */
	public function testARowKeepsItsKeys(): void {
		$this->assertSame(expected: ['a' => "'=x", 'b' => 'y'], actual: CsvCell::row(row: ['a' => '=x', 'b' => 'y']));
	}//end testARowKeepsItsKeys()

	/**
	 * SpreadsheetCell's CSV methods give the same answer, so every export follows the one rule.
	 *
	 * @return void
	 */
	public function testSpreadsheetCellDelegatesHere(): void {
		$row = ['=1', '@a', 'plain', '12', ['nested']];
		$this->assertSame(expected: CsvCell::row(row: $row), actual: SpreadsheetCell::csvRow(row: $row));
		$this->assertSame(expected: CsvCell::value(value: '+1 call'), actual: SpreadsheetCell::csvValue(value: '+1 call'));
	}//end testSpreadsheetCellDelegatesHere()
}//end class
