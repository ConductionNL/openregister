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
	 * The same entries with some keys renamed, in the same order.
	 *
	 * @param array<string, mixed>  $json  The serialised fields.
	 * @param array<string, string> $names Field name to the key it is serialised under.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function renamed(array $json, array $names): array {
		$renamed = [];
		foreach ($json as $key => $value) {
			$renamed[($names[$key] ?? $key)] = $value;
		}

		return $renamed;
	}//end renamed()

	/**
	 * The same entries with new ones placed right after the key they are listed under.
	 *
	 * @param array<string, mixed>                $json         The serialised fields.
	 * @param array<string, array<string, mixed>> $entriesAfter Key to the new entries that follow it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function inserted(array $json, array $entriesAfter): array {
		$inserted = [];
		foreach ($json as $key => $value) {
			$inserted[$key] = $value;
			$inserted += ($entriesAfter[$key] ?? []);
		}

		return $inserted;
	}//end inserted()

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
