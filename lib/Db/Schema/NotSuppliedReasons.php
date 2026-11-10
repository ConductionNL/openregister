<?php

/**
 * NotSuppliedReasons: the reasons a schema administers for a value not supplied.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;

/**
 * Reads the `x-openregister-not-supplied-reasons` annotation into its usable code => label pairs.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class NotSuppliedReasons {

	/**
	 * The reasons this schema administers, keyed by code; [] when it offers none.
	 *
	 * An entry without a non-empty string code and label is left out: a reason
	 * nobody can read is no reason.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array<string, string>
	 */
	public function reasons(?array $configuration): array {
		$annotation = (($configuration ?? [])[Schema::NOT_SUPPLIED_REASONS_ANNOTATION] ?? null);
		if (is_array($annotation) === false) {
			return [];
		}

		$reasons = [];
		foreach ($annotation as $code => $label) {
			if ($this->isReason(code: $code, label: $label) === true) {
				$reasons[$code] = $label;
			}
		}

		return $reasons;
	}//end reasons()

	/**
	 * Whether a code and label make a usable reason.
	 *
	 * @param int|string $code  The code.
	 * @param mixed      $label The label.
	 *
	 * @return bool
	 */
	private function isReason(int|string $code, mixed $label): bool {
		return is_string($code) === true && $code !== '' && is_string($label) === true && $label !== '';
	}//end isReason()
}//end class
