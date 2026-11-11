<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\McpDiscoveryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the MCP discovery catalog and every capability detail.
 *
 * The output of getCatalog(), getCapabilityIds() and getCapabilityDetail() for each
 * capability (and an unknown one) is compared with fixtures/mcp-discovery-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class McpDiscoveryServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mcp-discovery-golden.json';

	public function testDiscoveryOutputMatchesGolden(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([
			$this->entity(entity: new Register(), id: 1, values: ['uuid' => 'reg-1', 'title' => 'Zaken', 'schemas' => [10, 11, 99]]),
			$this->entity(entity: new Register(), id: 2, values: ['uuid' => 'reg-2', 'title' => 'Leeg', 'schemas' => []]),
		]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([
			$this->entity(entity: new Schema(), id: 10, values: ['uuid' => 's-10', 'title' => 'Zaak', 'properties' => ['a' => [], 'b' => []]]),
			$this->entity(entity: new Schema(), id: 11, values: ['uuid' => 's-11', 'title' => 'Document', 'properties' => []]),
		]);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => '/route/' . $route . '?' . http_build_query($args)
		);

		$service = new McpDiscoveryService(new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)), $urlGenerator);
		$actual = ['catalog' => $service->getCatalog(), 'ids' => $service->getCapabilityIds(), 'unknown' => $service->getCapabilityDetail('nope')];
		foreach ($service->getCapabilityIds() as $id) {
			$actual['detail'][$id] = $service->getCapabilityDetail($id);
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testDiscoveryOutputMatchesGolden()

	private function entity(object $entity, int $id, array $values): object {
		foreach ($values as $key => $value) {
			$entity->{'set' . ucfirst($key)}($value);
		}

		$property = new \ReflectionProperty($entity, 'id');
		$property->setAccessible(true);
		$property->setValue($entity, $id);
		return $entity;
	}//end entity()
}//end class
