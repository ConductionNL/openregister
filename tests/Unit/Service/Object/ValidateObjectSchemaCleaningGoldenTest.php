<?php

/**
 * ValidateObject's schema cleaning strips metadata and rewrites types exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Golden record of cleanSchemaForValidation() and cleanPropertyForValidation() over metadata
 * keywords (set, null and absent), nested properties and items, custom types as a string or a
 * list, misplaced enum and oneOf on arrays, and object items with and without relation markers.
 * The record is the JSON encoding, so an empty object and an empty list stay apart. Set
 * OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 */
class ValidateObjectSchemaCleaningGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/validate-object-schema-cleaning-golden.json';

	/**
	 * Every schema and property scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $json) {
			$actual['schema: ' . $name] = $this->cleaned(method: 'cleanSchemaForValidation', args: [json_decode($json)]);
			$actual['property: ' . $name] = $this->cleaned(method: 'cleanPropertyForValidation', args: [json_decode($json), false]);
			$actual['items: ' . $name] = $this->cleaned(method: 'cleanPropertyForValidation', args: [json_decode($json), true]);
		}

		$actual['property: scalar'] = $this->cleaned(method: 'cleanPropertyForValidation', args: ['plain', false]);
		$actual['property: list']   = $this->cleaned(method: 'cleanPropertyForValidation', args: [['type' => 'file'], true]);

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * Schemas as JSON, decoded to objects the way a stored schema arrives.
	 *
	 * @return array<string, string>
	 */
	private function scenarios(): array {
		$meta = '"cascadeDelete":true,"objectConfiguration":{"handling":"related-object"},"inversedBy":"x","mappedBy":"y","targetEntity":"t","fetch":"lazy","indexBy":"i","orphanRemoval":true,"joinColumns":[1],"inverseJoinColumns":[2],"joinTable":"j","uniqueConstraints":[3],"indexes":[4],"options":{"a":1},"computed":"{{ a }}","calculation":{"+":[1,2]}';
		return [
			'empty'                      => '{}',
			'every metadata keyword'     => '{"type":"object",' . $meta . ',"title":"kept"}',
			'null metadata kept'         => '{"type":"string","cascadeDelete":null,"computed":null,"fetch":false,"options":0}',
			'nested metadata'            => '{"type":"object","properties":{"a":{"type":"string",' . $meta . '},"b":{"type":"object","properties":{"c":{"type":"uuid","mappedBy":"m"}}}}}',
			'custom string types'        => '{"type":"object","properties":{"f":{"type":"file"},"d":{"type":"datetime"},"e":{"type":"date"},"t":{"type":"time"},"u":{"type":"uuid"},"l":{"type":"url"},"m":{"type":"email"},"p":{"type":"phone"},"c":{"type":"color"},"r":{"type":"recurrence"},"s":{"type":"string"},"n":{"type":"number"}}}',
			'custom type list'           => '{"type":["file","null"],"properties":{"x":{"type":["date","string","phone"]}}}',
			'custom type top level'      => '{"type":"email"}',
			'non-string type'            => '{"type":5,"properties":{"y":{"type":true}}}',
			'array enum moved'           => '{"type":"array","enum":["a","b"]}',
			'array enum, items enum'     => '{"type":"array","enum":["a"],"items":{"type":"integer","enum":[1]}}',
			'array enum, typed items'    => '{"type":"array","enum":["a"],"items":{"type":"integer"}}',
			'array empty enum'           => '{"type":"array","enum":[]}',
			'array enum not a list'      => '{"type":"array","enum":"a"}',
			'array oneOf list'           => '{"type":"array","oneOf":[{"type":"string"},{"type":"integer"}]}',
			'array oneOf object'         => '{"type":"array","oneOf":{"a":{"type":"string"}}}',
			'array empty oneOf'          => '{"type":"array","oneOf":[]}',
			'array empty oneOf object'   => '{"type":"array","oneOf":{}}',
			'array oneOf, items oneOf'   => '{"type":"array","oneOf":[{"type":"string"}],"items":{"oneOf":[{"type":"integer"}]}}',
			'array oneOf scalar'         => '{"type":"array","oneOf":"x"}',
			'array enum, untyped items' => '{"type":"array","enum":["a"],"items":{"minLength":1}}',
			'array enum, list items'     => '{"type":"array","enum":["a"],"items":[{"type":"string"}]}',
			'array oneOf, list items'    => '{"type":"array","oneOf":[{"type":"string"}],"items":[{"type":"string"}]}',
			'array oneOf, empty items'   => '{"type":"array","oneOf":[{"type":"string"}],"items":{}}',
			'array enum, null items'     => '{"type":"array","enum":["a"],"items":null}',
			'array enum and oneOf'       => '{"type":"array","enum":["a"],"oneOf":[{"type":"string"}]}',
			'array both, items present'  => '{"type":"array","enum":["a"],"oneOf":[{"type":"string"}],"items":{"type":"string"}}',
			'string with enum'           => '{"type":"string","enum":["a"],"oneOf":[{"type":"string"}]}',
			'nested array enum'          => '{"type":"object","properties":{"tags":{"type":"array","enum":["x"],"items":{"type":"string"}}}}',
			'items related-object'       => '{"type":"array","items":{"type":"object","objectConfiguration":{"handling":"related-object"},"properties":{"n":{"type":"string"}}}}',
			'items nested-object'        => '{"type":"array","items":{"type":"object","objectConfiguration":{"handling":"nested-object"},"$ref":"#/components/schemas/x"}}',
			'items with ref'             => '{"type":"array","items":{"type":"object","$ref":"#/components/schemas/x"}}',
			'items inline value object'  => '{"type":"array","items":{"type":"object","additionalProperties":false,"properties":{"register":{"type":"string"}}}}',
			'items bare object'          => '{"type":"array","items":{"type":"object"}}',
			'items untyped'              => '{"type":"array","items":{"$ref":"#/components/schemas/x","inversedBy":"p"}}',
			'items scalar'               => '{"type":"array","items":"string"}',
			'items custom type'          => '{"type":"array","items":{"type":"file","fetch":"x"}}',
			'items nested items'         => '{"type":"array","items":{"type":"array","items":{"type":"object","$ref":"r"}}}',
			'item schema items nested'   => '{"type":"object","items":{"type":"object","properties":{"a":{"type":"date"}},"items":{"type":"uuid"}}}',
			'properties empty list'      => '{"type":"object","properties":[]}',
			'properties empty object'    => '{"type":"object","properties":{}}',
			'property scalar'            => '{"type":"object","properties":{"a":true,"b":"x"}}',
			'deep nesting'               => '{"properties":{"a":{"properties":{"b":{"properties":{"c":{"type":"array","enum":[1],"inversedBy":"q"}}}}}}}',
		];
	}//end scenarios()

	/**
	 * The JSON encoding of what the method returns, or the class of what it throws.
	 *
	 * @param string $method The private method to call.
	 * @param array  $args   The arguments.
	 *
	 * @return string The recorded outcome.
	 */
	private function cleaned(string $method, array $args): string {
		try {
			$result = (new ReflectionMethod(ValidateObject::class, $method))->invokeArgs($this->validator(), $args);
		} catch (Throwable $e) {
			return 'threw ' . get_class($e);
		}

		return (string) json_encode($result, JSON_UNESCAPED_SLASHES);
	}//end cleaned()

	/**
	 * A validator whose collaborators are inert doubles.
	 *
	 * @return ValidateObject
	 */
	private function validator(): ValidateObject {
		return new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $this->createMock(IURLGenerator::class)),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end validator()
}//end class
