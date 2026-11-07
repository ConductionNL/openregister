<?php

/**
 * Reads an optional, trimmed string off a request payload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * Optional string values of a payload, with blanks read as absent.
 */
trait OptionalPayloadString {
	/**
	 * Read one optional string off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return string|null The value, or null when absent, empty or not a string.
	 */
	private function stringOrNull(array $data, string $key): ?string {
		if (isset($data[$key]) === false || is_string($data[$key]) === false) {
			return null;
		}

		$value = trim($data[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()

	/**
	 * Read one optional list off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return array<mixed> The value, or an empty array when absent or not an array.
	 */
	private function arrayOrEmpty(array $data, string $key): array {
		$value = ($data[$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOrEmpty()
}//end trait
