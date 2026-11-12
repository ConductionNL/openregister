<?php

/**
 * OpenRegister empty value stripper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * Strips empty values from API responses.
 *
 * Null, empty strings and empty arrays go; 0, false and "0" stay. Scalars
 * inside a list stay as they are, so a list keeps its positions.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class EmptyValueStripper {

	/**
	 * An array without its empty values, at every depth.
	 *
	 * @param array $data The array.
	 *
	 * @return array The array without empty values.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function stripped(array $data): array {
		$result = [];
		foreach ($data as $key => $value) {
			$kept = $this->strippedValue(value: $value);
			if (in_array($kept, [null, '', []], true) === false) {
				$result[$key] = $kept;
			}
		}

		return $result;
	}//end stripped()

	/**
	 * Result rows without their empty values; a row that serializes is serialized first.
	 *
	 * @param array $results The rows.
	 *
	 * @return array The rows.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function strippedRows(array $results): array {
		return array_map(fn (mixed $row): mixed => $this->strippedRow(row: $row), $results);
	}//end strippedRows()

	/**
	 * One value of an array, stripped when it is an array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The stripped value.
	 */
	private function strippedValue(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			return $this->stripped(data: $value);
		}

		return array_map(fn (mixed $item): mixed => $this->strippedListItem(item: $item), $value);
	}//end strippedValue()

	/**
	 * One item of a list: only arrays are stripped.
	 *
	 * @param mixed $item The item.
	 *
	 * @return mixed The item.
	 */
	private function strippedListItem(mixed $item): mixed {
		if (is_array($item) === true) {
			return $this->stripped(data: $item);
		}

		return $item;
	}//end strippedListItem()

	/**
	 * One result row.
	 *
	 * @param mixed $row An array, or an object that may serialize.
	 *
	 * @return mixed The stripped row, or the row when it does not serialize to an array.
	 */
	private function strippedRow(mixed $row): mixed {
		if (is_array($row) === false
			&& method_exists(object_or_class: $row, method: 'jsonSerialize') === true
		) {
			$row = $row->jsonSerialize();
		}

		return $this->strippedListItem(item: $row);
	}//end strippedRow()
}//end class
