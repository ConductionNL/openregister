<?php

/**
 * OpenRegister HydrateEmptyJsonAsNullTrait
 *
 * Hydrates an entity from an array, storing an empty array in a json field as null.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Hydrates the using entity from an array through its setters.
 *
 * An empty array given for a json field (see getJsonFields()) is stored as
 * null. Keys without a setter are ignored.
 */
trait HydrateEmptyJsonAsNullTrait {

	/**
	 * Hydrate the entity with data from an array
	 *
	 * @param array<string, mixed> $object The data array to hydrate from
	 *
	 * @return static Returns $this for method chaining
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function hydrate(array $object): static {
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (HydrationKey::isFieldName(key: $key) === false) {
				continue;
			}

			if (in_array($key, $jsonFields, true) === true && $value === []) {
				$value = null;
			}

			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()
}//end trait
