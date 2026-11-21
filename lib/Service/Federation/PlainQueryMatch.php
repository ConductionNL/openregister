<?php

/**
 * OpenRegister PlainQueryMatch
 *
 * Whether a payload meets a share query made of plain values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

/**
 * Admits a payload only when every condition of the query is a plain value (or list of
 * values) on a property the payload sets to that value. A condition it cannot read, such
 * as an operator, `_ids` or a search, admits nothing.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */
final class PlainQueryMatch {

	/**
	 * Whether the payload meets every condition.
	 *
	 * @param array<string, mixed> $filters The query's conditions.
	 * @param array<string, mixed> $data    The payload.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function admits(array $filters, array $data): bool {
		foreach ($filters as $property => $wanted) {
			if ($this->meets(property: (string)$property, wanted: self::plainValues(wanted: $wanted), data: $data) === false) {
				return false;
			}
		}

		return true;
	}//end admits()

	/**
	 * Whether the payload sets the property to one of the values.
	 *
	 * @param string                    $property The condition's property.
	 * @param array<int, scalar>|null   $wanted   The plain values, or null for a condition that is not plain.
	 * @param array<string, mixed>      $data     The payload.
	 *
	 * @return bool
	 */
	private function meets(string $property, ?array $wanted, array $data): bool {
		$given = ($data[$property] ?? null);
		if ($wanted === null || str_starts_with($property, '_') === true || is_scalar($given) === false) {
			return false;
		}

		return in_array((string)$given, array_map('strval', $wanted), true);
	}//end meets()

	/**
	 * A condition's values when it is a plain value or a non-empty list of them, else null.
	 *
	 * @param mixed $wanted The condition's value.
	 *
	 * @return array<int, scalar>|null
	 */
	private static function plainValues(mixed $wanted): ?array {
		if (is_scalar($wanted) === true) {
			return [$wanted];
		}

		if (is_array($wanted) === false || $wanted === [] || array_is_list($wanted) === false
			|| count(array_filter($wanted, 'is_scalar')) !== count($wanted)
		) {
			return null;
		}

		return $wanted;
	}//end plainValues()
}//end class
