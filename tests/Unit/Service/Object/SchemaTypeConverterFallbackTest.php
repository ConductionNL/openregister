<?php

/**
 * The schema type converter's pass-through cases: a value of no string-like
 * shape on the string path, a bracketed string that is not valid JSON, and
 * a value that cannot be re-encoded for a string-typed property.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use PHPUnit\Framework\TestCase;

/**
 * Pass-through tests for {@see SchemaTypeConverter}.
 */
class SchemaTypeConverterFallbackTest extends TestCase {

	/**
	 * On the string path a boolean or an array is returned untouched, while
	 * a number is cast to a string.
	 *
	 * @return void
	 */
	public function testTheStringPathPassesThroughNonStringValues(): void {
		$converter = new SchemaTypeConverter();

		$this->assertTrue($converter->convertValue(value: true, schemaType: 'string'));
		$this->assertSame(['a'], $converter->convertValue(value: ['a'], schemaType: 'color'));
		$this->assertSame('12.5', $converter->convertValue(value: 12.5, schemaType: 'string'));
	}//end testTheStringPathPassesThroughNonStringValues()

	/**
	 * A bracketed string that is not valid JSON stays a string; a valid one
	 * is decoded; plain text is not touched.
	 *
	 * @return void
	 */
	public function testABracketedStringIsDecodedOnlyWhenItIsValidJson(): void {
		$converter = new SchemaTypeConverter();

		$this->assertSame('[oops', $converter->convertValue(value: '[oops', schemaType: 'string'));
		$this->assertSame('{}x', $converter->convertValue(value: '{}x', schemaType: 'string'));
		$this->assertSame(['a' => 1], $converter->convertValue(value: ' {"a":1}', schemaType: 'string'));
		$this->assertSame('plain', $converter->convertValue(value: 'plain', schemaType: 'string'));
	}//end testABracketedStringIsDecodedOnlyWhenItIsValidJson()

	/**
	 * An array for a string-typed property is re-encoded, unless it cannot be
	 * encoded (invalid UTF-8), in which case it is returned as is.
	 *
	 * @return void
	 */
	public function testAnUnencodableArrayIsReturnedAsIs(): void {
		$converter = new SchemaTypeConverter();
		$broken = ['name' => "\xB1\x31"];

		$restored = $converter->restoreStringTypedValues(
			data: ['broken' => $broken, 'fine' => ['a' => 'é']],
			properties: ['broken' => ['type' => 'string'], 'fine' => ['type' => 'string']]
		);

		$this->assertSame($broken, $restored['broken']);
		$this->assertSame('{"a":"é"}', $restored['fine']);
	}//end testAnUnencodableArrayIsReturnedAsIs()
}//end class
