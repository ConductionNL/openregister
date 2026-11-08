<?php

/**
 * Golden test pinning how JSON Schema properties map to GraphQL output and input types.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\TypeMapperHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;

/**
 * Pins the output and input type of every type/format pair.
 *
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\TypeMapperHandler
 */
final class TypeMappingGoldenTest extends TestCase {

	/**
	 * The handler with named stand-in scalars.
	 *
	 * @return TypeMapperHandler
	 */
	private function handler(): TypeMapperHandler {
		$scalars = [];
		foreach (['DateTime', 'UUID', 'Email', 'URI', 'JSON'] as $name) {
			$scalars[$name] = new CustomScalarType(['name' => $name, 'serialize' => static fn ($v) => $v]);
		}

		return new TypeMapperHandler(
			$scalars,
			static fn () => null,
			static fn () => null,
			static fn (string $name): string => $name,
			static fn (string $name): string => $name,
			$this->createMock(PropertyRbacHandler::class)
		);
	}//end handler()

	/**
	 * Each property with its output type and input type, by name.
	 *
	 * @return array<string, array{array<string, mixed>, string, string}>
	 */
	public static function properties(): array {
		return [
			'no type' => [[], 'String', 'String'],
			'string' => [['type' => 'string'], 'String', 'String'],
			'date-time' => [['type' => 'string', 'format' => 'date-time'], 'DateTime', 'DateTime'],
			'date' => [['type' => 'string', 'format' => 'date'], 'DateTime', 'DateTime'],
			'uuid' => [['type' => 'string', 'format' => 'uuid'], 'UUID', 'UUID'],
			'email' => [['type' => 'string', 'format' => 'email'], 'Email', 'Email'],
			'uri' => [['type' => 'string', 'format' => 'uri'], 'URI', 'URI'],
			'url' => [['type' => 'string', 'format' => 'url'], 'URI', 'String'],
			'unknown format' => [['type' => 'string', 'format' => 'phone'], 'String', 'String'],
			'non-string format' => [['type' => 'string', 'format' => 5], 'String', 'String'],
			'array format' => [['type' => 'string', 'format' => ['uuid']], 'String', 'String'],
			'format on integer' => [['type' => 'integer', 'format' => 'uuid'], 'Int', 'Int'],
			'integer' => [['type' => 'integer'], 'Int', 'Int'],
			'number' => [['type' => 'number'], 'Float', 'Float'],
			'boolean' => [['type' => 'boolean'], 'Boolean', 'Boolean'],
			'object' => [['type' => 'object'], 'JSON', 'JSON'],
			'array' => [['type' => 'array'], '[String]', 'JSON'],
			'array of uuid' => [['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], '[UUID]', 'JSON'],
			'array of ref' => [['type' => 'array', 'items' => ['$ref' => 'x']], '[String]', '[ID]'],
			'object ref' => [['type' => 'object', '$ref' => 'x'], 'JSON', 'ID'],
			'null' => [['type' => 'null'], 'String', 'String'],
			'type list' => [['type' => ['string', 'null']], 'String', 'String'],
		];
	}//end properties()

	/**
	 * The output and input type of a property.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $output   The output type's name.
	 * @param string               $input    The input type's name.
	 *
	 * @return void
	 *
	 * @dataProvider properties
	 */
	public function testMapping(array $property, string $output, string $input): void {
		$handler = $this->handler();
		$this->assertSame($output, $this->printed($handler->mapPropertyToGraphQLType(property: $property)));
		$this->assertSame($input, $this->printed($handler->mapPropertyToInputType(property: $property)));
	}//end testMapping()

	/**
	 * The printed name of a type.
	 *
	 * @param Type $type The type.
	 *
	 * @return string
	 */
	private function printed(Type $type): string {
		return $type->toString();
	}//end printed()
}//end class
