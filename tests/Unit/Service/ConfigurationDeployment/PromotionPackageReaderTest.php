<?php

/**
 * Unit tests for what a promotion carries out of the source organisation.
 *
 * Registers and schemas come from the configuration export, matched back to
 * their source uuid by slug; mappings, sources, webhooks and endpoints come
 * from their mappers, only the source organisation's rows, without the fields
 * that belong to the source instance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use JsonSerializable;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\ConfigurationDeployment\PromotionComponent;
use OCA\OpenRegister\Service\ConfigurationDeployment\PromotionPackageReader;
use OCA\OpenRegister\Service\ConfigurationTransferInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromotionPackageReader::class)]
#[UsesClass(PromotionComponent::class)]
#[UsesClass(ListQuery::class)]
final class PromotionPackageReaderTest extends TestCase {

	private const ORG = 'org-source';

	private ConfigurationTransferInterface&MockObject $export;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private MappingMapper&MockObject $mappings;

	private SourceMapper&MockObject $sources;

	private WebhookMapperInterface&MockObject $webhooks;

	private EndpointMapper&MockObject $endpoints;

	protected function setUp(): void {
		parent::setUp();

		$this->export    = $this->createMock(ConfigurationTransferInterface::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas   = $this->createMock(SchemaMapper::class);
		$this->mappings  = $this->createMock(MappingMapper::class);
		$this->sources   = $this->createMock(SourceMapper::class);
		$this->webhooks  = $this->createMock(WebhookMapperInterface::class);
		$this->endpoints = $this->createMock(EndpointMapper::class);
	}

	private function reader(): PromotionPackageReader {
		return new PromotionPackageReader(
			export: $this->export,
			registers: $this->registers,
			schemas: $this->schemas,
			mappings: $this->mappings,
			sources: $this->sources,
			webhooks: $this->webhooks,
			endpoints: $this->endpoints
		);
	}

	/**
	 * A row as a mapper returns it.
	 *
	 * @param array<string, mixed> $row The serialised row.
	 */
	private function row(array $row): JsonSerializable {
		$entity = $this->createMock(JsonSerializable::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}

	private function register(string $uuid, string $slug): Register&MockObject {
		$register = $this->createMock(Register::class);
		$register->method('jsonSerialize')->willReturn(['uuid' => $uuid, 'slug' => $slug, 'organisation' => self::ORG]);

		return $register;
	}

	private function schema(string $uuid, string $slug): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['uuid' => $uuid, 'slug' => $slug]);

		return $schema;
	}

	public function testRegistersAndSchemasComeFromTheExportWithTheirSourceUuidBySlug(): void {
		$cases = $this->register('reg-cases', 'cases');
		$this->registers->expects($this->once())->method('findAll')
			->with(null, null, ['organisation' => self::ORG], [], [], false, false)
			->willReturn([$cases]);
		$this->schemas->expects($this->once())->method('findAll')
			->with(null, null, ['organisation' => self::ORG], [], [], [], false, false)
			->willReturn([$this->schema('sch-case', 'case')]);
		$this->export->expects($this->once())->method('exportConfig')->with($cases)->willReturn(
			[
				'components' => [
					'registers' => ['cases' => ['slug' => 'cases', 'schemas' => ['case', 'note']]],
					'schemas' => ['case' => ['slug' => 'case'], 'note' => ['slug' => 'note']],
				],
			]
		);
		$this->mappings->method('findAll')->willReturn([]);
		$this->sources->method('findAll')->willReturn([]);
		$this->webhooks->method('findAll')->willReturn([]);
		$this->endpoints->method('findAll')->willReturn([]);

		$components = $this->reader()->componentsOf(self::ORG);

		$this->assertSame(
			[
				['register', 'reg-cases', ['slug' => 'cases', 'schemas' => ['case', 'note']]],
				['schema', 'sch-case', ['slug' => 'case']],
				// An exported schema with no row of this organisation keeps no source uuid.
				['schema', null, ['slug' => 'note']],
			],
			array_map(static fn (PromotionComponent $c): array => [$c->type, $c->sourceUuid, $c->definition], $components)
		);
	}

	public function testAnOrganisationWithoutRegistersIsNotExported(): void {
		$this->registers->method('findAll')->willReturn([]);
		$this->schemas->expects($this->never())->method('findAll');
		$this->export->expects($this->never())->method('exportConfig');
		$this->mappings->method('findAll')->willReturn([]);
		$this->sources->method('findAll')->willReturn([]);
		$this->webhooks->method('findAll')->willReturn([]);
		$this->endpoints->method('findAll')->willReturn([]);

		$this->assertSame([], $this->reader()->componentsOf(self::ORG));
	}

	public function testIntegrationRowsAreTheSourceOrganisationsOnlyWithoutInstanceFields(): void {
		$this->registers->method('findAll')->willReturn([]);
		$instance = ['id' => 7, 'uuid' => 'u-map', 'organisation' => self::ORG, 'created' => 'c', 'updated' => 'u', 'owner' => 'alice'];
		$this->mappings->method('findAll')->willReturn(
			[
				$this->row($instance + ['name' => 'own mapping']),
				// The mapper's own scope may hand over another tenant's row: it never travels.
				$this->row(['uuid' => 'u-other', 'organisation' => 'org-other', 'name' => 'foreign']),
			]
		);
		$this->sources->expects($this->once())->method('findAll')->with(null, null, ['organisation' => self::ORG])
			->willReturn([$this->row(['uuid' => 'u-src', 'organisation' => self::ORG, 'location' => 'https://api'])]);
		$this->webhooks->expects($this->once())->method('findAll')
			->with($this->callback(static fn (ListQuery $query): bool => $query->filters === ['organisation' => self::ORG] && $query->limit === ListQuery::UNBOUNDED))
			->willReturn([$this->row(['organisation' => self::ORG, 'name' => 'no uuid'])]);
		$this->endpoints->method('findAll')->willReturn([$this->row(['uuid' => 'u-end', 'organisation' => self::ORG, 'endpoint' => '/x'])]);

		$components = $this->reader()->componentsOf(self::ORG);

		$this->assertSame(
			[
				['mapping', 'u-map', ['name' => 'own mapping']],
				['source', 'u-src', ['location' => 'https://api']],
				['webhook', null, ['name' => 'no uuid']],
				['endpoint', 'u-end', ['endpoint' => '/x']],
			],
			array_map(static fn (PromotionComponent $c): array => [$c->type, $c->sourceUuid, $c->definition], $components)
		);
	}
}
