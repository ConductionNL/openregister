<?php

/**
 * OpenRegister PromotionIntegrationRows
 *
 * The Integrations side of a promotion: one organisation's mappings, webhooks and endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;
use JsonSerializable;
use OCA\OpenRegister\Db\EndpointMapperInterface;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\IntegrationPromotionRowsInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Read the organisation's mappings, webhooks and endpoints from their mappers.
 *
 * The organisation is checked on each row itself as well: a mapper that
 * scopes to the caller's active organisation instead of the filter would
 * otherwise hand over another tenant's rows.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
class PromotionIntegrationRows implements IntegrationPromotionRowsInterface {

	/**
	 * Constructor.
	 *
	 * @param MappingMapperInterface  $mappings  The mappings.
	 * @param WebhookMapperInterface  $webhooks  The webhooks.
	 * @param EndpointMapperInterface $endpoints The endpoints.
	 */
	public function __construct(
		private readonly MappingMapperInterface $mappings,
		private readonly WebhookMapperInterface $webhooks,
		private readonly EndpointMapperInterface $endpoints,
	) {

	}//end __construct()

	/**
	 * The organisation's mappings, webhooks and endpoints, in that order.
	 *
	 * @param string $organisationUuid The organisation.
	 *
	 * @return list<array{type: string, uuid: string|null, definition: array<string, mixed>}> The rows.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function rowsOf(string $organisationUuid): array {
		$webhooks = $this->webhooks->findAll(ListQuery::unbounded(filters: ['organisation' => $organisationUuid]));

		return array_merge(
			self::rows(type: 'mapping', organisationUuid: $organisationUuid, entities: $this->mappings->findAll()),
			self::rows(type: 'webhook', organisationUuid: $organisationUuid, entities: $webhooks),
			self::rows(type: 'endpoint', organisationUuid: $organisationUuid, entities: $this->endpoints->findAll())
		);

	}//end rowsOf()

	/**
	 * The organisation's rows of one kind, without their instance-bound fields.
	 *
	 * @param string                       $type             The kind.
	 * @param string                       $organisationUuid The organisation.
	 * @param array<int, JsonSerializable> $entities         The rows the mapper returned.
	 *
	 * @return list<array{type: string, uuid: string|null, definition: array<string, mixed>}> The rows.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public static function rows(string $type, string $organisationUuid, array $entities): array {
		$rows = [];
		foreach ($entities as $entity) {
			$row = $entity->jsonSerialize();
			if (($row['organisation'] ?? null) !== $organisationUuid) {
				continue;
			}

			$uuid = null;
			if (is_string($row['uuid'] ?? null) === true) {
				$uuid = $row['uuid'];
			}

			foreach (self::INSTANCE_FIELDS as $field) {
				unset($row[$field]);
			}

			$rows[] = ['type' => $type, 'uuid' => $uuid, 'definition' => $row];
		}

		return $rows;

	}//end rows()
}//end class
