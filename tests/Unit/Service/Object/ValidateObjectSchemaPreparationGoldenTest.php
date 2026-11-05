<?php

/**
 * ValidateObject's schema preparation rewrites a schema for validation exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Golden record of transformSchemaForValidation() and prepareSchemaForValidation() over every
 * branch: self-references through a related-object or related-schema configuration (as an array
 * or an object, with and without inversedBy), self-referencing array items, references to other
 * schemas, `$id` removal, computed and calculated properties, required lists, enum and type null
 * widening, translatable properties and nested nullable properties. Set OR_GOLDEN_RECORD=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ValidateObjectSchemaPreparationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/validate-object-schema-preparation-golden.json';

	private const SELF = '#/components/schemas/item';

	/**
	 * Every transform scenario and every preparation scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->transformScenarios() as $name => [$schema, $slug]) {
			$decoded = $this->decode(schema: $schema);
			$this->asAssociativeArrays(name: $name, schema: $decoded);
			$actual['transform: ' . $name] = $this->transformed(schema: $decoded, slug: $slug);
		}

		foreach ($this->preparationScenarios() as $name => [$schema, $slug]) {
			$actual['prepare: ' . $name] = $this->prepared(schema: $this->decode(schema: $schema), slug: $slug);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * Schemas for transformSchemaForValidation(), each with the current schema slug.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	private function transformScenarios(): array {
		$self = self::SELF;
		return [
			'no properties'                       => [['type' => 'object', '$id' => 'x'], 'item'],
			'no self reference'                   => [['$id' => 'https://e.org/s', 'properties' => ['a' => ['type' => 'string', '$ref' => 'person'], 'b' => ['type' => 'integer']]], 'item'],
			'null id kept'                        => [['$id' => null, 'properties' => ['a' => ['type' => 'string']]], 'item'],
			'related-object, object config'       => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'pattern' => 'p', 'objectConfiguration' => ['handling' => 'related-object'], 'properties' => ['n' => ['type' => 'string']], 'required' => ['n']]]], 'item'],
			'related-object, inversedBy'          => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'pattern' => 'p', 'inversedBy' => 'children', 'objectConfiguration' => ['handling' => 'related-object'], 'properties' => ['n' => ['type' => 'string']], 'required' => ['n']]]], 'item'],
			'related-schema, inversedBy no type'  => [['properties' => ['parent' => ['$ref' => $self, 'inversedBy' => 'children', 'objectConfiguration' => ['handling' => 'related-schema']]]], 'item'],
			'related-object, array type'          => [['properties' => ['parents' => ['type' => 'array', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object'], 'items' => ['$ref' => $self]]]], 'item'],
			'related-object, assoc config'        => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object'], 'required' => ['n']]]], 'item'],
			'inversedBy, assoc config'            => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'inversedBy' => 'children', 'objectConfiguration' => ['handling' => 'related-schema']]]], 'item'],
			'nested-object, assoc config'         => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'nested-object']]]], 'item'],
			'assoc config without handling'       => [['properties' => ['parent' => ['type' => 'string', '$ref' => $self, 'objectConfiguration' => ['other' => 'related-object']]]], 'item'],
			'string with self items'              => [['properties' => ['kids' => ['type' => 'string', '$ref' => $self, 'items' => ['$ref' => $self]]]], 'item'],
			'self ref, assoc items'               => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self]]]], 'item'],
			'inversedBy, string type'             => [['properties' => ['parent' => ['type' => 'string', '$ref' => $self, 'pattern' => 'p', 'inversedBy' => 'children', 'objectConfiguration' => ['handling' => 'related-object']]]], 'item'],
			'nested-object handling'              => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'nested-object'], 'properties' => ['n' => ['type' => 'string']]]]], 'item'],
			'config without handling'             => [['properties' => ['parent' => ['type' => 'string', '$ref' => $self, 'objectConfiguration' => ['other' => 1]]]], 'item'],
			'config null handling'                => [['properties' => ['parent' => ['type' => 'string', '$ref' => $self, 'objectConfiguration' => ['handling' => null]]]], 'item'],
			'no config, string'                   => [['properties' => ['parent' => ['type' => 'string', '$ref' => $self, 'format' => 'uuid']]], 'item'],
			'self items, inversedBy'              => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self, 'inversedBy' => 'parent', 'type' => 'object']]]], 'item'],
			'self items'                          => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self, 'type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]]], 'item'],
			'self items, untyped'                 => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self]]]], 'item'],
			'other items'                         => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => '#/components/schemas/other', 'type' => 'object']]]], 'item'],
			'items without self ref on array'     => [['properties' => ['kids' => ['type' => 'array', 'items' => ['$ref' => $self, 'type' => 'object']]]], 'item'],
			'self ref, array without items'       => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self]]], 'item'],
			'self ref, items as list'             => [['properties' => ['kids' => ['type' => 'array', '$ref' => $self, 'items' => [['$ref' => $self]]]]], 'item'],
			'self ref, object items no type'      => [['properties' => ['kids' => ['$ref' => $self, 'items' => ['$ref' => $self]]]], 'item'],
			'object ref id'                       => [['properties' => ['parent' => ['type' => 'object', '$ref' => ['id' => $self], 'objectConfiguration' => ['handling' => 'related-object']]]], 'item'],
			'ref with query'                      => [['properties' => ['parent' => ['type' => 'object', '$ref' => $self . '?v=2', 'objectConfiguration' => ['handling' => 'related-object']]]], 'item'],
			'other ref with config'               => [['properties' => ['parent' => ['type' => 'object', '$ref' => '#/components/schemas/other', 'objectConfiguration' => ['handling' => 'related-object']]]], 'item'],
			'empty slug matches empty tail'       => [['properties' => ['parent' => ['type' => 'object', '$ref' => '#/components/schemas/', 'objectConfiguration' => ['handling' => 'related-object']]]], ''],
			'two self references'                 => [['$id' => 'i', 'properties' => ['a' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object']], 'b' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self]], 'c' => ['type' => 'string', '$ref' => 'person']]], 'item'],
			'non-object property'                 => [['properties' => ['flag' => true]], 'item'],
			'properties as list'                  => [['properties' => [['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object']]]], 'item'],
		];
	}//end transformScenarios()

	/**
	 * Schemas for prepareSchemaForValidation(), each with the slug of the schema entity (null: none).
	 *
	 * @return array<string, array{0: array, 1: ?string}>
	 */
	private function preparationScenarios(): array {
		$self = self::SELF;
		return [
			'no schema entity'               => [['type' => 'object', 'properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object']]]], null],
			'self reference'                 => [['type' => 'object', '$id' => 'x', 'properties' => ['parent' => ['type' => 'object', '$ref' => $self, 'objectConfiguration' => ['handling' => 'related-object']], 'kids' => ['type' => 'array', '$ref' => $self, 'items' => ['$ref' => $self]]]], 'item'],
			'no properties'                  => [['type' => 'object', 'required' => ['a']], 'item'],
			'empty required'                 => [['type' => 'object', 'required' => [], 'properties' => ['a' => ['type' => 'string']]], 'item'],
			'computed and calculation'       => [['type' => 'object', 'required' => ['a', 'b', 'c', 'd'], 'properties' => ['a' => ['type' => 'string', 'computed' => '{{ x }}'], 'b' => ['type' => 'number', 'calculation' => ['+' => [1, 2]]], 'c' => ['type' => 'string'], 'd' => ['type' => 'string', 'computed' => false]]], 'item'],
			'computed, not required'         => [['type' => 'object', 'required' => ['c'], 'properties' => ['a' => ['type' => 'string', 'computed' => 'x'], 'c' => ['type' => 'string']]], 'item'],
			'computed, no required list'     => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'computed' => 'x']]], 'item'],
			'computed, required not a list'  => [['type' => 'object', 'required' => 'a', 'properties' => ['a' => ['type' => 'string', 'computed' => 'x']]], 'item'],
			'only computed required'         => [['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'computed' => 'x']]], 'item'],
			'enum without null'              => [['type' => 'object', 'properties' => ['e' => ['type' => 'string', 'enum' => ['a', 'b']]]], 'item'],
			'enum with null'                 => [['type' => 'object', 'properties' => ['e' => ['type' => 'string', 'enum' => ['a', null]]]], 'item'],
			'enum not a list'                => [['type' => 'object', 'properties' => ['e' => ['type' => 'string', 'enum' => 'a']]], 'item'],
			'type list'                      => [['type' => 'object', 'properties' => ['t' => ['type' => ['string', 'integer']], 'u' => ['type' => ['string', 'null']]]], 'item'],
			'required keeps its type'        => [['type' => 'object', 'required' => ['r'], 'properties' => ['r' => ['type' => 'string'], 'o' => ['type' => 'string']]], 'item'],
			'untyped and odd types'          => [['type' => 'object', 'properties' => ['n' => ['description' => 'none'], 'x' => ['type' => 5]]], 'item'],
			'translatable'                   => [['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string', 'translatable' => true, 'maxLength' => 9], 'body' => ['type' => 'string', 'translatable' => true], 'note' => ['type' => 'string', 'translatable' => 'yes']]], 'item'],
			'nested nullable'                => [['type' => 'object', 'properties' => ['o' => ['type' => 'object', 'properties' => ['p' => ['type' => 'string', 'nullable' => true], 'q' => ['type' => 'string']]], 'l' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['c' => ['type' => 'integer', 'nullable' => true]]]]]], 'item'],
			'metadata stripped'              => [['type' => 'object', 'objectConfiguration' => ['a' => 1], 'inversedBy' => 'x', 'properties' => ['m' => ['type' => 'string', 'cascadeDelete' => true, 'mappedBy' => 'y', 'fetch' => 'lazy']]], 'item'],
			'computed twice in required'     => [['type' => 'object', 'required' => ['a', 'c', 'a'], 'properties' => ['a' => ['type' => 'string', 'computed' => 'x'], 'c' => ['type' => 'string']]], 'item'],
			'computed non-object neighbour'  => [['type' => 'object', 'required' => ['f', 'a'], 'properties' => ['f' => ['type' => 'string'], 'a' => ['type' => 'string', 'calculation' => 'x'], 'g' => ['type' => 'string', 'computed' => null]]], 'item'],
			'required as numeric names'      => [['type' => 'object', 'required' => [0], 'properties' => ['0' => ['type' => 'string'], 'x' => ['type' => 'string']]], 'item'],
			'required not a list, no props'  => [['type' => 'object', 'required' => 'a'], 'item'],
			'null type string'               => [['type' => 'object', 'properties' => ['n' => ['type' => 'null'], 'e' => ['enum' => ['a']], 'm' => ['enum' => [null], 'type' => ['string']]]], 'item'],
			'translatable enum, false marker' => [['type' => 'object', 'properties' => ['t' => ['translatable' => false], 'u' => ['translatable' => true, 'enum' => ['a']]]], 'item'],
			'translatable required order'    => [['type' => 'object', 'required' => ['b'], 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer', 'translatable' => true], 'c' => ['type' => 'boolean']]], 'item'],
			'entity without slug'            => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], '(no slug)'],
			'properties not an object'       => [['type' => 'object', 'properties' => 'x'], 'item'],
			'computed numeric name'          => [['type' => 'object', 'required' => [true, '1'], 'properties' => ['1' => ['type' => 'string', 'computed' => 'x'], 'b' => ['type' => 'string']]], 'item'],
			'properties as list'             => [['type' => 'object', 'properties' => [['type' => 'string'], ['type' => 'string', 'computed' => 'x']]], 'item'],
		];
	}//end preparationScenarios()

	/**
	 * A schema array decoded the way a stored schema arrives: objects all the way down.
	 *
	 * @param array $schema The schema as an array.
	 *
	 * @return object The decoded schema.
	 */
	private function decode(array $schema): object {
		return json_decode((string) json_encode($schema));
	}//end decode()

	/**
	 * Turn the configurations or items a scenario names as "assoc" back into associative arrays.
	 *
	 * @param string $name   The scenario name.
	 * @param object $schema The decoded schema, changed in place.
	 *
	 * @return void
	 */
	private function asAssociativeArrays(string $name, object $schema): void {
		if (str_contains($name, 'assoc') === false) {
			return;
		}

		foreach ((array) $schema->properties as $property) {
			foreach (['objectConfiguration', 'items'] as $key) {
				if (str_contains($name, 'assoc ' . ($key === 'items' ? 'items' : 'config')) === true && isset($property->$key) === true) {
					$property->$key = (array) $property->$key;
				}
			}
		}
	}//end asAssociativeArrays()

	/**
	 * The schema transformSchemaForValidation() returns, or the class of what it throws.
	 *
	 * @param object $schema The schema to transform.
	 * @param string $slug   The current schema slug.
	 *
	 * @return mixed The recorded outcome.
	 */
	private function transformed(object $schema, string $slug): mixed {
		$method = new ReflectionMethod(ValidateObject::class, 'transformSchemaForValidation');
		$args   = [$schema, $slug];
		if ($method->getNumberOfParameters() === 3) {
			$args = [$schema, ['kept' => true], $slug];
		}

		try {
			$result = $method->invokeArgs($this->validator(), $args);
		} catch (Throwable $e) {
			return ['threw' => get_class($e)];
		}

		if (is_array($result) === true) {
			$this->assertSame(expected: ['kept' => true], actual: $result[1]);
			$result = $result[0];
		}

		return json_decode((string) json_encode($result), true);
	}//end transformed()

	/**
	 * What prepareSchemaForValidation() returns, or the class of what it throws.
	 *
	 * @param object      $schema The schema to prepare.
	 * @param string|null $slug   The slug of the schema entity, or null for none.
	 *
	 * @return mixed The recorded outcome.
	 */
	private function prepared(object $schema, ?string $slug): mixed {
		$entity = null;
		if ($slug !== null) {
			$entity = new Schema();
			if ($slug !== '(no slug)') {
				$entity->setSlug($slug);
			}
		}

		$method = new ReflectionMethod(ValidateObject::class, 'prepareSchemaForValidation');
		try {
			$result = $method->invoke($this->validator(), $schema, $entity);
		} catch (Throwable $e) {
			return ['threw' => get_class($e)];
		}

		return json_decode((string) json_encode($result), true);
	}//end prepared()

	/**
	 * A validator whose collaborators are inert doubles.
	 *
	 * @return ValidateObject
	 */
	private function validator(): ValidateObject {
		return new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
	}//end validator()
}//end class
