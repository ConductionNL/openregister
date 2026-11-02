<?php

/**
 * Puts a property value in the form a reader would see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

/**
 * Two values that differ only in how they are stored normalise to the same value.
 *
 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
 */
class ValueNormaliser {

	/**
	 * One value in the form a reader would see, so a re-save that only changes
	 * how it is stored compares equal.
	 *
	 * An empty string and an empty list read as null, a list or map stored as
	 * its JSON text reads as the decoded value, a whole float reads as the
	 * integer, and a map's keys are sorted. A list keeps its order.
	 *
	 * @param mixed $value A property value.
	 *
	 * @return mixed The value in its normal form.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function normalised(mixed $value): mixed {
		$value = $this->decodedJson(value: $value);
		if ($value === '' || $value === []) {
			return null;
		}

		if (is_float($value) === true && floor($value) === $value && abs($value) < PHP_INT_MAX) {
			return (int) $value;
		}

		if (is_array($value) === false) {
			return $value;
		}

		$normal = array_map(fn ($entry): mixed => $this->normalised(value: $entry), $value);
		if (array_is_list($normal) === false) {
			ksort($normal);
		}

		return $normal;

	}//end normalised()

	/**
	 * A list or map stored as its JSON text, decoded; any other value as given.
	 *
	 * @param mixed $value A property value.
	 *
	 * @return mixed The decoded list or map, or the value unchanged.
	 */
	private function decodedJson(mixed $value): mixed {
		if (is_string($value) === false || $value === '' || in_array($value[0], ['[', '{'], true) === false) {
			return $value;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $value;

	}//end decodedJson()
}//end class
