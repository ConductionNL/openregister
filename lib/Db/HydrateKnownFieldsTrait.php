<?php

/**
 * HydrateKnownFieldsTrait: hydrate an entity from its known fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Hydrate an entity through its setters, for the fields it declares.
 */
trait HydrateKnownFieldsTrait {

	/**
	 * Hydrate the entity from an array.
	 *
	 * Keys that are not a field of the entity, and the id, are skipped.
	 *
	 * @param array<string, mixed> $object Data to hydrate from.
	 *
	 * @return self This entity.
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function hydrate(array $object): self {
		foreach ($object as $fieldName => $value) {
			if (array_key_exists($fieldName, $this->getFieldTypes()) === false || $fieldName === 'id') {
				continue;
			}

			$setter = 'set' . ucfirst($fieldName);
			$this->$setter($value);
		}

		return $this;
	}//end hydrate()
}//end trait
