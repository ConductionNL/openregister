<?php

/**
 * OpenRegister ExactKeyHydration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Register;

use OCA\OpenRegister\Db\Register;

/**
 * Hydrates a register through its setters, from the keys that name a property exactly.
 *
 * The setter call resolves `Organisation` to setOrganisation() too, and every
 * check on a register body reads the exact key, so only exact names are stored.
 * An empty array for a json field is stored as null; a setter that throws is skipped.
 *
 * No strict_types: moved out of Register, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class ExactKeyHydration {

	/**
	 * The register to hydrate.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * Constructor.
	 *
	 * @param Register $register The register to hydrate.
	 */
	public function __construct(Register $register) {
		$this->register = $register;
	}//end __construct()

	/**
	 * Apply the values to the register.
	 *
	 * @param array<mixed> $object The values by key.
	 *
	 * @return void
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function apply(array $object): void {
		$register = $this->register;
		$jsonFields = $register->getJsonFields();
		$exactKeys = array_filter(
			$object,
			static fn (int|string $key): bool => is_string($key) === true && property_exists($register, $key) === true,
			ARRAY_FILTER_USE_KEY
		);
		foreach ($exactKeys as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$value = null;
			}

			$this->set(key: $key, value: $value);
		}
	}//end apply()

	/**
	 * Call the field's setter, ignoring a value it refuses.
	 *
	 * @param string $key   The field name.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 */
	private function set(string $key, mixed $value): void {
		$method = 'set' . ucfirst($key);

		try {
			$this->register->$method($value);
		} catch (\Exception $exception) {
			// Silently ignore invalid properties.
		}
	}//end set()
}//end class
