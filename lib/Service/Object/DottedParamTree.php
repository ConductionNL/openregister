<?php

/**
 * OpenRegister DottedParamTree
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
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;


/**
 * Request parameters with PHP's dot-to-underscore mangling undone: `person_address_street`
 * becomes `person => address => street`.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class DottedParamTree {

	/**
	 * The rebuilt parameters.
	 *
	 * @var array<string, mixed>
	 */
	public array $params = [];

	/**
	 * Set a parameter as-is.
	 *
	 * @param string $key   The parameter name.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function set(string $key, mixed $value): void {
		$this->params[$key] = $value;
	}//end set()

	/**
	 * Set a parameter under the nested path its underscores spell; a scalar met on the way is replaced by an array.
	 *
	 * @param string $key   The mangled parameter name.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function nest(string $key, mixed $value): void {
		$parts = explode('_', $key);
		$params = $this->params;
		$current = &$params;
		$lastIndex = count($parts) - 1;

		foreach ($parts as $index => $part) {
			if ($index === $lastIndex) {
				$current[$part] = $value;
				continue;
			}

			if (isset($current[$part]) === false || is_array($current[$part]) === false) {
				$current[$part] = [];
			}

			$current = &$current[$part];
		}

		unset($current);
		$this->params = $params;
	}//end nest()
}//end class
