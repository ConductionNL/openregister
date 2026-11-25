<?php

/**
 * Unit tests for the Integrations side of a promotion: an organisation's mappings, webhooks and endpoints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use JsonSerializable;
use OCA\OpenRegister\Db\EndpointMapperInterface;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\IntegrationPromotionRowsInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Integration\PromotionIntegrationRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromotionIntegrationRows::class)]
#[UsesClass(ListQuery::class)]
final class PromotionIntegrationRowsTest extends TestCase {

	private const ORG = 'org-source';

	/**
	 * @param array<string, mixed> $row The serialised row.
	 */
	private function row(array $row): JsonSerializable {
		$entity = $this->createMock(JsonSerializable::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}

	public function testTheOrganisationsOwnRowsTravelWithoutInstanceFieldsInKindOrder(): void {
		$mappings = $this->createMock(MappingMapperInterface::class);
		$mappings->method('findAll')->willReturn(
			[
				$this->row(['id' => 1, 'uuid' => 'u-map', 'organisation' => self::ORG, 'created' => 'c', 'updated' => 'u', 'owner' => 'o', 'name' => 'own']),
				// The mapper's own scope may hand over another tenant's row: it never travels.
				$this->row(['uuid' => 'u-foreign', 'organisation' => 'org-other', 'name' => 'foreign']),
			]
		);
		$webhooks = $this->createMock(WebhookMapperInterface::class);
		$webhooks->expects($this->once())->method('findAll')
			->with($this->callback(static fn (ListQuery $query): bool => $query->filters === ['organisation' => self::ORG] && $query->limit === ListQuery::UNBOUNDED))
			->willReturn([$this->row(['organisation' => self::ORG, 'name' => 'hook'])]);
		$endpoints = $this->createMock(EndpointMapperInterface::class);
		$endpoints->method('findAll')->willReturn([$this->row(['uuid' => 'u-end', 'organisation' => self::ORG, 'endpoint' => '/x'])]);

		$rows = (new PromotionIntegrationRows(mappings: $mappings, webhooks: $webhooks, endpoints: $endpoints))->rowsOf(self::ORG);

		$this->assertSame(
			[
				['type' => 'mapping', 'uuid' => 'u-map', 'definition' => ['name' => 'own']],
				['type' => 'webhook', 'uuid' => null, 'definition' => ['name' => 'hook']],
				['type' => 'endpoint', 'uuid' => 'u-end', 'definition' => ['endpoint' => '/x']],
			],
			$rows
		);
	}

	public function testTheContractIsBoundInTheIntegrationsWiring(): void {
		$this->assertSame(PromotionIntegrationRows::class, IntegrationWiring::BINDINGS[IntegrationPromotionRowsInterface::class] ?? null);
		$this->assertTrue(is_subclass_of(PromotionIntegrationRows::class, IntegrationPromotionRowsInterface::class));
	}
}
