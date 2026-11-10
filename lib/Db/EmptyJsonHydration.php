<?php

/**
 * OpenRegister EmptyJsonHydration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use Exception;
use OCP\AppFramework\Db\Entity;

/**
 * Hydrates an entity through its setters, storing an empty array on a JSON field as null.
 *
 * Deliberately without strict_types, like the entities that call it: the
 * setters keep the scalar coercion those entities' hydrate() always had.
 *
 * @spec exclude entity plumbing shared by ObjectEntity and Source, not a capability of its own
 */
final class EmptyJsonHydration {

	/**
	 * Call each key's setter; an empty array on a JSON field is passed as null
	 * and a key the entity cannot set is skipped. A missing `metadata` key is
	 * hydrated as an empty array.
	 *
	 * @param ObjectEntity|Source $entity The entity to hydrate.
	 * @param array               $object The data, keyed by field name.
	 *
	 * @return void
	 *
	 * @spec exclude entity plumbing, not a capability of its own
	 */
	public function hydrate(Entity $entity, array $object): void {
		if (isset($object['metadata']) === false) {
			$object['metadata'] = [];
		}

		foreach ($this->emptyJsonAsNull(entity: $entity, object: $object) as $key => $value) {
			$method = 'set' . ucfirst($key);

			try {
				$entity->$method($value);
			} catch (Exception $exception) {
				// Silently ignore invalid properties.
			}
		}
	}//end hydrate()

	/**
	 * The data with every empty array on one of the entity's JSON fields replaced by null.
	 *
	 * @param ObjectEntity|Source $entity The entity the data is for.
	 * @param array               $object The data, keyed by field name.
	 *
	 * @return array The data, keys in the same order.
	 *
	 * @spec exclude entity plumbing, not a capability of its own
	 */
	private function emptyJsonAsNull(Entity $entity, array $object): array {
		$jsonFields = $entity->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$object[$key] = null;
			}
		}

		return $object;
	}//end emptyJsonAsNull()
}//end class
