<?php

/**
 * MCP Catalog Summaries
 *
 * The live register and schema summaries the MCP Tier 2 capability details carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * Summaries of the registers and schemas in the catalog, built by McpDiscoveryService.
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
 */
final class McpCatalogSummaries {

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog Live register and schema data.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()

	/**
	 * The id, title and uuid of every register.
	 *
	 * @return array<int, array{id: int|null, title: string|null, uuid: string|null}>
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
	 */
	public function registers(): array {
		$registers = $this->catalog->registers()->findAll();
		$context = [];
		foreach ($registers as $register) {
			$context[] = [
				'id' => $register->getId(),
				'title' => $register->getTitle(),
				'uuid' => $register->getUuid(),
			];
		}

		return $context;
	}//end registers()

	/**
	 * The id, title, uuid and property count of every schema.
	 *
	 * @return array<int, array{id: int|null, title: string|null, uuid: string|null, property_count: int}>
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
	 */
	public function schemas(): array {
		$schemas = $this->catalog->schemas()->findAll();
		$context = [];
		foreach ($schemas as $schema) {
			$context[] = [
				'id' => $schema->getId(),
				'title' => $schema->getTitle(),
				'uuid' => $schema->getUuid(),
				'property_count' => $this->propertyCount(schema: $schema),
			];
		}

		return $context;
	}//end schemas()

	/**
	 * The id and title of every register, with the schemas it lists.
	 *
	 * @return array<int, array{id: int|null, title: string|null, schemas: array<int, array{id: int|null, title: string|null}>}>
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
	 */
	public function registersWithSchemas(): array {
		$registers = $this->catalog->registers()->findAll();
		$schemaIndex = $this->schemasById();

		$context = [];
		foreach ($registers as $register) {
			$context[] = [
				'id' => $register->getId(),
				'title' => $register->getTitle(),
				'schemas' => $this->registerSchemaSummaries(register: $register, schemaIndex: $schemaIndex),
			];
		}

		return $context;
	}//end registersWithSchemas()

	/**
	 * How many properties a schema declares.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int
	 */
	private function propertyCount(Schema $schema): int {
		$properties = $schema->getProperties();
		$propertyCount = 0;
		if (is_array($properties) === true) {
			$propertyCount = count($properties);
		}

		return $propertyCount;
	}//end propertyCount()

	/**
	 * Every schema by id.
	 *
	 * @return array<int|string, Schema>
	 */
	private function schemasById(): array {
		$schemaIndex = [];
		foreach ($this->catalog->schemas()->findAll() as $schema) {
			$schemaIndex[$schema->getId()] = $schema;
		}

		return $schemaIndex;
	}//end schemasById()

	/**
	 * The id and title of each known schema a register lists.
	 *
	 * @param Register                  $register    The register
	 * @param array<int|string, Schema> $schemaIndex All schemas by id
	 *
	 * @return array<int, array{id: int|null, title: string|null}>
	 */
	private function registerSchemaSummaries(Register $register, array $schemaIndex): array {
		$schemaIds = $register->getSchemas();
		if (is_array($schemaIds) === false) {
			return [];
		}

		$summaries = [];
		foreach ($schemaIds as $schemaId) {
			if (isset($schemaIndex[$schemaId]) === true) {
				$summaries[] = ['id' => $schemaIndex[$schemaId]->getId(), 'title' => $schemaIndex[$schemaId]->getTitle()];
			}
		}

		return $summaries;
	}//end registerSchemaSummaries()
}//end class
