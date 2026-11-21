<?php

/**
 * MagicNumberColumnEdgeDigitsTest: numbers whose digits cannot be counted: a digitless multipleOf and an uncountable value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 */
class MagicNumberColumnEdgeDigitsTest extends TestCase {

	public function testAMultipleOfWithoutDigitsUsesTheDefaultScale(): void {
		$column = new MagicNumberColumn();

		foreach (['.', '+', '', 'e5'] as $multipleOf) {
			$this->assertSame(['precision' => 20, 'scale' => 6], $column->size(['multipleOf' => $multipleOf]), json_encode($multipleOf));
		}
	}//end testAMultipleOfWithoutDigitsUsesTheDefaultScale()

	public function testAMultipleOfWithDigitsSetsTheScale(): void {
		$this->assertSame(['precision' => 16, 'scale' => 2], (new MagicNumberColumn())->size(['multipleOf' => '.01']));
	}//end testAMultipleOfWithDigitsSetsTheScale()

	public function testANumberWhoseDigitsCannotBeCountedIsLeftToTheDatabase(): void {
		// is_numeric() accepts a leading form feed that trim() keeps, so the digits cannot be counted.
		$this->assertNull((new MagicNumberColumn())->refusal("\f123456789", ['precision' => 4, 'scale' => 2]));
		$this->assertSame('has more than 2 decimals', (new MagicNumberColumn())->refusal('1.234', ['precision' => 4, 'scale' => 2]));
	}//end testANumberWhoseDigitsCannotBeCountedIsLeftToTheDatabase()
}//end class
