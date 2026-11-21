<?php

/**
 * The MCP catalog summaries list registers, schemas and their nesting from the catalog.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\McpCatalogSummaries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\McpCatalogSummaries
 */
class McpCatalogSummariesTest extends TestCase {

	private function register(int $id, string $title, ?array $schemas = null): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle($title);
		$register->setUuid('reg-' . $id);
		if ($schemas !== null) {
			$register->setSchemas($schemas);
		}

		return $register;
	}//end register()

	private function schema(int $id, string $title, ?array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle($title);
		$schema->setUuid('sch-' . $id);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private function catalog(array $registers, array $schemas): SchemaCatalogInterface {
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('findAll')->willReturn($registers);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('findAll')->willReturn($schemas);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($registerMapper);
		$catalog->method('schemas')->willReturn($schemaMapper);
		$catalog->method('tables')->willReturn($this->createMock(MagicMapperInterface::class));

		return $catalog;
	}//end catalog()

	public function testRegistersAreSummarised(): void {
		$summaries = new McpCatalogSummaries($this->catalog([$this->register(1, 'Klanten')], []));

		$this->assertSame([['id' => 1, 'title' => 'Klanten', 'uuid' => 'reg-1']], $summaries->registers());
	}//end testRegistersAreSummarised()

	public function testSchemasCarryTheirPropertyCount(): void {
		$catalog = $this->catalog(
			[],
			[$this->schema(9, 'Klant', ['a' => [], 'b' => []]), $this->schema(10, 'Order', null)]
		);

		$this->assertSame(
			[
				['id' => 9, 'title' => 'Klant', 'uuid' => 'sch-9', 'property_count' => 2],
				['id' => 10, 'title' => 'Order', 'uuid' => 'sch-10', 'property_count' => 0],
			],
			(new McpCatalogSummaries($catalog))->schemas()
		);
	}//end testSchemasCarryTheirPropertyCount()

	public function testRegistersWithSchemasNestOnlyTheSchemasTheRegisterCarries(): void {
		$catalog = $this->catalog(
			[$this->register(1, 'Klanten', [9, 404])],
			[$this->schema(9, 'Klant', []), $this->schema(10, 'Order', [])]
		);

		$this->assertSame(
			[['id' => 1, 'title' => 'Klanten', 'schemas' => [['id' => 9, 'title' => 'Klant']]]],
			(new McpCatalogSummaries($catalog))->registersWithSchemas()
		);
	}//end testRegistersWithSchemasNestOnlyTheSchemasTheRegisterCarries()

	public function testARegisterWithoutASchemaListNestsNothing(): void {
		$catalog = $this->catalog([$this->register(1, 'Klanten', null)], [$this->schema(9, 'Klant', [])]);

		$this->assertSame([['id' => 1, 'title' => 'Klanten', 'schemas' => []]], (new McpCatalogSummaries($catalog))->registersWithSchemas());
	}//end testARegisterWithoutASchemaListNestsNothing()
}//end class
