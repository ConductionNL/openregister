<?php

/**
 * OpenRegister LanguageKeyedMap
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Whether an array is a language map: non-empty, and every key a BCP 47 tag
 * (2-3 lowercase letters, optional subtags). The save path and the translation
 * path must agree on this, so both ask here.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LanguageKeyedMap {
	/**
	 * @param array<mixed> $value The candidate value.
	 */
	public function __construct(
		private readonly array $value,
	) {
	}//end __construct()

	/**
	 * True when every key of the value is a language tag.
	 *
	 * @return bool
	 */
	public function isLanguageKeyed(): bool {
		if (empty($this->value) === true) {
			return false;
		}

		foreach (array_keys($this->value) as $key) {
			if (is_string($key) === false || preg_match('/^[a-z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $key) !== 1) {
				return false;
			}
		}

		return true;
	}//end isLanguageKeyed()
}//end class
