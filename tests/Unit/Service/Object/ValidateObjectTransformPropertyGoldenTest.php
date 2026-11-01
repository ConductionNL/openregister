<?php

/**
 * ValidateObject::transformPropertyForOpenRegister() rewrites a property schema for validation exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden record of the property-schema rewrite over every branch: unusable `$ref`s, inversedBy
 * relations on arrays and objects, array items (as arrays or objects, with inversedBy, a `$ref`
 * or object items with their own properties), direct object properties, string and untyped `$ref`s
 * and nested properties. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 */
class ValidateObjectTransformPropertyGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/validate-object-transform-property-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$scenarios = [
			'plain string'                 => ['type' => 'string'],
			'unusable int ref'             => ['type' => 'array', '$ref' => 12, 'items' => ['type' => 'string']],
			'unusable empty ref'           => ['$ref' => ''],
			'inversedBy array'             => ['type' => 'array', 'inversedBy' => 'owner', 'items' => ['type' => 'object', '$ref' => 'person']],
			'inversedBy object'            => ['type' => 'object', 'inversedBy' => 'owner', '$ref' => 'person', 'pattern' => 'x', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
			'inversedBy string'            => ['type' => 'string', 'inversedBy' => 'owner', '$ref' => 'person'],
			'inversedBy empty'             => ['type' => 'array', 'inversedBy' => '', 'items' => ['type' => 'string']],
			'inversedBy without type'      => ['inversedBy' => 'owner', '$ref' => 'person'],
			'array items as array'         => ['type' => 'array', 'items' => ['$ref' => 'agent', 'format' => 'uuid']],
			'array items inversedBy'       => ['type' => 'array', 'items' => ['type' => 'object', 'inversedBy' => 'parent', 'properties' => ['x' => ['type' => 'string']], 'required' => ['x'], '$ref' => 'p']],
			'array items ref'              => ['type' => 'array', 'items' => ['type' => 'object', '$ref' => 'material', 'properties' => ['y' => ['type' => 'integer']], 'required' => ['y']]],
			'array items unusable ref'     => ['type' => 'array', 'items' => ['type' => 'string', '$ref' => 7]],
			'array items object'           => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['planId' => ['type' => 'string', '$ref' => 'plan'], 'free' => ['$ref' => 'besluit'], 'deep' => ['type' => 'object', 'properties' => ['z' => ['type' => 'string', '$ref' => 'zed']]]]]],
			'array items scalar'           => ['type' => 'array', 'items' => ['type' => 'integer']],
			'array items string'           => ['type' => 'array', 'items' => 'string'],
			'array without items'          => ['type' => 'array'],
			'object property'              => ['type' => 'object', '$ref' => 'address', 'properties' => ['street' => ['type' => 'string', '$ref' => 'street']]],
			'object property, no ref'      => ['type' => 'object', 'properties' => ['n' => ['type' => 'number']], 'required' => ['n']],
			'string ref'                   => ['type' => 'string', '$ref' => 'person', 'format' => 'uuid'],
			'bare slug ref'                => ['$ref' => 'besluit'],
			'fragment ref kept'            => ['$ref' => '#/definitions/x'],
			'uri ref kept'                 => ['$ref' => 'https://example.org/schema'],
			'typed number ref kept'        => ['type' => 'number', '$ref' => 'measure'],
			'nested properties'            => ['type' => 'object', 'properties' => ['inner' => ['type' => 'array', 'items' => ['$ref' => 'thing']], 'other' => ['type' => 'string', '$ref' => 'o']]],
			'inversedBy object, fragment ref' => ['type' => 'object', 'inversedBy' => 'owner', '$ref' => '#/defs/p'],
			'fragment-only ref kept'       => ['$ref' => '#anchor'],
			'object related-object'        => ['type' => 'object', 'objectConfiguration' => ['handling' => 'related-object'], 'properties' => ['a' => ['type' => 'string']]],
			'object nested-object'         => ['type' => 'object', 'objectConfiguration' => ['handling' => 'nested-object'], 'properties' => ['a' => ['type' => 'string']]],
			'array items related-schema'   => ['type' => 'array', 'items' => ['type' => 'object', 'objectConfiguration' => ['handling' => 'related-schema'], 'properties' => ['b' => ['type' => 'string']]]],
			'untyped with properties'      => ['properties' => ['p' => ['type' => 'string', '$ref' => 'q']]],
		];

		$validator = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
		$transform = new ReflectionMethod(ValidateObject::class, 'transformPropertyForOpenRegister');

		$actual = [];
		foreach ($scenarios as $name => $propertySchema) {
			$schema = json_decode((string) json_encode($propertySchema));
			if ($name === 'array items as array') {
				$schema->items = ['$ref' => 'agent', 'format' => 'uuid'];
			}

			$transform->invoke($validator, $schema);
			$actual[$name] = json_decode((string) json_encode($schema), true);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()
}//end class
