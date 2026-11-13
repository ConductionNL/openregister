<?php

/**
 * RelationIndexReferenceFinder: the objects that reference a record, from the relation index.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Asks the relation index which objects hold a record's uuid, the same lookup
 * that answers "used by" (design D-1). The record itself is never listed as a
 * reference to itself.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
final class RelationIndexReferenceFinder implements ReferenceFinderInterface {

	/**
	 * Constructor.
	 *
	 * @param MagicRelationReaderInterface $relations The relation index across every magic table.
	 */
	public function __construct(
		private readonly MagicRelationReaderInterface $relations,
	) {
	}//end __construct()

	/**
	 * The objects referencing a record, with their stored data.
	 *
	 * @param string $uuid The referenced record's uuid.
	 *
	 * @return iterable<ReferencingObject>
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function referencingObjects(string $uuid): iterable {
		if ($uuid === '') {
			return [];
		}

		$found = [];
		foreach ($this->relations->findByRelation(uuid: $uuid) as $object) {
			if ($object instanceof ObjectEntity === false || (string)$object->getUuid() === $uuid) {
				continue;
			}

			$found[] = new ReferencingObject(
				uuid: (string)$object->getUuid(),
				register: self::idOrNull(value: $object->getRegister()),
				schema: self::idOrNull(value: $object->getSchema()),
				data: ($object->getObject() ?? [])
			);
		}

		return $found;
	}//end referencingObjects()

	/**
	 * A register or schema reference as a string, or null when there is none.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string|null
	 */
	private static function idOrNull(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end idOrNull()
}//end class
