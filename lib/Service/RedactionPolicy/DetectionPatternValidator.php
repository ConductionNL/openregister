<?php

/**
 * OpenRegister DetectionPatternValidator
 *
 * Refuses a detection pattern that would not run safely beside the model (REQ-RPD-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

/**
 * The save-time checks of a `detectionPattern`.
 *
 * A regex is refused when it does not compile, matches the empty string, is
 * longer than 500 characters, or exceeds the backtrack limit on a 100 KB test
 * text. The test texts are long runs of letters, digits, spaces and a
 * separator-laden mix, each ending in a character the run does not contain:
 * that tail is what makes a nested quantifier such as `(a+)+$` try every way
 * of splitting the run before failing, which is the shape a catastrophic
 * pattern takes on a real document.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */
final class DetectionPatternValidator {

	public const MAX_LENGTH = 500;

	public const TEST_TEXT_BYTES = 102400;

	/**
	 * The backtrack limit a pattern must stay within on every test text.
	 *
	 * PHP's default; a pattern that needs more on 100 KB would need it on a
	 * real document, where the failure is a silently missed detection.
	 *
	 * @var int
	 */
	public const BACKTRACK_LIMIT = 1000000;

	/**
	 * The delimiter a stored pattern is compiled with: a control character no
	 * officer types, so a pattern needs no escaping of its own slashes.
	 *
	 * @var string
	 */
	public const DELIMITER = "\x01";

	/**
	 * The problems of one pattern as written, in the order they are checked; empty when it may be saved.
	 *
	 * @param array<string, mixed> $pattern The detectionPattern object.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public function problemsOf(array $pattern): array {
		$text = $pattern['pattern'] ?? null;
		if (is_string($text) === false || $text === '') {
			return ['the pattern is empty'];
		}

		if (mb_strlen($text) > self::MAX_LENGTH) {
			return [sprintf('the pattern is longer than %d characters', self::MAX_LENGTH)];
		}

		if (($pattern['kind'] ?? 'regex') !== 'regex') {
			return [];
		}

		return $this->regexProblems(regex: self::compiled(pattern: $text));
	}//end problemsOf()

	/**
	 * A stored regex as PHP compiles it.
	 *
	 * @param string $pattern The pattern without delimiters.
	 *
	 * @return string The compiled form.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public static function compiled(string $pattern): string {
		return self::DELIMITER.$pattern.self::DELIMITER.'u';
	}//end compiled()

	/**
	 * Run a compiled regex with the backtrack limit in force, restoring the setting after.
	 *
	 * @param string $regex The compiled regex.
	 * @param string $text  The text.
	 *
	 * @return array{0: int|false, 1: int, 2: array<int, array<int, array{0: string, 1: int}>>} The result, the PCRE error, and the matches with offsets.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public static function matchAllBounded(string $regex, string $text): array {
		$previous = ini_set('pcre.backtrack_limit', (string)self::BACKTRACK_LIMIT);
		$matches = [];
		try {
			// The warning a pattern that does not compile raises is the refusal's reason, read from the return.
			$result = @preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);
			$error = preg_last_error();
		} finally {
			if ($previous !== false) {
				ini_set('pcre.backtrack_limit', $previous);
			}
		}

		return [$result, $error, $matches];
	}//end matchAllBounded()

	/**
	 * The problems of a regex that is short enough to try.
	 *
	 * @param string $regex The compiled regex.
	 *
	 * @return array<int, string> The problems.
	 */
	private function regexProblems(string $regex): array {
		if (@preg_match($regex, '') === false) {
			return ['the pattern is not a valid regular expression'];
		}

		if (preg_match($regex, '') === 1) {
			return ['the pattern matches the empty string, so it would mark every position of a document'];
		}

		foreach ($this->testTexts() as $text) {
			[$result, $error] = self::matchAllBounded(regex: $regex, text: $text);
			if ($result === false && in_array($error, [PREG_BACKTRACK_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR], true) === true) {
				return [sprintf('the pattern exceeds the backtrack limit (%d) on a %d KB test text', self::BACKTRACK_LIMIT, intdiv(self::TEST_TEXT_BYTES, 1024))];
			}
		}

		return [];
	}//end regexProblems()

	/**
	 * The 100 KB test texts.
	 *
	 * @return array<int, string> The texts.
	 */
	private function testTexts(): array {
		$runs = ['a', '1', ' ', 'a1-. '];
		$texts = [];
		foreach ($runs as $run) {
			$texts[] = substr(str_repeat($run, intdiv(self::TEST_TEXT_BYTES, strlen($run)) + 1), 0, self::TEST_TEXT_BYTES - 1).'!';
		}

		return $texts;
	}//end testTexts()
}//end class
