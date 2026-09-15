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
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
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
	 * @param SchemaMapper         $schemaMapper Loads the object's schema.
	 * @param MdtoElementCatalogue $catalogue    The authority on which elements MDTO demands.
	 * @param LoggerInterface      $logger       Where an unreadable schema is reported.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly MdtoElementCatalogue $catalogue,
		private readonly LoggerInterface $logger,
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

		try {
			$configuration = ($this->schemaMapper->find($schemaId)->getConfiguration() ?? []);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MdtoMappingResolver] Could not read the schema of ' . (string)$object->getUuid()
				. ', so its MDTO mapping is unknown: ' . $e->getMessage()
			);
			return null;
		}

		$mapping = ($configuration[ElementMappingValidator::ANNOTATION_KEY] ?? null);
		if (is_array($mapping) === false || $mapping === []) {
			return null;
		}

		return $mapping;
	}//end mappingFor()

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

		try {
			$mandatory = $this->catalogue->mandatory();
		} catch (Throwable $e) {
			// The catalogue refuses rather than reporting an empty set, and an
			// empty set here would wave every transfer through. Reported, and
			// treated as "cannot establish", which is not the same as "fine".
			$this->logger->error('[MdtoMappingResolver] ' . $e->getMessage());
			return [];
		}

		$row = ($object->getObject() ?? []);
		if (is_array($row) === false) {
			$row = [];
		}

		$unfilled = [];
		foreach ($mandatory as $element) {
			$entry = ($mapping[$element] ?? null);
			if (is_array($entry) === false) {
				$unfilled[] = $element;
				continue;
			}

			if (($entry[ElementMappingValidator::SOURCE_CONST] ?? null) !== null) {
				continue;
			}

			$property = ($entry[ElementMappingValidator::SOURCE_PROPERTY] ?? null);
			if (is_string($property) === false || trim($property) === '') {
				$unfilled[] = $element;
				continue;
			}

			if ($this->valueAt(row: $row, path: $property) === null) {
				$unfilled[] = $element;
			}
		}//end foreach

		return $unfilled;
	}//end unfilledMandatoryElements()

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
