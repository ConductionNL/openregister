<?php

/**
 * The `nl-phone` named format: a Dutch telephone number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
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

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * A Dutch number is a national number of nine digits whose first digit is not
 * zero, reached through a trunk `0` (`0612345678`) or the country code `+31` /
 * `0031` (`+31612345678`). Spaces, hyphens and parentheses a person types are
 * ignored; anything else is refused.
 *
 * It enforces identically through the UI and the API, because both reach this
 * one format.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
 */
final class NlPhoneFormat implements Format {

	/**
	 * Whether the value is a Dutch telephone number.
	 *
	 * @param mixed $data The value to validate.
	 *
	 * @inheritDoc
	 *
	 * @return bool True when the value is a Dutch number.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false && is_int($data) === false) {
			return false;
		}

		// A person's separators carry no meaning in a phone number, so they are
		// removed before the number is judged; any other character then fails.
		$digits = preg_replace('/[\s\-()]/', '', (string)$data);
		if ($digits === null || $digits === '') {
			return false;
		}

		// `+31` / `0031` are the country code; `0` is the national trunk. After
		// either, a national number is nine digits whose first is 1–9 (0 is the
		// trunk, never the first significant digit).
		return preg_match('/^(?:\+31|0031|0)[1-9][0-9]{8}$/', $digits) === 1;
	}//end validate()
}//end class
