<?php

/**
 * OpenRegister SimilarityCalculator
 *
 * Pure-function field-similarity routines used by duplicate detection.
 * Each method returns a similarity in [0, 1]. No I/O, no DB access, never
 * fatal: non-scalar or missing operands yield 0.0.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * Field comparison primitives for duplicate detection.
 *
 * Methods:
 * - exact:       1.0 when the raw string values are byte-identical, else 0.0.
 * - normalized:  1.0 when case-folded, whitespace-collapsed, accent-stripped
 *                values are equal, else 0.0.
 * - levenshtein: 1 - (editDistance / maxLen) over the normalised values,
 *                clamped to [0, 1]. A cheap stand-in for fuzzy name matching.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-4
 */
class SimilarityCalculator {
	/**
	 * Compute the similarity of two values under a named method.
	 *
	 * @param string         $method One of `exact`, `normalized`, `levenshtein`.
	 * @param ComparedValues $values The two values.
	 *
	 * @return float Similarity in [0, 1]; 0.0 for unknown method or non-scalar operands.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-4
	 */
	public function similarity(string $method, ComparedValues $values): float {
		if (is_scalar($values->left) === false || is_scalar($values->right) === false) {
			return 0.0;
		}

		$left = (string)$values->left;
		$right = (string)$values->right;

		return match ($method) {
			'exact' => $this->boolScore(matched: $left === $right),
			'normalized' => $this->boolScore(matched: $this->normalize(value: $left) === $this->normalize(value: $right)),
			'levenshtein' => $this->levenshteinRatio(a: $left, b: $right),
			default => 0.0,
		};
	}//end similarity()

	/**
	 * Map a boolean match outcome to a 0.0 / 1.0 score.
	 *
	 * @param bool $matched Whether the values matched.
	 *
	 * @return float 1.0 when matched, else 0.0.
	 */
	private function boolScore(bool $matched): float {
		if ($matched === true) {
			return 1.0;
		}

		return 0.0;
	}//end boolScore()

	/**
	 * Produce a stable blocking token for a value under a method.
	 *
	 * Used to group candidates into comparison blocks cheaply. `exact` keeps
	 * the raw value; everything else uses the normalised form so near-equal
	 * values land in the same block.
	 *
	 * @param string $method Match method.
	 * @param mixed $value Field value.
	 *
	 * @return string Blocking token (empty string for absent / non-scalar).
	 */
	public function blockingToken(string $method, $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$raw = (string)$value;
		if ($method === 'exact') {
			return $raw;
		}

		return $this->normalize(value: $raw);
	}//end blockingToken()

	/**
	 * Normalise a string: trim, lowercase, collapse whitespace, strip accents.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string Normalised value.
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) The @ on iconv() suppresses a locale
	 *   notice on non-translatable byte sequences; the result is null-checked immediately
	 *   after, so the operator is the idiomatic guard, not a swallowed error.
	 */
	private function normalize(string $value): string {
		$value = $this->foldCase(value: trim($value));

		// Strip common diacritics to ASCII where iconv is available.
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		if (is_string($ascii) === true && $ascii !== '') {
			$value = $ascii;
		}

		return trim($this->collapseWhitespace(value: $value));
	}//end normalize()

	/**
	 * Lowercase a string, multibyte-aware when mbstring is loaded.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string Lowercased value.
	 */
	private function foldCase(string $value): string {
		$value = strtolower($value);
		if (function_exists('mb_strtolower') === true) {
			$value = mb_strtolower($value, 'UTF-8');
		}

		return $value;
	}//end foldCase()

	/**
	 * Collapse internal whitespace runs to a single space.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string The collapsed value, or the input when the regex fails.
	 */
	private function collapseWhitespace(string $value): string {
		$collapsed = preg_replace('/\s+/', ' ', $value);
		if (is_string($collapsed) === true) {
			return $collapsed;
		}

		return $value;
	}//end collapseWhitespace()

	/**
	 * Levenshtein similarity ratio over normalised strings.
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 *
	 * @return float 1 - distance/maxLen, clamped to [0, 1].
	 */
	private function levenshteinRatio(string $a, string $b): float {
		// Only the first 255 bytes are compared (the historical levenshtein()
		// input cap). Equal strings, including two empty ones, score 1.0, so
		// the longest length below is never zero.
		$left = substr($this->normalize(value: $a), 0, 255);
		$right = substr($this->normalize(value: $b), 0, 255);
		if ($left === $right) {
			return 1.0;
		}

		$ratio = (1.0 - (levenshtein($left, $right) / max(strlen($left), strlen($right))));
		return max(0.0, min(1.0, $ratio));
	}//end levenshteinRatio()
}//end class
