<?php

/**
 * OpenRegister SearchTrailNameEnricher
 *
 * Adds register and schema names to search trail entities.
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
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SearchTrail;

/**
 * Looks up each referenced register and schema once and sets its title on the trails.
 *
 * Lookups use the mappers' default flags, so RBAC and multitenancy stay on.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
final class SearchTrailNameEnricher {

	/**
	 * Constructor for SearchTrailNameEnricher
	 *
	 * @param RegisterMapperInterface $registerMapper Mapper for register lookups
	 * @param SchemaMapperInterface   $schemaMapper   Mapper for schema lookups
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Enrich search trails with register and schema names
	 *
	 * All registers are looked up first, then all schemas. A lookup that fails falls back
	 * to "Register <id>" / "Schema <id>"; a missing title leaves the name untouched.
	 *
	 * @param SearchTrail[] $trails Search trail entities
	 *
	 * @return SearchTrail[] The same entities, enriched
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function enrich(array $trails): array {
		$registerIds = array_map(fn ($trail) => $trail->getRegister(), $trails);
		$registerNames = $this->titles(ids: $registerIds, mapper: $this->registerMapper, label: 'Register');
		$schemaIds = array_map(fn ($trail) => $trail->getSchema(), $trails);
		$schemaNames = $this->titles(ids: $schemaIds, mapper: $this->schemaMapper, label: 'Schema');

		foreach ($trails as $trail) {
			$this->applyNames(trail: $trail, registerNames: $registerNames, schemaNames: $schemaNames);
		}

		return $trails;
	}//end enrich()

	/**
	 * Set the register name, then the schema name, on one trail where one is known.
	 *
	 * @param SearchTrail $trail         The trail to enrich
	 * @param array       $registerNames Register names by id
	 * @param array       $schemaNames   Schema names by id
	 *
	 * @return void
	 */
	private function applyNames($trail, array $registerNames, array $schemaNames): void {
		$registerName = $this->nameFor(id: $trail->getRegister(), names: $registerNames);
		if ($registerName !== null) {
			$trail->setRegisterName($registerName);
		}

		$schemaName = $this->nameFor(id: $trail->getSchema(), names: $schemaNames);
		if ($schemaName !== null) {
			$trail->setSchemaName($schemaName);
		}
	}//end applyNames()

	/**
	 * Look up the title of each distinct, non-null id.
	 *
	 * @param array                                         $ids    Ids in trail order, null for none
	 * @param RegisterMapperInterface|SchemaMapperInterface $mapper Mapper to look the ids up with
	 * @param string                                        $label  Fallback label
	 *
	 * @return array Titles (or fallbacks) by id
	 */
	private function titles(array $ids, RegisterMapperInterface|SchemaMapperInterface $mapper, string $label): array {
		$names = [];
		foreach (array_unique(array_filter($ids, fn ($id) => $id !== null)) as $id) {
			try {
				$names[$id] = $mapper->find($id)->getTitle();
			} catch (Exception $e) {
				// Not found or any other error: use the fallback.
				$names[$id] = "$label $id";
			}
		}

		return $names;
	}//end titles()

	/**
	 * The name for an id, or null when the id or its name is absent.
	 *
	 * @param mixed $id    Register or schema id, null for none
	 * @param array    $names Names by id
	 *
	 * @return string|null
	 */
	private function nameFor(mixed $id, array $names): ?string {
		if ($id === null) {
			return null;
		}

		return $names[$id] ?? null;
	}//end nameFor()
}//end class
