<?php

/**
 * OpenRegister NlPatternSet
 *
 * The Dutch identifiers: for now the BSN (burgerservicenummer).
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

namespace OCA\OpenRegister\Service\TextExtraction\PatternSet;

use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;

/**
 * Recognises Dutch identifiers in text.
 *
 * A BSN is a nine-digit token, written plain or in the groups 4-2-3 or 3-2-4
 * separated by a dot or a space, that passes the elfproef. The elfproef is
 * the one in {@see BsnFormat}; no second copy is written here. A nine-digit
 * token that fails it is not a BSN and is not reported. A BSN is reported as
 * {@see EntityRecognitionHandler::ENTITY_TYPE_SSN}, the citizen service number
 * type the risk service rates very high.
 *
 * The licence plate that task 2.2 of the change also names is not part of
 * this set yet.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
 */
class NlPatternSet implements JurisdictionPatternSet {
	/**
	 * A BSN candidate: nine digits, plain or grouped 4-2-3 or 3-2-4, standing
	 * alone as a token. Not preceded by a letter, digit, dot or hyphen, and not
	 * followed by a letter, digit or hyphen, or by a dot or comma and a digit,
	 * so a run of digits inside an IBAN, a longer number or a decimal is never
	 * a candidate.
	 */
	private const BSN_CANDIDATE = '/(?<![\w.\-])(?:\d{9}|\d{4}[. ]\d{2}[. ]\d{3}|\d{3}[. ]\d{2}[. ]\d{4})(?![\w\-]|[.,]\d)/';

	/**
	 * Confidence of an elfproef-confirmed BSN. One random nine-digit number in
	 * eleven passes the elfproef, so a match is likely but not certain.
	 */
	private const BSN_CONFIDENCE = 0.85;

	/**
	 * Constructor.
	 *
	 * @param BsnFormat $bsnFormat The elfproef.
	 */
	public function __construct(
		private readonly BsnFormat $bsnFormat = new BsnFormat(),
	) {
	}//end __construct()

	/**
	 * The jurisdiction code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
	 */
	public function getCode(): string {
		return 'nl';
	}//end getCode()

	/**
	 * Detect BSNs in the text.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
	 */
	public function detect(string $text): array {
		if (preg_match_all(self::BSN_CANDIDATE, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
			return [];
		}

		$entities = [];
		foreach ($matches[0] as [$candidate, $offset]) {
			$digits = str_replace(['.', ' '], '', $candidate);
			if ($this->bsnFormat->validate($digits) === false) {
				continue;
			}

			$entities[] = [
				'type' => EntityRecognitionHandler::ENTITY_TYPE_SSN,
				'value' => $candidate,
				'category' => EntityRecognitionHandler::CATEGORY_SENSITIVE_PII,
				'position_start' => $offset,
				'position_end' => $offset + strlen($candidate),
				'confidence' => self::BSN_CONFIDENCE,
			];
		}

		return $entities;
	}//end detect()
}//end class
