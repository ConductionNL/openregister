<?php

/**
 * Deterministic letter texts for the near-duplicate tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

/**
 * Builds the same pseudo-random letter for a seed on every run.
 */
final class NearDuplicateTexts {

	/**
	 * The words letters are made of.
	 *
	 * @var list<string>
	 */
	private const WORDS = [
		'gemeente', 'besluit', 'verzoek', 'bezwaar', 'termijn', 'aanvraag', 'woning', 'vergunning', 'college', 'raad',
		'inwoner', 'bericht', 'datum', 'kenmerk', 'onderwerp', 'bijlage', 'afdeling', 'wethouder', 'locatie', 'plan',
		'straat', 'kosten', 'subsidie', 'advies', 'overleg', 'contact', 'brief', 'reactie', 'periode', 'jaar',
		'de', 'het', 'een', 'van', 'met', 'voor', 'over', 'naar', 'bij', 'door',
		'wordt', 'heeft', 'zijn', 'kan', 'moet', 'zal', 'niet', 'ook', 'nog', 'wel',
		'project', 'bouw', 'verkeer', 'groen', 'school', 'sport', 'zorg', 'werk', 'geld', 'regels',
	];

	/**
	 * A letter of the given length.
	 *
	 * @param int $seed  The seed: one seed, one text.
	 * @param int $words The number of words.
	 *
	 * @return string The text, with punctuation and line breaks.
	 */
	public static function letter(int $seed, int $words=300): string {
		mt_srand($seed);
		$out = [];
		for ($i = 1; $i <= $words; $i++) {
			$out[] = self::WORDS[mt_rand(0, count(self::WORDS) - 1)] . (($i % 12) === 0 ? ".\n" : '');
		}

		mt_srand();

		return ucfirst(implode(' ', $out));
	}//end letter()

	/**
	 * The letter with a word changed at each of the given positions.
	 *
	 * @param string    $text      The letter.
	 * @param list<int> $positions The 0-based word positions.
	 *
	 * @return string The edited letter.
	 */
	public static function edited(string $text, array $positions): string {
		$words = explode(' ', $text);
		foreach ($positions as $position) {
			$words[$position] = 'gewijzigd';
		}

		return implode(' ', $words);
	}//end edited()
}//end class
