<?php

/**
 * OpenRegister BsnFormat
 *
 * This file contains the format class for the Bsn format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;
use TypeError;

/**
 * Validates the Dutch BSN (Burgerservicenummer) as a JSON Schema format.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class BsnFormat implements Format {
	/**
	 * Validates if a given value conforms to the Dutch BSN (Burgerservicenummer) format.
	 *
	 * @param mixed $data The data to validate against the BSN format.
	 *
	 * @inheritDoc
	 *
	 * @return bool True if data is a valid BSN, false otherwise.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function validate(mixed $data): bool {
		// An array or object is a caller bug, not an invalid BSN, so it is
		// refused loudly. str_pad() used to raise the TypeError itself, further
		// down and by accident; raising it here keeps that contract explicit
		// once the cast below stops the value ever reaching str_pad() untyped.
		if (is_array($data) === true || is_object($data) === true) {
			throw new TypeError(
				'BsnFormat::validate() expects a scalar or null, '.get_debug_type($data).' given'
			);
		}

		// Cast ONCE, here. null and false coerce to '' and pad to the all-zero
		// sentinel, which is rejected below — that is the documented behaviour
		// (ADR-008 Rule 4). Passing null on to str_pad() instead is deprecated
		// in PHP 8.1 and a TypeError in PHP 9, so the same input would stop
		// being 'not a BSN' and start being a fatal.
		$data = (string)$data;

		// Reject over-length input before padding: str_pad only left-pads and
		// never truncates, so a >9-digit value would otherwise be checksummed
		// on a miscalculated weighting (ADR-008 Rule 4).
		if (strlen($data) > 9) {
			return false;
		}

		$data = str_pad(
			string: $data,
			length:9,
			pad_string: '0',
			pad_type: STR_PAD_LEFT,
		);

		if (ctype_digit($data) === false) {
			return false;
		}

		// Reject the all-zero sentinel: it passes the modulo-11 checksum
		// (0 % 11 === 0) but is not a real BSN, and empty/null input pads to it
		// (ADR-008 Rule 4).
		if ($data === '000000000') {
			return false;
		}

		$control = 0;
		$reversedIterator = 9;
		foreach (str_split($data) as $character) {
			// Calculate the multiplier based on position.
			$multiplier = -1;
			if ($reversedIterator > 1) {
				$multiplier = $reversedIterator;
			}

			$control += ((int)$character * $multiplier);
			$reversedIterator--;
		}

		return ($control % 11) === 0;
	}//end validate()
}//end class
