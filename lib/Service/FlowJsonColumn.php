<?php

/**
 * OpenRegister Flow JSON Column
 *
 * Decodes the JSON-serialised array columns of NC Flow's `flow_operations`.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The `events` and `checks` columns of a Flow operation as arrays, shared by FlowLinkService and FlowProvider.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class FlowJsonColumn {

	/**
	 * Decode a JSON-serialised array column (`events`, `checks`).
	 *
	 * NC Flow stores these as JSON strings. We tolerate both null and
	 * already-decoded arrays so callers can pass raw row values.
	 *
	 * @param mixed $value Raw column value.
	 *
	 * @return array<int|string,mixed> The array, or [] when the value is empty or not a JSON array.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public static function decode(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return [];
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decode()
}//end class
