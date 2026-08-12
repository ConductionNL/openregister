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

namespace OCA\OpenRegister\Service\Quality;

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
	 * @param string $method One of `exact`, `normalized`, `levenshtein`.
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return float Similarity in [0, 1]; 0.0 for unknown method or non-scalar operands.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-4
	 */
	public function similarity(string $method, $a, $b): float {
		if (is_scalar($a) === false || is_scalar($b) === false) {
			return 0.0;
		}

		$left = (string)$a;
		$right = (string)$b;

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
		$value = trim($value);
		$value = strtolower($value);
		if (function_exists('mb_strtolower') === true) {
			$value = mb_strtolower($value, 'UTF-8');
		}

		// Strip common diacritics to ASCII where iconv is available.
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		if (is_string($ascii) === true && $ascii !== '') {
			$value = $ascii;
		}

		// Collapse internal whitespace runs to a single space.
		$collapsed = preg_replace('/\s+/', ' ', $value);
		if (is_string($collapsed) === true) {
			$value = $collapsed;
		}

		return trim($value);
	}//end normalize()

	/**
	 * Levenshtein similarity ratio over normalised strings.
	 *
	 * @param string $a First value.
	 * @param string $b Second value.
	 *
	 * @return float 1 - distance/maxLen, clamped to [0, 1].
	 */
	private function levenshteinRatio(string $a, string $b): float {
		$left = $this->normalize(value: $a);
		$right = $this->normalize(value: $b);

		if ($left === $right) {
			return 1.0;
		}

		$maxLen = max(strlen($left), strlen($right));
		if ($maxLen === 0) {
			return 1.0;
		}

		// PHP's levenshtein() caps inputs at 255 bytes; guard longer strings.
		if (strlen($left) > 255 || strlen($right) > 255) {
			$left = substr($left, 0, 255);
			$right = substr($right, 0, 255);
			$maxLen = max(strlen($left), strlen($right));
			if ($maxLen === 0) {
				return 1.0;
			}
		}

		$distance = levenshtein($left, $right);
		$ratio = (1.0 - ($distance / $maxLen));

		return max(0.0, min(1.0, $ratio));
	}//end levenshteinRatio()
}//end class
