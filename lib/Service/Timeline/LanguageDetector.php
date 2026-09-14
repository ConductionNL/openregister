<?php

/**
 * LanguageDetector: what language a message arrived in.
 *
 * WHAT THIS IS AND IS NOT. This is a function-word detector over the languages
 * a Dutch government counter actually receives, plus a script test for the ones
 * that do not use the Latin alphabet. It is not a general language classifier
 * and it does not pretend to be one: it answers `null` rather than guessing
 * when nothing scores, and `null` is a perfectly good answer for a record whose
 * purpose is to let a handler FILTER ("show me what came in in Polish"), not to
 * drive a decision.
 *
 * It is here rather than behind a library because the alternative on this fleet
 * is a dependency for one column. If an instance ever needs better than this,
 * the seam is {@see detect()}: one method, one return value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

/**
 * Detects the language an entry was written in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 */
class LanguageDetector {

	/**
	 * How many function words a text needs before a verdict is given at all.
	 *
	 * A three-word message carries no evidence. Answering `null` there is
	 * honest; answering `nl` because one word happened to match is the kind of
	 * confident wrong value that then gets filtered on.
	 *
	 * @var integer
	 */
	public const MINIMUM_HITS = 2;

	/**
	 * Function words per language, lowercased.
	 *
	 * @var array<string, array<int,string>>
	 */
	private const MARKERS = [
		'nl' => ['de', 'het', 'een', 'ik', 'niet', 'dat', 'en', 'van', 'voor', 'met', 'maar', 'graag', 'ook', 'wij', 'zijn', 'hebben', 'wordt', 'daarom'],
		'en' => ['the', 'and', 'is', 'are', 'you', 'this', 'that', 'with', 'for', 'have', 'would', 'please', 'from', 'about', 'they', 'because'],
		'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'ich', 'sie', 'mit', 'für', 'auch', 'aber', 'werden', 'haben', 'weil'],
		'fr' => ['le', 'la', 'les', 'un', 'une', 'est', 'pas', 'vous', 'nous', 'pour', 'avec', 'mais', 'dans', 'sur', 'parce'],
		'es' => ['el', 'los', 'las', 'una', 'que', 'por', 'para', 'con', 'pero', 'como', 'esta', 'porque', 'usted'],
		'pl' => ['nie', 'jest', 'sie', 'się', 'na', 'do', 'że', 'ze', 'to', 'jak', 'ale', 'dla', 'przez', 'bardzo', 'dzień', 'proszę'],
		'tr' => ['bir', 'bu', 'için', 'ile', 'olarak', 'daha', 'çok', 'değil', 'olan', 'ama', 'ben', 'siz', 'lütfen'],
	];

	/**
	 * Scripts that settle the question on their own.
	 *
	 * @var array<string, string>
	 */
	private const SCRIPTS = [
		'ar' => '/\p{Arabic}/u',
		'uk' => '/[\x{0404}\x{0406}\x{0407}\x{0454}\x{0456}\x{0457}\x{0490}\x{0491}]/u',
		'ru' => '/\p{Cyrillic}/u',
		'zh' => '/\p{Han}/u',
	];

	/**
	 * The language a text is written in.
	 *
	 * @param string|null $text The entry text.
	 *
	 * @return string|null A two-letter code, or null when there is no evidence.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function detect(?string $text): ?string {
		if (is_string($text) === false) {
			return null;
		}

		$trimmed = trim($text);
		if ($trimmed === '') {
			return null;
		}

		foreach (self::SCRIPTS as $code => $pattern) {
			if (preg_match($pattern, $trimmed) === 1) {
				return $code;
			}
		}

		return $this->byFunctionWords(text: $trimmed);
	}//end detect()

	/**
	 * Score the Latin-script languages by their function words.
	 *
	 * @param string $text The entry text.
	 *
	 * @return string|null The winner, or null when nothing scored enough.
	 */
	private function byFunctionWords(string $text): ?string {
		$words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($words) === false || $words === []) {
			return null;
		}

		$scores = [];
		foreach (self::MARKERS as $code => $markers) {
			$scores[$code] = count(array_intersect($words, $markers));
		}

		arsort($scores);
		$best = array_key_first($scores);
		if ($best === null || $scores[$best] < self::MINIMUM_HITS) {
			return null;
		}

		// A tie is no evidence either. Two languages sharing the same function
		// words on a short text is exactly where a detector invents a verdict,
		// so this one declines instead.
		$runnerUp = 0;
		$seenBest = false;
		foreach ($scores as $code => $score) {
			if ($code === $best && $seenBest === false) {
				$seenBest = true;
				continue;
			}

			$runnerUp = max($runnerUp, $score);
		}

		if ($runnerUp === $scores[$best]) {
			return null;
		}

		return (string)$best;
	}//end byFunctionWords()
}//end class
