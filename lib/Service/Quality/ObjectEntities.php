<?php

/**
 * OpenRegister ObjectEntities
 *
 * Keeps the object entities of a mixed result list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object entities of an object-service result, which may hold other values.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
final class ObjectEntities {

	/**
	 * Keep only the ObjectEntity values, in order.
	 *
	 * @param iterable<mixed> $objects What the object service returned.
	 *
	 * @return array<int, ObjectEntity> The entities.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public static function in(iterable $objects): array {
		$entities = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$entities[] = $object;
			}
		}

		return $entities;
	}//end in()
}//end class
