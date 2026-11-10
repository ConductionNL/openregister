<?php

/**
 * OpenRegister SchemaObjectReader
 *
 * Reads a schema's stored population for the migration surface: locating the
 * register that contains a schema, and collecting the stored values of one of
 * its properties for a conversion preview. Extracted from
 * {@see \OCA\OpenRegister\Controller\SchemaMigrationController} so the
 * controller keeps only its endpoint wiring and this cohesive read logic lives
 * on its own injected collaborator (NC autowires it).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCP\IRequest;

/**
 * Locates a schema's register and reads its stored property values.
 */
class SchemaObjectReader implements SchemaObjectReaderInterface {

	/**
	 * How many objects a conversion preview reads.
	 *
	 * A preview is a decision aid, not an exhaustive migration: reading every
	 * row of a four-million-row table to answer "what would this cost" is a
	 * cost of its own. The counts are exact over what was read and the
	 * response says how many that was.
	 *
	 * @var integer
	 */
	public const CONVERSION_SCAN_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog Register lookup and the magic tables a conversion preview reads.
	 * @param IRequest               $request Request (reads an explicit `registerId` override).
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Whether stored values can be read (the catalog always carries the magic tables).
	 *
	 * @return boolean True when a conversion preview can read stored values.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function canReadValues(): bool {
		return true;
	}//end canReadValues()

	/**
	 * Every stored value of one property, across the schema's objects.
	 *
	 * @param integer $schemaId The schema id.
	 * @param string $property The property name.
	 *
	 * @return array<int,mixed> The stored values, one per object.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function storedValues(int $schemaId, string $property): array {
		$registerId = $this->resolveRegisterId(schemaId: $schemaId);
		if ($registerId === null) {
			return [];
		}

		return $this->valuesOf(objects: $this->scan(schemaId: $schemaId, registerId: $registerId), property: $property);
	}//end storedValues()

	/**
	 * Read up to the scan limit of a schema's objects in one register, without RBAC or multitenancy.
	 *
	 * @param int $schemaId   The schema id.
	 * @param int $registerId The register id.
	 *
	 * @return array<int,mixed> The objects, empty when they cannot be read.
	 */
	private function scan(int $schemaId, int $registerId): array {
		$query = ['@self' => ['register' => $registerId, 'schema' => $schemaId], '_limit' => self::CONVERSION_SCAN_LIMIT];
		try {
			$objects = $this->catalog->tables()->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $unreadable) {
			return [];
		}

		if (is_array($objects) === false) {
			return [];
		}

		return $objects;
	}//end scan()

	/**
	 * One property's value of each object, null where it is not set.
	 *
	 * @param array<int,mixed> $objects  The objects.
	 * @param string           $property The property name.
	 *
	 * @return array<int,mixed> The values, one per object with readable data.
	 */
	private function valuesOf(array $objects, string $property): array {
		$values = [];
		foreach ($objects as $object) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				$values[] = ($data[$property] ?? null);
			}
		}

		return $values;
	}//end valuesOf()

	/**
	 * Resolve a register id that contains the given schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int|null A register id, or null when none contains the schema.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function resolveRegisterId(int $schemaId): ?int {
		$explicit = $this->request->getParam('registerId');
		if ($explicit !== null && is_numeric($explicit) === true) {
			return (int)$explicit;
		}

		return $this->registerHolding(schemaId: $schemaId);
	}//end resolveRegisterId()

	/**
	 * The first register that lists the schema, read without RBAC or multitenancy.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int|null The register id, or null when none lists it.
	 */
	private function registerHolding(int $schemaId): ?int {
		foreach ($this->catalog->registers()->findAll(_rbac: false, _multitenancy: false) as $register) {
			$schemas = array_map('strval', ($register->getSchemas() ?? []));
			if (in_array((string)$schemaId, $schemas, true) === true) {
				return (int)$register->getId();
			}
		}

		return null;
	}//end registerHolding()
}//end class
