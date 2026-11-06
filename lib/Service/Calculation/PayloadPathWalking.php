<?php

/**
 * PayloadPathWalking: reading a value down a list of keys.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Walks an object payload along a list of keys.
 */
trait PayloadPathWalking {

	/**
	 * Walk the payload key by key.
	 *
	 * @param array<string, mixed> $payload Object data.
	 * @param array<int, string>   $parts   The keys, outermost first.
	 *
	 * @return mixed The value, or null when a key is missing.
	 */
	private function walkPath(array $payload, array $parts): mixed {
		$current = $payload;
		foreach ($parts as $part) {
			if (is_array($current) === false || array_key_exists($part, $current) === false) {
				return null;
			}

			$current = $current[$part];
		}

		return $current;
	}//end walkPath()
}//end trait
