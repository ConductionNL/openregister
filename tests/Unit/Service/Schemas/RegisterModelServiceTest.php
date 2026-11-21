<?php

/**
 * RegisterModelService tests: nodes and edges from `$ref`, `items.$ref`,
 * `inversedBy` and declared relations, with external, nameless and dangling
 * ends.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCA\OpenRegister\Service\Schemas\RegisterModelService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegisterModelService.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\RegisterModelService
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md
 */
class RegisterModelServiceTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private $registers;

	/**
	 * External schema reads.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private $schemas;

	/**
	 * The ref resolver.
	 *
	 * @var RelationCascadeHandler&MockObject
	 */
	private $refs;

	/**
	 * Every scope the resolver was asked with.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $scopes = [];

	/**
	 * Set up the mocks: refs resolve by slug, uuid or id to the ids below.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->refs = $this->createMock(RelationCascadeHandler::class);
		$known = [
			'zaak' => '1', '#/components/schemas/zaak' => '1',
			'document' => '2', 'b2c3d4e5-0000-4000-8000-000000000002' => '2',
			'contact' => '3', '3' => '3',
			'persoon' => '40', 'geheim' => '41', 'customer' => '3',
		];
		$this->refs->method('resolveSchemaReference')->willReturnCallback(
			function (string $reference, array $scope = []) use ($known): ?string {
				$this->scopes[] = $scope;
				return ($known[$reference] ?? null);
			}
		);
	}//end setUp()

	/**
	 * A real schema.
	 *
	 * @param int                  $id            The id.
	 * @param string               $slug          The slug.
	 * @param array<string, mixed> $properties    The properties.
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, array $properties, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * The model of a register holding these schemas.
	 *
	 * @param list<Schema> $schemas The register's schemas.
	 *
	 * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
	 */
	private function model(array $schemas): array {
		$register = new Register();
		$register->setId(9);
		$this->registers->expects($this->once())->method('getSchemasByRegisterId')->with(9)->willReturn($schemas);

		return (new RegisterModelService(registers: $this->registers, schemas: $this->schemas, refs: $this->refs))->model(register: $register);
	}//end model()

	/**
	 * The zaak/document/contact register: three nodes, a many edge with its inverse drawn once, a one edge.
	 *
	 * @return void
	 */
	public function testAThreeSchemaRegister(): void {
		$model = $this->model(
			[
				$this->schema(id: 1, slug: 'zaak', properties: [
					'titel' => ['type' => 'string'],
					'documenten' => ['type' => 'array', 'items' => ['$ref' => 'document'], 'inversedBy' => 'zaak'],
					'contact' => ['type' => 'object', '$ref' => '3'],
				]),
				$this->schema(id: 2, slug: 'document', properties: [
					'zaak' => ['type' => 'object', '$ref' => '#/components/schemas/zaak', 'inversedBy' => 'documenten'],
				]),
				$this->schema(id: 3, slug: 'contact', properties: ['naam' => ['type' => 'string'], 'odd' => 'not a definition']),
			]
		);

		$this->assertSame(['1', '2', '3'], array_column($model['nodes'], 'id'));
		$this->assertSame(
			[['name' => 'titel', 'type' => 'string'], ['name' => 'documenten', 'type' => 'array'], ['name' => 'contact', 'type' => 'object']],
			$model['nodes'][0]['properties']
		);
		$this->assertSame(['name' => 'odd', 'type' => null], $model['nodes'][2]['properties'][1]);
		$this->assertFalse($model['nodes'][0]['external']);
		$this->assertSame('Zaak', $model['nodes'][0]['title']);
		$this->assertSame(
			[
				'from' => '1', 'to' => '2', 'property' => 'documenten', 'cardinality' => 'many', 'inverse' => 'zaak',
				'source' => 'property', 'target' => 'document', 'dangling' => false,
			],
			$model['edges'][0]
		);
		$this->assertSame(['1', '3', 'contact', 'one'], [$model['edges'][1]['from'], $model['edges'][1]['to'], $model['edges'][1]['property'], $model['edges'][1]['cardinality']]);
		$this->assertCount(2, $model['edges'], 'The inverse half of documenten/zaak must not be drawn as a second line.');
		$this->assertSame([RelationCascadeHandler::SYSTEM_SCOPE], array_values(array_unique($this->scopes, SORT_REGULAR)));
	}//end testAThreeSchemaRegister()

	/**
	 * An inverse twin with a different label is a different link and stays.
	 *
	 * @return void
	 */
	public function testAnInverseThatDoesNotMatchIsKept(): void {
		$model = $this->model(
			[
				$this->schema(id: 1, slug: 'zaak', properties: ['documenten' => ['items' => ['$ref' => 'b2c3d4e5-0000-4000-8000-000000000002'], 'inversedBy' => 'zaak']]),
				$this->schema(id: 2, slug: 'document', properties: ['hoofdzaak' => ['$ref' => 'zaak']]),
			]
		);

		$this->assertCount(2, $model['edges']);
	}//end testAnInverseThatDoesNotMatchIsKept()

	/**
	 * A ref into another register adds an external node; one the caller may not read stays nameless.
	 *
	 * @return void
	 */
	public function testExternalNodesAndAnUnreadableOne(): void {
		$persoon = $this->schema(id: 40, slug: 'persoon', properties: ['bsn' => ['type' => 'string']]);
		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($persoon): Schema {
				if ((string)$id === '40') {
					return $persoon;
				}

				throw new DoesNotExistException('not readable');
			}
		);

		$model = $this->model(
			[$this->schema(id: 1, slug: 'zaak', properties: ['aanvrager' => ['$ref' => 'persoon'], 'dossier' => ['$ref' => 'geheim']])]
		);

		$this->assertSame(['1', '40', '41'], array_column($model['nodes'], 'id'));
		$this->assertTrue($model['nodes'][1]['external']);
		$this->assertTrue($model['nodes'][1]['readable']);
		$this->assertSame('Persoon', $model['nodes'][1]['title']);
		$this->assertSame(
			['id' => '41', 'slug' => null, 'title' => null, 'external' => true, 'readable' => false, 'properties' => []],
			$model['nodes'][2]
		);
	}//end testExternalNodesAndAnUnreadableOne()

	/**
	 * A ref to a deleted schema is a dangling edge, and adds no node.
	 *
	 * @return void
	 */
	public function testABrokenRefIsADanglingEdge(): void {
		$model = $this->model([$this->schema(id: 1, slug: 'zaak', properties: ['besluit' => ['$ref' => 'deleted-schema']])]);

		$this->assertSame(['1'], array_column($model['nodes'], 'id'));
		$this->assertNull($model['edges'][0]['to']);
		$this->assertTrue($model['edges'][0]['dangling']);
		$this->assertSame('deleted-schema', $model['edges'][0]['target']);
	}//end testABrokenRefIsADanglingEdge()

	/**
	 * Declared relations are edges; a duplicate of a $ref link is drawn once; an unresolved target dangles.
	 *
	 * @return void
	 */
	public function testDeclaredRelations(): void {
		$model = $this->model(
			[
				$this->schema(
					id: 2,
					slug: 'order',
					properties: ['klant' => ['$ref' => 'customer']],
					configuration: [
						'x-openregister-relations' => [
							['name' => 'koper', 'target' => 'customer', 'cardinality' => 'one', 'inverseOf' => 'orders'],
							['name' => 'klant', 'target' => '3', 'cardinality' => 'one'],
							['name' => 'regels', 'target' => 'orderline', 'cardinality' => 'many'],
							['name' => '', 'target' => 'customer'],
							'not an entry',
						],
					]
				),
				$this->schema(id: 3, slug: 'customer', properties: []),
			]
		);

		$this->assertSame(['klant', 'koper', 'regels'], array_column($model['edges'], 'property'));
		$this->assertSame(
			[
				'from' => '2', 'to' => '3', 'property' => 'koper', 'cardinality' => 'one', 'inverse' => 'orders',
				'source' => 'declared', 'target' => 'customer', 'dangling' => false,
			],
			$model['edges'][1]
		);
		$this->assertSame('property', $model['edges'][0]['source']);
		$this->assertSame(['many', true, null], [$model['edges'][2]['cardinality'], $model['edges'][2]['dangling'], $model['edges'][2]['to']]);
	}//end testDeclaredRelations()

	/**
	 * A register with no links draws its schemas with no lines; a malformed relations block is ignored.
	 *
	 * @return void
	 */
	public function testARegisterWithoutLinks(): void {
		$model = $this->model(
			[
				$this->schema(id: 1, slug: 'zaak', properties: ['titel' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]),
				$this->schema(id: 3, slug: 'contact', properties: [], configuration: ['x-openregister-relations' => 'not a list']),
			]
		);

		$this->assertCount(2, $model['nodes']);
		$this->assertSame([], $model['edges']);
	}//end testARegisterWithoutLinks()
}//end class
