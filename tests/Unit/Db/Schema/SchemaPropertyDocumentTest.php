<?php

/**
 * SchemaPropertyDocument renders a schema's properties as a JSON Schema properties document.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Schema
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use OCA\OpenRegister\Db\Schema\SchemaPropertyDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 */
class SchemaPropertyDocumentTest extends TestCase {

	/**
	 * The rendered document as plain arrays.
	 *
	 * @param array<string, mixed> $properties The schema properties.
	 *
	 * @return array<string, mixed>
	 */
	private static function render(array $properties): array {
		return json_decode((string)json_encode((new SchemaPropertyDocument())->render($properties)), true);
	}//end render()

	/**
	 * A flat property keeps its filled keys, drops empty ones and its required flag.
	 *
	 * @return void
	 */
	public function testAFlatPropertyKeepsOnlyFilledKeys(): void {
		$document = self::render([
			'name' => ['type' => 'string', 'title' => 'Name', 'description' => '', 'enum' => [], 'required' => true, 'format' => null],
		]);

		$this->assertSame(['name' => ['type' => 'string', 'title' => 'Name']], $document);
	}//end testAFlatPropertyKeepsOnlyFilledKeys()

	/**
	 * Value-carrying keys survive a falsy value such as 0 or false, but not an empty string.
	 *
	 * @return void
	 */
	public function testValueCarryingKeysSurviveFalsyValues(): void {
		$document = self::render([
			'count' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => '', 'const' => false, 'title' => 0],
		]);

		$this->assertSame(['type' => 'integer', 'default' => 0, 'minimum' => 0, 'const' => false], $document['count']);
	}//end testValueCarryingKeysSurviveFalsyValues()

	/**
	 * A computed or calculated property is marked read-only.
	 *
	 * @return void
	 */
	public function testComputedAndCalculatedPropertiesAreReadOnly(): void {
		$document = self::render([
			'total' => ['type' => 'number', 'computed' => ['expression' => 'a + b']],
			'age' => ['type' => 'integer', 'calculation' => ['from' => 'birthDate']],
			'flagged' => ['type' => 'string', 'computed' => 'not a definition'],
		]);

		$this->assertTrue($document['total']['readOnly']);
		$this->assertTrue($document['age']['readOnly']);
		$this->assertArrayNotHasKey('readOnly', $document['flagged']);
	}//end testComputedAndCalculatedPropertiesAreReadOnly()

	/**
	 * A property that is not a definition renders as an empty object.
	 *
	 * @return void
	 */
	public function testANonDefinitionRendersEmpty(): void {
		$this->assertSame('{"broken":{}}', json_encode((new SchemaPropertyDocument())->render(['broken' => 'text'])));
	}//end testANonDefinitionRendersEmpty()

	/**
	 * A nested object lists required sub-properties and drops an empty oneOf only.
	 *
	 * @return void
	 */
	public function testANestedObjectCollectsRequiredSubProperties(): void {
		$document = self::render([
			'address' => [
				'title' => 'Address',
				'properties' => [
					'street' => ['type' => 'string', 'required' => true, 'oneOf' => []],
					'zip' => ['type' => 'string', 'required' => false, 'description' => ''],
					'kind' => ['oneOf' => [['const' => 'home']]],
					'odd' => 'text',
				],
			],
		]);

		$this->assertSame(
			[
				'type' => 'object',
				'title' => 'Address',
				'required' => ['street'],
				'properties' => [
					'street' => ['type' => 'string', 'required' => true],
					'zip' => ['type' => 'string', 'required' => false, 'description' => ''],
					'kind' => ['oneOf' => [['const' => 'home']]],
					'odd' => [],
				],
			],
			$document['address']
		);
	}//end testANestedObjectCollectsRequiredSubProperties()

	/**
	 * A nested object whose properties are not a list has none.
	 *
	 * @return void
	 */
	public function testANestedObjectWithoutAPropertyListHasNoProperties(): void {
		$json = json_encode((new SchemaPropertyDocument())->render(['meta' => ['title' => 'Meta', 'properties' => 'x']]));

		$this->assertSame('{"meta":{"type":"object","title":"Meta","required":[],"properties":{}}}', $json);
	}//end testANestedObjectWithoutAPropertyListHasNoProperties()
}//end class
