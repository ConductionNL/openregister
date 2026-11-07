<?php

/**
 * OpenRegister RegisterSchemaIds
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Register;

/**
 * The schema id list a register carries.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class RegisterSchemaIds {

	/**
	 * The list as given: a JSON string is decoded, anything else but an array is empty.
	 *
	 * @param mixed $schemas Array of schema ids or a JSON string.
	 *
	 * @return array<int|string>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function fromInput(mixed $schemas): array {
		if (is_string($schemas) === true) {
			$schemas = (json_decode($schemas, true) ?? []);
		}

		if (is_array($schemas) === false) {
			return [];
		}

		return $this->idsOnly(schemas: $schemas);
	}//end fromInput()

	/**
	 * Only the int and string entries, keys kept.
	 *
	 * @param array<mixed> $schemas The entries.
	 *
	 * @return array<int|string>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function idsOnly(array $schemas): array {
		return array_filter(
			$schemas,
			static fn (mixed $item): bool => is_int($item) || is_string($item)
		);
	}//end idsOnly()

	/**
	 * Whether a numeric entry equals the id.
	 *
	 * @param array<int|string> $schemas  The entries.
	 * @param int               $schemaId The id.
	 *
	 * @return bool
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function contains(array $schemas, int $schemaId): bool {
		foreach ($schemas as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate === $schemaId) {
				return true;
			}
		}

		return false;
	}//end contains()
}//end class
