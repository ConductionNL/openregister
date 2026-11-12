<?php

/**
 * Configuration data as plain arrays, all the way down.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Turns the objects a decoder leaves in configuration data into arrays.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ArrayStructure
{

	/**
	 * The data with every object in it, at any depth, turned into an array.
	 *
	 * @param mixed $data An array or an object; anything else is a \TypeError.
	 *
	 * @return array The converted data.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function toArrays(mixed $data): array {
		if (is_object($data) === true) {
			$data = (array)$data;
		}

		return array_map(fn (mixed $value): mixed => $this->converted(value: $value), $data);
	}//end toArrays()

	/**
	 * One value of the data, converted when it holds structure.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value.
	 */
	private function converted(mixed $value): mixed {
		if (is_object($value) === true || is_array($value) === true) {
			return $this->toArrays(data: $value);
		}

		return $value;
	}//end converted()
}//end class
