<?php

/**
 * SchemaMapper::getRelated() is a catalog read and bypasses multitenancy.
 *
 * `GET /api/schemas/{id}/related` answered "Schema not found" for a non-admin
 * whose active organisation does not own the schema (Woo round 3, 2026-10-02),
 * while `GET /api/schemas/{id}` answered 200: getRelated() looked the schema up
 * with multitenancy on. SchemasController::related() already reads the outgoing
 * half with the metadata-read bypass; this pins the incoming half to the same.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaMapper::class)]
class SchemaMapperGetRelatedTenancyTest extends TestCase {

	/**
	 * A schema with an id, slug and properties.
	 *
	 * @param int                  $id         Schema id
	 * @param string               $slug       Schema slug
	 * @param array<string, mixed> $properties Schema properties
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('uuid-' . $slug);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * Both lookups run with multitenancy off, and the referring schema is found.
	 */
	public function testBothLookupsBypassMultitenancy(): void {
		$target = $this->schema(961, 'module');
		$referrer = $this->schema(1397, 'changeProposal', ['application' => ['type' => 'string', '$ref' => 'module']]);

		$mapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();

		$findTenancy = [];
		$mapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use (&$findTenancy, $target): Schema {
				$findTenancy[] = $_multitenancy;
				return $target;
			}
		);
		$findAllTenancy = [];
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use (&$findAllTenancy, $target, $referrer): array {
				$findAllTenancy[] = ($args['_multitenancy'] ?? $args[7] ?? true);
				return [$target, $referrer];
			}
		);

		$related = $mapper->getRelated('module');

		$this->assertSame([false], $findTenancy, 'The schema lookup must bypass multitenancy.');
		$this->assertSame([false], $findAllTenancy, 'The scan of all schemas must bypass multitenancy.');
		$this->assertSame([1397], array_map(fn (Schema $schema): ?int => $schema->getId(), $related));
	}//end testBothLookupsBypassMultitenancy()
}//end class
