<?php

declare(strict_types=1);

/**
 * Validation units golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * outcomes of the validation units that refactor touches: every refusal of
 * the not-supplied record (code and message), the declared map, the schema
 * preparation helpers of ValidateObject (self references, array items,
 * misplaced array constraints, oneOf handling) and the repeating-group row
 * and type checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\NotSuppliedHandler;
use OCA\OpenRegister\Service\Object\RepeatingGroupValidator;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden outcomes of NotSuppliedHandler, ValidateObject helpers and RepeatingGroupValidator.
 */
class ValidationUnitsGoldenTest extends TestCase {
	/**
	 * A schema with two properties and, optionally, administered reasons.
	 *
	 * @param bool $withReasons Whether reasons are administered
	 *
	 * @return Schema
	 */
	private static function notSuppliedSchema(bool $withReasons): Schema {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['type' => 'string'], 'note' => ['type' => 'string']]);
		if ($withReasons === true) {
			$schema->setConfiguration([Schema::NOT_SUPPLIED_REASONS_ANNOTATION => ['nvt' => 'Not applicable', 'onbekend' => 'Unknown']]);
		}

		return $schema;
	}//end notSuppliedSchema()

	/**
	 * Every refusal of the not-supplied record, by code and message.
	 *
	 * @return void
	 */
	public function testNotSuppliedValidateTable(): void {
		$handler = new NotSuppliedHandler();
		$key = Schema::NOT_SUPPLIED_KEY;
		$codes = static fn (array $violations): array => array_map(
			static fn (array $v): string => $v['property'] . '|' . $v['code'] . '|' . $v['message'],
			$violations
		);

		$this->assertSame([], $handler->validate(object: ['bsn' => '1'], schema: self::notSuppliedSchema(true)));
		$this->assertSame([], $handler->validate(object: [$key => []], schema: self::notSuppliedSchema(true)));
		$notAMap = [$key . '|not-supplied-not-a-map|' . $key . ' takes a property name for each reason code.'];
		$this->assertSame($notAMap, $codes($handler->validate(object: [$key => 'bsn'], schema: self::notSuppliedSchema(true))));
		$this->assertSame($notAMap, $codes($handler->validate(object: [$key => ['bsn']], schema: self::notSuppliedSchema(true))));
		$this->assertSame($notAMap, $codes($handler->validate(object: [$key => null], schema: self::notSuppliedSchema(true))));

		$this->assertSame(
			[
				"bsn|not-supplied-without-a-reason|'bsn' is marked not supplied with no reason. Name one from the schema's list.",
				"note|not-supplied-without-a-reason|'note' is marked not supplied with no reason. Name one from the schema's list.",
				"ghost|not-supplied-unknown-property|'ghost' is marked not supplied, and this schema does not declare it.",
				"x|not-supplied-without-a-reason|'x' is marked not supplied with no reason. Name one from the schema's list.",
			],
			$codes($handler->validate(object: [$key => ['bsn' => '', 'note' => 5, 'ghost' => 'nvt', 'x' => null]], schema: self::notSuppliedSchema(true)))
		);
		$this->assertSame(
			[
				"bsn|not-supplied-and-answered|'bsn' carries a value and is marked not supplied. It can be one or the other.",
				"note|not-supplied-unknown-reason|'zzz' is not a reason this schema administers. It accepts: nvt, onbekend.",
			],
			$codes($handler->validate(object: ['bsn' => '1', 'note' => [], $key => ['bsn' => 'nvt', 'note' => 'zzz']], schema: self::notSuppliedSchema(true)))
		);
		$this->assertSame(
			["bsn|not-supplied-no-reasons-administered|This schema administers no not-supplied reasons, so 'bsn' cannot be marked."],
			$codes($handler->validate(object: ['bsn' => null, $key => ['bsn' => 'nvt']], schema: self::notSuppliedSchema(false)))
		);
		$this->assertSame([], $handler->validate(object: ['bsn' => '', $key => ['bsn' => 'onbekend']], schema: self::notSuppliedSchema(true)));

		$this->assertSame(
			['bsn' => 'nvt', 'note' => 'x'],
			$handler->declared(object: [$key => ['bsn' => 'nvt', '' => 'nvt', 'a' => '', 'b' => 5, 7 => 'nvt', 'note' => 'x']])
		);
		$this->assertSame([], $handler->declared(object: [$key => 'bsn']));
		$this->assertSame([], $handler->declared(object: []));
	}//end testNotSuppliedValidateTable()

	/**
	 * Call a private ValidateObject method.
	 *
	 * @param string $method The method
	 * @param array  $args   Positional arguments
	 *
	 * @return mixed
	 */
	private function validateObject(string $method, array $args): mixed {
		$validator = new ValidateObject(
			schemas: new ValidationSchemaSource(
				config: $this->createMock(IAppConfig::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				urlGenerator: $this->createMock(IURLGenerator::class)
			),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);

		return (new ReflectionMethod(ValidateObject::class, $method))->invoke($validator, ...$args);
	}//end validateObject()

	/**
	 * Decode a JSON schema fragment into objects.
	 *
	 * @param string $json The fragment
	 *
	 * @return mixed
	 */
	private static function obj(string $json): mixed {
		return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
	}//end obj()

	/**
	 * The ValidateObject schema-preparation helpers.
	 *
	 * @return void
	 */
	public function testValidateObjectHelperTables(): void {
		$self = [
			['{"$ref":"#/components/schemas/person"}', true],
			['{"$ref":"#/components/schemas/person?x=1"}', true],
			['{"$ref":"#/components/schemas/other"}', false],
			['{"$ref":{"id":"#/components/schemas/person"}}', true],
			['{"$ref":{"name":"x"}}', false],
			['{"$ref":"person"}', false],
			['{"$ref":null}', false],
			['{"type":"string"}', false],
			['{"$ref":5}', false],
		];
		foreach ($self as $i => [$json, $expected]) {
			$this->assertSame($expected, $this->validateObject('isSelfReference', [self::obj($json), 'person']), "self case $i");
		}

		$arraySelf = (object) ['$ref' => ['id' => '#/components/schemas/person']];
		$this->assertTrue($this->validateObject('isSelfReference', [$arraySelf, 'person']));
		$arrayNoId = (object) ['$ref' => ['x' => 1]];
		$this->assertFalse($this->validateObject('isSelfReference', [$arrayNoId, 'person']));

		$items = [
			'{"type":"string"}',
			'{"properties":{"a":{}}}',
			'{"type":"object","properties":{"register":{"type":"string"}}}',
			'{"type":"object","$ref":"#/components/schemas/x"}',
			'{"type":"object","properties":{"a":{}},"objectConfiguration":{"handling":"nested-object"}}',
			'{"type":"object","properties":{"a":{}},"objectConfiguration":{"handling":"related-object"}}',
			'{"type":"object"}',
			'{"type":"object","objectConfiguration":{"handling":"weird"}}',
		];
		$transformed = [];
		foreach ($items as $json) {
			$transformed[] = json_encode($this->validateObject('transformArrayItemsForValidation', [self::obj($json)]));
		}

		$this->assertSame(
			[
				'{"type":"string"}',
				'{"properties":{"a":{}}}',
				'{"type":"object","properties":{"register":{"type":"string"}}}',
				'{"oneOf":[{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$","description":"UUID reference to a related object"},{"type":"object","description":"Object with id field referencing a related object","properties":{"id":{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$"}},"required":["id"],"additionalProperties":true}],"description":"UUID reference or object with id field"}',
				'{"type":"object","properties":{"id":{"type":"string","description":"Object identifier"}},"objectConfiguration":{"handling":"nested-object"},"description":"Nested object"}',
				'{"objectConfiguration":{"handling":"related-object"},"oneOf":[{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$","description":"UUID reference to a related object"},{"type":"object","description":"Object with id field referencing a related object","properties":{"id":{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$"}},"required":["id"],"additionalProperties":true}],"description":"UUID reference or object with id field"}',
				'{"type":"object","description":"Nested object","properties":{"id":{"type":"string","description":"Object identifier"}}}',
				'{"objectConfiguration":{"handling":"weird"},"oneOf":[{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$","description":"UUID reference to a related object"},{"type":"object","description":"Object with id field referencing a related object","properties":{"id":{"type":"string","pattern":"^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$"}},"required":["id"],"additionalProperties":true}],"description":"UUID reference or object with id field"}',
			],
			$transformed
		);

		$fixes = [
			['{"type":"string","enum":["a"]}', '{"type":"string","enum":["a"]}'],
			['{"type":"array","enum":["a","b"]}', '{"type":"array","items":{"type":"string","enum":["a","b"]}}'],
			['{"type":"array","enum":[],"items":{"type":"integer"}}', '{"type":"array","enum":[],"items":{"type":"integer"}}'],
			['{"type":"array","items":{"type":"integer","enum":[1]},"enum":[2]}', '{"type":"array","items":{"type":"integer","enum":[1]}}'],
			['{"type":"array","oneOf":[{"type":"string"}]}', '{"type":"array","items":{"oneOf":[{"type":"string"}]}}'],
			['{"type":"array","oneOf":{"a":{"type":"string"}}}', '{"type":"array","items":{"oneOf":{"a":{"type":"string"}}}}'],
			['{"type":"array","oneOf":[]}', '{"type":"array","oneOf":[]}'],
			['{"type":"array","oneOf":"x"}', '{"type":"array","oneOf":"x"}'],
			['{"type":"array","enum":["a"],"oneOf":[{"type":"string"}]}', '{"type":"array","items":{"type":"string","enum":["a"],"oneOf":[{"type":"string"}]}}'],
		];
		foreach ($fixes as $i => [$json, $expected]) {
			$this->assertSame($expected, json_encode($this->validateObject('fixMisplacedArrayConstraints', [self::obj($json)])), "fix case $i");
		}

		$oneOf = [
			[null, null],
			['x', null],
			[[], null],
			[self::obj('[{"type":"string"},{"objectConfiguration":{"handling":"related-object"}}]'), 'related-object'],
			[self::obj('{"a":{"objectConfiguration":{"handling":"nested-object"}}}'), 'nested-object'],
			[self::obj('[{"type":"string"}]'), null],
		];
		foreach ($oneOf as $i => [$value, $expected]) {
			$this->assertSame($expected, $this->validateObject('extractHandlingFromOneOfItems', [$value]), "oneOf case $i");
		}
	}//end testValidateObjectHelperTables()

	/**
	 * Repeating-group rows and value types.
	 *
	 * @return void
	 */
	public function testRepeatingGroupTables(): void {
		$validator = new RepeatingGroupValidator();
		$rowsOf = new ReflectionMethod(RepeatingGroupValidator::class, 'rowsOf');
		$matches = new ReflectionMethod(RepeatingGroupValidator::class, 'matchesType');

		$this->assertSame([], $rowsOf->invoke($validator, null));
		$this->assertSame([], $rowsOf->invoke($validator, ''));
		$this->assertSame([], $rowsOf->invoke($validator, []));
		$this->assertSame([['a' => 1]], $rowsOf->invoke($validator, [['a' => 1]]));
		$this->assertNull($rowsOf->invoke($validator, ['a' => 1]));
		$this->assertNull($rowsOf->invoke($validator, 'x'));
		$this->assertNull($rowsOf->invoke($validator, 0));

		$values = ['s', '12', 12, 1.5, '1.5', true, false, 'true', 'false', 0, 1, '0', '1', 2, null, [], [1, 2], ['a' => 1]];
		$expected = [
			'string'  => [1, 1, 1, 1, 1, 0, 0, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0],
			'number'  => [0, 1, 1, 1, 1, 0, 0, 0, 0, 1, 1, 1, 1, 1, 0, 0, 0, 0],
			'integer' => [0, 1, 1, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 0, 0, 0, 0],
			'boolean' => [0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0],
			'array'   => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0],
			'object'  => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1],
			'other'   => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
		];
		foreach ($expected as $type => $row) {
			$actual = array_map(static fn (mixed $v): int => (int)$matches->invoke($validator, $v, $type), $values);
			$this->assertSame($row, $actual, "type $type");
		}
	}//end testRepeatingGroupTables()
}//end class
