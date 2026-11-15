<?php

/**
 * OpenRegister IBAN format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
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

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * Validates an International Bank Account Number (ISO 13616) as a JSON Schema format.
 *
 * An IBAN is two country letters, two check digits and an account part of
 * letters and digits, 15 to 34 characters in all, whose mod-97 remainder is 1
 * once the first four characters move to the end and every letter becomes two
 * digits (A = 10 ... Z = 35). Spaces are accepted and ignored, as is lower case,
 * so `NL91 ABNA 0417 1643 00` passes. For a country in LENGTHS the length must
 * match it exactly; another country gets the general bounds only.
 *
 * Constructed with a country, it accepts only IBANs of that country, so an app
 * can ask "a valid Dutch IBAN" of one value without saving an object.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class IbanFormat implements Format {

	/**
	 * IBAN lengths by country, from the SWIFT IBAN registry, for the countries Conduction's apps meet most.
	 *
	 * @var array<string, int>
	 */
	public const LENGTHS = [
		'AT' => 20,
		'BE' => 16,
		'CH' => 21,
		'DE' => 22,
		'DK' => 18,
		'ES' => 24,
		'FI' => 18,
		'FR' => 27,
		'GB' => 22,
		'IE' => 22,
		'IT' => 27,
		'LU' => 20,
		'NL' => 18,
		'NO' => 15,
		'PL' => 28,
		'PT' => 25,
		'SE' => 24,
	];

	/**
	 * The shape every IBAN has before its checksum is checked.
	 *
	 * @var string
	 */
	private const SHAPE = '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/';

	/**
	 * Constructor.
	 *
	 * @param string|null $country Two-letter country the IBAN must belong to; null accepts any country.
	 *
	 * @return void
	 */
	public function __construct(private readonly ?string $country=null) {
	}//end __construct()

	/**
	 * Whether a value is a valid IBAN (of the configured country, when there is one).
	 *
	 * @param mixed $data The value to check.
	 *
	 * @inheritDoc
	 *
	 * @return bool True for a well-formed IBAN with a correct checksum.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false) {
			return false;
		}

		$iban = self::normalise(value: $data);
		if (preg_match(self::SHAPE, $iban) !== 1 || $this->belongsToCountry(iban: $iban) === false) {
			return false;
		}

		return self::hasCountryLength(iban: $iban) === true && self::mod97(iban: $iban) === 1;
	}//end validate()

	/**
	 * The value without whitespace, in upper case.
	 *
	 * @param string $value The value as typed.
	 *
	 * @return string The compact form.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function normalise(string $value): string {
		return strtoupper((string) preg_replace('/\s+/u', '', $value));
	}//end normalise()

	/**
	 * Whether the IBAN is of the configured country; always true without one.
	 *
	 * @param string $iban The compact IBAN.
	 *
	 * @return bool
	 */
	private function belongsToCountry(string $iban): bool {
		if ($this->country === null) {
			return true;
		}

		return str_starts_with($iban, strtoupper($this->country));
	}//end belongsToCountry()

	/**
	 * Whether the IBAN has its country's registered length; true for a country not in LENGTHS.
	 *
	 * @param string $iban The compact IBAN.
	 *
	 * @return bool
	 */
	private static function hasCountryLength(string $iban): bool {
		$length = (self::LENGTHS[substr($iban, 0, 2)] ?? null);

		return $length === null || strlen($iban) === $length;
	}//end hasCountryLength()

	/**
	 * The ISO 7064 mod-97 remainder of the rearranged IBAN.
	 *
	 * The digits are folded in chunks so the number never outgrows an integer.
	 *
	 * @param string $iban The compact IBAN, shape already checked.
	 *
	 * @return int The remainder; 1 for a valid IBAN.
	 */
	private static function mod97(string $iban): int {
		$digits = '';
		foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
			$value = (string) (ord($character) - 55);
			if (ctype_digit($character) === true) {
				$value = $character;
			}

			$digits .= $value;
		}

		$remainder = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$remainder = (int) ((string) $remainder.$chunk) % 97;
		}

		return $remainder;
	}//end mod97()
}//end class
