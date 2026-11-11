<?php

/**
 * Characterisation tests for TypeMapperHandler's create input and connection types.
 *
 * Pins the printed shape of `Create<Type>Input` (required properties become
 * non-null, a required name that is not a field is ignored, an empty schema
 * gets a `_placeholder`, a schema without a slug is named after its id) and of
 * the `<Type>Connection` / `<Type>Edge` pair, plus the per-schema caching of
 * both.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\SchemaPrinter;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLNames;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\TypeMapperHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;

final class TypeMapperInputAndConnectionTypesTest extends TestCase {

	private function handler(): TypeMapperHandler {
		$scalars = [];
		foreach (['DateTime', 'UUID', 'Email', 'URI', 'JSON'] as $name) {
			$scalars[$name] = new CustomScalarType(['name' => $name, 'serialize' => static fn ($v) => $v]);
		}

		$names = $this->createMock(GraphQLNames::class);
		$names->method('toFieldName')->willReturnCallback(static fn ($name) => str_replace('-', '_', (string) $name));
		$names->method('toTypeName')->willReturnCallback(static fn ($slug) => ucfirst((string) $slug));

		return new TypeMapperHandler(
			$scalars,
			static fn () => null,
			$names,
			$this->createMock(PropertyRbacHandler::class)
		);
	}

	private static function schema(int $id, ?string $slug, array $properties, ?array $required): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		$schema->setRequired($required);
		return $schema;
	}

	public function testRequiredPropertiesBecomeNonNullAndUnknownRequiredNamesAreIgnored(): void {
		$schema = self::schema(
			7,
			'zaak',
			[
				'naam' => ['type' => 'string'],
				'bsn-nummer' => ['type' => 'integer'],
				'tags' => ['type' => 'array', 'items' => ['$ref' => 'x']],
				'geen-array' => 'x',
			],
			['bsn-nummer', 'tags', 'bestaat-niet']
		);

		$this->assertSame(
			"input CreateZaakInput {\n  naam: String\n  bsn_nummer: Int!\n  tags: [ID]!\n}",
			SchemaPrinter::printType($this->handler()->getCreateInputType($schema))
		);
	}

	public function testWithoutRequiredEveryFieldStaysNullable(): void {
		$schema = self::schema(8, 'doos', ['maat' => ['type' => 'number']], null);

		$this->assertSame(
			"input CreateDoosInput {\n  maat: Float\n}",
			SchemaPrinter::printType($this->handler()->getCreateInputType($schema))
		);
	}

	public function testAnEmptySchemaGetsAPlaceholderAndASlugLessSchemaIsNamedAfterItsId(): void {
		$this->assertSame(
			"input CreateSchema9Input {\n  _placeholder: String\n}",
			SchemaPrinter::printType($this->handler()->getCreateInputType(self::schema(9, null, [], ['x'])))
		);
		$this->assertSame(
			"input CreateSchema10Input {\n  _placeholder: String\n}",
			SchemaPrinter::printType($this->handler()->getCreateInputType(self::schema(10, '', [], null)))
		);
	}

	public function testTheCreateInputIsBuiltOncePerSchema(): void {
		$handler = $this->handler();
		$schema = self::schema(7, 'zaak', ['naam' => ['type' => 'string']], null);

		$this->assertSame($handler->getCreateInputType($schema), $handler->getCreateInputType($schema));
	}

	public function testTheConnectionAndEdgeTypes(): void {
		$handler = $this->handler();
		$object = new ObjectType(['name' => 'Zaak', 'fields' => ['id' => Type::id()]]);
		$connection = $handler->getConnectionType(self::schema(7, 'zaak', [], null), $object);

		$this->assertSame(
			"type ZaakConnection {\n"
			. "  edges: [ZaakEdge!]!\n"
			. "  pageInfo: PageInfo!\n"
			. "  totalCount: Int!\n"
			. "  facets: JSON\n"
			. "  facetable: [String]\n"
			. "\n"
			. "  \"Ad-hoc bucket aggregation result; null unless `groupBy` was supplied.\"\n"
			. "  groups: [GroupBucket!]\n"
			. "\n"
			. "  \"Declared-aggregation result envelope; null unless `aggregation` was supplied.\"\n"
			. "  aggregation: JSON\n"
			. "}",
			SchemaPrinter::printType($connection)
		);

		$edge = Type::getNamedType($connection->getField('edges')->getType());
		$this->assertSame(
			"type ZaakEdge {\n"
			. "  cursor: String!\n"
			. "  node: Zaak!\n"
			. "\n"
			. "  \"Fuzzy search relevance score (0-100)\"\n"
			. "  _relevance: Float\n"
			. "}",
			SchemaPrinter::printType($edge)
		);
		$this->assertSame(
			"type PageInfo {\n  hasNextPage: Boolean!\n  hasPreviousPage: Boolean!\n  startCursor: String\n  endCursor: String\n}",
			SchemaPrinter::printType(Type::getNamedType($connection->getField('pageInfo')->getType()))
		);
	}

	public function testTheConnectionIsBuiltOncePerSchema(): void {
		$handler = $this->handler();
		$object = new ObjectType(['name' => 'Zaak', 'fields' => ['id' => Type::id()]]);
		$schema = self::schema(7, 'zaak', [], null);

		$this->assertSame($handler->getConnectionType($schema, $object), $handler->getConnectionType($schema, $object));
	}
}
