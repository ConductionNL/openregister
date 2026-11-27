<?php

/**
 * Unit tests for what a promotion carries out of the source organisation.
 *
 * Registers and schemas come from the configuration export, matched back to
 * their source uuid by slug; mappings, sources, webhooks and endpoints come
 * from their mappers or the Integrations contract, only the source
 * organisation's rows, without the fields that belong to the source instance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Organisation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Organisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use JsonSerializable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\IntegrationPromotionRowsInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionLandingInterface;
use OCA\OpenRegister\Service\Organisation\PromotionComponent;
use OCA\OpenRegister\Service\Organisation\PromotionPackageReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromotionPackageReader::class)]
#[UsesClass(PromotionComponent::class)]
final class PromotionPackageReaderTest extends TestCase {

	private const ORG = 'org-source';

	private PromotionLandingInterface&MockObject $landing;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private SourceMapperInterface&MockObject $sources;

	private IntegrationPromotionRowsInterface&MockObject $integrations;

	protected function setUp(): void {
		parent::setUp();

		$this->landing      = $this->createMock(PromotionLandingInterface::class);
		$this->registers    = $this->createMock(RegisterMapperInterface::class);
		$this->schemas      = $this->createMock(SchemaMapperInterface::class);
		$this->sources      = $this->createMock(SourceMapperInterface::class);
		$this->integrations = $this->createMock(IntegrationPromotionRowsInterface::class);
	}

	private function reader(): PromotionPackageReader {
		return new PromotionPackageReader(
			landing: $this->landing,
			registers: $this->registers,
			schemas: $this->schemas,
			sources: $this->sources,
			integrations: $this->integrations
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
		$this->landing->expects($this->once())->method('exportRegister')->with($cases)->willReturn(
			[
				'components' => [
					'registers' => ['cases' => ['slug' => 'cases', 'schemas' => ['case', 'note']]],
					'schemas' => ['case' => ['slug' => 'case'], 'note' => ['slug' => 'note']],
				],
			]
		);
		$this->sources->method('findAll')->willReturn([]);
		$this->integrations->method('rowsOf')->willReturn([]);

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
		$this->landing->expects($this->never())->method('exportRegister');
		$this->sources->method('findAll')->willReturn([]);
		$this->integrations->method('rowsOf')->willReturn([]);

		$this->assertSame([], $this->reader()->componentsOf(self::ORG));
	}

	public function testSourcesAreTheOrganisationsOwnWithoutInstanceFieldsThenTheIntegrationRows(): void {
		$this->registers->method('findAll')->willReturn([]);
		$this->sources->expects($this->once())->method('findAll')->with(null, null, ['organisation' => self::ORG])->willReturn(
			[
				$this->row(['id' => 3, 'uuid' => 'u-src', 'organisation' => self::ORG, 'owner' => 'alice', 'location' => 'https://api']),
				// The mapper's own scope may hand over another tenant's row: it never travels.
				$this->row(['uuid' => 'u-other', 'organisation' => 'org-other', 'location' => 'https://foreign']),
			]
		);
		$this->integrations->expects($this->once())->method('rowsOf')->with(self::ORG)
			->willReturn([['type' => 'mapping', 'uuid' => 'u-map', 'definition' => ['name' => 'm']]]);

		$components = $this->reader()->componentsOf(self::ORG);

		$this->assertSame(
			[
				['source', 'u-src', ['location' => 'https://api']],
				['mapping', 'u-map', ['name' => 'm']],
			],
			array_map(static fn (PromotionComponent $c): array => [$c->type, $c->sourceUuid, $c->definition], $components)
		);
	}
}
