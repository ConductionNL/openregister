<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for OasService::sanitizePropertyDefinition().
 *
 * Each case is one property definition. The sanitized definition, key order
 * included, is compared with fixtures/oas-sanitize-property-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class OasServiceSanitizePropertyGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/oas-sanitize-property-golden.json';

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function cases(): array {
		$cases = [
			'string scalar' => 'name',
			'null' => null,
			'integer scalar' => 5,
			'empty array' => [],
			'plain string' => ['type' => 'string'],
			'every keyword in reverse order' => [
				'title' => 'T', 'writeOnly' => false, 'readOnly' => true, 'nullable' => true, 'not' => ['type' => 'null'],
				'additionalProperties' => false, 'minProperties' => 1, 'maxProperties' => 3, 'uniqueItems' => true,
				'minItems' => 0, 'maxItems' => 9, 'pattern' => '^a', 'minLength' => 1, 'maxLength' => 5,
				'exclusiveMinimum' => 0, 'minimum' => 1, 'exclusiveMaximum' => 10, 'maximum' => 9, 'multipleOf' => 2,
				'const' => 'c', 'default' => 'd', 'examples' => ['e'], 'example' => 'e', 'description' => 'D',
				'format' => 'email', 'type' => 'string',
			],
			'internal fields stripped' => [
				'type' => 'object', 'objectConfiguration' => ['handling' => 'related'], 'inversedBy' => 'x',
				'authorization' => [], 'defaultBehavior' => 'falsy', 'visible' => true,
			],
			'null keyword values dropped' => ['type' => null, 'description' => null, 'format' => 'date'],
			'falsy keyword values kept' => ['type' => 'boolean', 'default' => false, 'description' => '', 'minimum' => 0],
			'unknown type' => ['type' => 'text'],
			'type list kept' => ['type' => ['string', 'null']],
			'type null' => ['type' => 'null'],
			'every valid type' => ['type' => 'integer', 'description' => 'n'],
			'array without items' => ['type' => 'array'],
			'array with items' => ['type' => 'array', 'items' => ['type' => 'integer', 'inversedBy' => 'x']],
			'array with list items' => ['type' => 'array', 'items' => [['type' => 'integer'], ['type' => 'string']]],
			'array with list of empty item' => ['type' => 'array', 'items' => [[]]],
			'array with list of scalar' => ['type' => 'array', 'items' => ['abc']],
			'array with empty items' => ['type' => 'array', 'items' => []],
			'array with scalar items' => ['type' => 'array', 'items' => 'string'],
			'items without type' => ['items' => ['$ref' => 'adres']],
			'nested items sanitized' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'weird']]],
			'object properties' => [
				'type' => 'object',
				'properties' => ['a' => ['type' => 'string', 'visible' => false], 'b' => 'scalar', 'c' => ['type' => 'object', 'properties' => ['d' => []]]],
			],
			'properties not an array' => ['type' => 'object', 'properties' => 'nope'],
			'required list' => ['type' => 'object', 'required' => ['a']],
			'required boolean' => ['type' => 'string', 'required' => true],
			'required string' => ['type' => 'string', 'required' => 'a'],
			'oneOf' => ['oneOf' => [['type' => 'string', 'extra' => 1], 'scalar', ['$ref' => 'x']]],
			'oneOf empty' => ['oneOf' => [], 'type' => 'string'],
			'oneOf scalar' => ['oneOf' => 'x'],
			'anyOf' => ['anyOf' => [['type' => 'integer'], []]],
			'anyOf empty' => ['anyOf' => []],
			'anyOf scalar' => ['anyOf' => 7],
			'allOf' => ['allOf' => [['type' => 'object'], [], 'x', ['$ref' => '#/components/schemas/A']]],
			'allOf only empty items' => ['allOf' => [[], 'x']],
			'allOf empty' => ['allOf' => []],
			'allOf scalar' => ['allOf' => 'x'],
			'allOf keyed' => ['allOf' => ['first' => ['type' => 'string'], 'second' => ['type' => 'integer']]],
			'ref bare' => ['$ref' => 'vestiging'],
			'ref bare with spaces' => ['$ref' => 'Mijn vestiging-type!'],
			'ref component' => ['$ref' => '#/components/schemas/Adres'],
			'ref relative' => ['$ref' => '#/definitions/A'],
			'ref hash without slash' => ['$ref' => '#Adres'],
			'ref absolute url' => ['$ref' => 'https://example.org/schema.json'],
			'ref empty' => ['$ref' => ''],
			'ref zero string' => ['$ref' => '0'],
			'ref array' => ['$ref' => ['x']],
			'ref with type and description' => ['$ref' => 'x', 'type' => 'object', 'description' => 'd'],
			'enum' => ['type' => 'string', 'enum' => ['a', 'b']],
			'enum empty' => ['type' => 'string', 'enum' => []],
			'enum scalar' => ['type' => 'string', 'enum' => 'a'],
			'not and additionalProperties untouched' => ['type' => 'object', 'additionalProperties' => ['type' => 'weird'], 'not' => ['visible' => 1]],
			'deep mix' => [
				'type' => 'object',
				'properties' => [
					'list' => ['type' => 'array', 'items' => [['oneOf' => [[], ['type' => 'x']]]]],
					'ref' => ['$ref' => 'persoon', 'required' => false],
					'choice' => ['anyOf' => [['allOf' => [[], ['type' => 'string']]], 'y']],
				],
				'required' => ['list'],
			],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	#[DataProvider('cases')]
	public function testSanitizePropertyDefinitionMatchesGolden(mixed $definition): void {
		$name = $this->dataName();
		$service = new OasService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IURLGenerator::class)
		);
		$method = new \ReflectionMethod($service, 'sanitizePropertyDefinition');
		$actual = $method->invoke($service, $definition);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], json_decode(json_encode($actual), true));
	}//end testSanitizePropertyDefinitionMatchesGolden()
}//end class
