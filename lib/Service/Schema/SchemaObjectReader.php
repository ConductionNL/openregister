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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\IRequest;

/**
 * Locates a schema's register and reads its stored property values.
 */
class SchemaObjectReader {

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
	 * @param RegisterMapper $registerMapper Register lookup (resolve population register).
	 * @param IRequest $request Request (reads an explicit `registerId` override).
	 * @param MagicMapper|null $objects Reads the stored values a conversion preview is measured over.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly IRequest $request,
		private readonly ?MagicMapper $objects = null,
	) {
	}//end __construct()

	/**
	 * Whether stored values can be read (the object mapper is available).
	 *
	 * @return boolean True when a conversion preview can read stored values.
	 */
	public function canReadValues(): bool {
		return ($this->objects !== null);
	}//end canReadValues()

	/**
	 * Every stored value of one property, across the schema's objects.
	 *
	 * @param integer $schemaId The schema id.
	 * @param string $property The property name.
	 *
	 * @return array<int,mixed> The stored values, one per object.
	 */
	public function storedValues(int $schemaId, string $property): array {
		if ($this->objects === null) {
			return [];
		}

		$registerId = $this->resolveRegisterId(schemaId: $schemaId);
		if ($registerId === null) {
			return [];
		}

		try {
			$objects = $this->objects->searchObjects(
				query: [
					'@self' => ['register' => $registerId, 'schema' => $schemaId],
					'_limit' => self::CONVERSION_SCAN_LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $unreadable) {
			return [];
		}

		if (is_array($objects) === false) {
			return [];
		}

		$values = [];
		foreach ($objects as $object) {
			$data = $object->getObject();
			if (is_array($data) === false) {
				continue;
			}

			$values[] = ($data[$property] ?? null);
		}

		return $values;
	}//end storedValues()

	/**
	 * Resolve a register id that contains the given schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int|null A register id, or null when none contains the schema.
	 */
	public function resolveRegisterId(int $schemaId): ?int {
		$explicit = $this->request->getParam('registerId');
		if ($explicit !== null && is_numeric($explicit) === true) {
			return (int)$explicit;
		}

		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		foreach ($registers as $register) {
			$schemas = array_map('strval', ($register->getSchemas() ?? []));
			if (in_array((string)$schemaId, $schemas, true) === true) {
				return (int)$register->getId();
			}
		}

		return null;
	}//end resolveRegisterId()
}//end class
