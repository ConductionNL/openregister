<?php

/**
 * OpenRegister PatchDataMerge
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Merges a partial payload onto stored data, PATCH-semantically.
 *
 * An explicit null clears a property. Two associative arrays merge recursively;
 * lists, on either side, replace wholesale.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class PatchDataMerge {

	/**
	 * The stored data with the patch applied.
	 *
	 * @param array<mixed> $stored The stored object data.
	 * @param array<mixed> $patch  The partial payload.
	 *
	 * @return array<mixed>
	 */
	public function merge(array $stored, array $patch): array {
		foreach ($patch as $key => $value) {
			$current = ($stored[$key] ?? null);
			if ($this->isMap(value: $value) === true && $this->isMap(value: $current) === true) {
				$value = $this->merge(stored: $current, patch: $value);
			}

			$stored[$key] = $value;
		}

		return $stored;
	}//end merge()

	/**
	 * Whether a value is an associative array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isMap(mixed $value): bool {
		return is_array($value) === true && array_is_list($value) === false;
	}//end isMap()
}//end class
