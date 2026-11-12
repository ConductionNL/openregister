<?php

/**
 * OpenRegister JsonArrayColumn
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

/**
 * Reads a json column that may hold an array, a JSON string or nothing.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
final class JsonArrayColumn {

	/**
	 * The decoded array of a JSON string, or null when it is not valid JSON for an array.
	 *
	 * @param string $json The JSON text.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function decoded(string $json): ?array {
		$decoded = json_decode($json, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decoded()

	/**
	 * The value as an array: itself, the decoded JSON string, or an empty array.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return ($this->decoded(json: $value) ?? []);
		}

		return [];
	}//end asArray()
}//end class
