<?php

/**
 * OpenRegister PromotionPackageReader
 *
 * Reads what a promotion carries: the registers and schemas through the existing
 * configuration export, the sources from their mapper, and the mappings, webhooks
 * and endpoints from the Integrations component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
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

namespace OCA\OpenRegister\Service\Organisation;
use JsonSerializable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\IntegrationPromotionRowsInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionLandingInterface;

/**
 * Collect one organisation's configuration as promotion components.
 *
 * Registers and schemas go through the configuration export a package
 * download uses, so a promotion carries exactly what an export would: no ids,
 * no organisation, references by slug. The export knows nothing of sources,
 * webhooks and endpoints, so those keep their serialised form minus the
 * instance-bound fields.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
class PromotionPackageReader implements PromotionPackageReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param PromotionLandingInterface         $landing      The configuration export.
	 * @param RegisterMapperInterface           $registers    The registers.
	 * @param SchemaMapperInterface             $schemas      The schemas.
	 * @param SourceMapperInterface             $sources      The sources.
	 * @param IntegrationPromotionRowsInterface $integrations The mappings, webhooks and endpoints.
	 */
	public function __construct(
		private readonly PromotionLandingInterface $landing,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly SourceMapperInterface $sources,
		private readonly IntegrationPromotionRowsInterface $integrations,
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
		$sources = $this->sources->findAll(filters: ['organisation' => $organisationUuid]);

		return array_merge(
			$this->exported(organisationUuid: $organisationUuid),
			self::serialised(type: PromotionComponent::TYPE_SOURCE, organisationUuid: $organisationUuid, entities: $sources),
			array_map(
				static fn (array $row): PromotionComponent => new PromotionComponent(
					type: $row['type'],
					sourceUuid: $row['uuid'],
					definition: $row['definition']
				),
				$this->integrations->rowsOf(organisationUuid: $organisationUuid)
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
		return $this->landing->exportRegister(register: $register);

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
	 * @param string                       $type             The component kind.
	 * @param string                       $organisationUuid The source organisation.
	 * @param array<int, JsonSerializable> $entities         The rows the mapper returned.
	 *
	 * @return list<PromotionComponent> The components.
	 */
	private static function serialised(string $type, string $organisationUuid, array $entities): array {
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

			foreach (IntegrationPromotionRowsInterface::INSTANCE_FIELDS as $field) {
				unset($row[$field]);
			}

			$components[] = new PromotionComponent(type: $type, sourceUuid: $uuid, definition: $row);
		}

		return $components;

	}//end serialised()
}//end class
