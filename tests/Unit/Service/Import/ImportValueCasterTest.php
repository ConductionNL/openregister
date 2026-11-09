<?php

/**
 * What an import cell becomes for each declared type.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use OCA\OpenRegister\Service\Import\ImportValueCaster;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Import\ImportValueCaster
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class ImportValueCasterTest extends TestCase {

	/**
	 * Cells: value, property definition, expected result.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: mixed}>
	 */
	public static function cells(): array {
		$related = ['type' => 'object', 'objectConfiguration' => ['handling' => 'related-object']];
		$nested  = ['type' => 'object', 'objectConfiguration' => ['handling' => 'nested-object']];

		return [
			'null stays' => [null, ['type' => 'integer'], null],
			'empty stays' => ['', ['type' => 'array'], ''],
			'zero text is filled' => ['0', ['type' => 'integer'], 0],
			'integer' => ['42abc', ['type' => 'integer'], 42],
			'number' => ['1.5', ['type' => 'number'], 1.5],
			'no type is text' => [12, [], '12'],
			'unknown type is text' => [12, ['type' => 'date'], '12'],
			'type list is text' => [12, ['type' => ['string', 'null']], '12'],
			'boolean stays' => [false, ['type' => 'boolean'], false],
			'true text' => [' YES ', ['type' => 'boolean'], true],
			'enabled text' => ['enabled', ['type' => 'boolean'], true],
			'other text is false' => ['nope', ['type' => 'boolean'], false],
			'one as number' => [1, ['type' => 'boolean'], true],
			'array stays' => [['a'], ['type' => 'array'], ['a']],
			'number in a list' => [7, ['type' => 'array'], [7]],
			'blank text is empty' => ['   ', ['type' => 'array'], []],
			'json list' => [' ["a",1] ', ['type' => 'array'], ['a', 1]],
			'broken json with comma' => ['[a,b]', ['type' => 'array'], ['[a', 'b]']],
			'broken json alone' => ['[a]', ['type' => 'array'], ['[a]']],
			'comma list' => ['a, "b" ,\'c\'', ['type' => 'array'], ['a', 'b', 'c']],
			'unbalanced quotes stay' => ['"a,b\'', ['type' => 'array'], ['"a', 'b\'']],
			'lone quote empties' => ['",x', ['type' => 'array'], ['', 'x']],
			'single value' => ['solo', ['type' => 'array'], ['solo']],
			'json object' => [' {"k":1} ', ['type' => 'object'], ['k' => 1]],
			'empty json object' => ['{}', ['type' => 'object'], []],
			'broken json object' => ['{k}', ['type' => 'object'], ['value' => '{k}']],
			'text as object' => [' plain ', ['type' => 'object'], ['value' => 'plain']],
			'array as object' => [['k' => 1], ['type' => 'object'], ['k' => 1]],
			'relation keeps text' => ['uuid-1', $related, 'uuid-1'],
			'nested is an object' => ['uuid-1', $nested, ['value' => 'uuid-1']],
		];
	}//end cells()

	/**
	 * Each cell casts as listed.
	 *
	 * @dataProvider cells
	 *
	 * @param mixed                $value    The cell
	 * @param array<string, mixed> $property The property definition
	 * @param mixed                $expected The result
	 *
	 * @return void
	 */
	public function testACellCastsByItsType(mixed $value, array $property, mixed $expected): void {
		$this->assertSame($expected, (new ImportValueCaster())->cast(value: $value, propertyDef: $property));
	}//end testACellCastsByItsType()

	/**
	 * Datetime texts: input and expected result.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function dateTimes(): array {
		return [
			'database format stays' => ['2025-01-01 12:30:00', '2025-01-01 12:30:00'],
			'offset moves to utc' => ['2025-01-01T05:00:00+05:00', '2025-01-01 00:00:00'],
			'negative offset' => ['2025-01-01T22:00:00-03:00', '2025-01-02 01:00:00'],
			'local timestamp' => ['2025-01-01T10:20:30', '2025-01-01 10:20:30'],
			'date only' => ['2025-01-01', '2025-01-01 00:00:00'],
			'impossible offset timestamp stays' => ['2025-13-45T00:00:00+00:00', '2025-13-45T00:00:00+00:00'],
			'impossible local timestamp stays' => ['2025-13-45T00:00:00', '2025-13-45T00:00:00'],
			'other text stays' => ['yesterday', 'yesterday'],
		];
	}//end dateTimes()

	/**
	 * Each datetime normalises as listed.
	 *
	 * @dataProvider dateTimes
	 *
	 * @param string $value    The text
	 * @param string $expected The result
	 *
	 * @return void
	 */
	public function testADateTimeNormalises(string $value, string $expected): void {
		$this->assertSame($expected, (new ImportValueCaster())->dateTime(value: $value));
	}//end testADateTimeNormalises()

	/**
	 * Only the datetime properties of `@self` are normalised.
	 *
	 * @return void
	 */
	public function testOnlySelfDateTimesNormalise(): void {
		$caster = new ImportValueCaster();

		foreach (['published', 'created', 'updated'] as $property) {
			$this->assertSame('2025-01-01 00:00:00', $caster->castSelfProperty(propertyName: $property, value: '2025-01-01'));
		}

		$this->assertSame('2025-01-01', $caster->castSelfProperty(propertyName: 'owner', value: '2025-01-01'));
	}//end testOnlySelfDateTimesNormalise()

	/**
	 * `@self` and undeclared properties pass through an object cast, in order.
	 *
	 * @return void
	 */
	public function testAnObjectCastKeepsWhatTheSchemaDoesNotDeclare(): void {
		$object = ['extra' => '1', '@self' => ['id' => 'x'], 'count' => '2'];
		$schema = ['count' => ['type' => 'integer'], '@self' => ['type' => 'string']];

		$this->assertSame(
			['extra' => '1', '@self' => ['id' => 'x'], 'count' => 2],
			(new ImportValueCaster())->castObject(objectData: $object, properties: $schema)
		);
	}//end testAnObjectCastKeepsWhatTheSchemaDoesNotDeclare()
}//end class
