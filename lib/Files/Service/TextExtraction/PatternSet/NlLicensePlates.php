<?php

/**
 * OpenRegister NlLicensePlates
 *
 * Finds Dutch licence plates in text, with the false-positive guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction\PatternSet
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

namespace OCA\OpenRegister\Files\Service\TextExtraction\PatternSet;

use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Formats\IdentifierFormats;
use OCA\OpenRegister\Formats\LicensePlateNlFormat;

/**
 * A plate is six characters in three groups, which is also the shape of case
 * numbers, dates and product codes, so a candidate needs proof:
 *
 * - A hyphenated candidate counts only when it matches one sidecode exactly,
 *   with that sidecode's letters, and stands alone as a token (no letter,
 *   digit or hyphen before or after it). `ZK-12-AB-34` and `2024-12-AB` yield
 *   nothing.
 * - An unhyphenated candidate counts only with a context word within 30
 *   characters before it.
 *
 * The sidecode rule itself is the Formats component's; none is copied here.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */
final class NlLicensePlates {

	/**
	 * Words that make an unhyphenated candidate a plate when one of them sits
	 * within CONTEXT_WINDOW characters before it.
	 *
	 * @var array<int, string>
	 */
	public const CONTEXT_WORDS = ['kenteken', 'kentekenplaat', 'voertuig', 'license plate', 'licence plate', 'registration'];

	/**
	 * How far before an unhyphenated candidate a context word may sit.
	 *
	 * @var int
	 */
	public const CONTEXT_WINDOW = 30;

	/**
	 * Confidence of a hyphenated plate.
	 *
	 * @var float
	 */
	public const HYPHENATED_CONFIDENCE = 0.8;

	/**
	 * Confidence of an unhyphenated plate with a context word.
	 *
	 * @var float
	 */
	public const COMPACT_CONFIDENCE = 0.6;

	/**
	 * A hyphenated candidate: three groups of one to three letters or digits,
	 * standing alone as a token.
	 *
	 * @var string
	 */
	private const HYPHENATED = '/(?<![\p{L}\p{N}\-])[A-Za-z0-9]{1,3}-[A-Za-z0-9]{1,3}-[A-Za-z0-9]{1,3}(?![\p{L}\p{N}\-])/u';

	/**
	 * An unhyphenated candidate: six letters and digits standing alone as a token.
	 *
	 * @var string
	 */
	private const COMPACT = '/(?<![\p{L}\p{N}\-])[A-Za-z0-9]{6}(?![\p{L}\p{N}\-])/u';

	/**
	 * The sidecode rule.
	 *
	 * @var LicensePlateNlFormat
	 */
	private readonly LicensePlateNlFormat $format;

	/**
	 * Constructor.
	 *
	 * @param LicensePlateNlFormat|null $format The sidecode rule; the Formats component's when null.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) IdentifierFormats is the Formats component's factory, as NlPatternSet uses it for the BSN.
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function __construct(?LicensePlateNlFormat $format=null) {
		$this->format = ($format ?? IdentifierFormats::licensePlateNl());
	}//end __construct()

	/**
	 * The plates in a text.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float, sidecode: int}>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function detect(string $text): array {
		$plates = [];
		foreach ($this->candidates(pattern: self::HYPHENATED, text: $text) as [$candidate, $offset]) {
			$sidecode = $this->format->sidecode(value: $candidate);
			if ($sidecode !== null) {
				$plates[] = $this->plate(candidate: $candidate, offset: $offset, found: [$sidecode, self::HYPHENATED_CONFIDENCE]);
			}
		}

		foreach ($this->candidates(pattern: self::COMPACT, text: $text) as [$candidate, $offset]) {
			$sidecode = $this->format->sidecode(value: $candidate);
			if ($sidecode !== null && $this->hasContextBefore(text: $text, offset: $offset) === true) {
				$plates[] = $this->plate(candidate: $candidate, offset: $offset, found: [$sidecode, self::COMPACT_CONFIDENCE]);
			}
		}

		return $plates;
	}//end detect()

	/**
	 * The matches of a candidate pattern with their byte offsets.
	 *
	 * @param string $pattern The candidate regex.
	 * @param string $text    The text.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	private function candidates(string $pattern, string $text): array {
		if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
			return [];
		}

		return $matches[0];
	}//end candidates()

	/**
	 * Whether a context word sits within the window before a byte offset.
	 *
	 * @param string $text   The text.
	 * @param int    $offset The candidate's byte offset.
	 *
	 * @return bool
	 */
	private function hasContextBefore(string $text, int $offset): bool {
		$start  = max(0, ($offset - self::CONTEXT_WINDOW));
		$before = mb_strtolower(substr($text, $start, ($offset - $start)));
		foreach (self::CONTEXT_WORDS as $word) {
			if (str_contains($before, $word) === true) {
				return true;
			}
		}

		return false;
	}//end hasContextBefore()

	/**
	 * One plate entity.
	 *
	 * @param string                    $candidate The matched text.
	 * @param int                       $offset    Its byte offset.
	 * @param array{0: int, 1: float} $found     The sidecode and the confidence.
	 *
	 * @return array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float, sidecode: int}
	 */
	private function plate(string $candidate, int $offset, array $found): array {
		return [
			'type' => EntityRecognitionHandler::ENTITY_TYPE_LICENSE_PLATE,
			'value' => $candidate,
			'category' => EntityRecognitionHandler::CATEGORY_PERSONAL_DATA,
			'position_start' => $offset,
			'position_end' => ($offset + strlen($candidate)),
			'confidence' => $found[1],
			'sidecode' => $found[0],
		];
	}//end plate()
}//end class
