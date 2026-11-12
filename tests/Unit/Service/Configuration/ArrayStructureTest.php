<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ArrayStructure;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

/**
 * Objects in configuration data become arrays at any depth.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ArrayStructureTest extends TestCase {

	/**
	 * A flat object becomes an array.
	 *
	 * @return void
	 */
	public function testAFlatObjectBecomesAnArray(): void {
		$this->assertSame(['key' => 'val', 'other' => 42], (new ArrayStructure())->toArrays(data: (object) ['key' => 'val', 'other' => 42]));
	}//end testAFlatObjectBecomesAnArray()

	/**
	 * Objects inside objects become arrays too.
	 *
	 * @return void
	 */
	public function testNestedObjectsBecomeArrays(): void {
		$outer = (object) ['child' => (object) ['deep' => (object) ['value' => 1]]];

		$this->assertSame(['child' => ['deep' => ['value' => 1]]], (new ArrayStructure())->toArrays(data: $outer));
	}//end testNestedObjectsBecomeArrays()

	/**
	 * A plain array comes back as it went in, keys and order kept.
	 *
	 * @return void
	 */
	public function testAPlainArrayStaysAsItIs(): void {
		$input = ['b' => 1, 'a' => [2, 3], 5 => null, 'c' => 'text'];

		$this->assertSame($input, (new ArrayStructure())->toArrays(data: $input));
	}//end testAPlainArrayStaysAsItIs()

	/**
	 * Only the objects among scalars and lists change, also below a list.
	 *
	 * @return void
	 */
	public function testObjectsAmongScalarsAndListsBecomeArrays(): void {
		$input = ['scalar' => 42, 'nested' => (object) ['key' => 'value'], 'list' => ['a', new stdClass()]];

		$this->assertSame(['scalar' => 42, 'nested' => ['key' => 'value'], 'list' => ['a', []]], (new ArrayStructure())->toArrays(data: $input));
	}//end testObjectsAmongScalarsAndListsBecomeArrays()

	/**
	 * A scalar is not configuration data.
	 *
	 * @return void
	 */
	public function testAScalarIsATypeError(): void {
		$this->expectException(TypeError::class);

		(new ArrayStructure())->toArrays(data: 5);
	}//end testAScalarIsATypeError()
}//end class
