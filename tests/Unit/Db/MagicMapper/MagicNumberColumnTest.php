<?php

/**
 * Tests for MagicNumberColumn: the size of a number property's column and the
 * refusal of a value it cannot hold.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn;
use OCA\OpenRegister\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class MagicNumberColumnTest extends TestCase {

	private MagicNumberColumn $column;

	protected function setUp(): void {
		parent::setUp();
		$this->column = new MagicNumberColumn();
	}//end setUp()

	public function testWithoutMultipleOfTheColumnIsTwentySix(): void {
		$this->assertSame(['precision' => 20, 'scale' => 6], $this->column->size(propertyConfig: ['type' => 'number']));
	}//end testWithoutMultipleOfTheColumnIsTwentySix()

	public function testMultipleOfSetsTheScale(): void {
		$this->assertSame(['precision' => 17, 'scale' => 3], $this->column->size(propertyConfig: ['type' => 'number', 'multipleOf' => 0.001]));
		$this->assertSame(['precision' => 16, 'scale' => 2], $this->column->size(propertyConfig: ['type' => 'number', 'multipleOf' => 0.01]));
		$this->assertSame(['precision' => 14, 'scale' => 0], $this->column->size(propertyConfig: ['type' => 'number', 'multipleOf' => 5]));
		$this->assertSame(['precision' => 22, 'scale' => 8], $this->column->size(propertyConfig: ['type' => 'number', 'multipleOf' => 1.0E-8]));
	}//end testMultipleOfSetsTheScale()

	public function testFiveGramsFitsTheDefaultColumn(): void {
		$this->assertNull($this->column->refusal(value: 0.005, precision: 20, scale: 6));
		$this->assertNull($this->column->refusal(value: '0.005', precision: 20, scale: 6));
	}//end testFiveGramsFitsTheDefaultColumn()

	public function testFiveGramsDoesNotFitTwoDecimals(): void {
		$this->assertNotNull($this->column->refusal(value: 0.005, precision: 10, scale: 2));
		$this->assertNotNull($this->column->refusal(value: '0.005', precision: 10, scale: 2));
	}//end testFiveGramsDoesNotFitTwoDecimals()

	public function testFloatNoiseIsNotRefused(): void {
		$this->assertNull($this->column->refusal(value: 0.1 + 0.2, precision: 20, scale: 6));
		$this->assertNull($this->column->refusal(value: 12.5, precision: 16, scale: 2));
	}//end testFloatNoiseIsNotRefused()

	public function testTooManyDecimalsOrDigitsAreRefused(): void {
		$this->assertNotNull($this->column->refusal(value: 0.0000001, precision: 20, scale: 6));
		$this->assertNotNull($this->column->refusal(value: '1.0E-7', precision: 20, scale: 6));
		$this->assertNotNull($this->column->refusal(value: 1.0E15, precision: 20, scale: 6));
		$this->assertNotNull($this->column->refusal(value: 100000000000000, precision: 20, scale: 6));
		$this->assertNull($this->column->refusal(value: 99999999999999, precision: 20, scale: 6));
		$this->assertNull($this->column->refusal(value: 'not a number', precision: 20, scale: 6));
	}//end testTooManyDecimalsOrDigitsAreRefused()

	public function testARowIsCheckedAgainstTheColumnsActualSize(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage("'weight'");
		$this->column->assertRowFits(row: ['weight' => 0.005, 'title' => '0.0000001'], columnSizes: ['weight' => ['precision' => 10, 'scale' => 2]]);
	}//end testARowIsCheckedAgainstTheColumnsActualSize()

	public function testARowThatFitsItsColumnsPasses(): void {
		$this->column->assertRowFits(row: ['weight' => 0.005, 'price' => null, 'title' => '0.0000001'], columnSizes: ['weight' => ['precision' => 20, 'scale' => 6], 'price' => ['precision' => 10, 'scale' => 2]]);
		$this->addToAssertionCount(1);
	}//end testARowThatFitsItsColumnsPasses()

	public function testAnOldTwoDecimalColumnIsWidenedNeverNarrowed(): void {
		$this->assertSame(['precision' => 20, 'scale' => 6], $this->column->widening(currentColumn: ['type' => 'numeric', 'precision' => 10, 'scale' => 2], precision: 20, scale: 6));
		$this->assertSame(['precision' => 16, 'scale' => 2], $this->column->widening(currentColumn: ['type' => 'decimal', 'precision' => 10, 'scale' => 2], precision: 16, scale: 2));
		$this->assertNull($this->column->widening(currentColumn: ['type' => 'numeric', 'precision' => 20, 'scale' => 6], precision: 17, scale: 3));
		$this->assertNull($this->column->widening(currentColumn: ['type' => 'text', 'precision' => null, 'scale' => null], precision: 20, scale: 6));
	}//end testAnOldTwoDecimalColumnIsWidenedNeverNarrowed()
}//end class
