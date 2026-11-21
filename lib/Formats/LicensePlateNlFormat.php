<?php

/**
 * OpenRegister Dutch licence plate format.
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
 * Validates a Dutch licence plate (kenteken) as a JSON Schema format, and
 * names the sidecode it matches.
 *
 * The fourteen sidecodes are data: `X` is a letter, `9` a digit. The letters C
 * and Q never appear; from sidecode 7 on the vowels A, E, I, O and U do not
 * appear either. A plate is accepted with its hyphens or without them, in
 * either case.
 *
 * Source: https://nl.wikipedia.org/wiki/Nederlands_kenteken (sidecodes 1-14
 * and the letter rules), read 2026-09-27, as recorded in the change's design.
 * Not yet checked against the RDW: do that before relying on the list beyond
 * detection. The letter rules are not made stricter
 * than the source: a false negative leaks a plate, a false positive only
 * redacts something needlessly.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */
class LicensePlateNlFormat implements Format {

	/**
	 * The sidecodes by number.
	 *
	 * @var array<int, string>
	 */
	public const SIDECODES = [
		1 => 'XX-99-99',
		2 => '99-99-XX',
		3 => '99-XX-99',
		4 => 'XX-99-XX',
		5 => 'XX-XX-99',
		6 => '99-XX-XX',
		7 => '99-XXX-9',
		8 => '9-XXX-99',
		9 => 'XX-999-X',
		10 => 'X-999-XX',
		11 => 'XXX-99-X',
		12 => 'X-99-XXX',
		13 => '9-XX-999',
		14 => '999-XX-9',
	];

	/**
	 * The letters of sidecodes 1 to 6: A to Z without C and Q.
	 *
	 * @var string
	 */
	private const LETTERS = 'ABDEFGHIJKLMNOPRSTUVWXYZ';

	/**
	 * The letters from sidecode 7 on: also without the vowels.
	 *
	 * @var string
	 */
	private const LETTERS_WITHOUT_VOWELS = 'BDFGHJKLMNPRSTVWXYZ';

	/**
	 * The first sidecode without vowels.
	 *
	 * @var int
	 */
	private const FIRST_WITHOUT_VOWELS = 7;

	/**
	 * Whether a value is a Dutch licence plate.
	 *
	 * @param mixed $data The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function validate(mixed $data): bool {
		return is_string($data) === true && $this->sidecode(value: $data) !== null;
	}//end validate()

	/**
	 * The sidecode a plate matches, or null when it matches none.
	 *
	 * A hyphenated value must match the sidecode's groups exactly; an
	 * unhyphenated value matches a sidecode by its letters and digits alone.
	 *
	 * @param string $value The plate, with or without hyphens.
	 *
	 * @return int|null The sidecode, 1 to 14.
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function sidecode(string $value): ?int {
		$plate = strtoupper(trim($value));
		foreach (self::SIDECODES as $sidecode => $shape) {
			if (str_contains($plate, '-') === false) {
				$shape = str_replace('-', '', $shape);
			}

			if (preg_match($this->pattern(sidecode: $sidecode, shape: $shape), $plate) === 1) {
				return $sidecode;
			}
		}

		return null;
	}//end sidecode()

	/**
	 * The anchored regex of one sidecode's shape.
	 *
	 * @param int    $sidecode The sidecode, which decides the letter set.
	 * @param string $shape    The shape, with or without hyphens.
	 *
	 * @return string
	 */
	private function pattern(int $sidecode, string $shape): string {
		$letters = self::LETTERS;
		if ($sidecode >= self::FIRST_WITHOUT_VOWELS) {
			$letters = self::LETTERS_WITHOUT_VOWELS;
		}

		return '/^'.strtr($shape, ['X' => '['.$letters.']', '9' => '\d', '-' => '-']).'$/';
	}//end pattern()
}//end class
