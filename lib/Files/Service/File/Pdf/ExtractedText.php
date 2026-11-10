<?php

/**
 * ExtractedText
 *
 * The text re-extracted from a redacted PDF, raw and whitespace-normalised, probed for residual entity text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

/**
 * Re-extracted output text in both its raw and its whitespace-normalised form.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final class ExtractedText {
	/**
	 * Constructor.
	 *
	 * @param string $raw        Raw re-extracted output text.
	 * @param string $normalised Whitespace-normalised output text.
	 */
	public function __construct(
		public readonly string $raw,
		public readonly string $normalised,
	) {

	}//end __construct()

	/**
	 * Whether one needle is still present in the text.
	 *
	 * A needle whose normalisation failed (invalid UTF-8, null) is probed
	 * against the raw bytes (`strpos` is encoding-agnostic). Otherwise the
	 * check is case-SENSITIVE and multibyte-safe via `mb_strpos`, mirroring
	 * the replacement engine: SAPP replaces exact-case needles only, so a
	 * case-insensitive probe would flag text that was never a detected entity
	 * (the lowercase domain in "www.amsterdam.nl" against "Amsterdam").
	 *
	 * @param string      $rawNeedle        The needle as given.
	 * @param string|null $normalisedNeedle The whitespace-normalised needle, or null when normalisation failed.
	 *
	 * @return bool
	 */
	public function holds(string $rawNeedle, ?string $normalisedNeedle): bool {
		if ($normalisedNeedle === null) {
			return strpos($this->raw, $rawNeedle) !== false;
		}

		$normalisedNeedle = trim($normalisedNeedle);
		if ($normalisedNeedle === '') {
			return false;
		}

		return mb_strpos($this->normalised, $normalisedNeedle) !== false;
	}//end holds()
}//end class
