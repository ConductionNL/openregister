<?php

/**
 * OpenRegister PromotionPackageReader
 *
 * Reads what a promotion carries: the registers and schemas through the existing
 * configuration export, the mappings, sources, webhooks and endpoints from their own mappers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
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

namespace OCA\OpenRegister\Service\ConfigurationDeployment;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\ConfigurationTransferInterface;
use JsonSerializable;

/**
 * Collect one organisation's configuration as promotion components.
 *
 * Registers and schemas go through the configuration export a package
 * download uses, so a promotion carries exactly what an export would: no ids,
 * no organisation, references by slug. The export knows nothing of webhooks
 * and endpoints, so those four kinds are read from their mappers and keep
 * their serialised form minus the instance-bound fields.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One mapper per promoted kind plus the export; splitting them would split one package.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
class PromotionPackageReader implements PromotionPackageReaderInterface {

	/**
	 * Fields that belong to the source instance and never travel.
	 *
	 * @var list<string>
	 */
	private const INSTANCE_FIELDS = ['id', 'uuid', 'organisation', 'created', 'updated', 'owner'];

	/**
	 * Constructor.
	 *
	 * @param ConfigurationTransferInterface $export    The configuration export.
	 * @param RegisterMapper                 $registers The registers.
	 * @param SchemaMapper                   $schemas   The schemas.
	 * @param MappingMapper                  $mappings  The mappings.
	 * @param SourceMapper                   $sources   The sources.
	 * @param WebhookMapperInterface         $webhooks  The webhooks.
	 * @param EndpointMapper                 $endpoints The endpoints.
	 */
	public function __construct(
		private readonly ConfigurationTransferInterface $export,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly MappingMapper $mappings,
		private readonly SourceMapper $sources,
		private readonly WebhookMapperInterface $webhooks,
		private readonly EndpointMapper $endpoints,
	) {

	}//end __construct()

	/**
	 * Every component the organisation holds, registers first.
	 *
	 * @param string $organisationUuid The source organisation.
	 *
	 * @return list<PromotionComponent> The components.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function componentsOf(string $organisationUuid): array {
		$filters = ['organisation' => $organisationUuid];

		return array_merge(
			$this->exported(organisationUuid: $organisationUuid),
			$this->serialised(type: PromotionComponent::TYPE_MAPPING, organisationUuid: $organisationUuid, entities: $this->mappings->findAll()),
			$this->serialised(
				type: PromotionComponent::TYPE_SOURCE,
				organisationUuid: $organisationUuid,
				entities: $this->sources->findAll(filters: $filters)
			),
			$this->serialised(
				type: PromotionComponent::TYPE_WEBHOOK,
				organisationUuid: $organisationUuid,
				entities: $this->webhooks->findAll(ListQuery::unbounded(filters: $filters))
			),
			$this->serialised(
				type: PromotionComponent::TYPE_ENDPOINT,
				organisationUuid: $organisationUuid,
				entities: $this->endpoints->findAll()
			)
		);

	}//end componentsOf()

	/**
	 * The organisation's registers and their schemas, as the configuration export writes them.
	 *
	 * The export drops uuids, so each exported register and schema is matched
	 * back to its source row by slug: that is how the promotion knows which
	 * uuid a reference elsewhere in the package means.
	 *
	 * @param string $organisationUuid The source organisation.
	 *
	 * @return list<PromotionComponent> The registers, then the schemas.
	 */
	private function exported(string $organisationUuid): array {
		$filters   = ['organisation' => $organisationUuid];
		$registers = $this->registers->findAll(filters: $filters, _rbac: false, _multitenancy: false);
		if ($registers === []) {
			return [];
		}

		$registerUuids = self::uuidsBySlug(entities: $registers);
		$schemaUuids   = self::uuidsBySlug(entities: $this->schemas->findAll(filters: $filters, _rbac: false, _multitenancy: false));

		$exportedRegisters = [];
		$exportedSchemas   = [];
		foreach ($registers as $register) {
			$components         = ($this->exportOf(register: $register)['components'] ?? []);
			$exportedRegisters += ($components['registers'] ?? []);
			$exportedSchemas   += ($components['schemas'] ?? []);
		}

		return array_merge(
			self::bySlug(type: PromotionComponent::TYPE_REGISTER, definitions: $exportedRegisters, uuids: $registerUuids),
			self::bySlug(type: PromotionComponent::TYPE_SCHEMA, definitions: $exportedSchemas, uuids: $schemaUuids)
		);

	}//end exported()

	/**
	 * One register's export document.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, mixed> The OpenAPI document the export writes.
	 */
	private function exportOf(Register $register): array {
		return $this->export->exportConfig(input: $register);

	}//end exportOf()

	/**
	 * Exported definitions keyed by slug as components, with the source uuid the slug names.
	 *
	 * @param string                              $type        The component kind.
	 * @param array<string, array<string, mixed>> $definitions The exported definitions, by slug.
	 * @param array<string, string>               $uuids       Source uuids by slug.
	 *
	 * @return list<PromotionComponent> The components.
	 */
	private static function bySlug(string $type, array $definitions, array $uuids): array {
		$components = [];
		foreach ($definitions as $slug => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$components[] = new PromotionComponent(type: $type, sourceUuid: ($uuids[(string) $slug] ?? null), definition: $definition);
		}

		return $components;

	}//end bySlug()

	/**
	 * The source uuid of each entity, by slug.
	 *
	 * @param array<int, JsonSerializable> $entities Registers or schemas.
	 *
	 * @return array<string, string> Uuid by slug.
	 */
	private static function uuidsBySlug(array $entities): array {
		$uuids = [];
		foreach ($entities as $entity) {
			$row = $entity->jsonSerialize();
			if (is_string($row['slug'] ?? null) === true && is_string($row['uuid'] ?? null) === true) {
				$uuids[$row['slug']] = $row['uuid'];
			}
		}

		return $uuids;

	}//end uuidsBySlug()

	/**
	 * The organisation's rows of one kind as components, without their instance-bound fields.
	 *
	 * The organisation is checked on the row itself as well: a mapper that
	 * scopes to the caller's active organisation instead of the filter would
	 * otherwise hand over another tenant's rows.
	 *
	 * @param string             $type             The component kind.
	 * @param string             $organisationUuid The source organisation.
	 * @param array<int, JsonSerializable> $entities         The rows the mapper returned.
	 *
	 * @return list<PromotionComponent> The components.
	 */
	private function serialised(string $type, string $organisationUuid, array $entities): array {
		$components = [];
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

			$components[] = new PromotionComponent(type: $type, sourceUuid: $uuid, definition: $row);
		}

		return $components;

	}//end serialised()
}//end class
