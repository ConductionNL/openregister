<?php

/**
 * OpenRegister TypedFieldsJson
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;

/**
 * The plain JSON form of an entity: every typed field in registration order,
 * a date-time as ISO 8601 and every other value as it is stored.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
final class TypedFieldsJson {

	/**
	 * Each typed field's value, keyed and ordered as the field types are.
	 *
	 * @param array<string, string> $fieldTypes The entity's field types (getFieldTypes()).
	 * @param array<string, mixed>  $values     The entity's field values by name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function serialise(array $fieldTypes, array $values): array {
		return $this->pick(values: $values, names: array_keys($fieldTypes));
	}//end serialise()

	/**
	 * The named fields' values, in the order given.
	 *
	 * @param array<string, mixed> $values The entity's field values by name.
	 * @param string[]             $names  The fields to serialise.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function pick(array $values, array $names): array {
		$json = [];
		foreach ($names as $name) {
			$json[$name] = $this->value(value: ($values[$name] ?? null));
		}

		return $json;
	}//end pick()

	/**
	 * One value, a date-time formatted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed
	 */
	private function value(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format('c');
		}

		return $value;
	}//end value()
}//end class
