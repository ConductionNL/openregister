<?php

/**
 * Reads a schema's MDTO mapping and says what it fills, and what it does not.
 *
 * 🔴 THE MAPPING IS CHECKED BEFORE THE TRANSFER, NOT DURING IT. An unmapped
 * mandatory element discovered by the e-Depot is a failed transfer and a
 * support call, and by then the package has left. So the packaging path asks
 * this class first, and a mandatory element nothing fills refuses the transfer
 * with the element named.
 *
 * 🔴 A SCHEMA THAT DECLARES NO MAPPING KEEPS TODAY'S BEHAVIOUR. Every existing
 * install transfers through {@see \OCA\OpenRegister\Service\Edepot\MdtoSourceReader},
 * which reads from a fixed set of places, and refusing those transfers the day
 * this shipped would break e-Depot on every instance that never asked for an
 * administered mapping. The refusal bites only once somebody has started
 * administering one, which is the same backwards-compatibility line the rest of
 * this change holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Resolves an administered MDTO mapping against one object.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoMappingResolver {

	/**
	 * Constructor.
	 *
	 * @param ArchivalSchemaLookup $lookup    Loads the object's schema; reports an unreadable one.
	 * @param MdtoElementCatalogue $catalogue The authority on which elements MDTO demands.
	 */
	public function __construct(
		private readonly ArchivalSchemaLookup $lookup,
		private readonly MdtoElementCatalogue $catalogue,
	) {
	}//end __construct()

	/**
	 * The mapping this object's schema declares, or null when it declares none.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>|null The mapping, or null.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function mappingFor(ObjectEntity $object): ?array {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		$configuration = $this->schemaConfiguration(object: $object, schemaId: $schemaId);
		if ($configuration === null) {
			return null;
		}

		$mapping = ($configuration[ElementMappingValidator::ANNOTATION_KEY] ?? null);
		if (is_array($mapping) === false || $mapping === []) {
			return null;
		}

		return $mapping;
	}//end mappingFor()

	/**
	 * The configuration of a record's schema; null (logged) when the schema cannot be read.
	 *
	 * @param ObjectEntity $object   The record.
	 * @param string       $schemaId Its schema id.
	 *
	 * @return array<string, mixed>|null The configuration.
	 */
	private function schemaConfiguration(ObjectEntity $object, string $schemaId): ?array {
		try {
			return ($this->lookup->schemaMapper->find($schemaId)->getConfiguration() ?? []);
		} catch (Throwable $e) {
			$this->lookup->logger->warning(
				'[MdtoMappingResolver] Could not read the schema of ' . (string)$object->getUuid()
				. ', so its MDTO mapping is unknown: ' . $e->getMessage()
			);
			return null;
		}
	}//end schemaConfiguration()

	/**
	 * The mandatory MDTO elements this object's mapping does not fill.
	 *
	 * An element is unfilled when the mapping omits it, or names a property
	 * this object leaves empty. A `const` always fills.
	 *
	 * @param ObjectEntity $object The object about to be packaged.
	 *
	 * @return string[] The unfilled mandatory element names; empty when all are filled,
	 *                  and empty when the schema declares no mapping at all.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function unfilledMandatoryElements(ObjectEntity $object): array {
		$mapping = $this->mappingFor(object: $object);
		if ($mapping === null) {
			return [];
		}

		return $this->unfilledOf(mapping: $mapping, object: $object);
	}//end unfilledMandatoryElements()

	/**
	 * The mandatory elements a mapping leaves empty for a record; none when the catalogue cannot say.
	 *
	 * @param array<string, mixed> $mapping The schema's MDTO mapping.
	 * @param ObjectEntity         $object  The record.
	 *
	 * @return array<int, string> The unfilled element names.
	 */
	private function unfilledOf(array $mapping, ObjectEntity $object): array {
		$mandatory = $this->mandatoryElements();
		if ($mandatory === null) {
			return [];
		}

		$row = $this->recordRow(object: $object);

		return array_values(
			array_filter(
				$mandatory,
				fn (string|int $element): bool => $this->isFilled(entry: ($mapping[$element] ?? null), row: $row) === false
			)
		);
	}//end unfilledOf()

	/**
	 * The elements MDTO demands, or null (logged) when the catalogue cannot establish them.
	 *
	 * @return array<int, string>|null The mandatory element names.
	 */
	private function mandatoryElements(): ?array {
		try {
			return $this->catalogue->mandatory();
		} catch (Throwable $e) {
			// The catalogue refuses rather than reporting an empty set, and an
			// empty set here would wave every transfer through. Reported, and
			// treated as "cannot establish", which is not the same as "fine".
			$this->lookup->logger->error('[MdtoMappingResolver] ' . $e->getMessage());
			return null;
		}
	}//end mandatoryElements()

	/**
	 * The record's data, or an empty row.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function recordRow(ObjectEntity $object): array {
		$row = ($object->getObject() ?? []);
		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end recordRow()

	/**
	 * Does this mapping entry put a value on this record?
	 *
	 * @param mixed                $entry The mapping entry for one element.
	 * @param array<string, mixed> $row   The record's data.
	 *
	 * @return bool True when the element is filled.
	 */
	private function isFilled(mixed $entry, array $row): bool {
		if (is_array($entry) === false) {
			return false;
		}

		if (($entry[ElementMappingValidator::SOURCE_CONST] ?? null) !== null) {
			return true;
		}

		$property = ($entry[ElementMappingValidator::SOURCE_PROPERTY] ?? null);
		if (is_string($property) === false || trim($property) === '') {
			return false;
		}

		return ($this->valueAt(row: $row, path: $property) !== null);
	}//end isFilled()

	/**
	 * Follow a dotted path into the object's data.
	 *
	 * @param array<string, mixed> $row  The object's data.
	 * @param string               $path The dotted property path.
	 *
	 * @return mixed The value, or null when the path is absent or says nothing.
	 */
	private function valueAt(array $row, string $path): mixed {
		$cursor = $row;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		if ($cursor === null || $cursor === '' || $cursor === []) {
			return null;
		}

		return $cursor;
	}//end valueAt()
}//end class
